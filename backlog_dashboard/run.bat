@echo off
setlocal
cd /d "%~dp0.."
py -m pip install -r backlog_dashboard\requirements.txt
if errorlevel 1 exit /b 1
py backlog_dashboard\app.py
