@echo off
setlocal
cd /d "%~dp0"
title Quarter Clown

where py >nul 2>nul && (set "PY=py -3") || (set "PY=python")

if not exist ".venv\Scripts\python.exe" (
  echo [Quarter Clown] Erstelle virtuelle Umgebung ...
  %PY% -m venv .venv || goto :nopython
)
if not exist ".venv\.installed" (
  echo [Quarter Clown] Installiere Abhaengigkeiten ...
  ".venv\Scripts\python.exe" -m pip install --upgrade pip >nul
  ".venv\Scripts\python.exe" -m pip install -r requirements.txt || goto :fail
  echo ok> ".venv\.installed"
)
if not exist ".env" (
  copy ".env.example" ".env" >nul
  echo [Quarter Clown] .env angelegt - bitte DISCORD_BOT_TOKEN und optional ANTHROPIC_API_KEY eintragen.
  notepad ".env"
)
".venv\Scripts\python.exe" -m quarter_clown %*
goto :eof

:nopython
echo Python 3.11+ nicht gefunden. Installiere es von https://www.python.org/downloads/ (Haken bei "Add to PATH").
pause
goto :eof
:fail
echo Installation fehlgeschlagen.
pause
