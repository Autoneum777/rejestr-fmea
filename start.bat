@echo off
cd /d "%~dp0"
"C:\php-8.5.11\php.exe" -c "%~dp0php.ini" dev.php
pause
