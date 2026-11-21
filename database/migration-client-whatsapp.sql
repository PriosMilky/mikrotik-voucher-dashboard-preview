-- Jalankan hanya jika database lama sudah dibuat dari schema.sql versi sebelumnya.
USE mikrotik_vouchers;
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
ALTER TABLE voucher_batches
  ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN whatsapp_status ENUM('not_opened','opened','confirmed') NOT NULL DEFAULT 'not_opened',
  ADD COLUMN whatsapp_confirmed_at DATETIME NULL;
-- Existing historical batches have no customer record. Keep client_id NULL for those old rows.
ALTER TABLE voucher_batches
  ADD CONSTRAINT fk_batch_client FOREIGN KEY (client_id) REFERENCES clients(id),
  ADD INDEX idx_batch_client (client_id);
