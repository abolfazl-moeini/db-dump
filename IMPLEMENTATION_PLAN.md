# Implementation Plan: Ultra-Fast & Resilient Direct DB Copy Engine (`DatabaseCopier`)
**Project:** `db-dump` (Single-File PHP Database Migration & Restore Tool)  
**Target File:** `db-dump.php`  
**Author:** Antigravity Team  
**Date:** September 2026  
**Status:** Ready for Technical Review  

---

## 1. Executive Summary & Problem Diagnosis

### 1.1 The Production Incident
During production database migrations across live WordPress installations on shared hosting environments (cPanel, DirectAdmin, CloudLinux, LiteSpeed), the **Direct DB Copy** operation (`copyTab`) degraded to over **1 hour without completing**, despite databases being moderate in size (100MB – 1GB, ~1–2 million rows).

### 1.2 Root-Cause Deconstruction (Why the Current Code Stalls)
The current implementation in [`copy_table_chunk`](db-dump.php#L2015-L2140) suffers from six compounding architectural bottlenecks:

1. **Quadratic Disk Scan via `LIMIT chunk OFFSET offset` without `ORDER BY` ($O(N^2)$ Disk Complexity):**
   - Query in current code: `SELECT * FROM tbl LIMIT 5000 OFFSET 250000;`
   - In MySQL/InnoDB, an `OFFSET` without an index seek forces the storage engine to read all $N$ preceding rows from disk and discard them for *every* chunk. For a 2-million-row table like `wp_postmeta`, MySQL scans over **400,000,000 rows** across chunks.
   - **Data Integrity Bug:** Furthermore, because `SELECT * FROM tbl LIMIT 5000 OFFSET x` has **no `ORDER BY` clause**, InnoDB returns rows in physical page order. If the buffer pool rearranges pages between HTTP calls, the row sequence shifts, causing duplicate row fetches (fatal `Duplicate entry for PRIMARY key` crash) or skipped rows (silent data loss).
2. **Autocommit per 100 Rows (Disk I/O Choke):**
   - The current code inserts rows in tiny batches of 100 without an explicit transaction wrapper.
   - In InnoDB, every batch auto-commits, triggering an `fsync()` to the physical disk / Redo log. A 1.5-million-row database causes **15,000 physical fsync operations**, saturating shared hosting I/O limits (e.g., CloudLinux 1–5 MB/s I/O throttle).
3. **Client-Driven Ping-Pong + Browser Background Tab Throttling:**
   - The browser makes an independent HTTP request for every single 5,000-row chunk, waits for the response, sleeps 40ms, and fires the next request.
   - Over 500–1,000 chunks, network latency and HTTP handshake overhead add minutes.
   - **Crucial Browser Penalty:** When the user switches to another tab while waiting, modern browsers (Chrome, Safari, Firefox) aggressively throttle background tab timers (`setTimeout`) from 40ms to **1,000ms – 2,000ms per call**, introducing 15 to 30 minutes of dead idle time in the client alone.
4. **Empty / Tiny Table HTTP Penalty:**
   - WordPress has 10–30 small tables (<1,000 rows). Each table incurs a separate round-trip, database connection handshake, and a slow `SHOW FULL TABLES` query.
5. **No State Persistence (`copy_state.json`) or Concurrency Lock (`copy.lock`):**
   - Unlike `DatabaseExporter` and `DatabaseImporter`, `copy_table_chunk` maintains zero server-side state. If a client connection drops momentarily, all progress is permanently lost.
6. **Collation Incompatibility Crash:**
   - In `SHOW CREATE TABLE`, MySQL 8.0 tables created with `utf8mb4_0900_ai_ci` fail on MariaDB 10.3 / MySQL 5.7 destinations with `Unknown collation` because `fixSqlCollationCompatibility()` is never invoked.

---

## 2. Target Performance & Design Goals

| Metric | Current Implementation | Target with `DatabaseCopier` | Improvement |
|---|---|---|---|
| **Read Complexity** | $O(N^2)$ table scan (no `ORDER BY`) | $O(1)$ B-Tree Index Seek | Eliminates ~400M wasted disk reads |
| **HTTP Requests** | 450 – 1,000 round-trips | **3 – 6 long-burst requests** | 99% reduction in HTTP overhead |
| **Server Loop** | 1 chunk per HTTP request | **24-second Time-Budget Loop** | Full batching of small tables in 1 call |
| **Destination Disk I/O** | 15,000+ fsync commits | <50 chunk-level commits | 99.6% reduction in disk write ops |
| **Packet Safety** | Fixed row count (can exceed packet) | **Dual Bounding:** 1,000 rows OR 1.5MB | 100% immune to `max_allowed_packet` |
| **Background Tab Effect** | Stalls (1s penalty per chunk) | Zero impact (server runs continuously) | Eliminates 20+ minutes of idle wait |
| **Resilience** | Total restart on network glitch | Resume from `copy_state.json` + Auto-retry | Zero lost progress |
| **Total Migration Time** | **>60 – 90 minutes (often stalls)** | **40 – 90 seconds** | **~60x – 80x Speedup** |

---

## 3. Core Architecture of `DatabaseCopier`

### 3.1 Component Architecture Diagram

```
┌────────────────────────────────────────────────────────────────────────┐
│                        Client UI (copyTab JS)                          │
│  - POST init_copy (validates, tests, sets up state)                   │
│  - While-loop calling POST process_copy (~24s burst duration)          │
│  - Auto-Retry mechanism (up to 3 attempts with exponential backoff)    │
│  - Real-time row-based progress estimation                             │
└───────────────────────────────────▲────────────────────────────────────┘
                                    │ HTTP Keep-Alive (Only 3-6 requests total)
┌───────────────────────────────────▼────────────────────────────────────┐
│                       Backend: DatabaseCopier                          │
│                                                                        │
│ 1. State & Concurrency Lock:                                           │
│    - db_exports/copy_state.json (atomic state persistence)             │
│    - db_exports/copy.lock (exclusive flock prevents concurrent runs)   │
│                                                                        │
│ 2. Deterministic Primary Key Cursor Navigation:                        │
│    - Single PK: SELECT * FROM t WHERE pk > :last_pk ORDER BY pk LIMIT N │
│    - Composite: WHERE (k1 > :l1) OR (k1 = :l1 AND k2 > :l2)            │
│    - Non-PK: ORDER BY 1 ASC LIMIT N OFFSET offset                      │
│                                                                        │
│ 3. Dual-Bounded Extended Multi-Row Inserts:                            │
│    - START TRANSACTION -> batch INSERT (1000 rows OR 1.5MB) -> COMMIT │
│    - Strict binary preservation via 0x... hex literals                │
│                                                                        │
│ 4. Server-Side Time-Budget Loop:                                       │
│    - while ((microtime(true) - $start) < ($timeLimit - 2.5))           │
│    - Completes small tables back-to-back in the same HTTP call         │
│                                                                        │
│ 5. Session Optimization & Cross-Version Collation Safety:              │
│    - fixSqlCollationCompatibility() on SHOW CREATE TABLE               │
│    - SET autocommit=0, FOREIGN_KEY_CHECKS=0, UNIQUE_CHECKS=0          │
│    - NO_AUTO_VALUE_ON_ZERO mode + 600s socket timeouts                 │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Detailed Technical Specification

### 4.1 State File Schema (`db_exports/copy_state.json`)
The copier maintains its state across bursts in `copy_state.json`:

```json
{
  "phase": "tables",
  "created_at": 1727350000,
  "updated_at": 1727350025,
  "src_db": "source_db",
  "dest_db": "dest_db",
  "dest_host": "127.0.0.1",
  "dest_port": 3306,
  "dest_user": "dest_user",
  "dest_pass": "encrypted_or_state_pass",
  "total_tables": 42,
  "total_rows_est": 1850000,
  "copied_rows": 450000,
  "current_table_idx": 3,
  "tables": [
    {
      "name": "wp_posts",
      "pks": ["ID"],
      "pk_types": ["int"],
      "rows_est": 25000,
      "rows_done": 15000,
      "last_pk": [15000],
      "offset": 0,
      "structure_created": true,
      "done": false
    }
  ],
  "views": ["wp_v_active_users"],
  "triggers": []
}
```

### 4.2 Primary Key Cursor Engine (`buildCursorQuery`)
To guarantee $O(1)$ read speed and strictly prevent duplicate/skipped rows, queries are generated based on table schema:

1. **Single Primary Key (98% of WordPress Tables):**
   ```sql
   SELECT * FROM `wp_posts`
   WHERE `ID` > 15000
   ORDER BY `ID` ASC
   LIMIT 10000;
   ```
2. **Composite Primary Key (e.g., `wp_term_relationships` with `(object_id, term_taxonomy_id)`):**
   Row-value constructor `(col1, col2) > (v1, v2)` does not optimize well on older MariaDB/MySQL storage engines. We use the standard expanded B-Tree seek predicate:
   ```sql
   SELECT * FROM `wp_term_relationships`
   WHERE (`object_id` > 100) OR (`object_id` = 100 AND `term_taxonomy_id` > 5)
   ORDER BY `object_id` ASC, `term_taxonomy_id` ASC
   LIMIT 10000;
   ```
3. **Tables without Primary Key (<1% edge cases):**
   Fallback to offset with deterministic ordering:
   ```sql
   SELECT * FROM `tbl_without_pk`
   ORDER BY 1 ASC
   LIMIT 10000 OFFSET 20000;
   ```

### 4.3 Dual-Bounded Extended Inserts & Binary Safety
- Buffer rules: Flush whenever `count($batch) >= 1000` **OR** `strlen($batchSql) >= 1,572,864` (1.5 MB).
- Data types:
  - `NULL` values $\to$ literal `NULL`.
  - Numeric columns (int, float, decimal) $\to$ raw numbers without quotes.
  - Binary / BLOB data $\to$ hex format `0x...` via `bin2hex()` to avoid UTF-8 truncation or encoding corruption.
  - Strings $\to$ `'...'` escaped via `$destDb->real_escape_string()`.
- Transaction: Wrapped in explicit `START TRANSACTION` ... `COMMIT` per chunk.

### 4.4 Collation & Compatibility Filter
Before executing `CREATE TABLE` on the destination, the raw `SHOW CREATE TABLE` output from the source is piped through `fixSqlCollationCompatibility($createSql)`:
- `utf8mb4_0900_ai_ci` $\to$ `utf8mb4_unicode_520_ci`
- `utf8mb4_0900_bin` $\to$ `utf8mb4_bin`
- MySQL 8.0 `COLLATE` overrides safely downgraded for MySQL 5.7 / MariaDB 10.x.

### 4.5 Server-Side Time-Budget Loop
Each HTTP call to `action=process_copy` executes:
```php
$startTime = microtime(true);
$timeBudget = (float) ($this->config['time_limit'] ?? 28); // e.g. 24 seconds max

while ((microtime(true) - $startTime) < ($timeBudget - 2.5)) {
    // 1. If current table needs structure creation, DROP IF EXISTS + CREATE TABLE on destination
    // 2. Fetch chunk (up to 10,000 rows via Cursor Query)
    // 3. Insert into destination with dual-bounding (1000 rows / 1.5MB)
    // 4. Update last_pk and copied_rows
    // 5. If table has 0 rows returned, mark table done and advance to next table IMMEDIATELY
    // 6. If all tables done, copy Views & Triggers, mark completed
}
```
*Impact:* 30 tiny tables are created and fully copied within **one single 2-second HTTP burst**, rather than requiring 30 round-trips!

---

## 5. Security & Safety Invariants

1. **Main Site Protection Enforcement:**
   - Both `init_copy` and `process_copy` enforce `isDbNameMainSiteProtected($destDbName, $discoveredWp)`.
   - If destination belongs to `public_html` and `ALLOW_MAIN_SITE_OVERWRITE !== true`, immediate `403 Forbidden` response.
2. **Identical Database Prevention:**
   - `destName !== srcName` verified on client and server.
3. **Concurrency Guard:**
   - Exclusive non-blocking file lock `flock(LOCK_EX | LOCK_NB)` on `copy.lock`.
   - Stale lock detection: automatically breaks lock if state timestamp is >120 seconds old.
4. **Credential Security:**
   - State file `copy_state.json` is protected inside `db_exports/` which has `.htaccess`, `web.config`, and `index.php` access denial.
   - Cleared and deleted upon copy completion.

---

## 6. Implementation Steps & Code Modifications

### Step 1: Create `DatabaseCopier` Class in `db-dump.php`
- Model after `DatabaseExporter` (lines 3112–3650).
- Implement `init(array $payload): array`.
- Implement `processChunk(): array`.
- Implement helper methods: `detectPrimaryKeys()`, `buildCursorQuery()`, `copyViewsAndTriggers()`.

### Step 2: Register API Endpoints in Request Router
- Replace legacy `copy_table_chunk` and `finalize_copy` with:
  - `action=init_copy`
  - `action=process_copy`
- Enforce `requireAuth()`, `requirePost()`, `requireCsrf()`, and `isDbNameMainSiteProtected()`.

### Step 3: Modernize Frontend `copyTab` UX & JS Runner
- Add `copyDestSiteSelect` to auto-populate credentials from discovered `wp-config.php`.
- Display visual route notice: `Source Database [X] ➔ Destination Database [Y]`.
- Enforce explicit confirmation modal: `<<< از دیتابیس X به دیتابیس Y کپی خواهد شد >>>` requiring destination database name typing.
- Implement the client runner loop with auto-retry (3 attempts on network error).
- Render live percentage based on rows copied vs total estimated rows.

### Step 4: Verification & Automated Self-Tests
- Add unit tests to `runSelfTests()` for cursor query builder, composite PK sorting, and collation filters.
- Verify zero syntax errors (`php -l db-dump.php`).
- Execute all 73 existing self-tests to ensure zero regressions.
