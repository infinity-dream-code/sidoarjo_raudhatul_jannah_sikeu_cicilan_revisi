-- Jalankan di database DATA_MYSQL (Navicat).
-- Tabel template pesan WhatsApp untuk tombol Kirim WA di Data Tagihan.

CREATE TABLE IF NOT EXISTS `wa_template` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode` VARCHAR(50) NOT NULL COMMENT 'Kode pemakaian, contoh: tagihan',
  `nama` VARCHAR(100) NOT NULL COMMENT 'Nama template',
  `template` TEXT NOT NULL COMMENT 'Isi pesan. Placeholder: {nama} {nis} {no_daftar} {kelas} {kelompok} {unit} {nama_tagihan} {periode} {jumlah_tagihan} {terbayar} {sisa_tagihan} {rincian} {no_va}',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=aktif, 0=nonaktif',
  `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wa_template_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `wa_template` (`kode`, `nama`, `template`, `is_active`)
VALUES (
  'tagihan',
  'Template Tagihan',
  'Assalamu''alaikum wr. wb.

Yth. Wali siswa *{nama}*
NIS: {nis}
Kelas: {kelas}

Kami informasikan tagihan:
*{nama_tagihan}*
Periode: {periode}

Jumlah tagihan: Rp {jumlah_tagihan}
Sudah dibayar: Rp {terbayar}
Sisa tagihan: Rp {sisa_tagihan}

Mohon dapat segera diselesaikan. Terima kasih.',
  1
)
ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`);
