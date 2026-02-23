@echo off
echo Reimporting Railway Database...
echo.

cd /d C:\xampp\mysql\bin

echo Dropping existing database...
mysql -u root -e "DROP DATABASE IF EXISTS railway_system;"

echo Creating and importing new database...
mysql -u root < "C:\xampp\htdocs\railway\database\railway_schema.sql"

echo.
echo Database reimport complete!
echo.
echo You can now login with:
echo Username: admin
echo Password: password123
echo.
pause
