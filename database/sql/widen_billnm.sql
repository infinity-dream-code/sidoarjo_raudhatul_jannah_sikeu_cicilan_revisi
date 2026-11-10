-- Perpanjang kolom nama tagihan (sebelumnya sering VARCHAR(30), memotong "2026" jadi "202").
-- Jalankan di database DATA_MYSQL (scctbill).
-- Sesuaikan tipe bila server memakai charset berbeda.

ALTER TABLE scctbill
  MODIFY COLUMN BILLNM VARCHAR(100) NULL;

-- Jika ada kolom sejenis di transaksi:
-- ALTER TABLE sccttran MODIFY COLUMN BILLTARGET VARCHAR(100) NULL;

-- Stored procedure InputTagihan: ubah parameter p_NMTagihan dari VARCHAR(30)
-- menjadi VARCHAR(100), lalu recreate procedure (SHOW CREATE PROCEDURE InputTagihan).
