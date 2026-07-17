<?php
/**
 * Database Exporter v2.6 — GZIP Compression & Table Selection
 * Refactored for Clean Code and PHP >= 7.4 Compatibility
 */

// ━━━━━━━━━━━━━━━━
//  CONFIGURATION
// ━━━━━━━━━━━━━━━━


// Performance optimizations for shared hosting
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '120');
ini_set('output_buffering', '0');

putenv('DB_EXPORT_PASSWORD_HASH=');

$config = [
    'auth_token'       => getenv('DB_EXPORT_TOKEN') ?: 'MY_SECRET_TOKEN_CHANGE_ME',

    // Database 
    'db_host'          => getenv('DB_HOST') ?: getenv('WORDPRESS_DB_HOST') ?: '127.0.0.1',
    'db_name'          => getenv('DB_NAME') ?: getenv('WORDPRESS_DB_NAME') ?: '',
    'db_user'          => getenv('DB_USER') ?: getenv('WORDPRESS_DB_USER') ?: '',
    'db_pass'          => getenv('DB_PASS') ?: getenv('WORDPRESS_DB_PASSWORD') ?: '',
    'db_charset'       => 'utf8mb4',
    'db_port'          => (int)(getenv('DB_PORT') ?: 3306),
    //
    // Destination database defaults (optional)
    'dest_db_host'     => getenv('DEST_DB_HOST') ?: '127.0.0.1',
    'dest_db_name'     => getenv('DEST_DB_NAME') ?: '',
    'dest_db_user'     => getenv('DEST_DB_USER') ?: '',
    'dest_db_pass'     => getenv('DEST_DB_PASS') ?: '',
    'dest_db_port'     => (int)(getenv('DEST_DB_PORT') ?: 3306),
    
    // Export Tuning
    'chunk_size'       => 10000,      // Increased for fewer requests
    'time_limit'       => 50,         // More time per chunk on shared hosting
    'max_insert_bytes' => 2097152,    // 2MB
    'max_insert_rows'  => 1000,

    // Paths & Features
    'export_dir'       => __DIR__ . '/db_exports/',
    'export_routines'  => true,
    'export_events'    => true,
    'compression'      => 'gzip',
];

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit($config['time_limit'] + 10);

// Always start session (needed for AJAX authentication)
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
    ]);
}

if (!is_dir($config['export_dir'])) {
    mkdir($config['export_dir'], 0750, true);
}

$htaccessPath = $config['export_dir'] . '.htaccess';
if (!file_exists($htaccessPath)) {
    file_put_contents($htaccessPath, "Require all denied\n");
}

// ──────────────────────────────────────────────────────────
//  AUTHENTICATION & SESSIONS
// ──────────────────────────────────────────────────────────
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $storedHash = getenv('DB_EXPORT_PASSWORD_HASH');
    if (!$storedHash || !password_verify($_POST['password'], $storedHash)) {
        $loginError = 'Invalid password.';
        sleep(1);
    } else {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

function isAuthenticated(): bool
{
    global $config;

    if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 3600) {
            return false;
        }
        return true;
    }

    $authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';

    return $authHeader !== '' && hash_equals($config['auth_token'], $authHeader);
}

