CREATE TABLE IF NOT EXISTS email_messages (
  id VARCHAR(64) PRIMARY KEY,
  type ENUM('BILLING', 'PAYMENT_CONFIRMATION') NOT NULL,
  status ENUM('PENDING', 'SENDING', 'SENT', 'FAILED') NOT NULL DEFAULT 'PENDING',

  recipient_email VARCHAR(255) NOT NULL,
  recipient_name VARCHAR(255) NULL,
  student_name VARCHAR(255) NOT NULL,
  student_nis VARCHAR(64) NULL,
  student_class VARCHAR(128) NULL,

  subject VARCHAR(500) NOT NULL,
  va_number VARCHAR(64) NULL,
  payment_link TEXT NULL,
  payment_guide TEXT NULL,
  meta_json LONGTEXT NULL,

  items_json LONGTEXT NOT NULL,
  total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  outstanding_amount DECIMAL(18,2) NULL,
  bill_amount DECIMAL(18,2) NULL,
  payment_amount DECIMAL(18,2) NULL,

  order_number VARCHAR(128) NULL,
  reference_number VARCHAR(128) NULL,
  payment_method VARCHAR(128) NULL,
  paid_at DATETIME NULL,

  error_message TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,

  INDEX idx_status_created (status, created_at),
  INDEX idx_type (type),
  INDEX idx_recipient (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
