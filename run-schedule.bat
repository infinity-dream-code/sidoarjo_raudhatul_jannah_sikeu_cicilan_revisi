@echo off
cd /d "%~dp0"
echo SIKEU schedule runner - jangan tutup jendela ini
echo payment-email:dispatch jalan tiap ~5 menit
echo.

:loop
C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe artisan schedule:run
timeout /t 60 /nobreak >nul
goto loop
