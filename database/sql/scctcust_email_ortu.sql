-- Jalankan di database DATA_MYSQL (Navicat).
-- Kolom email orang tua / wali di scctcust (untuk kirim email nanti).

ALTER TABLE `scctcust`
  ADD COLUMN `EMAIL_ORTU` VARCHAR(150) NULL DEFAULT NULL
  COMMENT 'Email orang tua / wali'
  AFTER `NO_WA`;
