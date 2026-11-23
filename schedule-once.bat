@echo off
cd /d "%~dp0"
C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe artisan schedule:run
