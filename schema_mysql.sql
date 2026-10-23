-- ============================================================
-- Wildboar Gym — MySQL / MariaDB schema (Hostinger)
-- Import via hPanel → phpMyAdmin (or: mysql -u user -p < schema_mysql.sql)
-- ============================================================

CREATE DATABASE IF NOT EXISTS wildboar_gym
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wildboar_gym;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(10) NOT NULL DEFAULT 'member',
    member_id VARCHAR(20) NULL UNIQUE,
    status VARCHAR(8) NOT NULL DEFAULT 'active',
    phone VARCHAR(20) NULL,
    membership_expires DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(160) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attempts (username, ip, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    check_in_time DATETIME NOT NULL,
    check_in_method VARCHAR(10) NOT NULL DEFAULT 'kiosk',
    created_by INT NULL,
    token_hash VARCHAR(64) NULL,
    KEY idx_att_lookup (member_id, check_in_time),
    KEY idx_att_time (check_in_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loyalty_perks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    perk_year INT NOT NULL,
    attendance_count INT NOT NULL DEFAULT 0,
    eligible TINYINT(1) NOT NULL DEFAULT 0,
    voucher_code VARCHAR(30) NULL,
    issued_at DATETIME NULL,
    created_by INT NULL,
    UNIQUE KEY uq_loyalty_member_year (member_id, perk_year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    action VARCHAR(60) NOT NULL,
    detail VARCHAR(500) NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;