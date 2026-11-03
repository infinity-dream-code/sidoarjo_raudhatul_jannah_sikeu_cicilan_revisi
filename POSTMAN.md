# Dokumentasi Postman — Email Broadcaster

## Import collection

1. Buka **Postman**
2. Klik **Import**
3. Pilih file: `docs/EmailBroadcaster.postman_collection.json`
4. Collection **Email Broadcaster API** akan muncul

## Set variabel collection

Di collection → **Variables**:

| Variable | Contoh | Keterangan |
|----------|--------|------------|
| `baseUrl` | `http://101.50.2.56/emailBroadcaster` | URL root app |
| `apiToken` | *(kosongkan jika API_TOKEN kosong)* | Token Bearer opsional |
| `messageId` | id dari response | Untuk detail / retry |

Jika `API_TOKEN` di `.env` diisi, isi `apiToken` di Postman. Collection sudah pakai **Bearer Token** `{{apiToken}}`.

Header body JSON:

```http
Content-Type: application/json
Authorization: Bearer {{apiToken}}   # hanya jika API_TOKEN diisi
```

---

## Folder request

### 1) Health
- `GET Health` → cek SMTP & DB

### 2) Notify (disarankan)
Semua lewat `POST {{baseUrl}}/api/notify.php` + field **`method`**:

| Request | `method` | Fungsi |
|---------|----------|--------|
| Billing CLOSE | `billing` | Tagihan close |
| Billing OPEN | `billing` + `outstandingAmount` | Tagihan cicilan |
| Billing Bulk | `billing` + `messages[]` | Banyak siswa |
| Payment Confirmation | `payment_confirmation` | Notifikasi bayar sukses |

### 3) Endpoint Lama
- `billing.php` / `payment-confirmation.php` tetap ada
- Field `method` di body **tetap dihormati**

### 4) Messages
- List, filter `PAYMENT_CONFIRMATION`, detail, retry, process-now

---

## Field `method` (wajib di `/api/notify.php`)

| Nilai utama | Alias yang diterima | Hasil `type` di DB/email |
|-------------|---------------------|---------------------------|
| `billing` | `tagihan`, `penagihan`, `bill` | `BILLING` |
| `payment_confirmation` | `konfirmasi`, `payment`, `paymentConfirmation` | `PAYMENT_CONFIRMATION` |

> Jangan tertukar: **`method`** = jenis notifikasi. **`paymentMethod`** = nama bank (Permata/BSI).

---

## A) Payload Billing (`method: "billing"`)

Endpoint: `POST {{baseUrl}}/api/notify.php`  
(atau `POST {{baseUrl}}/api/billing.php`)

### Field wajib

| Field | Alias | Tipe | Keterangan |
|-------|-------|------|------------|
| `method` | `jenis` | string | Isi `billing` (wajib di notify.php) |
| `nama` | `studentName`, `name` | string | Nama siswa |
| `email` | `recipientEmail` | string | Email ortu/wali |

### Field opsional — data siswa & invoice

| Field | Alias | Tipe | Default / catatan |
|-------|-------|------|-------------------|
| `nis` | `studentNis` | string | NIS |
| `kelas` | `studentClass` | string | Kelas |
| `recipientName` | `namaWali` | string | Nama penerima email (default = `nama`) |
| `va` | `vaNumber`, `nomorVa` | string | Nomor Virtual Account |
| `orderNumber` | `invoiceNumber`, `noInvoice` | string | No. invoice / order |
| `subject` | — | string | Subject email (auto: `Tagihan {nama} - {kelas} #{orderNumber}`) |
| `organizationName` | `yayasan`, `organisasi` | string | Nama institusi di email (default dari `.env`) |

### Field opsional — tipe & tanggal tagihan

| Field | Alias | Tipe | Default / catatan |
|-------|-------|------|-------------------|
| `billType` | `tipeTagihan`, `jenisTagihan` | string | `CLOSE` (default) atau `OPEN` |
| `invoiceDate` | `tanggalDibuat`, `createdDate` | string | Tanggal dibuat, contoh `09 July 2026` |
| `activeDate` | `tanggalAktif` | string | Tanggal VA aktif |
| `expireDate` | `tanggalBerakhir`, `kadaluarsa` | string | Tanggal berakhir |

### Field opsional — nominal & pembayaran

