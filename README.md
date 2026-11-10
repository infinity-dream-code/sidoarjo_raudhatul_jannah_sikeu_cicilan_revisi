SIKEU NEW

Laravel 10.

pastikan sudah menginstall
1. PHP 8.1^ (dan extensinya)
2. HTTP Web Server (Nginx/Apache)
3. PHP Composer
4. Database Server (Mysql/Postgre/etc)

Cara install

1. clone repositori ini
2. copy file ```.env.example``` menjadi ```.env```
3. Buka file ```.env```
4. Ubah konfigurasi 'APP_NAME' sesuai dengan nama website
   ```
    APP_NAME="NAMA WEBSITE ANDA"
    ```
6. Tutup file ```.env```
7. Buka terminal pada folder project ini
8. Update laravel
    ``` 
    composer update 
    ```
   jika tidak bisa melakukan update
     ``` 
    composer install 
    ```
9. Buat tabel berdasarkan konfigurasi yang ada pada folder ```/database/migrations```
    ``` 
    php artisan migrate:fresh
    ```
   > ⚠️ **Warning:** Jangan jalankan perintah ini ketika program sudah digunakan instansi!

10. tambahkan data pada tabel berdasarkan konfigurasi yang ada pada folder ```/database/seeders```
    ``` 
    php artisan db:seed 
    ```
    > ⚠️ **Warning:** Jangan jalankan perintah ini ketika program sudah digunakan instansi!

11. Buat _application key_
    ``` 
    php artisan key:generate
    ```
12. untuk menjalankan aplikasi, jalankan perintah
    ``` 
    php artiasn serve 
    ``` 

* jika program sudah digunakan, setelah melakukan modifikasi pada file ```.env``` atau file pada folder ``` /config ```,
  jalankan perintah ``` php artisan optimize:clear``` pada terminal.


* untuk melakukan backup database, silahkan install
    ```
    composer install laravel/spatie-backup
    ```
  lalu jalankan perintah
    ```
    php artisan backup:run --only-db --disable-notifications
    ```
  backup disimpan pada folder storage/app/{APP_NAME}

## Email konfirmasi setelah bayar

Notifikasi email ke `EMAIL_ORTU` memakai emailBroadcaster (`PAYMENT_CONFIRMATION`).

Semua perintah di bawah dijalankan di **server SIKEU** (folder project Laravel, via SSH / terminal), **bukan** di PC lokal kecuali testing lokal.

1. Migration tabel dedupe (sekali):
   ```
   cd /path/ke/sikeu
   php artisan migrate
   ```
2. Go-live — tandai pembayaran lama supaya tidak spam email (sekali):
   ```
   php artisan payment-email:dispatch --baseline --days=30
   ```
3. Cron Laravel (wajib agar bayar bank/Android ikut terdeteksi). Edit crontab server:
   ```
   * * * * * php /path/ke/sikeu/artisan schedule:run >> /dev/null 2>&1
   ```
   Ini yang memanggil `payment-email:dispatch` tiap 5 menit (lihat `app/Console/Kernel.php`).

4. Env SIKEU: `EMAIL_PAYMENT_CONFIRMATION_ENABLED`, `EMAIL_PAYMENT_LOOKBACK_DAYS`, `EMAIL_BROADCASTER_URL`

5. Env emailBroadcaster: `PAYMENT_RESERVE_SLOTS=1` — tiap batch kirim menyisakan minimal 1 slot untuk email konfirmasi bayar meskipun antrian tagihan (Excel/kirim email) sedang sibuk.
