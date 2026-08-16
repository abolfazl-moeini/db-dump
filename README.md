# Database Dump & Restore Tool

A lightweight, single-file PHP tool designed for **shared hosting**, **cPanel**, **DirectAdmin**, and **WordPress** to:
1. **Import / Restore** large `.sql`, `.sql.gz` (GZIP), or `.zip` files in chunks without HTTP or PHP execution timeouts.
2. **Export** selected database tables to compressed `.sql.gz` or `.sql`.
3. **Copy** databases directly from one host to another.
4. **Search & Replace** URLs/domains safely across WordPress serialized data (in `wp_options`, `wp_postmeta`, etc.).

---

## Features

- **Chunked & Resumable Execution**: Works within 15–25 second HTTP request bursts, preventing server `504 Gateway Timeout` or `max_execution_time` kills on shared hosting.
- **GZIP Streaming**: Reads and writes `.sql.gz` compressed archives on the fly without heavy memory usage.
- **WordPress wp-config.php Auto-Detection**: Automatically detects database credentials if placed in your WordPress root or public directory.
- **Domain Search & Replace**: Automatically updates domain URLs (e.g. `https://tavangarynew.local` -> `https://tavangary.com`) while preserving serialized PHP data length integrity.
- **File Upload & Browser Manager**: Upload dump files directly from your browser or FTP them to `db_exports/`.

---

## How to Import a Localhost SQL Dump to Server

### Step 1: Export your Localhost Database
1. You can use this tool on localhost (or WP-CLI `wp db export dump.sql.gz`) to generate a compressed backup file:
   ```bash
   wp db export dump.sql.gz
   ```

### Step 2: Upload to Server
1. Upload `db-dump.php` to your server (e.g. in `public_html/` or a secure subfolder).
2. Upload your `dump.sql.gz` (or `dump.sql`) directly to the `db_exports/` directory on the server via FTP / cPanel File Manager (or use the web upload button in the tool).

### Step 3: Run the Import
1. Open `https://your-domain.com/db-dump.php` in your browser.
2. Go to the **Import / Restore** tab.
3. Select your uploaded dump file from the dropdown.
4. (Optional) In the **Domain / URL Search & Replace** section:
   - **Old URL**: `https://tavangarynew.local` (your local dev URL)
   - **New URL**: `https://your-domain.com` (your live site URL)
   - Keep "Safely update WordPress serialized strings" checked.
5. Click **Start Database Import**.
6. The progress bar will stream through the dump chunk-by-chunk until complete.

### Step 4: Cleanup
- **IMPORTANT**: Delete `db-dump.php` and the `db_exports/` folder from your server once the import is finished for security!
