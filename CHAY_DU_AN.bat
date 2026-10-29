@echo off
title BTL PTPMMNM - Cong Cu Sao Luu & Khoi Phuc Phien Ban
echo ===================================================================
echo     DANG KHOI DONG CONG CU SAO LUU TANG DAN & KHOI PHUC DU LIEU
echo     Cong nghe: PHP 8.2 & MariaDB / MySQL (XAMPP Server)
echo ===================================================================

echo Dang kiem tra Apache (Port 8080)...
netstat -ano | findstr 8080 >nul 2>nul
if %errorlevel% neq 0 (
    echo Dang bat Apache...
    start "" "C:\Users\Lapto\Desktop\xampp\apache_start.bat"
    timeout /t 2 >nul
)

echo Dang kiem tra MySQL (Port 3306)...
netstat -ano | findstr 3306 >nul 2>nul
if %errorlevel% neq 0 (
    echo Dang bat MySQL...
    start "" "C:\Users\Lapto\Desktop\xampp\mysql_start.bat"
    timeout /t 2 >nul
)

echo Dang mo trinh duyet...
start "" "http://localhost:8080/btl/"

echo.
echo ===================================================================
echo  [THANH CONG] Ung dung da duoc mo tren trinh duyet!
echo.
echo  Trang chu (Dashboard):   http://localhost:8080/btl/
echo  Sao luu (Backup):        http://localhost:8080/btl/backup.php
echo  Phuc hoi (Restore):      http://localhost:8080/btl/restore.php
echo  Phien ban (Versions):    http://localhost:8080/btl/versions.php
echo  Kiem thu (Test Suite):   http://localhost:8080/btl/test_suite.php
echo  phpMyAdmin:              http://localhost:8080/phpmyadmin/
echo ===================================================================
echo.
pause

