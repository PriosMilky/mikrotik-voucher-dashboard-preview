CREATE DATABASE IF NOT EXISTS mikrotik_vouchers CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mikrotik_vouchers;

CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_sequences (
  client_date DATE PRIMARY KEY,
  last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_no VARCHAR(24) NOT NULL UNIQUE,
  customer_name VARCHAR(120) NOT NULL,
  phone VARCHAR(24) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client_phone (phone),
  INDEX idx_client_created (created_at),
  CONSTRAINT fk_client_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS voucher_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  batch_code VARCHAR(40) NOT NULL UNIQUE,
  profile_name VARCHAR(80) NOT NULL,
  limit_uptime VARCHAR(20) NOT NULL DEFAULT '10h',
  quantity INT UNSIGNED NOT NULL,
  whatsapp_status ENUM('not_opened','opened','confirmed') NOT NULL DEFAULT 'not_opened',
  whatsapp_confirmed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batch_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_batch_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
  INDEX idx_batch_client (client_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vouchers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_enc TEXT NOT NULL,
  profile_name VARCHAR(80) NOT NULL,
  limit_uptime VARCHAR(20) NOT NULL DEFAULT '10h',
  router_status ENUM('created','disabled','deleted','error') NOT NULL DEFAULT 'created',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_voucher_batch FOREIGN KEY (batch_id) REFERENCES voucher_batches(id) ON DELETE CASCADE,
  INDEX idx_voucher_batch (batch_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;
