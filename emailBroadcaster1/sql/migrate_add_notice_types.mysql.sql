-- Perluas tipe antrian email (jalankan sekali di MySQL jika perlu).
ALTER TABLE email_messages
  MODIFY COLUMN type ENUM(
    'BILLING',
    'PAYMENT_CONFIRMATION',
    'BILL_UPDATE',
    'EXP_UPDATE'
  ) NOT NULL;
