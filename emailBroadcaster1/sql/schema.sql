CREATE TABLE IF NOT EXISTS email_messages (
  id TEXT PRIMARY KEY,
  type TEXT NOT NULL CHECK(type IN ('BILLING', 'PAYMENT_CONFIRMATION')),
  status TEXT NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING', 'SENDING', 'SENT', 'FAILED')),

  recipient_email TEXT NOT NULL,
  recipient_name TEXT,
  student_name TEXT NOT NULL,
  student_nis TEXT,
  student_class TEXT,

  subject TEXT NOT NULL,
  va_number TEXT,
  payment_link TEXT,
  payment_guide TEXT,
  meta_json TEXT NOT NULL DEFAULT '{}',

  items_json TEXT NOT NULL DEFAULT '[]',
  total_amount REAL NOT NULL DEFAULT 0,
  outstanding_amount REAL,
  bill_amount REAL,
  payment_amount REAL,

  order_number TEXT,
  reference_number TEXT,
  payment_method TEXT,
  paid_at TEXT,

  error_message TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  sent_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_email_messages_status_created
  ON email_messages (status, created_at);

CREATE INDEX IF NOT EXISTS idx_email_messages_type
  ON email_messages (type);

CREATE INDEX IF NOT EXISTS idx_email_messages_recipient
  ON email_messages (recipient_email);
