@echo off
REM Copyright (c) 2026:
REM vatofichor - Sebastian Mass       [>_<]
REM and Assisted By Gemini Antigravity /|\

setlocal

set SCRIPT_DIR=%~dp0
cd /d "%SCRIPT_DIR%"

echo ========================================================
echo  vat-harvester Simplex GUI Dashboard
echo ========================================================
echo  Binding server to http://127.0.0.1:8091 ...
echo  Press Ctrl+C in this console to stop the server.
echo ========================================================

start "" http://127.0.0.1:8091

php -S 127.0.0.1:8091 -t "%SCRIPT_DIR%gui" "%SCRIPT_DIR%gui\index.php"

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo [ERROR] Web server terminated with error code %ERRORLEVEL%.
)

pause
