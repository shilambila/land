<?php
// db_connection.php - PDO Version

// Database configuration
$host = '127.0.0.1';
$username = 'root';
$password = 'Movile@123';
$database = 'land-system';
$charset = 'utf8mb4';

// DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$database;charset=$charset";

// PDO options
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch as associative array
    PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset" // Set charset
];

try {
    // Create PDO connection if it doesn't exist
    if (!isset($conn)) {
        $conn = new PDO($dsn, $username, $password, $options);
    }
} catch (PDOException $e) {
    // Log error and show user-friendly message
    error_log("Database Connection Error: " . $e->getMessage());
    die("Connection failed: Unable to connect to the database. Please try again later.");
}

/**
 * Execute a query with prepared statements
 * 
 * @param PDO $conn Database connection
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return PDOStatement
 */
function executeQuery($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Query Execution Error: " . $e->getMessage());
        error_log("SQL: " . $sql);
        error_log("Params: " . print_r($params, true));
        throw new Exception("Database error occurred. Please try again.");
    }
}

/**
 * Fetch a single row
 */
function fetchOne($conn, $sql, $params = []) {
    $stmt = executeQuery($conn, $sql, $params);
    return $stmt->fetch();
}

/**
 * Fetch all rows
 */
function fetchAll($conn, $sql, $params = []) {
    $stmt = executeQuery($conn, $sql, $params);
    return $stmt->fetchAll();
}

/**
 * Insert data and return last insert ID
 */
function insertData($conn, $sql, $params = []) {
    executeQuery($conn, $sql, $params);
    return $conn->lastInsertId();
}

/**
 * Update data and return number of affected rows
 */
function updateData($conn, $sql, $params = []) {
    $stmt = executeQuery($conn, $sql, $params);
    return $stmt->rowCount();
}

/**
 * Begin transaction
 */
function beginTransaction($conn) {
    return $conn->beginTransaction();
}

/**
 * Commit transaction
 */
function commitTransaction($conn) {
    return $conn->commit();
}

/**
 * Rollback transaction
 */
function rollbackTransaction($conn) {
    return $conn->rollBack();
}

/**
 * Get table columns
 */
function getTableColumns($conn, $table) {
    $sql = "SHOW COLUMNS FROM " . $table;
    return fetchAll($conn, $sql);
}

// Optional: Test connection with a simple query
try {
    $conn->query("SELECT 1");
} catch (PDOException $e) {
    error_log("Connection test failed: " . $e->getMessage());
    die("Database connection test failed.");
}
?>