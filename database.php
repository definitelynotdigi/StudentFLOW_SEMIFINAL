<?php
// database.php - PDO Database Driver Connection
$config = require_once 'config.php';

$host    = $config['db']['host'] ?? 'localhost';
$db      = $config['db']['name'] ?? 'student_portal_db';
$user    = $config['db']['user'] ?? 'root';
$pass    = $config['db']['pass'] ?? '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>