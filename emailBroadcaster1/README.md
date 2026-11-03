# Email Broadcaster (PHP Native)

Antrian email penagihan & konfirmasi pembayaran sekolah.

Struktur **PHP biasa** — panggil langsung file `.php`, tanpa rewrite/router.

## Struktur

```text
emailBroadcaster/
  dashboard.html
  index.php
  health.php
  style.css
  api/
    billing.php
    payment-confirmation.php
    messages.php
    message.php
    retry.php
    process-now.php
  app/          # library internal
  cron/send_emails.php
  storage/      # sqlite
  .env
```

## Deploy ke server (contoh server 56)

Upload seluruh folder ke web root, misalnya:

```text
/var/www/html/emailBroadcaster/
```

Akses:

- Dashboard: `http://101.50.2.56/emailBroadcaster/dashboard.html`
- Health: `http://101.50.2.56/emailBroadcaster/health.php`

Pastikan PHP-FPM aktif di nginx/Apache untuk file `.php`.

## Setup

1. Copy `.env.example` → `.env`
2. Isi **3 akun SMTP** (App Password Gmail):
   ```env
   SMTP1_USER=akun1@gmail.com
   SMTP1_PASS=app-password-1
   SMTP1_FROM_EMAIL=akun1@gmail.com

   SMTP2_USER=akun2@gmail.com
   SMTP2_PASS=app-password-2
   SMTP2_FROM_EMAIL=akun2@gmail.com

   SMTP3_USER=akun3@gmail.com
   SMTP3_PASS=app-password-3
   SMTP3_FROM_EMAIL=akun3@gmail.com
   ```
   Fallback lama `SMTP_USER` / `SMTP_PASS` masih didukung jika SMTP1..3 kosong.
3. Set `APP_BASE_URL` sesuai URL server

### Rate limit pengiriman

| Antrian PENDING | Akun | Kuota |
|---|---|---|
| 1–20 | 1 akun (acak) | max 20 / 10 menit |
| >20 | sampai 3 akun | max 20 / akun / 10 menit (= 60 / 10 menit) |

Kuota disimpan di `storage/smtp_quota.json`. Jeda antar kirim: `EMAIL_SEND_DELAY_US` (default 200ms).

Lokal testing:

```bash
php -S 127.0.0.1:8080
```

Lalu buka http://127.0.0.1:8080/dashboard.html

## API

Dokumentasi lengkap (field, contoh JSON, cURL): **[docs/API.md](docs/API.md)**

Base URL contoh: `http://101.50.2.56/emailBroadcaster`

| Method | File | Keterangan |
|--------|------|------------|
| POST | `/api/notify.php` | Disarankan — bedakan lewat `method` (`billing` / `payment_confirmation`) |
| POST | `/api/billing.php` | Antri email penagihan (single/bulk) |
| POST | `/api/payment-confirmation.php` | Antri konfirmasi pembayaran |
| GET | `/api/messages.php` | List + summary (`?status=&type=&q=`) |
| GET | `/api/message.php?id=` | Detail pesan |
| POST | `/api/retry.php?id=` | Retry FAILED → PENDING |
| POST | `/api/process-now.php` | Proses antrian sekarang |
| GET | `/health.php` | Cek SMTP |

Jika `API_TOKEN` di `.env` diisi, kirim header `Authorization: Bearer <token>`.

### Contoh penagihan

`POST /api/billing.php`

```json
{
  "nama": "Gaza Solahudin Mubarok",
  "nis": "2026001",
  "kelas": "Daycare A",
  "email": "ortu@gmail.com",
  "va": "900362125117",
  "paymentLink": "https://example.com/pay/xxx",
  "items": [
    { "name": "SPP DAYCARE JULI 2026", "amount": 247500 },
    { "name": "Ekskul Renang", "amount": 150000 }
  ]
}
```

Bulk: kirim `{ "messages": [ {...}, {...} ] }`.

### Contoh konfirmasi pembayaran

`POST /api/payment-confirmation.php`

```json
{
  "nama": "IBRAHIM ARFA GUNAWAN",
  "email": "ortu@gmail.com",
  "orderNumber": "QUBAP-DFU-262701-221",
  "paymentAmount": 2708000,
  "billAmount": 10028000,
  "outstandingAmount": 7320000,
  "referenceNumber": "1787702013506227",
  "paymentMethod": "Permata",
  "items": [{ "name": "Daftar Ulang Tahun 2026/2027", "amount": 10028000 }]
}
```

## Cron tiap 10 menit

```bash
*/10 * * * * php /var/www/html/emailBroadcaster/cron/send_emails.php
```

Windows Task Scheduler: jalankan `php.exe` + path ke `cron/send_emails.php` tiap 10 menit.
