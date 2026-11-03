# Dokumentasi API — Email Broadcaster

Base URL (contoh server):

```text
http://101.50.2.56/emailBroadcaster
```

Semua request API memakai:

- **Method** sesuai endpoint
- **Header** `Content-Type: application/json` (untuk POST body)
- **Body** JSON (untuk POST)

---

## Autentikasi (opsional)

Jika `API_TOKEN` di `.env` diisi, wajib kirim salah satu:

```http
Authorization: Bearer <API_TOKEN>
```

atau

```http
X-Api-Token: <API_TOKEN>
```

Jika `API_TOKEN` kosong, API terbuka tanpa auth.

---

## Ringkasan Endpoint


| Method | Endpoint                        | Keterangan                                  |
| ------ | ------------------------------- | ------------------------------------------- |
| POST   | `/api/notify.php`               | **Disarankan** — bedakan lewat field `method` |
| POST   | `/api/billing.php`              | Antri email penagihan (single / bulk)       |
| POST   | `/api/payment-confirmation.php` | Antri email konfirmasi pembayaran           |
| GET    | `/api/messages.php`             | List pesan + summary status                 |
| GET    | `/api/message.php?id=`          | Detail 1 pesan                              |
| POST   | `/api/retry.php?id=`            | Retry pesan `FAILED` → `PENDING`            |
| POST   | `/api/process-now.php`          | Proses antrian sekarang (tanpa tunggu cron) |
| GET    | `/health.php`                   | Cek service + SMTP + DB driver              |


Dashboard: `/dashboard.html`

---

## 0) Notify (satu endpoint + `method`) — disarankan

`POST /api/notify.php`

| `method` | Alias | Hasil email |
|----------|-------|-------------|
| `billing` | `tagihan`, `invoice`, `penagihan` | Penagihan (template yang sudah ada) |
| `payment_confirmation` | `konfirmasi`, `payment_success`, `confirmation` | Konfirmasi pembayaran berhasil |

> `paymentMethod` = bank (Permata/BSI). Jangan tertukar dengan `method`.

### Tagihan

```json
{
  "method": "billing",
  "nama": "Gaza Solahudin Mubarok",
  "email": "fatwaakbar2003@gmail.com",
  "va": "900362125117",
  "orderNumber": "DAYCARE02/262701-0006",
  "billType": "CLOSE",
  "items": [{ "name": "SPP DAYCARE JULI 2026", "amount": 247500 }]
}
```

### Konfirmasi pembayaran berhasil

```json
{
  "method": "payment_confirmation",
  "nama": "IBRAHIM ARFA GUNAWAN",
  "email": "ortu@gmail.com",
  "orderNumber": "QUBAP-DFU-262701-221",
  "paymentAmount": 2708000,
  "billAmount": 10028000,
  "outstandingAmount": 7320000,
  "referenceNumber": "1787702013506227",
  "paymentMethod": "Permata",
  "paidAt": "2026-08-26T06:53:44+07:00",
  "items": [{ "name": "Daftar Ulang Tahun 2026/2027", "amount": 10028000 }]
}
```

Teks email konfirmasi:
- *Thank You! Your payment has been received*
- *Great news, Your payment is successful! Below are the summary of Your purchase :*

Endpoint lama tetap bisa dipakai. Field `method` di payload **selalu dihormati**, meskipun URL-nya `/api/billing.php` atau `/api/payment-confirmation.php`.

Contoh: kirim `"method":"payment_confirmation"` ke `/api/billing.php` tetap akan membuat tipe **PAYMENT_CONFIRMATION** (bukan tagihan).

---

## 1) Antri Email Penagihan

`POST /api/billing.php`  
(atau `POST /api/notify.php` + `"method":"billing"`)

Menyimpan pesan tipe `BILLING` status `PENDING`. Pengiriman dilakukan cron tiap 10 menit (atau `process-now.php`).

### Field wajib


| Field   | Alias                 | Tipe   | Keterangan      |
| ------- | --------------------- | ------ | --------------- |
| `nama`  | `studentName`, `name` | string | Nama siswa      |
| `email` | `recipientEmail`      | string | Email ortu/wali |


