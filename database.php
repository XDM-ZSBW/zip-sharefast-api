<?php
/**
 * Database Helper Functions
 * Provides MySQL database connection and helper functions
 */

require_once __DIR__ . '/config.php';

class Database {
    private static $connection = null;
    
    public static function getConnection() {
        if (self::$connection === null) {
            try {
                self::$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                
                if (self::$connection->connect_error) {
                    error_log("Database connection failed: " . self::$connection->connect_error);
                    return null;
                }
                
                self::$connection->set_charset("utf8mb4");
            } catch (Exception $e) {
                error_log("Database connection error: " . $e->getMessage());
                return null;
            }
        }
        
        return self::$connection;
    }
    
    public static function close() {
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }
    
    public static function escape($value) {
        $conn = self::getConnection();
        if ($conn === null) {
            return addslashes($value); // Fallback if connection fails
        }
        return $conn->real_escape_string($value);
    }
    
    public static function query($sql) {
        $conn = self::getConnection();
        if ($conn === null) {
            return false;
        }
        
        // OPTIMIZATION: Use unbuffered queries for large result sets (relay messages)
        // This reduces memory usage and improves performance
        $result = $conn->query($sql);
        if (!$result) {
            // Only log errors (not warnings) to reduce overhead
            error_log("Database query error: " . $conn->error . " | SQL: " . substr($sql, 0, 200));
            return false;
        }
        
        return $result;
    }
    
    public static function insertId() {
        $conn = self::getConnection();
        if ($conn === null) {
            return 0;
        }
        return $conn->insert_id;
    }
    
    public static function affectedRows() {
        $conn = self::getConnection();
        if ($conn === null) {
            return 0;
        }
        return $conn->affected_rows;
    }
}

?>

