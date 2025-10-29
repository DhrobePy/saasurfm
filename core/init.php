<?php
// new_ufmhrm/core/init.php

// MUST BE THE VERY FIRST THING. Starts the session on every page.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Load the configuration file which defines database constants.
require_once __DIR__ . '/config/config.php';

// Set up a robust autoloader with an absolute path.
spl_autoload_register(function ($class) {
    $class_file = __DIR__ . '/classes/' . $class . '.php';
    if (file_exists($class_file)) {
        require_once $class_file;
    }
});

// Include all helper functions.
require_once __DIR__ . '/functions/helpers.php';

// Create a single database instance for the application to use.
$db = Database::getInstance();