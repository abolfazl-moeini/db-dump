# Database Exporter

A single-file PHP web app for exporting selected MySQL/MariaDB tables to SQL or GZIP, managing backup files, and copying tables to another database. Exports run in chunks, making the tool suitable for shared hosting.

## Quick start

Requirements: PHP 7.4+ with `mysqli`, `zlib` (for GZIP), a MySQL/MariaDB database, and a writable web directory.

1. Put `db-dump.php` in a private, HTTPS-enabled web directory.
2. Remove the `putenv('DB_EXPORT_PASSWORD_HASH=');` line near the top of the file so it does not erase your configured password hash.
3. Generate a login password hash:

   ```bash
   php -r 'echo password_hash("choose-a-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
   ```

4. Configure your web server/PHP environment:

   ```env
   DB_EXPORT_PASSWORD_HASH=<generated-hash>
   DB_EXPORT_TOKEN=<long-random-token>
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=your_database
   DB_USER=your_user
   DB_PASS=your_password
   ```

   WordPress-style `WORDPRESS_DB_HOST`, `WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER`, and `WORDPRESS_DB_PASSWORD` variables are also supported.

5. Open `db-dump.php` in your browser, sign in, select tables and compression, then click **Start Export**. Backups are stored in `db_exports/`.

Optional destination variables for database copying: `DEST_DB_HOST`, `DEST_DB_PORT`, `DEST_DB_NAME`, `DEST_DB_USER`, and `DEST_DB_PASS`.

> **Security:** Change the default token, never commit credentials or generated `db_exports/` files, use HTTPS, and restrict access by IP or additional web-server authentication when possible.
