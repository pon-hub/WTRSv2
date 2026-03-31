<?php
/**
 * WMSU Thesis Repository System
 * Database Configuration using Secure PDO Connections
 */

$host = '127.0.0.1';
$db   = 'wtrs_db';
$user = 'root'; // Standard XAMPP default
$pass = '';     // Standard XAMPP default
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Set strict PDO options for secure error handling and native fetching
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // Important for SQL Injection defense
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // In production, log this error rather than outputting to the screen
    die("Database Connection Failure. Please check if MySQL is running via XAMPP control panel. Error: " . $e->getMessage());
}
?>
