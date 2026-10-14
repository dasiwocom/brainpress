# Brainpress deployment

This ready-to-upload package includes production PHP dependencies and built browser assets. No Node.js or Composer is required on the server.

1. Install PHP 8.2+ with PDO, pdo_sqlite (or pdo_mysql), mbstring, fileinfo and a PHP web server integration.
2. Extract the complete package into /var/www/brainpress. Set the website document root to /var/www/brainpress/public. Never expose the project root.
3. Grant the PHP process write permission to storage/ and public/uploads/. Keep other source files read-only. Do not use chmod 777.
4. SQLite works by default. For MySQL, create an empty utf8mb4 database and copy config.example.php to config.php, then enter its credentials. Tables are created on first request.
5. Nginx: adapt nginx.conf, especially domain, path and PHP-FPM socket. Apache: enable AllowOverride for the supplied .htaccess files. Configure HTTPS through your server panel.
6. Open the website, create a category, save a draft, publish it, and test image upload.

The package starts with no personal articles, uploads, configuration or database. Existing local SQLite articles are not automatically migrated to MySQL. Copy your database and uploads separately only when deliberately moving your content.

There is no login yet: editing, drafts and upload are available to anyone who can reach this installation. For personal testing on a cloud server, put the entire site behind HTTP Basic Auth or an IP allowlist in your server panel. Draft status filters the feed; it is not an access-control mechanism.

Use the PHP built-in server only for a local smoke test: php -S 127.0.0.1:8081 -t public public/router.php.

After setup, open /admin to manage posts, images, categories, tags, site settings and the shared header menu. This release has no authentication; protect the test site externally.