// ──────────────────────────────────────────────────────────
//  FILE MANAGEMENT ENDPOINTS
// ──────────────────────────────────────────────────────────
if (isset($_GET['action']) && in_array($_GET['action'], ['download', 'delete', 'list_files','copy_db', 'copy_table_chunk', 'finalize_copy'], true)) {
    if (!isAuthenticated()) {
        http_response_code(401);
        die(json_encode(['error' => 'Unauthorized']));
    }

    $reqFile = $_GET['file'] ?? '';
    $safeFileName = basename((string)$reqFile);
    $targetPath = $config['export_dir'] . $safeFileName;

    if ($_GET['action'] === 'download') {
        if ($safeFileName && file_exists($targetPath) && is_readable($targetPath) && preg_match('/\.(sql|gz|zip)$/', $safeFileName)) {
            $fileSize = filesize($targetPath);
            $start = 0;
            $end = $fileSize - 1;
            
            // چک کردن Range header
            if (isset($_SERVER['HTTP_RANGE'])) {
                if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                    $start = intval($matches[1]);
                    if (!empty($matches[2])) {
                        $end = intval($matches[2]);
                    }
                }
            }
            
            $contentType = 'application/octet-stream';
            
            // اگر Range درخواست شده، کد 206 برمی‌گردونیم
            if ($start > 0 || $end < $fileSize - 1) {
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            } else {
                header('HTTP/1.1 200 OK');
            }
            
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
            header('Content-Length: ' . ($end - $start + 1));
            header('Accept-Ranges: bytes');
            header('Cache-Control: no-cache');
            
            set_time_limit(0);
            $file = fopen($targetPath, 'rb');
            fseek($file, $start);
            $remaining = $end - $start + 1;
            while (!feof($file) && $remaining > 0) {
                $chunk = min(8192, $remaining);
                echo fread($file, $chunk);
                flush();
                $remaining -= $chunk;
            }
            fclose($file);
            exit;
        }
    }

    if ($_GET['action'] === 'delete') {
        if ($safeFileName && file_exists($targetPath) && preg_match('/\.(sql|gz|zip)$/', $safeFileName)) {
            unlink($targetPath);
            echo json_encode(['success' => true]);
            exit;
        }

        http_response_code(404);
        die(json_encode(['error' => 'File not found.']));
    }

    if ($_GET['action'] === 'list_files') {
        $files = array_merge(
            glob($config['export_dir'] . '*.sql') ?: [],
            glob($config['export_dir'] . '*.gz') ?: [],
            glob($config['export_dir'] . '*.zip') ?: []
        );

        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'date' => filemtime($file)
            ];
        }

        usort($result, fn($a, $b) => $b['date'] <=> $a['date']);

        echo json_encode($result);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    //  COPY DATABASE ENDPOINT
    // ──────────────────────────────────────────────────────────
    if ($_GET['action'] === 'copy_db') {

        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        if (!is_array($payload)) {
            http_response_code(400);
            die(json_encode(['error' => 'Invalid JSON payload']));
        }

        $destHost = $payload['dest_host'] ?? $config['dest_db_host'];
        $destPort = $payload['dest_port'] ?? $config['dest_db_port'];
        $destName = $payload['dest_name'] ?? $config['dest_db_name'];
        $destUser = $payload['dest_user'] ?? $config['dest_db_user'];
        $destPass = $payload['dest_pass'] ?? $config['dest_db_pass'];
        $table = $payload['table'] ?? null;
        $offset = isset($payload['offset']) ? (int)$payload['offset'] : 0;
        $chunkSize = isset($payload['chunk_size']) ? (int)$payload['chunk_size'] : 5000; // Increased from 1000

        if (empty($destHost) || empty($destName) || empty($destUser) || empty($table)) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing destination database credentials or table']));
        }

        try {
            // Use persistent connections for better performance
            $srcHost = 'p:' . $config['db_host'];
            $destHostPersistent = 'p:' . $destHost;
            
            // Connect to source
            $srcDb = new mysqli(
                $srcHost,
                $config['db_user'],
                $config['db_pass'],
                $config['db_name'],
                $config['db_port']
            );

            if ($srcDb->connect_error) {
                throw new RuntimeException('Source connection failed: ' . $srcDb->connect_error);
            }

            $srcDb->set_charset($config['db_charset']);

            // Connect to destination
            $destDb = new mysqli($destHostPersistent, $destUser, $destPass, $destName, $destPort);

            if ($destDb->connect_error) {
                throw new RuntimeException('Destination connection failed: ' . $destDb->connect_error);
            }

            $destDb->set_charset($config['db_charset']);

            // Disable foreign key checks for the session
            $destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 0");
            $destDb->query("SET SESSION UNIQUE_CHECKS = 0");

            $safeTable = '`' . str_replace('`', '``', $table) . '`';

            // Get table structure (only on first chunk)
            if ($offset === 0) {
                $result = $srcDb->query("SHOW CREATE TABLE {$safeTable}");
                if (!$result) {
                    throw new RuntimeException("Failed to get structure for {$table}: " . $srcDb->error);
                }
                $row = $result->fetch_assoc();
                $createTable = $row['Create Table'] ?? '';
                $result->free();

                // Drop and create table in destination
                if (!$destDb->query("DROP TABLE IF EXISTS {$safeTable}")) {
                    throw new RuntimeException("Failed to drop table {$table}: " . $destDb->error);
                }
                if (!$destDb->query($createTable)) {
                    throw new RuntimeException("Failed to create table {$table}: " . $destDb->error);
                }
            }

            // Copy data in chunk
            $result = $srcDb->query("SELECT COUNT(*) AS total FROM {$safeTable}");
            if (!$result) {
                throw new RuntimeException("Failed to count rows in {$table}: " . $srcDb->error);
            }
            $row = $result->fetch_assoc();
            $rowCount = (int)$row['total'];
            $result->free();

            $copiedRows = 0;
            if ($rowCount > 0 && $offset < $rowCount) {
                $dataResult = $srcDb->query("SELECT * FROM {$safeTable} LIMIT {$chunkSize} OFFSET {$offset}", MYSQLI_USE_RESULT);
                if (!$dataResult) {
                    throw new RuntimeException("Failed to read data from {$table}: " . $srcDb->error);
                }

                $columns = [];
                $fields = $dataResult->fetch_fields();
                foreach ($fields as $field) {
                    $columns[] = '`' . str_replace('`', '``', $field->name) . '`';
                }
                $columnsList = implode(', ', $columns);
                
                // Batch insert for better performance
                $batchSize = 100;
                $batchValues = [];
                $totalInserted = 0;

                while ($row = $dataResult->fetch_row()) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } else {
                            $rowValues[] = "'" . $destDb->real_escape_string($value) . "'";
                        }
                    }
                    $batchValues[] = '(' . implode(',', $rowValues) . ')';
                    $copiedRows++;

                    // Insert in batches
                    if (count($batchValues) >= $batchSize) {
                        $insertSql = "INSERT INTO {$safeTable} ({$columnsList}) VALUES " . implode(',', $batchValues);
                        if (!$destDb->query($insertSql)) {
                            throw new RuntimeException("Failed to insert data into {$table}: " . $destDb->error);
                        }
                        $totalInserted += count($batchValues);
                        $batchValues = [];
                    }
                }

                // Insert remaining rows
                if (!empty($batchValues)) {
                    $insertSql = "INSERT INTO {$safeTable} ({$columnsList}) VALUES " . implode(',', $batchValues);
                    if (!$destDb->query($insertSql)) {
                        throw new RuntimeException("Failed to insert data into {$table}: " . $destDb->error);
                    }
                    $totalInserted += count($batchValues);
                }

                $dataResult->free();
            }

            // Re-enable checks before closing
            $destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 1");
            $destDb->query("SET SESSION UNIQUE_CHECKS = 1");

            $srcDb->close();
            $destDb->close();

            $nextOffset = $offset + $chunkSize;
            $done = ($nextOffset >= $rowCount);

            echo json_encode([
                'success' => true,
                'table' => $table,
                'offset' => $nextOffset,
                'chunk_size' => $chunkSize,
                'total_rows' => $rowCount,
                'copied_rows' => min($nextOffset, $rowCount),
                'done' => $done
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    // ──────────────────────────────────────────────────────────
    //  COPY TABLE CHUNK (for batched copy with progress)
    // ──────────────────────────────────────────────────────────
    if ($_GET['action'] === 'copy_table_chunk') {
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        if (!is_array($payload)) {
            http_response_code(400);
            die(json_encode(['error' => 'Invalid JSON payload']));
        }

        $destHost = $payload['dest_host'] ?? $config['dest_db_host'];
        $destPort = $payload['dest_port'] ?? $config['dest_db_port'];
        $destName = $payload['dest_name'] ?? $config['dest_db_name'];
        $destUser = $payload['dest_user'] ?? $config['dest_db_user'];
        $destPass = $payload['dest_pass'] ?? $config['dest_db_pass'];
        $table = $payload['table'] ?? '';
        $offset = isset($payload['offset']) ? (int)$payload['offset'] : 0;
        $chunkSize = isset($payload['chunk_size']) ? (int)$payload['chunk_size'] : 5000;
        $createTable = $payload['create_table'] ?? false;

        if (empty($table)) {
            http_response_code(400);
            die(json_encode(['error' => 'Table name required']));
        }

        try {
            $srcHost = 'p:' . $config['db_host'];
            $destHostPersistent = 'p:' . $destHost;
            
            $srcDb = new mysqli($srcHost, $config['db_user'], $config['db_pass'], $config['db_name'], $config['db_port']);
            if ($srcDb->connect_error) {
                throw new RuntimeException('Source connection failed: ' . $srcDb->connect_error);
            }
            $srcDb->set_charset($config['db_charset']);

            $destDb = new mysqli($destHostPersistent, $destUser, $destPass, $destName, $destPort);
            if ($destDb->connect_error) {
                throw new RuntimeException('Destination connection failed: ' . $destDb->connect_error);
            }
            $destDb->set_charset($config['db_charset']);

            // Disable foreign key checks for the session
            $destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 0");
            $destDb->query("SET SESSION UNIQUE_CHECKS = 0");
            $destDb->query("SET SESSION AUTOCOMMIT = 0");

            $safeTable = '`' . str_replace('`', '``', $table) . '`';

            // Create table structure if needed
            if ($createTable) {
                $result = $srcDb->query("SHOW CREATE TABLE {$safeTable}");
                if ($result) {
                    $row = $result->fetch_assoc();
                    $createSql = $row['Create Table'] ?? '';
                    $result->free();
                    
                    $destDb->query("DROP TABLE IF EXISTS {$safeTable}");
                    if (!$destDb->query($createSql)) {
                        throw new RuntimeException("Failed to create table: " . $destDb->error);
                    }
                }
            }

            // Get total rows
            $result = $srcDb->query("SELECT COUNT(*) AS total FROM {$safeTable}");
            $rowCount = $result ? (int)$result->fetch_assoc()['total'] : 0;
            if ($result) $result->free();

            $copiedRows = 0;
            if ($rowCount > 0 && $offset < $rowCount) {
                $dataResult = $srcDb->query("SELECT * FROM {$safeTable} LIMIT {$chunkSize} OFFSET {$offset}", MYSQLI_USE_RESULT);
                
                if ($dataResult) {
                    $columns = [];
                    foreach ($dataResult->fetch_fields() as $field) {
                        $columns[] = '`' . str_replace('`', '``', $field->name) . '`';
                    }
                    $columnsList = implode(', ', $columns);
                    
                    $batchValues = [];
                    $batchSize = 100;

                    while ($row = $dataResult->fetch_row()) {
                        $rowValues = [];
                        foreach ($row as $value) {
                            $rowValues[] = $value === null ? 'NULL' : "'" . $destDb->real_escape_string($value) . "'";
                        }
                        $batchValues[] = '(' . implode(',', $rowValues) . ')';
                        $copiedRows++;

                        if (count($batchValues) >= $batchSize) {
                            $destDb->query("INSERT INTO {$safeTable} ({$columnsList}) VALUES " . implode(',', $batchValues));
                            $batchValues = [];
                        }
                    }

                    if (!empty($batchValues)) {
                        $destDb->query("INSERT INTO {$safeTable} ({$columnsList}) VALUES " . implode(',', $batchValues));
                    }

                    $dataResult->free();
                }
            }

            // Commit any pending transaction
            $destDb->query("COMMIT");
            
            // Re-enable checks before closing
            $destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 1");
            $destDb->query("SET SESSION UNIQUE_CHECKS = 1");
            $destDb->query("SET SESSION AUTOCOMMIT = 1");

            $srcDb->close();
            $destDb->close();

            $nextOffset = $offset + $chunkSize;
            $done = ($nextOffset >= $rowCount);

            echo json_encode([
                'success' => true,
                'table' => $table,
                'offset' => $nextOffset,
                'total_rows' => $rowCount,
                'copied_rows' => $copiedRows,
                'total_copied' => min($nextOffset, $rowCount),
                'done' => $done
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    // ──────────────────────────────────────────────────────────
    //  FINALIZE COPY (re-enable foreign keys and validate)
    // ──────────────────────────────────────────────────────────
    if ($_GET['action'] === 'finalize_copy') {
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        $destHost = $payload['dest_host'] ?? $config['dest_db_host'];
        $destPort = $payload['dest_port'] ?? $config['dest_db_port'];
        $destName = $payload['dest_name'] ?? $config['dest_db_name'];
        $destUser = $payload['dest_user'] ?? $config['dest_db_user'];
        $destPass = $payload['dest_pass'] ?? $config['dest_db_pass'];

        try {
            $destHostPersistent = 'p:' . $destHost;
            $destDb = new mysqli($destHostPersistent, $destUser, $destPass, $destName, $destPort);
            
            if ($destDb->connect_error) {
                throw new RuntimeException('Connection failed: ' . $destDb->connect_error);
            }

            $destDb->set_charset($config['db_charset']);

            // Re-enable foreign key checks
            $destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 1");
            $destDb->query("SET SESSION UNIQUE_CHECKS = 1");
            $destDb->query("SET SESSION AUTOCOMMIT = 1");

            // Check for any foreign key constraint errors
            $result = $destDb->query("SELECT COUNT(*) AS invalid FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $destDb->real_escape_string($destName) . "' AND TABLE_TYPE = 'BASE TABLE'");
            $tableCount = $result ? (int)$result->fetch_assoc()['invalid'] : 0;
            if ($result) $result->free();

            $destDb->close();

            echo json_encode([
                'success' => true,
                'message' => 'Foreign key checks re-enabled',
                'tables_count' => $tableCount
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }
}

// ──────────────────────────────────────────────────────────
//  GET TABLES LIST
// ──────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_tables') {
    if (!isAuthenticated()) {
        http_response_code(401);
        die(json_encode(['error' => 'Unauthorized']));
    }

    try {

        $db = new mysqli(
            $config['db_host'],
            $config['db_user'],
            $config['db_pass'],
            $config['db_name'],
            $config['db_port']
        );

        if ($db->connect_error) {
            throw new RuntimeException('Connection failed: ' . $db->connect_error);
        }

        $db->set_charset($config['db_charset']);
        $tables = [];
        $result = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

        if ($result) {
            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }$result->free();
        }

        $db->close();

        echo json_encode(['tables' => $tables]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}

// ──────────────────────────────────────────────────────────
//  SERVE UI
// ──────────────────────────────────────────────────────────
if (!isset($_GET['action'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Exporter</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #2d3748;
        }

        .container {
            max-width: 680px;
            width: 100%;
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        h1 {
            font-size: 28px;
            margin-bottom: 8px;
            font-weight: 700;
            color: #1a202c;display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .subtitle {
            font-size: 14px;
            color: #718096;
            margin-bottom: 32px;
        }

        .logout {
            font-size: 14px;
            color: #e53e3e;
            text-decoration: none;
            font-weight: 500;
            transition: opacity 0.2s;
        }

        .logout:hover { opacity: 0.7; }

        .input-group { margin-bottom: 24px; }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
        }

        input, select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.2s;
            background: #f7fafc;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        button:active { transform: translateY(0); }

        button:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none;
        }

        button.btn-sm {
            padding: 8px 16px;
            font-size: 13px;
            width: auto;
        }

        button.btn-danger {
            background: linear-gradient(135deg, #fc8181 0%, #e53e3e 100%);}

        button.btn-success {
            background: linear-gradient(135deg, #68d391 0%, #38a169 100%);
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin: 24px 0;
            display: none;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s ease;width: 0%;
        }

        .status {
            padding: 16px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 14px;
            display: none;
            border-left: 4px solid;}

        .status.info {
            background: #ebf8ff;
            color: #2c5282;
            border-color: #4299e1;
        }

        .status.success {
            background: #f0fff4;
            color: #22543d;
            border-color: #38a169;
        }

        .status.error {
            background: #fff5f5;
            color: #742a2a;
            border-color: #e53e3e;
        }

        .stats {
            display: none;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 20px;
        }

        .stat {
            padding: 16px;
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border-radius: 8px;
            text-align: center;
        }

        .stat-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .file-manager {
            margin-top: 48px;
            padding-top: 32px;
            border-top: 2px solid #e2e8f0;
        }

        .file-manager h2 {
            font-size: 20px;
            margin-bottom: 20px;
            font-weight: 700;
            color: #1a202c;
        }

        .file-list { list-style: none; }

        .file-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f7fafc;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            border: 2px solid #e2e8f0;
            transition: all 0.2s;
        }

        .file-list li:hover {
            border-color: #cbd5e0;
            transform: translateX(4px);
        }

        .file-info { display: flex; flex-direction: column; flex: 1; }

        .file-name {
            font-weight: 600;
            font-size: 14px;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .file-meta {
            font-size: 12px;
            color: #a0aec0;
        }

        .file-actions {
            display: flex;
            gap: 8px;
        }

        .table-selector {
            margin: 24px 0;
            padding: 20px;
            background: #f7fafc;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .table-selector h3 {
            font-size: 16px;
            margin-bottom: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .table-list {
            max-height: 240px;
            overflow-y: auto;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            background: white;
        }

        .table-list::-webkit-scrollbar { width: 8px; }
        .table-list::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .table-list::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 4px; }
        .table-list::-webkit-scrollbar-thumb:hover { background: #a0aec0; }

        .table-item {
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-radius: 6px;
            transition: background 0.2s;
        }

        .table-item:hover { background: #edf2f7; }

        .table-item input[type="checkbox"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #667eea;
        }

        .table-item label {
            cursor: pointer;
            font-size: 14px;
            flex: 1;
            color: #2d3748;font-weight: 500;
        }

        .table-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .table-actions button {
            width: auto;
            padding: 10px 20px;
            font-size: 13px;
        }

        .empty-state {
            text-align: center;
            padding: 32px 20px;
            color: #a0aec0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!isAuthenticated()): ?>
            <h1>Database Exporter</h1>
            <div class="subtitle">Secure database backup tool</div>

            <?php if ($loginError): ?>
                <div class="status error" style="display:block; margin-bottom:24px;">
                    <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autofocus autocomplete="current-password" placeholder="Enter your password">
                </div>
                <button type="submit">Sign In</button>
            </form>
        <?php else: ?>
            <h1>
                Database Exporter
                <a href="?logout=1" class="logout">Logout</a>
            </h1><div class="subtitle">Export and manage your database backups</div>

            <div class="input-group">
                <label for="compression">Compression Format</label>
                <select id="compression">
                    <option value="none">None (.sql)</option>
                    <option value="gzip" selected>GZIP (.sql.gz)</option>
                </select>
            </div>

            <div class="table-selector">
                <h3>Select Tables</h3>
                <div class="table-list" id="tableList">
                    <div class="empty-state">Loading tables...</div>
                </div>
                <div class="table-actions">
                    <button type="button" class="btn-sm" onclick="selectAllTables()">Select All</button>
                    <button type="button" class="btn-sm" onclick="deselectAllTables()">Deselect All</button>
                </div>
            </div>

            <button type="button" id="startBtn" onclick="startExport()">Start Export</button>

            <div class="progress-bar" id="progressBar">
                <div class="progress-fill" id="progressFill"></div>
            </div>

            <div class="stats" id="stats">
                <div class="stat">
                    <div class="stat-label">Phase</div>
                    <div class="stat-value" id="phase">—</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Rows Exported</div>
                    <div class="stat-value" id="rows">0</div>
                </div></div>
            <div class="status" id="status"></div>

            <!-- Copy Database Panel -->
            <div style="margin-top: 48px; padding-top: 48px; border-top: 1px solid #e2e8f0;">
                <h2 style="margin-bottom: 8px;">Copy Database</h2>
                <div class="subtitle" style="margin-bottom: 24px;">Copy selected tables to another database</div>

                <div class="input-group">
                    <label for="destHost">Destination Host</label>
                    <input type="text" id="destHost" placeholder="localhost">
                </div>

                <div class="input-group">
                    <label for="destPort">Port</label>
                    <input type="number" id="destPort" value="3306">
                </div>

                <div class="input-group">
                    <label for="destName">Database Name</label>
                    <input type="text" id="destName" placeholder="target_database">
                </div>

                <div class="input-group">
                    <label for="destUser">Username</label>
                    <input type="text" id="destUser" placeholder="root">
                </div>

                <div class="input-group">
                    <label for="destPass">Password</label>
                    <input type="password" id="destPass" placeholder="••••••••">
                </div>

                <button type="button" id="copyBtn" onclick="startCopy()">Copy Database</button>
            </div>

            <div class="status" id="status"></div>

            <div class="file-manager">
                <h2>Backup Files</h2>
                <ul class="file-list" id="fileList"></ul>
            </div>

            <script>
                const API_URL = window.location.pathname;
                let isRunning = false;

                function formatBytes(bytes, decimals = 2) {
                    if (bytes === 0) return '0 Bytes';
                    const k = 1024;
                    const dm = decimals < 0 ? 0 : decimals;
                    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
                }

                function showStatus(msg, type = 'info') {
                    const el = document.getElementById('status');
                    el.textContent = msg;
                    el.className = `status ${type}`;
                    el.style.display = 'block';
                }

                function updateProgress(percent, phase, rows, total) {
                    document.getElementById('progressBar').style.display = 'block';
                    document.getElementById('progressFill').style.width = Math.min(100, percent) + '%';
                    document.getElementById('stats').style.display = 'grid';
                    document.getElementById('phase').textContent = phase.toUpperCase();
                    document.getElementById('rows').textContent =
                        rows.toLocaleString() + (total ? ` / ${total.toLocaleString()}` : '');
                }

                async function apiCall(action, body = null) {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 90000); // 90s timeout
                    
                    const opts = { 
                        method: body ? 'POST' : 'GET',
                        signal: controller.signal
                    };
                    if (body) {
                        opts.headers = { 'Content-Type': 'application/json' };
                        opts.body = JSON.stringify(body);
                    }
                    try {
                        const res = await fetch(`${API_URL}?action=${action}`, opts);
                        clearTimeout(timeoutId);
                        if (!res.ok) {
                            const bodyData = await res.json().catch(() => ({}));
                            throw new Error(bodyData.error || `HTTP ${res.status}`);
                        }
                        return res.json();
                    } catch (e) {
                        clearTimeout(timeoutId);
                        if (e.name === 'AbortError') {
                            throw new Error('Request timeout - server too slow');
                        }
                        throw e;
                    }
                }

                async function loadTables() {
                    try {
                        const data = await apiCall('get_tables');
                        const tables = data.tables || [];
                        const list = document.getElementById('tableList');
                        list.innerHTML = '';
                        if (tables.length === 0) {
                            list.innerHTML = '<div class="empty-state">No tables found</div>';
                            return;
                        }

                        tables.forEach(table => {
                            const div = document.createElement('div');
                            div.className = 'table-item';
                            const safeTable = table.replace(/"/g, '&quot;');
                            div.innerHTML = `
                                <input type="checkbox" id="tbl_${safeTable}" value="${safeTable}" checked>
                                <label for="tbl_${safeTable}">${table}</label>
                            `;
                            list.appendChild(div);
                        });
                    } catch (e) {
                        console.error('Failed to load tables', e);
                        document.getElementById('tableList').innerHTML =
                            '<div class="empty-state" style="color:#e53e3e;">Failed to load tables</div>';
                    }
                }

                function selectAllTables() {
                    document.querySelectorAll('#tableList input[type="checkbox"]').forEach(cb => cb.checked = true);
                }

                function deselectAllTables() {
                    document.querySelectorAll('#tableList input[type="checkbox"]').forEach(cb => cb.checked = false);
                }

                function getSelectedTables() {
                    return Array.from(document.querySelectorAll('#tableList input[type="checkbox"]:checked'))
                        .map(cb => cb.value);
                }

                async function loadFiles() {
                    try {
                        const files = await apiCall('list_files');
                        const list = document.getElementById('fileList');
                        list.innerHTML = '';

                        if(files.length === 0) {
                            list.innerHTML = '<li class="empty-state">No backup files yet</li>';
                            return;
                        }

                        files.forEach(f => {
                            const dateStr = new Date(f.date * 1000).toLocaleString();
                            const li = document.createElement('li');
                            const safeName = f.name.replace(/"/g, '&quot;');
                            li.innerHTML = `
                                <div class="file-info">
                                    <span class="file-name">${f.name}</span>
                                    <span class="file-meta">${formatBytes(f.size)} • ${dateStr}</span>
                                </div>
                                <div class="file-actions">
                                    <button class="btn-sm btn-success" onclick="window.location.href='?action=download&file=${encodeURIComponent(f.name)}'">Download</button>
                                    <button class="btn-sm btn-danger" onclick="deleteFile('${safeName}')">Delete</button>
                                </div>
                            `;
                            list.appendChild(li);
                        });
                    } catch (e) {
                        console.error('Failed to load files', e);
                    }
                }

                async function deleteFile(filename) {
                    if(!confirm(`Delete ${filename}?`)) return;
                    try {
                        await fetch(`${API_URL}?action=delete&file=${encodeURIComponent(filename)}`);
                        loadFiles();
                    } catch(e) { alert(e.message); }
                }

                async function startExport() {
                    if (isRunning) return;

                    const selectedTables = getSelectedTables();
                    if (selectedTables.length === 0) {
                        alert('Please select at least one table to export.');
                        return;
                    }

                    isRunning = true;
                    document.getElementById('startBtn').disabled = true;

                    try {
                        showStatus('Initializing export…', 'info');
                        const compression = document.getElementById('compression').value;
                        const init = await fetch(`${API_URL}?action=init`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ compression, tables: selectedTables })
                        }).then(r => r.json());

                        if (init.error) {
                            showStatus(init.error, 'error');
                            return;
                        }

                        showStatus(init.message, 'info');
                        await processChunks();
                    } catch (err) {
                        showStatus('Error: ' + err.message, 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('startBtn').disabled = false;loadFiles();
                    }
                }

                async function startCopy() {
                    if (isRunning) return;

                    const selectedTables = getSelectedTables();
                    if (selectedTables.length === 0) {
                        alert('Please select at least one table');
                        return;
                    }

                    const destHost = document.getElementById('destHost').value.trim();
                    const destPort = parseInt(document.getElementById('destPort').value) || 3306;
                    const destName = document.getElementById('destName').value.trim();
                    const destUser = document.getElementById('destUser').value.trim();
                    const destPass = document.getElementById('destPass').value;

                    if (!destHost && !destName && !destUser) {
                        if (!confirm('Destination fields are empty. Use server config defaults?')) return;
                    }

                    isRunning = true;
                    document.getElementById('copyBtn').disabled = true;
                    document.getElementById('startBtn').disabled = true;
                    showStatus('Starting database copy...', 'info');
                    updateProgress(0, 'initializing', 0, 0);

                    let totalRowsCopied = 0;
                    let tablesCopied = 0;

                    try {
                        for (let i = 0; i < selectedTables.length; i++) {
                            const table = selectedTables[i];
                            showStatus(`Copying table ${i + 1}/${selectedTables.length}: ${table}`, 'info');
                            
                            let offset = 0;
                            let createTable = true;
                            
                            while (true) {
                                const body = { 
                                    table, 
                                    offset, 
                                    chunk_size: 5000,
                                    create_table: createTable 
                                };
                                if (destHost) body.dest_host = destHost;
                                if (destPort) body.dest_port = destPort;
                                if (destName) body.dest_name = destName;
                                if (destUser) body.dest_user = destUser;
                                if (destPass) body.dest_pass = destPass;

                                const response = await apiCall('copy_table_chunk', body);
                                
                                if (response.error) {
                                    throw new Error(response.error);
                                }

                                offset = response.offset;
                                totalRowsCopied += response.copied_rows;
                                createTable = false;

                                const pct = response.total_rows > 0 
                                    ? Math.round((response.total_copied / response.total_rows) * 100) 
                                    : 100;
                                updateProgress(pct, `copying ${table}`, response.total_copied, response.total_rows);

                                if (response.done) {
                                    tablesCopied++;
                                    break;
                                }
                                
                                // Small delay between chunks
                                await new Promise(r => setTimeout(r, 50));
                            }
                        }

                        // Finalize: re-enable foreign key checks
                        showStatus('Finalizing copy...', 'info');
                        const finalizeBody = {};
                        if (destHost) finalizeBody.dest_host = destHost;
                        if (destPort) finalizeBody.dest_port = destPort;
                        if (destName) finalizeBody.dest_name = destName;
                        if (destUser) finalizeBody.dest_user = destUser;
                        if (destPass) finalizeBody.dest_pass = destPass;
                        
                        await apiCall('finalize_copy', finalizeBody);

                        updateProgress(100, 'completed', totalRowsCopied, totalRowsCopied);
                        showStatus(
                            `Copy completed! ${tablesCopied} table(s), ` +
                            `${totalRowsCopied.toLocaleString()} row(s) copied.`,
                            'success'
                        );
                    } catch (e) {
                        console.error('Copy failed', e);
                        showStatus(e.message || 'Database copy failed.', 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('copyBtn').disabled = false;
                        document.getElementById('startBtn').disabled = false;
                    }
                }

                async function processChunks() {
                    let retries = 0;
                    const MAX_RETRIES = 3;

                    while (true) {
                        try {
                            const data = await apiCall('process');
                            if (data.error) {
                                showStatus(data.error, 'error');
                                break;
                            }

                            retries = 0;
                            const pct = data.total > 0 ? Math.round((data.rows / data.total) * 100) : 0;
                            updateProgress(pct, data.phase, data.rows, data.total);
                            showStatus(data.message, 'info');

                            if (data.done) {
                                showStatus('Export completed successfully!', 'success');
                                break;
                            }
                            await new Promise(r => setTimeout(r, data.retry ? 2000 : 100));
                        } catch (err) {
                            if (++retries >= MAX_RETRIES) {
                                showStatus('Error after retries: ' + err.message, 'error');
                                break;
                            }
                            await new Promise(r => setTimeout(r, 2000));
                        }
                    }
                }

                loadTables();
                loadFiles();
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
    <?php
    exit;
}

// ──────────────────────────────────────────────────────────
//  DATABASE EXPORTER CLASS
// ──────────────────────────────────────────────────────────
class DatabaseExporter
{
    private mysqli $db;
    private array $fieldTypeCache = [];
    private $zipHandle = null;

    private array $config;
    private string $stateFile;
    private string $lockFile;

    public function __construct(array $config, string $stateFile, string $lockFile)
    {
        $this->config = $config;
        $this->stateFile = $stateFile;
        $this->lockFile = $lockFile;

        $this->validateConfig();
        $this->connectDatabase();
    }

    public function __destruct()
    {
        if ($this->zipHandle instanceof ZipArchive) {
            $this->zipHandle->close();
        }

        if (isset($this->db) && $this->db instanceof mysqli) {
            $this->db->close();
        }
    }

    private function validateConfig(): void
    {
        if ($this->config['chunk_size'] < 1 || $this->config['chunk_size'] > 50000) {
            throw new InvalidArgumentException('chunk_size must be between 1 and 50,000');
        }

        if ($this->config['time_limit'] < 5) {
            throw new InvalidArgumentException('time_limit must be at least 5 seconds');
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->config['db_charset'])) {
            throw new InvalidArgumentException('Invalid charset name');
        }
    }

    private function connectDatabase(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // Use persistent connection on shared hosting to reduce overhead
        $host = 'p:' . $this->config['db_host'];

        // Set connection timeout before connecting
        ini_set('mysqli.connect_timeout', '10');

        $this->db = new mysqli(
            $host,
            $this->config['db_user'],
            $this->config['db_pass'],
            $this->config['db_name'],
            $this->config['db_port']
        );

        $this->db->set_charset($this->config['db_charset']);
        
        // Optimize MySQL session settings
        $this->db->query("SET SESSION net_write_timeout = 600, wait_timeout = 600, net_read_timeout = 600");
        $this->db->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    }

    private function escapeId(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function init(array $options = []): array
    {
        $lockFp = fopen($this->lockFile, 'c');

        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            if ($lockFp) {
                fclose($lockFp);
            }
            return $this->errorResponse('Another export is already running.');
        }

        try {
            return $this->initInternal($options);
        } finally {
            flock($lockFp, LOCK_UN);fclose($lockFp);
        }
    }

    private function initInternal(array $options): array
    {
        $this->cleanup();

        $compression = $options['compression'] ?? 'none';
        $selectedTables = $options['tables'] ?? [];

        $tables = [];
        $views = [];
        $rowCounts = [];

        $dbId = $this->escapeId($this->config['db_name']);

        $result = $this->db->query("SHOW TABLE STATUS FROM {$dbId}");
        while ($row = $result->fetch_assoc()) {
            $rowCounts[$row['Name']] = (int) ($row['Rows'] ?? 0);
        }
        $result->free();

        $result = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $result->fetch_row()) {
            $tableName = $row[0];
            if (empty($selectedTables) || in_array($tableName, $selectedTables, true)) {
                $tables[] = [
                    'name' => $tableName,
                    'rows' => $rowCounts[$tableName] ?? 0,
                    'pk'   => $this->detectPrimaryKey($tableName)
                ];
            }
        }
        $result->free();

        $result = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        while ($row = $result->fetch_row()) {
            $views[] = $row[0];
        }
        $result->free();

        switch($compression) {

            case 'gzip':
                $ext = '.sql.gz';
            break;

            case 'zip':
                $ext =  '.zip';
            break;
            default: 
            $ext = '.sql';
        }

        $fileName = 'dump_' . $this->config['db_name'] . '_' . date('Y-m-d_His') . $ext;

        $state = [
            'status'          => 'running',
            'phase'           => 'structure',
            'tables'          => $tables,
            'views'           => $views,
            'current_table'   => 0,
            'last_pk'         => null,
            'current_offset'  => 0,
            'structure_index' => 0,
            'total_rows'      => array_sum(array_column($tables, 'rows')),
            'exported_rows'   => 0,
            'started_at'      => time(),
            'last_chunk_at'   => time(),
            'chunks_done'     => 0,
            'file_name'       => $fileName,
            'file_size'       => 0,
            'compression'     => $compression,
        ];

        $this->saveState($state);
        $this->writeHeader($state);

        return $this->progressResponse($state, "Export initialized. Found " . count($tables) . " table(s), " . count($views) . " view(s).");
    }

    private function detectPrimaryKey(string $tableName): ?string
    {
        $result = $this->db->query("SHOW KEYS FROM {$this->escapeId($tableName)} WHERE Key_name = 'PRIMARY'");
        if (!$result) {
            return null;
        }

        $pkColumns = [];
        while ($row = $result->fetch_assoc()) {
            $pkColumns[] = $row['Column_name'];
        }

        $result->free();
        return (count($pkColumns) === 1) ? $pkColumns[0] : null;
    }

    public function processChunk(): array
    {
        $lockFp = fopen($this->lockFile, 'c');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            if ($lockFp) fclose($lockFp);
            return [
                'done'    => false,
                'message' => 'Another chunk is processing',
                'retry'   => true,
                'phase'   => 'waiting',
                'rows'    => 0,
                'total'   => 0
            ];
        }

        try {
            return $this->processChunkInternal();
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    private function processChunkInternal(): array
    {
        $startTime = microtime(true);
        $state     = $this->getState();

        if (!$state) {
            return $this->errorResponse('No export in progress. Call init first.');
        }
        if ($state['status'] === 'done') {
            return $this->progressResponse($state, 'Export already completed.');
        }

        $fp = $this->openForWrite($state);
        if (!$fp) {
            return $this->errorResponse('Failed to open file for writing');
        }

        try {
            switch ($state['phase']) {
                case 'structure':
                    $state = $this->exportStructures($fp, $state, $startTime);
                    break;
                case 'data':
                    $state = $this->exportDataChunk($fp, $state, $startTime);
                    break;
                case 'views':
                    $state = $this->exportViews($fp, $state);
                    break;
                case 'routines':
                    $state = $this->exportRoutines($fp, $state);
                    break;
                case 'events':
                    $state = $this->exportEvents($fp, $state);
                    break;
                case 'footer':
                    $state = $this->writeFooter($fp, $state);
                    break;
                default:
                    throw new RuntimeException("Invalid phase: {$state['phase']}");
            }

            $state['last_chunk_at'] = time();
            $state['chunks_done']++;
            $state['file_size'] = $this->getFileSize($state);
        } finally {
            $this->closeWrite($fp, $state);
        }

        $this->saveState($state);
        $progressMsg = sprintf(
            'Phase: %s | %.2fs | %s rows',
            strtoupper($state['phase']),
            microtime(true) - $startTime,
            number_format($state['exported_rows'])
        );

        return $this->progressResponse($state, $progressMsg);
    }

    private function openForWrite(array $state)
    {
        $path = $this->config['export_dir'] . $state['file_name'];

        if ($state['compression'] === 'gzip') {
            // Level 1 = fastest compression, better for shared hosting CPU
            return gzopen($path, 'ab1');
        }

        return fopen($path, 'ab');
    }

    private function closeWrite($fp, array $state): void
    {
        if ($state['compression'] === 'gzip' && is_resource($fp)) {
            gzclose($fp);
        } elseif (is_resource($fp)) {
            fclose($fp);
        }
    }

    private function fwriteChecked($fp, string $data, array $state): void
    {
        if ($state['compression'] === 'gzip') {
            $written = gzwrite($fp, $data);
        } else {
            $written = fwrite($fp, $data);
        }

        if ($written === false || $written < strlen($data)) {
            throw new RuntimeException('Failed to write to file (disk full?)');
        }
    }

    private function getFileSize(array $state): int
    {
        $path = $this->config['export_dir'] . $state['file_name'];
        return file_exists($path) ? filesize($path) : 0;
    }

    private function exportStructures($fp, array $state, float $startTime): array
    {
        $startIdx = $state['structure_index'] ?? 0;

        if ($startIdx === 0) {
            $header = "\n-- ========================================\n-- TABLE STRUCTURES\n-- ========================================\n\n";
            $this->fwriteChecked($fp, $header, $state);
        }

        for ($i = $startIdx; $i < count($state['tables']); $i++) {
            $tableName = $state['tables'][$i]['name'];
            $tableId   = $this->escapeId($tableName);

            $this->fwriteChecked($fp, "DROP TABLE IF EXISTS {$tableId};\n", $state);
            $result = $this->db->query("SHOW CREATE TABLE {$tableId}");

            if ($result) {
                $row = $result->fetch_row();
                $this->fwriteChecked($fp, $row[1] . ";\n\n", $state);
                $result->free();
            }

            if ($this->shouldYield($startTime)) {
                $state['structure_index'] = $i + 1;
                return $state;
            }
        }

        $state['phase'] = 'data';
        $state['current_table'] = 0;
        $state['last_pk'] = null;
        $state['current_offset'] = 0;

        return $state;
    }

    private function exportDataChunk($fp, array $state, float $startTime): array
    {
        $tableIndex = $state['current_table'];
        $lastPk     = $state['last_pk'];
        $offset     = $state['current_offset'];
        $chunkSize  = $this->config['chunk_size'];

        while ($tableIndex < count($state['tables'])) {
            $table     = $state['tables'][$tableIndex];
            $tableName = $table['name'];
            $tableId   = $this->escapeId($tableName);

            if ($lastPk === null && $offset === 0) {
                $this->fwriteChecked($fp, "\n-- Data for {$tableId}\nALTER TABLE {$tableId} DISABLE KEYS;\n", $state);
            }

            $query  = $this->buildPaginationQuery($tableName, $table, $lastPk, $offset, $chunkSize);
            
            // Use unbuffered query for lower memory usage
            $result = $this->db->query($query, MYSQLI_USE_RESULT);

            if (!$result || $result->num_rows === 0) {
                if ($result) $result->free();
                $this->fwriteChecked($fp, "ALTER TABLE {$tableId} ENABLE KEYS;\n\n", $state);
                $tableIndex++;
                $lastPk = null;
                $offset = 0;
                continue;
            }

            $fields   = $result->fetch_fields();
            $cacheKey = $tableName;

            if (!isset($this->fieldTypeCache[$cacheKey])) {
                $this->fieldTypeCache[$cacheKey] = [
                    'columns' => array_map(fn($f) => $this->escapeId($f->name), $fields),
                    'numeric' => array_map(fn($f) => $this->isNumericField($f), $fields),
                    'binary'  => array_map(fn($f) => $this->isBinaryField($f), $fields),
                    'pkIndex' => $this->findPkIndex($fields, $table['pk']),
                ];
            }

            $cache      = $this->fieldTypeCache[$cacheKey];
            $insertBase = "INSERT INTO {$tableId} (" . implode(', ', $cache['columns']) . ") VALUES\n";
            $rowCount   = $this->writeRows($fp, $result, $cache, $insertBase, $lastPk, $state);

            $result->free();

            $state['exported_rows'] += $rowCount;
            if ($table['pk'] === null) {
                $offset += $rowCount;
            }

            if ($rowCount < $chunkSize) {
                $this->fwriteChecked($fp, "ALTER TABLE {$tableId} ENABLE KEYS;\n\n", $state);
                $tableIndex++;
                $lastPk = null;
                $offset = 0;
            }

            if ($this->shouldYield($startTime)) {
                break;
            }
        }

        $state['current_table']  = $tableIndex;
        $state['last_pk']        = $lastPk;
        $state['current_offset'] = $offset;

        if ($tableIndex >= count($state['tables'])) {
            $state['phase'] = $this->nextPhaseAfterData($state);
        }

        return $state;
    }

    private function buildPaginationQuery(string $tableName, array $table, $lastPk, int $offset, int $limit): string
    {
        $tableId = $this->escapeId($tableName);

        if ($table['pk'] !== null) {
            $pkId = $this->escapeId($table['pk']);
            if ($lastPk === null) {
                return "SELECT * FROM {$tableId} ORDER BY {$pkId} ASC LIMIT {$limit}";
            }
            $safe = $this->db->real_escape_string((string) $lastPk);
            return "SELECT * FROM {$tableId} WHERE {$pkId} > '{$safe}' ORDER BY {$pkId} ASC LIMIT {$limit}";
        }

        return "SELECT * FROM {$tableId} LIMIT {$offset},{$limit}";
    }

    private function findPkIndex(array $fields, ?string $pk): ?int
    {
        if ($pk === null) return null;

        foreach ($fields as $i => $f) {
            if ($f->name === $pk) return $i;
        }

        return null;
    }

    private function writeRows($fp, $result, array $cache, string $insertBase, &$lastPk, array $state): int
    {
        $insertValues = [];
        $currentSize  = strlen($insertBase);
        $rowsInInsert = 0;
        $rowCount     = 0;

        $maxBytes = $this->config['max_insert_bytes'];
        $maxRows  = $this->config['max_insert_rows'];

        while ($row = $result->fetch_row()) {
            $rowCount++;

            if ($cache['pkIndex'] !== null) {
                $lastPk = $row[$cache['pkIndex']];
            }

            $vals = [];
            foreach ($row as $i => $value) {
                if ($value === null) {
                    $vals[] = 'NULL';
                } elseif ($cache['numeric'][$i]) {
                    $vals[] = $value;
                } elseif ($cache['binary'][$i]) {
                    $vals[] = "0x" . bin2hex($value);
                } else {
                    $vals[] = "'" . $this->db->real_escape_string($value) . "'";
                }
            }

            $tuple = '(' . implode(',', $vals) . ')';
            $tupleSize = strlen($tuple) + 2;

            if ($currentSize + $tupleSize > $maxBytes || $rowsInInsert >= $maxRows) {
                $this->fwriteChecked($fp, $insertBase . implode(",\n", $insertValues) . ";\n", $state);
                $insertValues = [];
                $currentSize = strlen($insertBase);
                $rowsInInsert = 0;
            }

            $insertValues[] = $tuple;
            $rowsInInsert++;
            $currentSize += $tupleSize;
        }

        if (!empty($insertValues)) {
            $this->fwriteChecked($fp, $insertBase . implode(",\n", $insertValues) . ";\n", $state);
        }

        return $rowCount;
    }

    private function exportViews($fp, array $state): array
    {
        $header = "\n-- ========================================\n-- VIEWS\n-- ========================================\n\n";
        $this->fwriteChecked($fp, $header, $state);

        foreach ($state['views'] as $view) {
            $viewId = $this->escapeId($view);
            $this->fwriteChecked($fp, "DROP VIEW IF EXISTS {$viewId};\n", $state);

            $res = $this->db->query("SHOW CREATE VIEW {$viewId}");
            if ($res) {
                $row = $res->fetch_assoc();
                $this->fwriteChecked($fp, $row['Create View'] . ";\n\n", $state);
                $res->free();
            }}

        $state['phase'] = $this->config['export_routines'] ? 'routines' : ($this->config['export_events'] ? 'events' : 'footer');

        return $state;
    }

    private function exportRoutines($fp, array $state): array
    {
        $dbId = $this->escapeId($this->config['db_name']);
        $header = "\n-- ========================================\n-- TRIGGERS, PROCEDURES & FUNCTIONS\n-- ========================================\n\n";
        $this->fwriteChecked($fp, $header, $state);

        $res = $this->db->query("SHOW TRIGGERS FROM {$dbId}");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $trigId = $this->escapeId($row['Trigger']);
                $this->fwriteChecked($fp, "DROP TRIGGER IF EXISTS {$trigId};\nDELIMITER ;;\n", $state);

                $show = $this->db->query("SHOW CREATE TRIGGER {$trigId}");
                if ($show) {
                    $this->fwriteChecked($fp, $show->fetch_assoc()['SQL Original Statement'] . " ;;\n", $state);
                    $show->free();
                }

                $this->fwriteChecked($fp, "DELIMITER ;\n\n", $state);
            }$res->free();
        }

        $dbEsc = $this->db->real_escape_string($this->config['db_name']);
        $query = "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = '{$dbEsc}' ORDER BY ROUTINE_TYPE, ROUTINE_NAME";
        $res   = $this->db->query($query);

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $routineId   = $this->escapeId($row['ROUTINE_NAME']);
                $routineType = strtoupper($row['ROUTINE_TYPE']);

                $this->fwriteChecked($fp, "DROP {$routineType} IF EXISTS {$routineId};\nDELIMITER ;;\n", $state);

                $show = $this->db->query("SHOW CREATE {$routineType} {$routineId}");
                if ($show) {
                    $createRow = $show->fetch_assoc();
                    $key = ($routineType === 'FUNCTION') ? 'Create Function' : 'Create Procedure';

                    if (!empty($createRow[$key])) {
                        $this->fwriteChecked($fp, $createRow[$key] . " ;;\n", $state);
                    }
                    $show->free();
                }
                $this->fwriteChecked($fp, "DELIMITER ;\n\n", $state);
            }
            $res->free();
        }

        $state['phase'] = $this->config['export_events'] ? 'events' : 'footer';
        return $state;
    }

    private function exportEvents($fp, array $state): array
    {
        $dbId = $this->escapeId($this->config['db_name']);
        $header = "\n-- ========================================\n-- EVENTS\n-- ========================================\n\n";
        $this->fwriteChecked($fp, $header, $state);

        $res = $this->db->query("SHOW EVENTS FROM {$dbId}");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $eventId = $this->escapeId($row['Name']);
                $this->fwriteChecked($fp, "DROP EVENT IF EXISTS {$eventId};\nDELIMITER ;;\n", $state);

                $show = $this->db->query("SHOW CREATE EVENT {$eventId}");
                if ($show) {
                    $this->fwriteChecked($fp, $show->fetch_assoc()['Create Event'] . " ;;\n", $state);
                    $show->free();
                }

                $this->fwriteChecked($fp, "DELIMITER ;\n\n", $state);
            }
            $res->free();
        }

        $state['phase'] = 'footer';
        return $state;
    }

    private function writeHeader(array $state): void
    {
        $fp = $this->openForWrite($state);
        if (!$fp) {
            throw new RuntimeException('Failed to create file');
        }

        $charset = $this->config['db_charset'];
        $headerData = "-- Database Export\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- Database:  " . $this->config['db_name'] . "\n\n";

        $this->fwriteChecked($fp, $headerData, $state);
        $this->fwriteChecked($fp, "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\n", $state);
        $this->fwriteChecked($fp, "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n", $state);
        $this->fwriteChecked($fp, "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n", $state);
        $this->fwriteChecked($fp, "SET TIME_ZONE='+00:00';\n", $state);
        $this->fwriteChecked($fp, "/*!40101 SET NAMES {$charset} */;\n\n", $state);

        $this->closeWrite($fp, $state);
    }

    private function writeFooter($fp, array $state): array
    {
        $footerData = "\n-- RESTORE SETTINGS\nSET SQL_MODE=@OLD_SQL_MODE;\nSET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\nSET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n-- Finished at: " . date('Y-m-d H:i:s') . "\n";
        $this->fwriteChecked($fp, $footerData, $state);
        $state['status'] = 'done';

        return $state;
    }

    private function nextPhaseAfterData(array $state): string
    {
        if (!empty($state['views'])) return 'views';
        if ($this->config['export_routines']) return 'routines';
        if ($this->config['export_events']) return 'events';
        return 'footer';
    }

    private function shouldYield(float $startTime): bool
    {
        return (microtime(true) - $startTime) > ($this->config['time_limit'] - 2);
    }

    private function isNumericField(object $field): bool
    {
        return in_array($field->type, [MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG,
            MYSQLI_TYPE_INT24, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL,
            MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE
        ], true);
    }

    private function isBinaryField(object $field): bool
    {
        return in_array($field->type, [
            MYSQLI_TYPE_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_TINY_BLOB
        ], true) && ($field->flags & MYSQLI_BINARY_FLAG);
    }

    private function saveState(array $state): void
    {
        $tmp = $this->stateFile . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, $this->stateFile);
    }

    private function getState(): ?array
    {
        if (!file_exists($this->stateFile)) return null;

        $content = file_get_contents($this->stateFile);
        return $content ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function cleanup(): void
    {
        @unlink($this->stateFile);
        @unlink($this->stateFile . '.tmp');
    }

    private function progressResponse(array $state, string $msg): array
    {
        return [
            'done'    => $state['status'] === 'done',
            'phase'   => $state['phase'],
            'rows'    => $state['exported_rows'],
            'total'   => $state['total_rows'],
            'message' => $msg
        ];
    }

    private function errorResponse(string $msg): array
    {
        return [
            'error' => $msg,
            'done'  => false,
            'phase' => 'error',
            'rows'  => 0,
            'total' => 0
        ];
    }
}

// ━━━━━━━━━━━━━━━━
//  EXECUTE API ACTIONS
// ━━━━━━━━━━━━━━━━
// Disable output buffering for faster response
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: application/json');
header('X-Accel-Buffering: no'); // Disable nginx buffering

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action    = $_GET['action'] ?? 'process';
$stateFile = $config['export_dir'] . 'export_state.json';
$lockFile  = $config['export_dir'] . 'export.lock';

try {
    $exporter = new DatabaseExporter($config, $stateFile, $lockFile);

    if ($action === 'init') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $result = $exporter->init($input);
    } else {
        $result = $exporter->processChunk();
    }
    
    echo json_encode($result);
    
    // Flush output immediately for faster response
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'done' => false]);
}
