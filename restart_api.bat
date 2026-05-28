@echo off
REM Script khởi động lại Python API (uvicorn)
REM Chạy file này khi cần restart API sau khi update code

echo Dang dung tat ca process tren port 8000...
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8000.*LISTENING"') do (
    echo Killing PID %%a
    taskkill /PID %%a /F 2>nul
)

timeout /t 2 /nobreak >nul

echo Dang khoi dong Python API (uvicorn)...
cd /d %~dp0\python_api

set PYTHONUTF8=1

REM Tu dong phat hien va kich hoat moi truong ao (venv hoac conda)
if exist "%~dp0\.venv\Scripts\activate.bat" (
    echo [INFO] Tu dong kich hoat moi truong ao .venv...
    start "Python API - uvicorn" /min cmd /c "call ..\.venv\Scripts\activate && uvicorn main:app --host 127.0.0.1 --port 8000 --log-level info > ..\logs\uvicorn.log 2>&1"
) else (
    echo [INFO] Khong thay .venv, dang thu kich hoat Conda amdgt_env...
    start "Python API - uvicorn" /min cmd /c "call conda activate amdgt_env && uvicorn main:app --host 127.0.0.1 --port 8000 --log-level info > ..\logs\uvicorn.log 2>&1"
)

timeout /t 4 /nobreak >nul

echo Kiem tra port 8000...
netstat -ano | findstr ":8000.*LISTENING"
if errorlevel 1 (
    echo [LOI] Uvicorn khong start duoc! Kiem tra logs\uvicorn.log
) else (
    echo [OK] API dang chay tai http://127.0.0.1:8000
)
pause
