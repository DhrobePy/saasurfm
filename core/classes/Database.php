<?php
/**
 * Database Class
 * 
 * Singleton PDO wrapper with query builder methods
 * Supports both legacy ID-based updates and modern array-based where clauses
 * 
 * @version 2.0.0
 * @date 2025-03-17
 */

class Database
{
    private static $_instance = null;
    private $_pdo;
    private $_query;
    private $_error = false;
    private $_results = [];
    private $_count = 0;
    private $_lastInsertId = null;

    private function __construct()
    {
        try {
            $options = [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->_pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                $options
            );

        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check logs or contact support.");
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new Database();
        }
        return self::$_instance;
    }

    /**
     * Get PDO instance
     */
    public function getPdo()
    {
        return $this->_pdo;
    }

    /**
     * Execute a SQL query with parameters
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return Database Returns instance for chaining
     */
    public function query($sql, $params = [])
    {
        $this->_error = false;
        $this->_results = [];
        $this->_count = 0;

        try {
            $this->_query = $this->_pdo->prepare($sql);
            $success = $this->_query->execute($params);

            if ($success) {
                $sqlType = strtoupper(trim(explode(' ', trim($sql))[0]));
                
                if ($sqlType === 'SELECT') {
                    $this->_results = $this->_query->fetchAll(PDO::FETCH_OBJ);
                    $this->_count = count($this->_results);
                } else {
                    $this->_count = $this->_query->rowCount();
                }
            }

        } catch (PDOException $e) {
            $this->_error = true;
            error_log(sprintf(
                "SQL Error [%s]: %s\nQuery: %s\nParams: %s",
                $e->getCode(),
                $e->getMessage(),
                $sql,
                json_encode($params, JSON_UNESCAPED_UNICODE)
            ));
        }

        return $this;
    }

