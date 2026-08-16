# Database Dump & Restore Tool

A single-file PHP tool for **shared hosting**, **cPanel**, **DirectAdmin**, and **WordPress**. It can:

1. **Import / restore** large `.sql`, `.sql.gz`, or `.zip` dumps in short HTTP bursts so shared-host timeouts do not kill the job.
2. **Export** selected tables to `.sql.gz` or `.sql` (plus views, triggers, routines, and events when available).
3. **Copy** tables (and views) from the current database to another MySQL/MariaDB host.
4. **Search & replace** URLs after import, including WordPress serialized data, without breaking string lengths.

Requires **PHP 7.4+** with `mysqli`. GZIP import/export needs `zlib`. ZIP upload needs `zip`.

---

## Security (read this first)

This file can drop tables, rewrite a live database, and download dumps. Treat it as a temporary admin tool.

- On first open it **refuses to run until you set a password** (stored as a hash in `db-dump-auth.php`).
- Optional header auth: set env `DB_EXPORT_TOKEN` and send `X-Auth-Token`. There is no default token.
- Optional hash via env: `DB_EXPORT_PASSWORD_HASH` (from `password_hash('...', PASSWORD_DEFAULT)`).
- Dumps live in `db_exports/`. The tool writes `.htaccess`, `web.config`, and `index.php` deny files. **Nginx does not honor `.htaccess`** — deny `/db_exports/` in the server config, or only upload dumps through the tool and delete them after.
- **Delete `db-dump.php`, `db-dump-auth.php`, and `db_exports/` when you are done.** The Files tab has a button that does this after you type `DELETE`.

Do not leave this script on a public site after the migration.

---

## How to import a localhost dump on the server

### 1. Export the local database

```bash
wp db export dump.sql.gz
```

Or use this tool on localhost (Export Dump tab).

### 2. Upload to the server

1. Upload `db-dump.php` (WordPress root is fine; it will read `wp-config.php`).
2. Open `https://your-domain.com/db-dump.php` and set a password.
3. Choose `dump.sql.gz` in the tool. The browser uploads it in 2 MB chunks so PHP `upload_max_filesize` / `post_max_size` do not block large dumps. You can still FTP a file into `db_exports/` if you prefer.

### 3. Run the import

1. Open the **Import / Restore** tab.
2. Select the dump.
3. Optional domain replace:
   - Old URL: `http://localhost:8080`
   - New URL: `https://your-domain.com`
   - Keep **Safely update WordPress serialized strings** checked so `wp_options` / `wp_postmeta` stay valid.
4. Click **Start Database Import** and leave the tab open until it finishes.

If WordPress replace is checked, SQL is imported unchanged and replace runs afterward in its own chunked phase. That avoids corrupting serialized PHP by doing a naive `str_replace` on the dump.

### 4. Cleanup

Delete `db-dump.php`, `db-dump-auth.php`, and `db_exports/` (or use **Delete this tool and all dumps**).

---

## Credentials

Resolved in this order: environment variables, then nearby `wp-config.php`, then `127.0.0.1`.

| Variable | Purpose |
|---|---|
| `DB_HOST` / `WORDPRESS_DB_HOST` | Host, `host:port`, or `localhost:/path/mysql.sock` |
| `DB_NAME` / `WORDPRESS_DB_NAME` | Database name |
| `DB_USER` / `WORDPRESS_DB_USER` | User |
| `DB_PASS` / `WORDPRESS_DB_PASSWORD` | Password |
| `DB_PORT` | Port (default 3306) |
| `DB_EXPORT_PASSWORD_HASH` | Login hash (otherwise first-run setup writes `db-dump-auth.php`) |
| `DB_EXPORT_TOKEN` | Optional `X-Auth-Token` for automation |

---

## Self-test

From the project directory:

```bash
php db-dump.php --self-test
```
