@echo off
setlocal
cd /d "%~dp0"

if not exist "vendor\autoload.php" (
    echo Laravel dependencies are missing. Run: composer install
    exit /b 1
)

php artisan config:clear
if errorlevel 1 exit /b 1
php artisan migrate
if errorlevel 1 (
    echo Migration failed. Check that MySQL is running and the database credentials in .env are correct.
    exit /b 1
)

echo Database migrations completed successfully.