    /**
     * Insert data into table
     * 
     * @param string $table Table name
     * @param array $fields Associative array of column => value
     * @return mixed Last insert ID on success, false on failure
     */
    public function insert($table, $fields = [])
    {
        if (empty($fields)) {
            error_log("Database insert failed: no fields provided for table '{$table}'.");
            return false;
        }

        $keys = array_keys($fields);
        $columns = '`' . implode('`, `', array_map([$this, 'sanitizeIdentifier'], $keys)) . '`';
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));

        $sql = "INSERT INTO `" . $this->sanitizeIdentifier($table) . "` ({$columns}) VALUES ({$placeholders})";

        if (!$this->query($sql, array_values($fields))->error()) {
            try {
                $this->_lastInsertId = $this->_pdo->lastInsertId();
                return $this->_lastInsertId;
            } catch (PDOException $e) {
                error_log("Could not get lastInsertId: " . $e->getMessage());
                return true;
            }
        }

        error_log("Database insert failed for table '{$table}'.");
        return false;
    }

    /**
     * Update data in table
     * 
     * Supports both legacy and modern syntax:
     * - Modern: update($table, $fields, ['id' => 123])
     * - Legacy: update($table, 123, $fields)
     * 
     * @param string $table Table name
     * @param mixed $fieldsOrId Fields array (modern) or ID (legacy)
     * @param mixed $whereOrFields Where clause array (modern) or fields array (legacy)
     * @return bool
     */
    public function update($table, $fieldsOrId, $whereOrFields = [])
    {
        // Detect call signature
        $fields = [];
        $where = [];

        if (is_array($fieldsOrId)) {
            // Modern syntax: update($table, $fields, $where)
            $fields = $fieldsOrId;
            $where = $whereOrFields;
        } else {
            // Legacy syntax: update($table, $id, $fields)
            $where = ['id' => $fieldsOrId];
            $fields = $whereOrFields;
        }

        // Validate inputs
        if (empty($fields)) {
            error_log("Database update failed: no fields provided for table '{$table}'.");
            return false;
        }

        if (empty($where)) {
            error_log("Database update failed: no WHERE clause provided for table '{$table}'.");
            return false;
        }

        // Build SET clause
        $set = [];
        $params = [];
        foreach ($fields as $column => $value) {
            $set[] = '`' . $this->sanitizeIdentifier($column) . '` = ?';
            $params[] = $value;
        }

        // Build WHERE clause
        $conditions = [];
        foreach ($where as $column => $value) {
            if (is_array($value)) {
                // Handle IN clause: ['status' => ['pending', 'processing']]
                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $conditions[] = '`' . $this->sanitizeIdentifier($column) . '` IN (' . $placeholders . ')';
                $params = array_merge($params, $value);
            } else {
                $conditions[] = '`' . $this->sanitizeIdentifier($column) . '` = ?';
                $params[] = $value;
            }
        }

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $this->sanitizeIdentifier($table),
            implode(', ', $set),
            implode(' AND ', $conditions)
        );

        if (!$this->query($sql, $params)->error()) {
            return true;
        }

        error_log("Database update failed for table '{$table}'.");
        return false;
    }

    /**
     * Delete records from table
     * 
     * @param string $table Table name
     * @param array $where Where conditions ['column' => 'value']
     * @return bool
     */
    public function delete($table, $where = [])
    {
        if (empty($where)) {
            error_log("Database delete failed: no WHERE clause provided for table '{$table}' (safety).");
            return false;
        }

        $conditions = [];
        $params = [];

        foreach ($where as $column => $value) {
            if (is_array($value)) {
                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $conditions[] = '`' . $this->sanitizeIdentifier($column) . '` IN (' . $placeholders . ')';
                $params = array_merge($params, $value);
            } else {
                $conditions[] = '`' . $this->sanitizeIdentifier($column) . '` = ?';
                $params[] = $value;
            }
        }

        $sql = sprintf(
            "DELETE FROM `%s` WHERE %s",
            $this->sanitizeIdentifier($table),
            implode(' AND ', $conditions)
        );

        if (!$this->query($sql, $params)->error()) {
            return true;
        }

        error_log("Database delete failed for table '{$table}'.");
        return false;
    }

    /**
     * Get records from table
     * 
     * @param string $table Table name
     * @param array $where Where conditions
     * @return Database
     */
    public function get($table, $where = [])
    {
        if (empty($where)) {
            $sql = "SELECT * FROM `" . $this->sanitizeIdentifier($table) . "`";
            return $this->query($sql);
        }

        $conditions = [];
        $params = [];

        foreach ($where as $column => $value) {
            if (is_array($value)) {
                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $conditions[] = '`' . $this->sanitizeIdentifier($column) . '` IN (' . $placeholders . ')';
                $params = array_merge($params, $value);
            } else {
                $conditions[] = '`' . $this->sanitizeIdentifier($column) . '` = ?';
                $params[] = $value;
            }
        }

        $sql = sprintf(
            "SELECT * FROM `%s` WHERE %s",
            $this->sanitizeIdentifier($table),
            implode(' AND ', $conditions)
        );

        return $this->query($sql, $params);
    }

    /**
     * Check if record exists
     * 
     * @param string $table Table name
     * @param array $where Where conditions
     * @return bool
     */
    public function exists($table, $where = [])
    {
        if (empty($where)) {
            return false;
        }

        $conditions = [];
        $params = [];

        foreach ($where as $column => $value) {
            $conditions[] = '`' . $this->sanitizeIdentifier($column) . '` = ?';
            $params[] = $value;
        }

        $sql = sprintf(
            "SELECT COUNT(*) as count FROM `%s` WHERE %s LIMIT 1",
            $this->sanitizeIdentifier($table),
            implode(' AND ', $conditions)
        );

        $result = $this->query($sql, $params)->first();
        return $result && $result->count > 0;
    }

    /**
     * Get single record by ID
     * 
     * @param string $table Table name
     * @param int $id Record ID
     * @return object|null
     */
    public function getById($table, $id)
    {
        $sql = "SELECT * FROM `" . $this->sanitizeIdentifier($table) . "` WHERE `id` = ? LIMIT 1";
        return $this->query($sql, [$id])->first();
    }

    /**
     * Transaction methods
     */
    public function beginTransaction()
    {
        return $this->_pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->_pdo->commit();
    }

    public function rollback()
    {
        return $this->_pdo->rollBack();
    }

    public function inTransaction()
    {
        return $this->_pdo->inTransaction();
    }

    /**
     * Execute callback within transaction
     * 
     * @param callable $callback Function to execute
     * @return mixed Returns callback result or false on error
     */
    public function transaction($callback)
    {
        try {
            $this->beginTransaction();
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            error_log("Transaction failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sanitize table/column identifier
     * 
     * @param string $identifier Table or column name
     * @return string Sanitized identifier
     */
    private function sanitizeIdentifier($identifier)
    {
        // Remove backticks and escape any that might be in the name
        return str_replace('`', '', $identifier);
    }

    /**
     * Result methods
     */
    public function results()
    {
        return $this->_results;
    }

    public function first()
    {
        return $this->_results[0] ?? null;
    }

    public function count()
    {
        return $this->_count;
    }

    public function error()
    {
        return $this->_error;
    }

    public function errorInfo()
    {
        return $this->_query ? $this->_query->errorInfo() : $this->_pdo->errorInfo();
    }

    public function lastInsertId()
    {
        return $this->_lastInsertId;
    }

    /**
     * Legacy action method (for backward compatibility)
     * 
     * @deprecated Use get() or delete() directly
     */
    public function action($action, $table, $where = [])
    {
        if (count($where) === 3) {
            $operators = ['=', '>', '<', '>=', '<=', '!=', '<>', 'LIKE', 'NOT LIKE'];
            
            $field = $where[0];
            $operator = strtoupper($where[1]);
            $value = $where[2];

            if (in_array($operator, $operators)) {
                $sql = sprintf(
                    "%s FROM `%s` WHERE `%s` %s ?",
                    $action,
                    $this->sanitizeIdentifier($table),
                    $this->sanitizeIdentifier($field),
                    $operator
                );
                
                if (!$this->query($sql, [$value])->error()) {
                    return $this;
                }
            }
        }
        
        error_log("Database action '{$action}' failed or had invalid 'where' clause.");
        return false;
    }
}