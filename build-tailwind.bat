@echo off
setlocal
cd /d "%~dp0"

where npm >nul 2>nul
if errorlevel 1 (
    echo npm was not found. Install Node.js and npm first.
    exit /b 1
)

if exist "public\hot" del /q "public\hot"

call npm run build
if errorlevel 1 (
    echo Tailwind/Vite production build failed.
    exit /b 1
)

echo Tailwind styles built successfully.
