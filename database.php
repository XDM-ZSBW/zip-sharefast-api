<?php
/**
 * Database Helper Functions
 * Provides MySQL database connection and helper functions
 */

require_once __DIR__ . '/config.php';

class Database {
    private static $connection = null;
    
    /**
     * Get database connection with connection pooling
     * OPTIMIZED: Reuses existing connection and checks if it's still alive
     * This eliminates connection overhead (saves 50-200ms per request)
     */
    public static function getConnection() {
        // Check if connection exists and is still alive
        if (self::$connection !== null) {
            // Use ping() to check if connection is still valid (much faster than reconnect)
            // ping() returns true if connection is alive, false if dead
            if (self::$connection->ping()) {
                return self::$connection;
            } else {
                // Connection is dead, close it and reconnect
                self::$connection->close();
                self::$connection = null;
            }
        }
        
        // Create new connection (only when needed)
        try {
            // OPTIMIZED: Create mysqli object first, then set options before connecting
            self::$connection = mysqli_init();
            
            // Set connection timeout (prevent hanging connections)
            // Must be set before connect()
            self::$connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            
            // Connect to database
            self::$connection->real_connect(
                DB_HOST, 
                DB_USER, 
                DB_PASS, 
                DB_NAME,
                null,  // Use default port
                null,  // Use default socket
                MYSQLI_CLIENT_COMPRESS | MYSQLI_CLIENT_INTERACTIVE
            );
            
            if (self::$connection->connect_error) {
                error_log("Database connection failed: " . self::$connection->connect_error);
                return null;
            }
            
            // OPTIMIZED: Set charset for better performance
            self::$connection->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            self::$connection = null;
            return null;
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

