<?php
// core/classes/Database.php

class Database
{
    private static $_instance = null;
    private $_pdo,
            $_query,
            $_error = false,
            $_results,
            $_count = 0;

    private function __construct()
    {
        try {
            // Enable persistent connections and error mode exceptions
            $options = [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ, // Default to objects
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci" // Ensure UTF8
            ];
            $this->_pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {
            // Log error instead of dying directly in production
            error_log("Database Connection Error: " . $e->getMessage());
            // Optionally re-throw or handle differently based on environment
            die("Database connection failed. Please check logs or contact support.");
        }
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new Database();
        }
        return self::$_instance;
    }

    public function getPdo()
    {
        return $this->_pdo;
    }

    /**
     * Executes a SQL query with parameters.
     * Handles both positional (?) and named (:name) placeholders.
     *
     * @param string $sql The SQL query string.
     * @param array $params An array of parameters to bind.
     * @return Database Returns the Database instance for chaining.
     */
    public function query($sql, $params = [])
    {
        $this->_error = false; // Reset error state
        $this->_results = [];  // Reset results
        $this->_count = 0;     // Reset count

        try {
            $this->_query = $this->_pdo->prepare($sql);

            // Let PDO's execute handle parameter binding
            $success = $this->_query->execute($params);

            if ($success) {
                // Check if it was a SELECT statement before fetching
                // Use trim() to handle potential whitespace
                if (stripos(trim($sql), 'SELECT') === 0) {
                    $this->_results = $this->_query->fetchAll(PDO::FETCH_OBJ); // Fetch as objects
                    $this->_count = $this->_query->rowCount(); // rowCount is reliable after fetchAll for SELECT
                } else {
                    // For INSERT, UPDATE, DELETE, rowCount gives affected rows
                    $this->_count = $this->_query->rowCount();
                }
            }
            // No need for an else here, execute throws exception on failure with ERRMODE_EXCEPTION

        } catch (PDOException $e) {
            $this->_error = true;
            // Log the detailed PDO error
            error_log("SQL Error [{$e->getCode()}]: {$e->getMessage()} in query: $sql | Params: " . print_r($params, true));
            // You might want to throw the exception again or handle it based on context
            // throw $e; // Re-throw if you want calling code to handle it
        }

        return $this; // Return instance for chaining
    }


    /**
     * Simplified action method (kept for potential compatibility, but recommend direct query)
     * Only handles simple WHERE clauses with one condition.
     */
    public function action($action, $table, $where = [])
    {
        if (count($where) === 3) {
            $operators = ['=', '>', '<', '>=', '<=', '!=', '<>']; // Added !=, <>

            $field = "`" . str_replace("`", "``", $where[0]) . "`"; // Basic quoting
            $operator = $where[1];
            $value = $where[2];

            if (in_array($operator, $operators)) {
                // Use positional placeholder for compatibility with this method's design
                $sql = "{$action} FROM `{$table}` WHERE {$field} {$operator} ?";
                if (!$this->query($sql, [$value])->error()) {
                    return $this;
                }
            }
        }
        // Log error if action fails or conditions are wrong
        error_log("Database action '{$action}' failed or had invalid 'where' clause.");
        return false;
    }

    /**
     * Simplified get method.
     */
    public function get($table, $where)
    {
        return $this->action('SELECT *', $table, $where);
    }

    /**
     * Simplified delete method.
     */
    public function delete($table, $where)
    {
        return $this->action('DELETE', $table, $where);
    }


    /**
     * Inserts data into a table.
     *
     * @param string $table Table name.
     * @param array $fields Associative array of column => value.
     * @return mixed Last insert ID on success, false on failure.
     */
    public function insert($table, $fields = [])
    {
        if (count($fields)) {
            $keys = array_keys($fields);
            // Properly quote column names
            $columns = "`" . implode('`, `', $keys) . "`";
            // Create positional placeholders
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));

            $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

            // Use array_values to ensure parameters match placeholders order
            if (!$this->query($sql, array_values($fields))->error()) {
                try {
                    return $this->_pdo->lastInsertId();
                } catch (PDOException $e) {
                    // Handle cases where lastInsertId might not be applicable (e.g., tables without auto-increment)
                    error_log("Could not get lastInsertId after INSERT: " . $e->getMessage());
                    return true; // Indicate success even if ID isn't available/needed
                }
            }
        }
        error_log("Database insert failed for table '{$table}'.");
        return false;
    }

    /**
     * Updates data in a table based on ID.
     *
     * @param string $table Table name.
     * @param int $id The ID of the row to update.
     * @param array $fields Associative array of column => value.
     * @return bool True on success (or no change), false on failure.
     */
    public function update($table, $id, $fields = [])
    {
        if (count($fields) && $id) {
            $set = '';
            $params = [];
            foreach ($fields as $name => $value) {
                // Properly quote column names and create placeholders
                $set .= "`{$name}` = ?, ";
                $params[] = $value;
            }
            $set = rtrim($set, ', '); // Remove trailing comma and space

            // Add the ID to the parameters array for the WHERE clause
            $params[] = $id;

            $sql = "UPDATE `{$table}` SET {$set} WHERE `id` = ?";

            if (!$this->query($sql, $params)->error()) {
                // Check if rows were actually affected, or just return true if query succeeded
                // return $this->count() > 0; // Stricter: only true if rows changed
                return true; // More lenient: true if query ran without error
            }
        }
        error_log("Database update failed for table '{$table}', ID '{$id}'.");
        return false;
    }

    // --- Result Methods ---

    public function results()
    {
        return $this->_results;
    }

    public function first()
    {
        // Return the first result object, or null if no results
        return $this->_results[0] ?? null;
    }

    public function count()
    {
        // Returns rows selected (for SELECT) or rows affected (for INSERT/UPDATE/DELETE)
        return $this->_count;
    }

    public function error()
    {
        return $this->_error;
    }

    // Added to get raw PDO error info if needed for detailed debugging
    public function errorInfo() {
        return $this->_query ? $this->_query->errorInfo() : $this->_pdo->errorInfo();
    }
}
?>