| Field | Alias | Tipe | Default / catatan |
|-------|-------|------|-------------------|
| `items` | `tagihan` | array | List item tagihan |
| `totalAmount` | — | number | Auto = jumlah `items[].amount` jika kosong |
| `outstandingAmount` | `sisaCicilan` | number | Sisa cicilan (untuk `billType: OPEN`) |
| `adminFee` | `biayaAdmin` | number | Biaya admin VA |
| `adminFeeLabel` | `labelAdmin` | string | Default `Admin VA` |
| `paymentLink` | `linkPembayaran` | string | Default dari `.env` `DEFAULT_PAYMENT_LINK` |
| `paymentGuide` | `tataCara` | string | Panduan bayar di email |

### Format `items[]` / `tagihan[]`

| Field | Alias | Tipe | Keterangan |
|-------|-------|------|------------|
| `name` | `nama`, `deskripsi` | string | Nama item |
| `amount` | `nominal`, `harga` | number | Nominal (rupiah, tanpa titik) |

### Bulk (banyak siswa sekaligus)

| Field | Tipe | Keterangan |
|-------|------|------------|
| `method` | string | `billing` |
| `messages` | array | Array objek billing (field sama seperti single, tanpa `method` per item) |

Boleh juga kirim **array langsung** di root body: `[ {...}, {...} ]`.

### Contoh lengkap — Billing CLOSE

```json
{
  "method": "billing",
  "nama": "Gaza Solahudin Mubarok",
  "email": "fatwaakbar2003@gmail.com",
  "recipientName": "Orang Tua Gaza",
  "nis": "2026001",
  "kelas": "Daycare A",
  "va": "900362125117",
  "orderNumber": "DAYCARE02/262701-0006",
  "billType": "CLOSE",
  "invoiceDate": "09 July 2026",
  "activeDate": "09 July 2026",
  "expireDate": "15 July 2026",
  "adminFee": 2500,
  "adminFeeLabel": "Admin VA BSI",
  "totalAmount": 802500,
  "paymentLink": "https://sdit-gemanurani.smartpayment.co.id",
  "paymentGuide": "Via ATM BSI (Tipe Close Payment)",
  "organizationName": "Yayasan ICT",
  "subject": "Tagihan Gaza Solahudin Mubarok - Daycare A",
  "items": [
    { "name": "SPP DAYCARE JULI 2026", "amount": 247500 },
    { "name": "SPP DAYCARE AGUSTUS 2026", "amount": 207500 },
    { "name": "SPP DAYCARE MARET 2026", "amount": 347500 }
  ]
}
```

### Contoh lengkap — Billing OPEN (cicilan)

```json
{
  "method": "billing",
  "nama": "Gaza Solahudin Mubarok",
  "email": "fatwaakbar2003@gmail.com",
  "kelas": "Daycare A",
  "nis": "2026001",
  "va": "900362125117",
  "orderNumber": "DAYCARE02/262701-0006",
  "billType": "OPEN",
  "invoiceDate": "09 July 2026",
  "activeDate": "09 July 2026",
  "expireDate": "15 July 2026",
  "outstandingAmount": 555000,
  "adminFee": 2500,
  "adminFeeLabel": "Admin VA BSI",
  "items": [
    { "name": "SPP DAYCARE JULI 2026", "amount": 247500 },
    { "name": "SPP DAYCARE AGUSTUS 2026", "amount": 207500 },
    { "name": "SPP DAYCARE MARET 2026", "amount": 347500 }
  ],
  "paymentGuide": "Via ATM BSI (Tipe Open Payment / Cicilan)"
}
```

### Contoh — Billing Bulk

```json
{
  "method": "billing",
  "messages": [
    {
      "nama": "Siswa A",
      "email": "a@mail.com",
      "kelas": "1A",
      "nis": "2026001",
      "va": "9001",
      "billType": "CLOSE",
      "items": [{ "name": "SPP", "amount": 500000 }]
    },
    {
      "nama": "Siswa B",
      "email": "b@mail.com",
      "kelas": "1B",
      "va": "9002",
      "billType": "OPEN",
      "outstandingAmount": 250000,
      "items": [
        { "name": "SPP", "amount": 500000 },
        { "name": "Ekskul Futsal", "amount": 75000 }
      ]
    }
  ]
}
```

### Response sukses `201`

```json
{
  "ok": true,
  "method": "billing",
  "queued": 1,
  "failed": 0,
  "data": [{ "id": "...", "status": "PENDING", "type": "BILLING" }],
  "errors": []
}
```

---

## B) Payload Payment Confirmation (`method: "payment_confirmation"`)

Endpoint: `POST {{baseUrl}}/api/notify.php`  
(atau `POST {{baseUrl}}/api/payment-confirmation.php`)

