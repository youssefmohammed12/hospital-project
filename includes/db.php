<?php
/**
 * HealthBridge — Database Configuration & Connection
 */

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'healthbridge');
define('DB_PORT', '3306');

/**
 * Get a PDO database connection
 * @return PDO
 * @throws PDOException
 */
function getDB(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    if (DB_PORT !== '') {
        $dsn .= ';port=' . DB_PORT;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3,
    ];

    return new PDO($dsn, DB_USER, DB_PASS, $options);
}
