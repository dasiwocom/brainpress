# Brainpress deployment

This ready-to-upload package includes production PHP dependencies and built browser assets. No Node.js or Composer is required on the server.

1. Install PHP 8.2+ with PDO, pdo_sqlite (or pdo_mysql), mbstring, fileinfo and a PHP web server integration.
2. Extract the complete package into /var/www/brainpress. Set the website document root to /var/www/brainpress/public. Never expose the project root.
3. Grant the PHP process write permission to storage/ and public/uploads/. Keep other source files read-only. Do not use chmod 777.
4. SQLite works by default. For MySQL, create an empty utf8mb4 database and copy config.example.php to config.php, then enter its credentials. Tables are created on first request.
5. Nginx: adapt nginx.conf, especially domain, path and PHP-FPM socket. Apache: enable AllowOverride for the supplied .htaccess files. Configure HTTPS through your server panel.
6. Open /setup. Read storage/setup-token.txt through the server file manager, then enter the token and choose your administrator credentials. Sign in and create a category, save a draft, publish and test image upload.

The package starts with no personal articles, uploads, configuration or database. Existing local SQLite articles are not automatically migrated to MySQL. Copy your database and uploads separately only when deliberately moving your content.

Only signed-in users can publish or upload; only administrators can access /admin and site management actions. Public registration creates member accounts. Members can edit their own posts and select their own media; published image URLs remain public. Configure HTTPS before using real credentials.

Use the PHP built-in server only for a local smoke test: php -S 127.0.0.1:8081 -t public public/router.php.

After setup, open /admin to manage posts, images, categories, tags, site settings and the shared header menu. Tags are under Article management; Header menu and Mobile tabs are under System management. Accounts are managed under Users.

## Upgrading an existing installation

Keep config.php, storage/ and public/uploads/ when replacing source files. New account/ownership tables and columns are added automatically. After the first upgrade to accounts, visit /setup once and use the server token to create the administrator; existing posts and indexed media are assigned to this administrator. A clean package contains no accounts, setup tokens or session secrets. No password is bundled.