### Field wajib

| Field | Alias | Tipe | Keterangan |
|-------|-------|------|------------|
| `method` | `jenis` | string | Isi `payment_confirmation` (wajib di notify.php) |
| `nama` | `studentName`, `name` | string | Nama siswa |
| `email` | `recipientEmail` | string | Email ortu/wali |
| `paymentAmount` | `jumlahBayar` | number | Jumlah yang dibayar |

### Field opsional

| Field | Alias | Tipe | Default / catatan |
|-------|-------|------|-------------------|
| `nis` | `studentNis` | string | NIS |
| `kelas` | `studentClass` | string | Kelas |
| `recipientName` | `namaWali` | string | Nama penerima (default = `nama`) |
| `va` | `vaNumber` | string | Nomor VA |
| `orderNumber` | `invoiceNumber` | string | No. order / invoice |
| `billAmount` | `totalTagihan` | number | Total tagihan awal (default = sum items, atau = `paymentAmount`) |
| `outstandingAmount` | `sisaCicilan` | number | Sisa setelah bayar |
| `referenceNumber` | `refNumber` | string | No. referensi bank/gateway |
| `paymentMethod` | `metodePembayaran`, `bank`, `metode` | string | Bank/channel, contoh `Permata`, `BSI` |
| `paidAt` | — | string datetime | Waktu bayar, contoh `2026-08-26T06:53:44+07:00` (default: sekarang) |
| `items` | `tagihan` | array | Item yang dibayar (format sama billing) |
| `subject` | — | string | Auto: `Confirmation of Receipt at {organizationName} #{orderNumber}` |
| `organizationName` | `yayasan`, `organisasi` | string | Nama institusi di email |

### Format `items[]`

Sama seperti billing:

| Field | Alias | Tipe |
|-------|-------|------|
| `name` | `nama`, `deskripsi` | string |
| `amount` | `nominal`, `harga` | number |

### Contoh lengkap — Payment Confirmation

```json
{
  "method": "payment_confirmation",
  "nama": "IBRAHIM ARFA GUNAWAN",
  "email": "fatwaakbar2003@gmail.com",
  "recipientName": "Orang Tua Ibrahim",
  "nis": "2025123",
  "kelas": "TK B",
  "va": "900362199999",
  "orderNumber": "QUBAP-DFU-262701-221",
  "paymentAmount": 2708000,
  "billAmount": 10028000,
  "outstandingAmount": 7320000,
  "referenceNumber": "1787702013506227",
  "paymentMethod": "Permata",
  "paidAt": "2026-08-26T06:53:44+07:00",
  "organizationName": "Yayasan ICT",
  "subject": "Confirmation of Receipt at Yayasan ICT #QUBAP-DFU-262701-221",
  "items": [
    { "name": "Daftar Ulang Tahun 2026/2027", "amount": 10028000 }
  ]
}
```

### Response sukses `201`

```json
{
  "ok": true,
  "method": "payment_confirmation",
  "data": {
    "id": "...",
    "status": "PENDING",
    "type": "PAYMENT_CONFIRMATION"
  }
}
```

---

## C) Query Messages (bukan body JSON)

| Request | Method | Path / Query |
|---------|--------|--------------|
| List | GET | `/api/messages.php?page=1&limit=50` |
| Filter status | GET | `...&status=PENDING` / `SENT` / `FAILED` |
| Filter type | GET | `...&type=BILLING` / `PAYMENT_CONFIRMATION` |
| Search | GET | `...&q=nama-atau-email` |
| Detail | GET | `/api/message.php?id={{messageId}}` |
| Retry | POST | `/api/retry.php?id={{messageId}}` |
| Process Now | POST | `/api/process-now.php` |

---

## Cara tes cepat (urutan)

1. **GET Health** → `"smtpConfigured": true`
2. **Notify → Billing CLOSE** → `201`, `type: BILLING`, `status: PENDING`
3. **Notify → Payment Confirmation** → `type: PAYMENT_CONFIRMATION` (**bukan** BILLING)
4. **Process Now** → kirim email segera
5. Dashboard / **List Messages** → status `SENT`

## Checklist response

| Request | `type` yang benar |
|---------|-------------------|
| `method: billing` | `BILLING` |
| `method: payment_confirmation` | `PAYMENT_CONFIRMATION` |

Kalau konfirmasi malah jadi `BILLING`, pastikan file server sudah terbaru (`api/billing.php` + `NotifyController.php`).

## Dokumentasi API lengkap

Lihat juga: [API.md](./API.md)
