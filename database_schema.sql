-- ShareFast Database Schema
-- Run this SQL script to create the tables in your existing database
-- Note: Database 'lwavhbte_sharefast' should already exist (created via cPanel)

USE lwavhbte_sharefast;

-- Sessions table - stores client and admin sessions
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) UNIQUE NOT NULL,
    code VARCHAR(32) NOT NULL,  -- Increased from VARCHAR(6) to support word-word codes (e.g., "moon-green")
    mode ENUM('client', 'admin') NOT NULL,
    peer_id VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    port INT DEFAULT 8765,
    allow_autonomous TINYINT(1) DEFAULT 0,
    connected TINYINT(1) DEFAULT 0,
    admin_email VARCHAR(255) NULL,  -- Admin email entered by client (for client sessions only)
    created_at INT NOT NULL,
    expires_at INT NOT NULL,
    last_keepalive INT NULL,
    INDEX idx_code (code),
    INDEX idx_session_id (session_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_mode (mode),
    INDEX idx_admin_email (admin_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admins table - stores admin users
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    admin_code VARCHAR(8) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    added_at INT NOT NULL,
    INDEX idx_email (email),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relay messages table - stores relayed data between peers
CREATE TABLE IF NOT EXISTS relay_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL,
    message_type VARCHAR(50) NOT NULL,
    message_data MEDIUMTEXT NOT NULL,
    created_at INT NOT NULL,
    read_at INT NULL,
    INDEX idx_session_id (session_id),
    INDEX idx_read_at (read_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Signals table - stores WebRTC signaling data
CREATE TABLE IF NOT EXISTS signals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL,
    code VARCHAR(32) NOT NULL,  -- Increased from VARCHAR(6) to support word-word codes
    signal_type VARCHAR(50) NOT NULL,
    signal_data TEXT NOT NULL,
    created_at INT NOT NULL,
    read_at INT NULL,
    INDEX idx_session_id (session_id),
    INDEX idx_code (code),
    INDEX idx_read_at (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin session info table - stores admin reconnection info
CREATE TABLE IF NOT EXISTS admin_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_session_id VARCHAR(255) NOT NULL,
    admin_code VARCHAR(32) NOT NULL,  -- Increased from VARCHAR(6) to support word-word codes
    peer_session_id VARCHAR(255) NOT NULL,
    peer_code VARCHAR(32) NOT NULL,  -- Increased from VARCHAR(6) to support word-word codes
    peer_ip VARCHAR(45) NULL,
    peer_port INT DEFAULT 8765,
    connected_at INT NOT NULL,
    expires_at INT NOT NULL,
    INDEX idx_admin_session_id (admin_session_id),
    INDEX idx_peer_session_id (peer_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin client codes table - stores previously used client codes per admin
CREATE TABLE IF NOT EXISTS admin_client_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_email VARCHAR(255) NOT NULL,
    client_code VARCHAR(32) NOT NULL,
    client_name VARCHAR(255) NULL,  -- Optional name/label for the client
    allow_reconnect TINYINT(1) DEFAULT 1,  -- Whether reconnect is allowed
    last_used_at INT NOT NULL,
    created_at INT NOT NULL,
    UNIQUE KEY unique_admin_code (admin_email, client_code),
    INDEX idx_admin_email (admin_email),
    INDEX idx_client_code (client_code),
    INDEX idx_last_used (last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