### Field opsional


| Field               | Alias                           | Tipe   | Keterangan                                              |
| ------------------- | ------------------------------- | ------ | ------------------------------------------------------- |
| `nis`               | `studentNis`                    | string | NIS                                                     |
| `kelas`             | `studentClass`                  | string | Kelas                                                   |
| `va`                | `vaNumber`, `nomorVa`           | string | Nomor Virtual Account                                   |
| `orderNumber`       | `invoiceNumber`, `noInvoice`    | string | No. invoice                                             |
| `paymentLink`       | `linkPembayaran`                | string | Link bayar (default dari `.env` `DEFAULT_PAYMENT_LINK`) |
| `paymentGuide`      | `tataCara`                      | string | Panduan pembayaran                                      |
| `subject`           | —                               | string | Subject email (auto jika kosong)                        |
| `outstandingAmount` | `sisaCicilan`                   | number | Sisa cicilan Open VA                                    |
| `totalAmount`       | —                               | number | Total (auto = jumlah item jika kosong)                  |
| `items`             | `tagihan`                       | array  | List item tagihan                                       |
| `billType`          | `tipeTagihan`, `jenisTagihan`   | string | Default `CLOSE`                                         |
| `invoiceDate`       | `tanggalDibuat`, `createdDate`  | string | Tanggal dibuat                                          |
| `activeDate`        | `tanggalAktif`                  | string | Tanggal aktif                                           |
| `expireDate`        | `tanggalBerakhir`, `kadaluarsa` | string | Tanggal berakhir                                        |
| `adminFee`          | `biayaAdmin`                    | number | Biaya admin VA                                          |
| `adminFeeLabel`     | `labelAdmin`                    | string | Label admin (default `Admin VA`)                        |
| `organizationName`  | `yayasan`, `organisasi`         | string | Nama institusi di email                                 |
| `recipientName`     | `namaWali`                      | string | Nama penerima (default = nama siswa)                    |


### Format `items[]`


| Field    | Alias               | Tipe   |
| -------- | ------------------- | ------ |
| `name`   | `nama`, `deskripsi` | string |
| `amount` | `nominal`, `harga`  | number |


### Contoh single

```http
POST /api/billing.php
Content-Type: application/json
```

```json
{
  "nama": "Gaza Solahudin Mubarok",
  "nis": "2026001",
  "kelas": "Daycare A",
  "email": "ortu@gmail.com",
  "va": "900362125117",
  "orderNumber": "DAYCARE02/262701-0006",
  "billType": "CLOSE",
  "invoiceDate": "09 July 2026",
  "activeDate": "09 July 2026",
  "expireDate": "15 July 2026",
  "adminFee": 2500,
  "adminFeeLabel": "Admin VA BSI",
  "paymentLink": "https://sdit-gemanurani.smartpayment.co.id",
  "items": [
    { "name": "SPP DAYCARE JULI 2026", "amount": 247500 },
    { "name": "Ekskul Renang", "amount": 150000 }
  ],
  "paymentGuide": "Via ATM BSI (Tipe Close Payment)"
}
```

### Contoh Open VA (cicilan)

```json
{
  "nama": "Siswa B",
  "email": "b@mail.com",
  "kelas": "1B",
  "va": "9002",
  "billType": "OPEN",
  "outstandingAmount": 7320000,
  "items": [
    { "name": "Uang Pangkal", "amount": 2708000 }
  ]
}
```

### Contoh bulk

```json
{
  "messages": [
    {
      "nama": "Siswa A",
      "email": "a@mail.com",
      "kelas": "1A",
      "items": [{ "name": "SPP", "amount": 500000 }]
    },
    {
      "nama": "Siswa B",
      "email": "b@mail.com",
      "kelas": "1B",
      "va": "9001",
      "items": [
        { "name": "SPP", "amount": 500000 },
        { "name": "Ekskul Futsal", "amount": 75000 }
      ]
    }
  ]
}
```

Boleh juga kirim **array langsung** `[ {...}, {...} ]`.

### Response sukses `201`

