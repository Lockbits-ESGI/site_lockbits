LOCKBITS CLIENT AREA SETUP (XAMPP + MySQL)

1) Create database and tables
   - Open phpMyAdmin
   - Import file: /lockbits/client/database.sql

2) Check DB credentials
   - Open: /lockbits/client/config.php
   - Update DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS if needed

3) Open client area
   - http://localhost/lockbits/client/register.php (create first account)
   - Then login from: http://localhost/lockbits/client/login.php

Notes
   - Passwords are hashed with password_hash()
   - Session is required for dashboard access
   - Logout is available at /lockbits/client/logout.php
