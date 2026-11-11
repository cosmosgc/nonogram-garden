@echo off
setlocal
cd /d "%~dp0"

where npm >nul 2>nul
if errorlevel 1 (
    echo npm was not found. Install Node.js and npm first.
    exit /b 1
)

if not exist "node_modules" (
    echo Installing frontend dependencies...
    call npm install
    if errorlevel 1 exit /b 1
)

call npm run build
if errorlevel 1 (
    echo Vite/Tailwind production build failed.
    exit /b 1
)

echo Vite and Tailwind production build completed successfully.
