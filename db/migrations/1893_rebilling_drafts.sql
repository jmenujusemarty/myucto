SET NAMES utf8mb4;
ALTER TABLE api_tokens MODIFY COLUMN scope ENUM('read','read_write','rebilling_drafts') NOT NULL DEFAULT 'read_write';
CREATE TABLE IF NOT EXISTS rebilling_receipts (
  supplier_id INT UNSIGNED NOT NULL,
  source_key VARCHAR(200) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  source_number VARCHAR(100) NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  line_ids LONGTEXT NOT NULL,
  start_index INT UNSIGNED NOT NULL,
  line_count INT UNSIGNED NOT NULL,
  snapshot_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(supplier_id, source_key),
  KEY idx_rebilling_invoice(supplier_id, invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS idx_rebilling_client_period ON invoices(supplier_id,client_id,issue_date,id);
