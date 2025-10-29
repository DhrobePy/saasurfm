<?php
// new_ufmhrm/core/classes/Employee.php

class Employee
{
    private $_db;

    // The constructor now explicitly requires the database object.
    // This removes any guesswork about where the connection comes from.
    public function __construct($db)
    {
        $this->_db = $db;
    }

    public function create($fields = [])
    {
        if (!$this->_db->insert('employees', $fields)) {
            throw new Exception('There was a problem creating an employee.');
        }
    }

    public function find($id)
    {
        $data = $this->_db->get('employees', ['id', '=', $id]);
        if ($data->count()) {
            return $data->first();
        }
        return false;
    }

    public function findAll()
    {
        return $this->_db->query("SELECT * FROM employees")->results();
    }

    public function count()
    {
        // This query will now use the guaranteed-to-exist database connection.
        return $this->_db->query("SELECT * FROM employees")->count();
    }
}