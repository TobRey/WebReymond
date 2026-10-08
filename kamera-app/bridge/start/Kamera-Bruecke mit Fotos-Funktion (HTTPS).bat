@echo off
chcp 65001 >nul
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel%==0 (
  py -3 kamera-bruecke.py --https %*
) else (
  python kamera-bruecke.py --https %*
)
if errorlevel 9009 echo Python 3 fehlt: https://www.python.org/downloads/ installieren (Haken bei "Add to PATH").
pause
