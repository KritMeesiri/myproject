@echo off
"C:\MAMP\bin\php\php8.3.1\php.exe" "C:\MAMP\htdocs\project\cron\release_expired_holds.php" >> "C:\MAMP\htdocs\project\cron\cron_holds.log" 2>&1
"C:\MAMP\bin\php\php8.3.1\php.exe" "C:\MAMP\htdocs\project\cron\reset_tables_hourly.php" >> "C:\MAMP\htdocs\project\cron\cron_holds.log" 2>&1
"C:\MAMP\bin\php\php8.3.1\php.exe" "C:\MAMP\htdocs\project\cron\send_deadline_warnings.php" >> "C:\MAMP\htdocs\project\cron\cron_holds.log" 2>&1