```json
{
  "ok": true,
  "queued": 1,
  "failed": 0,
  "data": [ { "id": "...", "status": "PENDING", "type": "BILLING", "...": "..." } ],
  "errors": []
}
```

---

## 2) Antri Konfirmasi Pembayaran

`POST /api/payment-confirmation.php`

### Field wajib


| Field           | Alias                 | Tipe   |
| --------------- | --------------------- | ------ |
| `nama`          | `studentName`, `name` | string |
| `email`         | `recipientEmail`      | string |
| `paymentAmount` | `jumlahBayar`         | number |


### Field opsional


| Field               | Alias           | Tipe            | Keterangan               |
| ------------------- | --------------- | --------------- | ------------------------ |
| `nis`               | `studentNis`    | string          |                          |
| `kelas`             | `studentClass`  | string          |                          |
| `orderNumber`       | `invoiceNumber` | string          |                          |
| `billAmount`        | `totalTagihan`  | number          | Total tagihan awal       |
| `outstandingAmount` | `sisaCicilan`   | number          | Sisa setelah bayar       |
| `referenceNumber`   | `refNumber`     | string          |                          |
| `paymentMethod`     | `metode`        | string          | Contoh: `Permata`, `BSI` |
| `paidAt`            | —               | string datetime | Default: sekarang        |
| `va`                | `vaNumber`      | string          |                          |
| `items`             | `tagihan`       | array           | Item yang dibayar        |
| `subject`           | —               | string          |                          |


### Contoh

```json
{
  "nama": "IBRAHIM ARFA GUNAWAN",
  "email": "ortu@gmail.com",
  "kelas": "TK B",
  "nis": "2025123",
  "orderNumber": "QUBAP-DFU-262701-221",
  "paymentAmount": 2708000,
  "billAmount": 10028000,
  "outstandingAmount": 7320000,
  "referenceNumber": "1787702013506227",
  "paymentMethod": "Permata",
  "paidAt": "2026-08-26T06:53:44+07:00",
  "items": [
    { "name": "Daftar Ulang Tahun 2026/2027", "amount": 10028000 }
  ]
}
```

### Response sukses `201`

```json
{
  "ok": true,
  "queued": 1,
  "data": { "id": "...", "type": "PAYMENT_CONFIRMATION", "status": "PENDING" }
}
```

---

## 3) List Pesan

`GET /api/messages.php`

### Query params


| Param    | Keterangan                                |
| -------- | ----------------------------------------- |
| `status` | `PENDING` / `SENDING` / `SENT` / `FAILED` |
| `type`   | `BILLING` / `PAYMENT_CONFIRMATION`        |
| `q`      | Cari nama / email / NIS / invoice         |
| `limit`  | Default 50, max 200                       |
| `page`   | Default 1                                 |


### Contoh

```http
GET /api/messages.php?status=SENT&limit=20&q=Gaza
```

### Response

```json
{
  "ok": true,
  "summary": { "PENDING": 0, "SENDING": 0, "SENT": 5, "FAILED": 0 },
  "total": 5,
  "page": 1,
  "limit": 20,
  "data": [ /* array pesan */ ]
}
```

---

## 4) Detail Pesan

`GET /api/message.php?id={id}`

### Contoh

```http
GET /api/message.php?id=fe72340b381ac5927edcf6c90c1a7f19
```

### Response

```json
{
  "ok": true,
  "data": {
    "id": "...",
    "type": "BILLING",
    "status": "SENT",
    "studentName": "...",
    "recipientEmail": "...",
    "vaNumber": "...",
    "itemsJson": "[{\"name\":\"SPP\",\"amount\":247500}]",
    "meta": {
      "billType": "CLOSE",
      "invoiceDate": "09 July 2026",
      "adminFee": 2500
    },
    "totalAmount": 247500,
    "paymentLink": "https://sdit-gemanurani.smartpayment.co.id"
  }
}
```

---

## 5) Retry Pesan Gagal

`POST /api/retry.php?id={id}`

Mengubah status `FAILED` → `PENDING` agar dikirim ulang oleh cron.

```http
POST /api/retry.php?id=fe72340b381ac5927edcf6c90c1a7f19
```

