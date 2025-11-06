-- Migration: Add admin_email column to sessions table
-- This allows clients to specify which admin email they want to connect with
-- Run this SQL script to update existing databases

USE lwavhbte_sharefast;

-- Add admin_email column if it doesn't exist
ALTER TABLE sessions 
ADD COLUMN IF NOT EXISTS admin_email VARCHAR(255) NULL AFTER connected;

-- Add index for efficient filtering by admin_email
CREATE INDEX IF NOT EXISTS idx_admin_email ON sessions(admin_email);


