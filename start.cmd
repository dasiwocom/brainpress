@echo off
cd /d "%~dp0"
if exist ".runtime\php\php.exe" (
  ".runtime\php\php.exe" -S 127.0.0.1:8081 -t public public/router.php
) else (
  php -S 127.0.0.1:8081 -t public public/router.php
)
pause