---

## 6) Proses Antrian Sekarang

`POST /api/process-now.php`

Memproses batch `PENDING` segera (tanpa tunggu cron 10 menit), tetap menghormati kuota SMTP.

```http
POST /api/process-now.php
```

### Response

```json
{
  "ok": true,
  "skipped": false,
  "processed": 3,
  "sent": 3,
  "failed": 0
}
```

Jika SMTP belum dikonfigurasi:

```json
{
  "ok": true,
  "skipped": true,
  "reason": "SMTP not configured",
  "processed": 0,
  "sent": 0,
  "failed": 0
}
```

---

## 7) Health Check

`GET /health.php`

```json
{
  "ok": true,
  "smtpConfigured": true,
  "smtpAccounts": 3,
  "smtpQuota": {
    "perAccount": 20,
    "windowSeconds": 600,
    "accounts": [
      { "id": "1", "user": "akun1@gmail.com", "from": "akun1@gmail.com", "remaining": 20, "used": 0 }
    ]
  },
  "dbDriver": "mysql",
  "dbHost": "localhost",
  "dbName": "email_broadcaster",
  "cron": "*/10 * * * * (via cron/send_emails.php)",
  "batchSize": 20,
  "singleAccountMax": 20
}
```

---

## Status Pesan


| Status    | Arti                  |
| --------- | --------------------- |
| `PENDING` | Menunggu dikirim      |
| `SENDING` | Sedang diproses       |
| `SENT`    | Berhasil terkirim     |
| `FAILED`  | Gagal (bisa di-retry) |


Urutan kirim: **FIFO** (`created_at` paling lama dulu).

Rate limit multi-SMTP:

- Pending ≤20 → 1 akun (acak)
- Pending >20 → sampai 3 akun
- Max `SMTP_QUOTA_PER_ACCOUNT` (default 20) per akun per jendela 10 menit → hingga ~60 email / 10 menit

Tiap siklus cron/proses: maksimal `EMAIL_BATCH_SIZE` per akun (default 20).

---

## Contoh cURL

### Penagihan

```bash
curl -X POST "http://101.50.2.56/emailBroadcaster/api/billing.php" \
  -H "Content-Type: application/json" \
  -d '{
    "nama": "Tes Siswa",
    "email": "ortu@gmail.com",
    "kelas": "1A",
    "va": "900362125117",
    "items": [
      {"name": "SPP Juli", "amount": 247500}
    ]
  }'
```

### Konfirmasi pembayaran

```bash
curl -X POST "http://101.50.2.56/emailBroadcaster/api/payment-confirmation.php" \
  -H "Content-Type: application/json" \
  -d '{
    "nama": "Tes Siswa",
    "email": "ortu@gmail.com",
    "paymentAmount": 100000,
    "billAmount": 500000,
    "outstandingAmount": 400000,
    "items": [{"name": "Uang Pangkal", "amount": 500000}]
  }'
```

### Dengan token

```bash
curl -X POST "http://101.50.2.56/emailBroadcaster/api/billing.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -d '{"nama":"A","email":"a@mail.com","items":[{"name":"SPP","amount":1}]}'
```

---

## Error umum


| HTTP | Contoh                                     | Arti                                           |
| ---- | ------------------------------------------ | ---------------------------------------------- |
| 400  | `Field wajib: studentName, recipientEmail` | Body kurang field                              |
| 401  | `Unauthorized`                             | Token salah / kosong padahal `API_TOKEN` aktif |
| 404  | `Not found`                                | ID pesan tidak ada                             |
| 500  | pesan DB/SMTP                              | Cek `.env`, MySQL, atau SMTP                   |


---

## Alur integrasi (sistem billing / Excel)

1. Parser Excel / sistem lain memanggil `POST /api/billing.php` (bulk `messages`)
2. Data masuk DB status `PENDING`
3. Cron tiap 10 menit mengirim email (`cron/send_emails.php`) dengan kuota multi-SMTP
4. Pantau di dashboard atau `GET /api/messages.php`
5. Saat pembayaran sukses → `POST /api/payment-confirmation.php`

