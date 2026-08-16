<?php
/**
 * Database Dump & Restore Tool v3.0
 * Fast, Resumable MySQL/MariaDB Exporter & Importer for Shared Hosting & WordPress
 * PHP >= 7.4 Compatibility | GZIP Compression | Chunked Resuming | WP Search & Replace
 */

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  CONFIGURATION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Performance optimizations for shared hosting environments
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '120');

// Attempt to auto-detect WordPress wp-config.php if DB credentials are not set
function detectWpConfigDb(): array
{
    $locations = [
        __DIR__ . '/wp-config.php',
        dirname(__DIR__) . '/wp-config.php',
        dirname(__DIR__, 2) . '/wp-config.php',
    ];

    foreach ($locations as $loc) {
        if (file_exists($loc) && is_readable($loc)) {
            $content = file_get_contents($loc);
            if ($content !== false) {
                $db = [];
                if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"](.*?)['\"]\s*\);/", $content, $m)) $db['name'] = $m[1];
                if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"](.*?)['\"]\s*\);/", $content, $m)) $db['user'] = $m[1];
                if (preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"](.*?)['\"]\s*\);/", $content, $m)) $db['pass'] = $m[1];
                if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.*?)['\"]\s*\);/", $content, $m)) {
                    $hostParts = explode(':', $m[1]);
                    $db['host'] = $hostParts[0];
                    if (!empty($hostParts[1]) && is_numeric($hostParts[1])) $db['port'] = (int)$hostParts[1];
                }
                if (preg_match("/define\s*\(\s*['\"]DB_CHARSET['\"]\s*,\s*['\"](.*?)['\"]\s*\);/", $content, $m)) $db['charset'] = $m[1];

                if (!empty($db['name']) && !empty($db['user'])) {
                    return $db;
                }
            }
        }
    }

    return [];
}

$wpDb = detectWpConfigDb();

$config = [
    // Authentication: you can set a password hash here or in DB_EXPORT_PASSWORD_HASH env var.
    // Example: password_hash('my_secret_pass', PASSWORD_DEFAULT)
    'password_hash'    => getenv('DB_EXPORT_PASSWORD_HASH') ?: '',
    'auth_token'       => getenv('DB_EXPORT_TOKEN') ?: 'MY_SECRET_TOKEN_CHANGE_ME',

    // Database Connection
    'db_host'          => getenv('DB_HOST') ?: getenv('WORDPRESS_DB_HOST') ?: ($wpDb['host'] ?? '127.0.0.1'),
    'db_name'          => getenv('DB_NAME') ?: getenv('WORDPRESS_DB_NAME') ?: ($wpDb['name'] ?? ''),
    'db_user'          => getenv('DB_USER') ?: getenv('WORDPRESS_DB_USER') ?: ($wpDb['user'] ?? ''),
    'db_pass'          => getenv('DB_PASS') ?: getenv('WORDPRESS_DB_PASSWORD') ?: ($wpDb['pass'] ?? ''),
    'db_charset'       => $wpDb['charset'] ?? 'utf8mb4',
    'db_port'          => (int)(getenv('DB_PORT') ?: ($wpDb['port'] ?? 3306)),

    // Destination Database (optional for direct copy)
    'dest_db_host'     => getenv('DEST_DB_HOST') ?: '127.0.0.1',
    'dest_db_name'     => getenv('DEST_DB_NAME') ?: '',
    'dest_db_user'     => getenv('DEST_DB_USER') ?: '',
    'dest_db_pass'     => getenv('DEST_DB_PASS') ?: '',
    'dest_db_port'     => (int)(getenv('DEST_DB_PORT') ?: 3306),

    // Chunking & Execution Tuning for Shared Hosting
    'chunk_size'       => 10000,
    'time_limit'       => 25,         // Safe seconds per HTTP request (well below shared host timeouts)
    'max_insert_bytes' => 2097152,    // 2MB
    'max_insert_rows'  => 1000,

    // Storage & Features
    'export_dir'       => __DIR__ . '/db_exports/',
    'export_routines'  => true,
    'export_events'    => true,
    'compression'      => 'gzip',
];

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit($config['time_limit'] + 15);

// Start Session
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

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  AUTHENTICATION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $storedHash = $config['password_hash'] ?: getenv('DB_EXPORT_PASSWORD_HASH');
    
    // If no password hash configured, allow logging in with any password or prompt to set one
    if (empty($storedHash)) {
        // Fallback: If no hash is configured, allow direct setup or authentication
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!password_verify($_POST['password'], $storedHash)) {
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
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 7200) {
            return false;
        }
        $_SESSION['login_time'] = time(); // Refresh session
        return true;
    }

    $authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    return $authHeader !== '' && hash_equals($config['auth_token'], $authHeader);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  FILE MANAGEMENT ENDPOINTS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if (isset($_GET['action']) && in_array($_GET['action'], ['download', 'delete', 'list_files', 'upload_file'], true)) {
    if (!isAuthenticated()) {
        http_response_code(401);
        die(json_encode(['error' => 'Unauthorized']));
    }

    $reqFile = $_GET['file'] ?? '';
    $safeFileName = basename((string)$reqFile);
    $targetPath = $config['export_dir'] . $safeFileName;

    if ($_GET['action'] === 'upload_file') {
        header('Content-Type: application/json');
        if (empty($_FILES['file'])) {
            http_response_code(400);
            die(json_encode(['error' => 'No file uploaded']));
        }
        $up = $_FILES['file'];
        if ($up['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            die(json_encode(['error' => 'Upload error code: ' . $up['error']]));
        }
        $name = basename($up['name']);
        if (!preg_match('/\.(sql|gz|zip)$/i', $name)) {
            http_response_code(400);
            die(json_encode(['error' => 'Only .sql, .gz, and .zip files are allowed']));
        }
        $dest = $config['export_dir'] . $name;
        if (!move_uploaded_file($up['tmp_name'], $dest)) {
            http_response_code(500);
            die(json_encode(['error' => 'Failed to save uploaded file']));
        }
        echo json_encode(['success' => true, 'filename' => $name, 'size' => filesize($dest)]);
        exit;
    }

    if ($_GET['action'] === 'download') {
        if ($safeFileName && file_exists($targetPath) && is_readable($targetPath) && preg_match('/\.(sql|gz|zip)$/i', $safeFileName)) {
            $fileSize = filesize($targetPath);
            $start = 0;
            $end = $fileSize - 1;
            
            if (isset($_SERVER['HTTP_RANGE'])) {
                if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                    $start = intval($matches[1]);
                    if (!empty($matches[2])) {
                        $end = intval($matches[2]);
                    }
                }
            }
            
            if ($start > 0 || $end < $fileSize - 1) {
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            } else {
                header('HTTP/1.1 200 OK');
            }
            
            header('Content-Type: application/octet-stream');
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
        if ($safeFileName && file_exists($targetPath) && preg_match('/\.(sql|gz|zip)$/i', $safeFileName)) {
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
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  GET TABLES LIST
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
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
            }
            $result->free();
        }

        $db->close();
        echo json_encode(['tables' => $tables, 'db_name' => $config['db_name']]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  COPY DATABASE ENDPOINTS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if (isset($_GET['action']) && in_array($_GET['action'], ['copy_table_chunk', 'finalize_copy'], true)) {
    if (!isAuthenticated()) {
        http_response_code(401);
        die(json_encode(['error' => 'Unauthorized']));
    }

    $rawInput = file_get_contents('php://input');
    $payload  = json_decode($rawInput, true);

    if (!is_array($payload)) {
        http_response_code(400);
        die(json_encode(['error' => 'Invalid JSON payload']));
    }

    $destHost = $payload['dest_host'] ?? $config['dest_db_host'];
    $destPort = $payload['dest_port'] ?? $config['dest_db_port'];
    $destName = $payload['dest_name'] ?? $config['dest_db_name'];
    $destUser = $payload['dest_user'] ?? $config['dest_db_user'];
    $destPass = $payload['dest_pass'] ?? $config['dest_db_pass'];

    if ($_GET['action'] === 'copy_table_chunk') {
        $table     = $payload['table'] ?? '';
        $offset    = isset($payload['offset']) ? (int)$payload['offset'] : 0;
        $chunkSize = isset($payload['chunk_size']) ? (int)$payload['chunk_size'] : 5000;

        if (empty($destHost) || empty($destName) || empty($destUser) || empty($table)) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing destination database credentials or table']));
        }

        try {
            $srcDb = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name'], $config['db_port']);
            if ($srcDb->connect_error) throw new RuntimeException('Source DB connection failed: ' . $srcDb->connect_error);
            $srcDb->set_charset($config['db_charset']);

            $destDb = new mysqli($destHost, $destUser, $destPass, $destName, (int)$destPort);
            if ($destDb->connect_error) throw new RuntimeException('Destination DB connection failed: ' . $destDb->connect_error);
            $destDb->set_charset($config['db_charset']);

            $destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 0");
            $destDb->query("SET SESSION UNIQUE_CHECKS = 0");

            $safeTable = '`' . str_replace('`', '``', $table) . '`';

            if ($offset === 0) {
                $res = $srcDb->query("SHOW CREATE TABLE {$safeTable}");
                if (!$res) throw new RuntimeException("Failed to get structure for {$table}: " . $srcDb->error);
                $row = $res->fetch_assoc();
                $createSql = $row['Create Table'] ?? '';
                $res->free();

                $destDb->query("DROP TABLE IF EXISTS {$safeTable}");
                if (!$destDb->query($createSql)) {
                    throw new RuntimeException("Failed to create table {$table}: " . $destDb->error);
                }
            }

            $countRes = $srcDb->query("SELECT COUNT(*) AS total FROM {$safeTable}");
            $rowCount = $countRes ? (int)$countRes->fetch_assoc()['total'] : 0;
            if ($countRes) $countRes->free();

            $copiedRows = 0;
            if ($rowCount > 0 && $offset < $rowCount) {
                $dataResult = $srcDb->query("SELECT * FROM {$safeTable} LIMIT {$chunkSize} OFFSET {$offset}", MYSQLI_USE_RESULT);
                if (!$dataResult) throw new RuntimeException("Failed to read data from {$table}: " . $srcDb->error);

                $columns = [];
                foreach ($dataResult->fetch_fields() as $f) {
                    $columns[] = '`' . str_replace('`', '``', $f->name) . '`';
                }
                $colsStr = implode(', ', $columns);

                $batchSize = 100;
                $batchVals = [];

                while ($row = $dataResult->fetch_row()) {
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = ($v === null) ? 'NULL' : ("'" . $destDb->real_escape_string($v) . "'");
                    }
                    $batchVals[] = '(' . implode(',', $vals) . ')';
                    $copiedRows++;

                    if (count($batchVals) >= $batchSize) {
                        $insertSql = "INSERT INTO {$safeTable} ({$colsStr}) VALUES " . implode(',', $batchVals);
                        if (!$destDb->query($insertSql)) {
                            throw new RuntimeException("Insert failed on {$table}: " . $destDb->error);
                        }
                        $batchVals = [];
                    }
                }

                if (!empty($batchVals)) {
                    $insertSql = "INSERT INTO {$safeTable} ({$colsStr}) VALUES " . implode(',', $batchVals);
                    if (!$destDb->query($insertSql)) {
                        throw new RuntimeException("Insert failed on {$table}: " . $destDb->error);
                    }
                }
                $dataResult->free();
            }

            $srcDb->close();
            $destDb->close();

            $nextOffset = $offset + $chunkSize;
            $done = ($nextOffset >= $rowCount);

            echo json_encode([
                'success'      => true,
                'table'        => $table,
                'offset'       => $nextOffset,
                'total_rows'   => $rowCount,
                'copied_rows'  => $copiedRows,
                'total_copied' => min($nextOffset, $rowCount),
                'done'         => $done
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] === 'finalize_copy') {
        try {
            $destDb = new mysqli($destHost, $destUser, $destPass, $destName, (int)$destPort);
            if (!$destDb->connect_error) {
                $destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 1");
                $destDb->query("SET SESSION UNIQUE_CHECKS = 1");
                $destDb->close();
            }
            echo json_encode(['success' => true, 'message' => 'Foreign key checks re-enabled']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  DATABASE IMPORTER CLASS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class DatabaseImporter
{
    private array $config;
    private string $stateFile;
    private string $lockFile;
    private mysqli $db;

    public function __construct(array $config, string $stateFile, string $lockFile)
    {
        $this->config    = $config;
        $this->stateFile = $stateFile;
        $this->lockFile  = $lockFile;
    }

    private function connect(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->db = new mysqli(
            $this->config['db_host'],
            $this->config['db_user'],
            $this->config['db_pass'],
            $this->config['db_name'],
            $this->config['db_port']
        );

        if ($this->db->connect_error) {
            throw new RuntimeException('Database connection failed: ' . $this->db->connect_error);
        }

        $this->db->set_charset($this->config['db_charset']);
        $this->db->query("SET SESSION FOREIGN_KEY_CHECKS = 0");
        $this->db->query("SET SESSION UNIQUE_CHECKS = 0");
        $this->db->query("SET SESSION SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        $this->db->query("SET SESSION max_allowed_packet = 1073741824");
    }

    public function init(array $options): array
    {
        $fileName = basename($options['file'] ?? '');
        $filePath = $this->config['export_dir'] . $fileName;

        if (!$fileName || !file_exists($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('Dump file not found: ' . htmlspecialchars($fileName));
        }

        $fileSize = filesize($filePath);
        $isGzip   = (bool)preg_match('/\.gz$/i', $fileName);
        $isZip    = (bool)preg_match('/\.zip$/i', $fileName);

        // If ZIP, extract the first .sql file to export_dir
        if ($isZip) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (preg_match('/\.sql$/i', $entry)) {
                        $unzippedName = 'unzipped_' . basename($entry);
                        $zip->extractTo($this->config['export_dir'], $entry);
                        rename($this->config['export_dir'] . $entry, $this->config['export_dir'] . $unzippedName);
                        $fileName = $unzippedName;
                        $filePath = $this->config['export_dir'] . $fileName;
                        $fileSize = filesize($filePath);
                        $isGzip   = false;
                        break;
                    }
                }
                $zip->close();
            }
        }

        $state = [
            'status'           => 'running',
            'file_name'        => $fileName,
            'file_size'        => $fileSize,
            'is_gzip'          => $isGzip,
            'offset'           => 0,
            'queries_count'    => 0,
            'started_at'       => time(),
            'last_chunk_at'    => time(),
            'search_old'       => trim($options['search_old'] ?? ''),
            'search_new'       => trim($options['search_new'] ?? ''),
            'wp_search_replace'=> !empty($options['wp_search_replace']),
        ];

        $this->saveState($state);
        return [
            'done'          => false,
            'phase'         => 'importing',
            'offset'        => 0,
            'total_size'    => $fileSize,
            'percent'       => 0,
            'queries_count' => 0,
            'message'       => "Import initialized for {$fileName} (" . number_format($fileSize / 1048576, 2) . " MB)"
        ];
    }

    public function processChunk(): array
    {
        $state = $this->getState();
        if (!$state) {
            throw new RuntimeException('No import in progress.');
        }

        if ($state['status'] === 'done') {
            return [
                'done'          => true,
                'phase'         => 'completed',
                'offset'        => $state['file_size'],
                'total_size'    => $state['file_size'],
                'percent'       => 100,
                'queries_count' => $state['queries_count'],
                'message'       => 'Import already completed.'
            ];
        }

        $this->connect();
        $startTime = microtime(true);
        $timeLimit = $this->config['time_limit'];

        $filePath = $this->config['export_dir'] . $state['file_name'];
        $isGzip   = $state['is_gzip'];

        $fp = $isGzip ? gzopen($filePath, 'rb') : fopen($filePath, 'rb');
        if (!$fp) {
            throw new RuntimeException('Failed to open file: ' . $state['file_name']);
        }

        // Advance to offset
        if ($state['offset'] > 0) {
            if ($isGzip) {
                gzseek($fp, $state['offset']);
            } else {
                fseek($fp, $state['offset']);
            }
        }

        $buffer    = '';
        $delimiter = ';';
        $inString  = false;
        $stringChar= '';
        $queriesThisChunk = 0;
        $bytesRead = $state['offset'];

        while (!($isGzip ? gzeof($fp) : feof($fp))) {
            $line = $isGzip ? gzgets($fp, 65536) : fgets($fp, 65536);
            if ($line === false) break;

            $bytesRead = $isGzip ? gztell($fp) : ftell($fp);
            $trimmed = trim($line);

            // Handle comments and DELIMITER statements
            if (!$inString && ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0 || strpos($trimmed, '/*') === 0 && substr($trimmed, -2) === '*/')) {
                continue;
            }

            if (!$inString && preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
                $delimiter = trim($m[1]);
                continue;
            }

            $buffer .= $line;

            // Check if delimiter is reached outside of string quotes
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $char = $line[$i];
                $prev = ($i > 0) ? $line[$i - 1] : '';

                if (($char === "'" || $char === '"') && $prev !== '\\') {
                    if (!$inString) {
                        $inString   = true;
                        $stringChar = $char;
                    } elseif ($stringChar === $char) {
                        $inString   = false;
                        $stringChar = '';
                    }
                }
            }

            if (!$inString) {
                $dLen = strlen($delimiter);
                if (substr(rtrim($buffer), -$dLen) === $delimiter) {
                    $sql = rtrim($buffer);
                    $sql = substr($sql, 0, -$dLen);
                    $sql = trim($sql);
                    $buffer = '';

                    if ($sql !== '') {
                        // Apply direct search & replace if requested
                        if ($state['search_old'] !== '' && $state['search_new'] !== '') {
                            $sql = str_replace($state['search_old'], $state['search_new'], $sql);
                        }

                        if (!$this->db->query($sql)) {
                            $err = $this->db->error;
                            // Ignore non-fatal warnings on drop
                            if (!preg_match('/Unknown table|Unknown view|doesn\'t exist/i', $err)) {
                                error_log("SQL Import Notice: {$err} in query: " . substr($sql, 0, 150));
                            }
                        }
                        $state['queries_count']++;
                        $queriesThisChunk++;
                    }
                }
            }

            // Yield if time limit is approaching
            if ((microtime(true) - $startTime) > ($timeLimit - 2)) {
                break;
            }
        }

        $eof = $isGzip ? gzeof($fp) : feof($fp);
        if ($isGzip) {
            gzclose($fp);
        } else {
            fclose($fp);
        }

        $state['offset']        = $bytesRead;
        $state['last_chunk_at'] = time();

        if ($eof) {
            $this->db->query("SET SESSION FOREIGN_KEY_CHECKS = 1");
            $this->db->query("SET SESSION UNIQUE_CHECKS = 1");

            // Perform recursive WordPress search & replace if enabled
            $wpMsg = '';
            if ($state['wp_search_replace'] && $state['search_old'] !== '' && $state['search_new'] !== '') {
                $wpMsg = $this->runWordPressSearchReplace($state['search_old'], $state['search_new']);
            }

            $state['status'] = 'done';
            $this->saveState($state);

            return [
                'done'          => true,
                'phase'         => 'completed',
                'offset'        => $state['file_size'],
                'total_size'    => $state['file_size'],
                'percent'       => 100,
                'queries_count' => $state['queries_count'],
                'message'       => "Import completed! {$state['queries_count']} queries executed. {$wpMsg}"
            ];
        }

        $this->saveState($state);
        $pct = ($state['file_size'] > 0) ? min(99, round(($bytesRead / $state['file_size']) * 100)) : 50;

        return [
            'done'          => false,
            'phase'         => 'importing',
            'offset'        => $bytesRead,
            'total_size'    => $state['file_size'],
            'percent'       => $pct,
            'queries_count' => $state['queries_count'],
            'message'       => "Importing... {$pct}% (" . number_format($bytesRead / 1048576, 1) . " MB / " . number_format($state['file_size'] / 1048576, 1) . " MB, {$state['queries_count']} queries)"
        ];
    }

    private function runWordPressSearchReplace(string $old, string $new): string
    {
        $tables = [];
        $res = $this->db->query("SHOW TABLES");
        if ($res) {
            while ($row = $res->fetch_row()) $tables[] = $row[0];
            $res->free();
        }

        $replacedCount = 0;
        foreach ($tables as $t) {
            $safeT = '`' . str_replace('`', '``', $t) . '`';
            $colsRes = $this->db->query("SHOW COLUMNS FROM {$safeT}");
            if (!$colsRes) continue;

            $pk = null;
            $textCols = [];
            while ($col = $colsRes->fetch_assoc()) {
                if ($col['Key'] === 'PRI') $pk = $col['Field'];
                if (preg_match('/char|text|blob/i', $col['Type'])) {
                    $textCols[] = $col['Field'];
                }
            }
            $colsRes->free();

            if (!$pk || empty($textCols)) continue;

            $safePk = '`' . str_replace('`', '``', $pk) . '`';
            $rowsRes = $this->db->query("SELECT * FROM {$safeT} WHERE 1");
            if (!$rowsRes) continue;

            while ($r = $rowsRes->fetch_assoc()) {
                $updates = [];
                foreach ($textCols as $c) {
                    $val = $r[$c];
                    if ($val === null || strpos($val, $old) === false) continue;

                    $newVal = $this->recursiveReplace($old, $new, $val);
                    if ($newVal !== $val) {
                        $updates[] = '`' . str_replace('`', '``', $c) . "` = '" . $this->db->real_escape_string($newVal) . "'";
                        $replacedCount++;
                    }
                }

                if (!empty($updates)) {
                    $upSql = "UPDATE {$safeT} SET " . implode(', ', $updates) . " WHERE {$safePk} = '" . $this->db->real_escape_string($r[$pk]) . "'";
                    $this->db->query($upSql);
                }
            }
            $rowsRes->free();
        }

        return "WP Search & Replace: {$replacedCount} serialized/string item(s) updated.";
    }

    private function recursiveReplace(string $from, string $to, $data)
    {
        if (is_string($data)) {
            // Check if serialized
            if ($this->isSerialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false || $data === 'b:0;') {
                    $replaced = $this->recursiveReplace($from, $to, $unserialized);
                    return serialize($replaced);
                }
            }
            return str_replace($from, $to, $data);
        }

        if (is_array($data)) {
            $tmp = [];
            foreach ($data as $key => $value) {
                $newKey = is_string($key) ? str_replace($from, $to, $key) : $key;
                $tmp[$newKey] = $this->recursiveReplace($from, $to, $value);
            }
            return $tmp;
        }

        if (is_object($data)) {
            $props = get_object_vars($data);
            foreach ($props as $key => $value) {
                $data->$key = $this->recursiveReplace($from, $to, $value);
            }
            return $data;
        }

        return $data;
    }

    private function isSerialized(string $data): bool
    {
        $data = trim($data);
        if ($data === 'N;') return true;
        if (!preg_match('/^([adObis]):/', $data, $badions)) return false;
        switch ($badions[1]) {
            case 'a':
            case 'O':
            case 's':
                if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) return true;
                break;
            case 'b':
            case 'i':
            case 'd':
                if (preg_match("/^{$badions[1]}:[0-9.E+-]+;\$/", $data)) return true;
                break;
        }
        return false;
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
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  DATABASE EXPORTER CLASS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class DatabaseExporter
{
    private mysqli $db;
    private array $fieldTypeCache = [];
    private array $config;
    private string $stateFile;
    private string $lockFile;

    public function __construct(array $config, string $stateFile, string $lockFile)
    {
        $this->config = $config;
        $this->stateFile = $stateFile;
        $this->lockFile = $lockFile;

        $this->connectDatabase();
    }

    public function __destruct()
    {
        if (isset($this->db) && $this->db instanceof mysqli) {
            $this->db->close();
        }
    }

    private function connectDatabase(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new mysqli(
            $this->config['db_host'],
            $this->config['db_user'],
            $this->config['db_pass'],
            $this->config['db_name'],
            $this->config['db_port']
        );

        $this->db->set_charset($this->config['db_charset']);
        $this->db->query("SET SESSION net_write_timeout = 600, wait_timeout = 600, net_read_timeout = 600");
        $this->db->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    }

    private function escapeId(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function init(array $options = []): array
    {
        $this->cleanup();

        $compression = $options['compression'] ?? 'gzip';
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

        $ext = ($compression === 'gzip') ? '.sql.gz' : '.sql';
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

        return [
            'done'    => false,
            'phase'   => 'structure',
            'rows'    => 0,
            'total'   => $state['total_rows'],
            'message' => "Export initialized for " . count($tables) . " table(s)."
        ];
    }

    private function detectPrimaryKey(string $tableName): ?string
    {
        $result = $this->db->query("SHOW KEYS FROM {$this->escapeId($tableName)} WHERE Key_name = 'PRIMARY'");
        if (!$result) return null;

        $pkColumns = [];
        while ($row = $result->fetch_assoc()) {
            $pkColumns[] = $row['Column_name'];
        }
        $result->free();
        return (count($pkColumns) === 1) ? $pkColumns[0] : null;
    }

    public function processChunk(): array
    {
        $startTime = microtime(true);
        $state     = $this->getState();

        if (!$state) {
            throw new RuntimeException('No export in progress.');
        }
        if ($state['status'] === 'done') {
            return [
                'done'    => true,
                'phase'   => 'completed',
                'rows'    => $state['exported_rows'],
                'total'   => $state['total_rows'],
                'message' => 'Export already completed.'
            ];
        }

        $fp = $this->openForWrite($state);
        if (!$fp) throw new RuntimeException('Failed to open file for writing');

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
            }

            $state['last_chunk_at'] = time();
            $state['chunks_done']++;
            $state['file_size'] = $this->getFileSize($state);
        } finally {
            $this->closeWrite($fp, $state);
        }

        $this->saveState($state);
        $progressMsg = sprintf(
            'Phase: %s | %s rows',
            strtoupper($state['phase']),
            number_format($state['exported_rows'])
        );

        return [
            'done'    => $state['status'] === 'done',
            'phase'   => $state['phase'],
            'rows'    => $state['exported_rows'],
            'total'   => $state['total_rows'],
            'message' => $progressMsg
        ];
    }

    private function openForWrite(array $state)
    {
        $path = $this->config['export_dir'] . $state['file_name'];
        if ($state['compression'] === 'gzip') {
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
        if ($written === false) {
            throw new RuntimeException('Failed to write to dump file.');
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
            $header = "\n-- TABLE STRUCTURES\n\n";
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

            if ((microtime(true) - $startTime) > ($this->config['time_limit'] - 2)) {
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

            if ((microtime(true) - $startTime) > ($this->config['time_limit'] - 2)) {
                break;
            }
        }

        $state['current_table']  = $tableIndex;
        $state['last_pk']        = $lastPk;
        $state['current_offset'] = $offset;

        if ($tableIndex >= count($state['tables'])) {
            $state['phase'] = !empty($state['views']) ? 'views' : ($this->config['export_routines'] ? 'routines' : 'footer');
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
        $maxBytes     = $this->config['max_insert_bytes'];
        $maxRows      = $this->config['max_insert_rows'];

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
        foreach ($state['views'] as $view) {
            $viewId = $this->escapeId($view);
            $this->fwriteChecked($fp, "DROP VIEW IF EXISTS {$viewId};\n", $state);
            $res = $this->db->query("SHOW CREATE VIEW {$viewId}");
            if ($res) {
                $row = $res->fetch_assoc();
                $this->fwriteChecked($fp, $row['Create View'] . ";\n\n", $state);
                $res->free();
            }
        }
        $state['phase'] = $this->config['export_routines'] ? 'routines' : 'footer';
        return $state;
    }

    private function exportRoutines($fp, array $state): array
    {
        $dbEsc = $this->db->real_escape_string($this->config['db_name']);
        $query = "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = '{$dbEsc}'";
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
        $state['phase'] = 'footer';
        return $state;
    }

    private function exportEvents($fp, array $state): array
    {
        $state['phase'] = 'footer';
        return $state;
    }

    private function writeHeader(array $state): void
    {
        $fp = $this->openForWrite($state);
        $charset = $this->config['db_charset'];
        $headerData = "-- Database Export\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- Database: " . $this->config['db_name'] . "\n\n"
            . "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\n"
            . "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n"
            . "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET TIME_ZONE='+00:00';\n"
            . "/*!40101 SET NAMES {$charset} */;\n\n";

        $this->fwriteChecked($fp, $headerData, $state);
        $this->closeWrite($fp, $state);
    }

    private function writeFooter($fp, array $state): array
    {
        $footerData = "\n-- RESTORE SETTINGS\nSET SQL_MODE=@OLD_SQL_MODE;\nSET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\nSET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n-- Finished at: " . date('Y-m-d H:i:s') . "\n";
        $this->fwriteChecked($fp, $footerData, $state);
        $state['status'] = 'done';
        return $state;
    }

    private function isNumericField(object $field): bool
    {
        return in_array($field->type, [
            MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG,
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
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  EXECUTE API ACTIONS (JSON)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if (isset($_GET['action'])) {
    while (ob_get_level() > 0) ob_end_flush();
    header('Content-Type: application/json');
    header('X-Accel-Buffering: no');

    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $action          = $_GET['action'];
    $exportStateFile = $config['export_dir'] . 'export_state.json';
    $exportLockFile  = $config['export_dir'] . 'export.lock';
    $importStateFile = $config['export_dir'] . 'import_state.json';
    $importLockFile  = $config['export_dir'] . 'import.lock';

    try {
        if ($action === 'init') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $exporter = new DatabaseExporter($config, $exportStateFile, $exportLockFile);
            echo json_encode($exporter->init($input));
        } elseif ($action === 'process') {
            $exporter = new DatabaseExporter($config, $exportStateFile, $exportLockFile);
            echo json_encode($exporter->processChunk());
        } elseif ($action === 'init_import') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $importer = new DatabaseImporter($config, $importStateFile, $importLockFile);
            echo json_encode($importer->init($input));
        } elseif ($action === 'process_import') {
            $importer = new DatabaseImporter($config, $importStateFile, $importLockFile);
            echo json_encode($importer->processChunk());
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'done' => false]);
    }
    exit;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  SERVE UI (HTML)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Dump & Restore Tool</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #334155;
        }
        .container {
            max-width: 760px;
            width: 100%;
            background: #ffffff;
            border-radius: 16px;
            padding: 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }
        .subtitle {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 24px;
        }
        .badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .logout {
            color: #ef4444;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }
        .logout:hover { text-decoration: underline; }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 24px;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
            width: auto;
        }
        .tab-btn:hover { color: #0284c7; }
        .tab-btn.active {
            color: #0284c7;
            border-bottom-color: #0284c7;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .input-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        .help-text {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }
        input, select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: #f8fafc;
            color: #0f172a;
            transition: all 0.2s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #0284c7;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }
        button {
            width: 100%;
            padding: 12px 20px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(2, 132, 199, 0.25);
        }
        button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            width: auto;
        }
        .btn-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .btn-secondary { background: #64748b; }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 20px 0 10px 0;
            display: none;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0284c7 0%, #10b981 100%);
            transition: width 0.25s ease;
            width: 0%;
        }
        .status {
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 16px;
            font-size: 13px;
            display: none;
            border-left: 4px solid;
            line-height: 1.5;
        }
        .status.info { background: #f0f9ff; color: #0369a1; border-color: #0284c7; }
        .status.success { background: #f0fdf4; color: #166534; border-color: #22c55e; }
        .status.error { background: #fef2f2; color: #991b1b; border-color: #ef4444; }

        .table-selector {
            margin: 16px 0 20px 0;
            padding: 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
        }
        .table-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px;
            background: #ffffff;
        }
        .table-item {
            padding: 6px 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 4px;
        }
        .table-item:hover { background: #f1f5f9; }
        .table-item input { width: auto; }

        .file-list { list-style: none; margin-top: 16px; }
        .file-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
        }
        .file-info { display: flex; flex-direction: column; }
        .file-name { font-weight: 600; font-size: 13px; color: #0f172a; }
        .file-meta { font-size: 11px; color: #64748b; margin-top: 2px; }
        .file-actions { display: flex; gap: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!isAuthenticated()): ?>
            <div class="header">
                <h1>Database Tool</h1>
                <span class="badge">v3.0</span>
            </div>
            <div class="subtitle">Enter password to manage exports & imports</div>

            <?php if ($loginError): ?>
                <div class="status error" style="display:block; margin-bottom: 16px;">
                    <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autofocus placeholder="Enter password">
                    <?php if (empty($config['password_hash']) && !getenv('DB_EXPORT_PASSWORD_HASH')): ?>
                        <div class="help-text" style="color: #d97706;">* No password hash is currently configured in environment. You can sign in directly or set DB_EXPORT_PASSWORD_HASH.</div>
                    <?php endif; ?>
                </div>
                <button type="submit">Sign In</button>
            </form>
        <?php else: ?>
            <div class="header">
                <h1>
                    Database Tool
                    <span class="badge"><?= htmlspecialchars($config['db_name'] ?: 'No DB Selected') ?></span>
                </h1>
                <a href="?logout=1" class="logout">Logout</a>
            </div>
            <div class="subtitle">Connected to <?= htmlspecialchars($config['db_host']) ?>:<?= $config['db_port'] ?></div>

            <div class="tabs">
                <button type="button" class="tab-btn active" onclick="switchTab('importTab')">Import / Restore</button>
                <button type="button" class="tab-btn" onclick="switchTab('exportTab')">Export Dump</button>
                <button type="button" class="tab-btn" onclick="switchTab('copyTab')">Direct DB Copy</button>
                <button type="button" class="tab-btn" onclick="switchTab('filesTab')">Backup Files</button>
            </div>

            <!-- TAB 1: IMPORT -->
            <div id="importTab" class="tab-content active">
                <div class="input-group">
                    <label for="importFileSelect">Select SQL / GZ Dump File from Server</label>
                    <select id="importFileSelect">
                        <option value="">-- Choose a file from db_exports/ --</option>
                    </select>
                </div>

                <div class="input-group" style="border: 2px dashed #cbd5e1; padding: 16px; border-radius: 8px; text-align: center;">
                    <label style="cursor: pointer; display: block;">
                        <strong>Or Upload New File (.sql, .sql.gz, .zip)</strong>
                        <input type="file" id="uploadFileInput" accept=".sql,.gz,.zip" style="margin-top: 8px;" onchange="uploadDumpFile()">
                    </label>
                    <div id="uploadStatusText" class="help-text">Max file size depends on PHP upload_max_filesize (larger files can be uploaded via FTP directly to db_exports/)</div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 20px;">
                    <label style="font-weight: 700; margin-bottom: 8px;">Domain / URL Search & Replace (Optional):</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label for="searchOld" style="font-size: 12px;">Old URL / String</label>
                            <input type="text" id="searchOld" placeholder="https://tavangarynew.local">
                        </div>
                        <div>
                            <label for="searchNew" style="font-size: 12px;">New URL / String</label>
                            <input type="text" id="searchNew" placeholder="https://tavangary.com">
                        </div>
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 12px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="wpSearchReplace" checked style="width: auto;">
                        Safely update WordPress serialized strings in wp_options and wp_postmeta
                    </label>
                </div>

                <button type="button" id="startImportBtn" class="btn-success" onclick="startImport()">Start Database Import</button>
            </div>

            <!-- TAB 2: EXPORT -->
            <div id="exportTab" class="tab-content">
                <div class="input-group">
                    <label for="compression">Compression Format</label>
                    <select id="compression">
                        <option value="gzip" selected>GZIP (.sql.gz) - Recommended</option>
                        <option value="none">Plain SQL (.sql)</option>
                    </select>
                </div>

                <div class="table-selector">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label style="margin: 0;">Select Tables</label>
                        <div>
                            <button type="button" class="btn-sm btn-secondary" onclick="selectAllTables()">All</button>
                            <button type="button" class="btn-sm btn-secondary" onclick="deselectAllTables()">None</button>
                        </div>
                    </div>
                    <div class="table-list" id="tableList">
                        <div style="padding: 10px; color: #94a3b8; text-align: center;">Loading tables...</div>
                    </div>
                </div>

                <button type="button" id="startExportBtn" onclick="startExport()">Start Database Export</button>
            </div>

            <!-- TAB 3: DIRECT DB COPY -->
            <div id="copyTab" class="tab-content">
                <div class="input-group">
                    <label for="destHost">Destination Host</label>
                    <input type="text" id="destHost" placeholder="127.0.0.1">
                </div>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
                    <div class="input-group">
                        <label for="destName">Destination DB Name</label>
                        <input type="text" id="destName" placeholder="destination_db">
                    </div>
                    <div class="input-group">
                        <label for="destPort">Port</label>
                        <input type="number" id="destPort" value="3306">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="input-group">
                        <label for="destUser">Username</label>
                        <input type="text" id="destUser" placeholder="db_user">
                    </div>
                    <div class="input-group">
                        <label for="destPass">Password</label>
                        <input type="password" id="destPass" placeholder="••••••••">
                    </div>
                </div>
                <button type="button" id="startCopyBtn" onclick="startCopy()">Start Direct Copy</button>
            </div>

            <!-- TAB 4: FILES -->
            <div id="filesTab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 15px;">Files in db_exports/</h3>
                    <button type="button" class="btn-sm btn-secondary" onclick="loadFiles()">Refresh List</button>
                </div>
                <ul class="file-list" id="fileList"></ul>
            </div>

            <!-- SHARED PROGRESS & STATUS -->
            <div class="progress-bar" id="progressBar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="status" id="statusBox"></div>

            <script>
                const API_URL = window.location.pathname;
                let isRunning = false;

                function switchTab(tabId) {
                    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    event.target.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                }

                function formatBytes(bytes) {
                    if (bytes === 0) return '0 Bytes';
                    const k = 1024;
                    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                }

                function showStatus(msg, type = 'info') {
                    const el = document.getElementById('statusBox');
                    el.textContent = msg;
                    el.className = 'status ' + type;
                    el.style.display = 'block';
                }

                function updateProgress(pct) {
                    const bar = document.getElementById('progressBar');
                    const fill = document.getElementById('progressFill');
                    bar.style.display = 'block';
                    fill.style.width = Math.min(100, Math.max(0, pct)) + '%';
                }

                async function apiCall(action, body = null) {
                    const opts = {
                        method: body ? 'POST' : 'GET',
                        headers: { 'Content-Type': 'application/json' }
                    };
                    if (body) opts.body = JSON.stringify(body);
                    const res = await fetch(`${API_URL}?action=${action}`, opts);
                    return await res.json();
                }

                async function loadTables() {
                    try {
                        const data = await apiCall('get_tables');
                        if (data.tables) {
                            const list = document.getElementById('tableList');
                            list.innerHTML = data.tables.map(t => `
                                <div class="table-item">
                                    <input type="checkbox" id="tbl_${t}" value="${t}" checked>
                                    <label for="tbl_${t}">${t}</label>
                                </div>
                            `).join('');
                        }
                    } catch (e) {
                        console.error('Failed to load tables', e);
                    }
                }

                function selectAllTables() {
                    document.querySelectorAll('#tableList input[type="checkbox"]').forEach(c => c.checked = true);
                }
                function deselectAllTables() {
                    document.querySelectorAll('#tableList input[type="checkbox"]').forEach(c => c.checked = false);
                }

                async function loadFiles() {
                    try {
                        const files = await apiCall('list_files');
                        const list = document.getElementById('fileList');
                        const select = document.getElementById('importFileSelect');

                        if (files && files.length > 0) {
                            list.innerHTML = files.map(f => `
                                <li>
                                    <div class="file-info">
                                        <span class="file-name">${f.name}</span>
                                        <span class="file-meta">${formatBytes(f.size)} • ${new Date(f.date * 1000).toLocaleString()}</span>
                                    </div>
                                    <div class="file-actions">
                                        <button class="btn-sm btn-success" onclick="selectForImport('${f.name}')">Restore</button>
                                        <button class="btn-sm" onclick="window.location.href='?action=download&file=${encodeURIComponent(f.name)}'">Download</button>
                                        <button class="btn-sm btn-danger" onclick="deleteFile('${f.name}')">Delete</button>
                                    </div>
                                </li>
                            `).join('');

                            select.innerHTML = '<option value="">-- Choose a file from db_exports/ --</option>' + files.map(f => `
                                <option value="${f.name}">${f.name} (${formatBytes(f.size)})</option>
                            `).join('');
                        } else {
                            list.innerHTML = '<li style="color:#94a3b8; justify-content:center;">No backup files found in db_exports/</li>';
                            select.innerHTML = '<option value="">-- No backup files found --</option>';
                        }
                    } catch (e) {
                        console.error('Failed to load files', e);
                    }
                }

                function selectForImport(filename) {
                    switchTab('importTab');
                    document.querySelectorAll('.tab-btn')[0].classList.add('active');
                    document.getElementById('importFileSelect').value = filename;
                }

                async function deleteFile(filename) {
                    if (!confirm(`Delete ${filename}?`)) return;
                    await apiCall(`delete&file=${encodeURIComponent(filename)}`);
                    loadFiles();
                }

                async function uploadDumpFile() {
                    const fileInput = document.getElementById('uploadFileInput');
                    if (!fileInput.files || fileInput.files.length === 0) return;
                    const file = fileInput.files[0];

                    const formData = new FormData();
                    formData.append('file', file);

                    showStatus(`Uploading ${file.name} (${formatBytes(file.size)})...`, 'info');
                    try {
                        const res = await fetch(`${API_URL}?action=upload_file`, { method: 'POST', body: formData });
                        const data = await res.json();
                        if (data.error) throw new Error(data.error);

                        showStatus(`Upload complete: ${data.filename}`, 'success');
                        await loadFiles();
                        document.getElementById('importFileSelect').value = data.filename;
                    } catch (e) {
                        showStatus('Upload failed: ' + e.message, 'error');
                    }
                }

                // ── IMPORT PROCESS ──
                async function startImport() {
                    if (isRunning) return;
                    const file = document.getElementById('importFileSelect').value;
                    if (!file) {
                        alert('Please select a dump file to import');
                        return;
                    }

                    if (!confirm(`WARNING: Importing ${file} will execute all SQL statements and may overwrite existing tables. Continue?`)) {
                        return;
                    }

                    isRunning = true;
                    document.getElementById('startImportBtn').disabled = true;
                    showStatus('Initializing database import...', 'info');
                    updateProgress(0);

                    try {
                        const init = await apiCall('init_import', {
                            file: file,
                            search_old: document.getElementById('searchOld').value.trim(),
                            search_new: document.getElementById('searchNew').value.trim(),
                            wp_search_replace: document.getElementById('wpSearchReplace').checked
                        });

                        if (init.error) throw new Error(init.error);
                        showStatus(init.message, 'info');

                        while (true) {
                            const chunk = await apiCall('process_import');
                            if (chunk.error) throw new Error(chunk.error);

                            updateProgress(chunk.percent);
                            showStatus(chunk.message, chunk.done ? 'success' : 'info');

                            if (chunk.done) break;
                            await new Promise(r => setTimeout(r, 50));
                        }
                    } catch (err) {
                        showStatus('Import error: ' + err.message, 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('startImportBtn').disabled = false;
                    }
                }

                // ── EXPORT PROCESS ──
                async function startExport() {
                    if (isRunning) return;
                    const selected = Array.from(document.querySelectorAll('#tableList input:checked')).map(c => c.value);
                    if (selected.length === 0) {
                        alert('Please select at least one table');
                        return;
                    }

                    isRunning = true;
                    document.getElementById('startExportBtn').disabled = true;
                    showStatus('Initializing export...', 'info');
                    updateProgress(0);

                    try {
                        const init = await apiCall('init', {
                            compression: document.getElementById('compression').value,
                            tables: selected
                        });
                        if (init.error) throw new Error(init.error);
                        showStatus(init.message, 'info');

                        while (true) {
                            const chunk = await apiCall('process');
                            if (chunk.error) throw new Error(chunk.error);

                            const pct = chunk.total > 0 ? Math.round((chunk.rows / chunk.total) * 100) : 50;
                            updateProgress(pct);
                            showStatus(chunk.message, chunk.done ? 'success' : 'info');

                            if (chunk.done) {
                                loadFiles();
                                break;
                            }
                            await new Promise(r => setTimeout(r, 80));
                        }
                    } catch (err) {
                        showStatus('Export error: ' + err.message, 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('startExportBtn').disabled = false;
                    }
                }

                // ── DIRECT COPY PROCESS ──
                async function startCopy() {
                    if (isRunning) return;
                    const destHost = document.getElementById('destHost').value.trim();
                    const destName = document.getElementById('destName').value.trim();
                    const destUser = document.getElementById('destUser').value.trim();
                    const destPass = document.getElementById('destPass').value;
                    const destPort = parseInt(document.getElementById('destPort').value) || 3306;

                    if (!destHost || !destName || !destUser) {
                        alert('Please fill in Destination Host, Database Name, and Username');
                        return;
                    }

                    const tablesData = await apiCall('get_tables');
                    const tables = tablesData.tables || [];

                    if (tables.length === 0) {
                        alert('No tables found to copy');
                        return;
                    }

                    if (!confirm(`Copy ${tables.length} tables to ${destName} on ${destHost}?`)) return;

                    isRunning = true;
                    document.getElementById('startCopyBtn').disabled = true;
                    showStatus('Starting database copy...', 'info');
                    updateProgress(0);

                    try {
                        let totalCopied = 0;
                        for (let i = 0; i < tables.length; i++) {
                            const table = tables[i];
                            let offset = 0;
                            showStatus(`Copying table (${i + 1}/${tables.length}): ${table}...`, 'info');

                            while (true) {
                                const chunk = await apiCall('copy_table_chunk', {
                                    table, offset, chunk_size: 5000,
                                    dest_host: destHost, dest_port: destPort, dest_name: destName, dest_user: destUser, dest_pass: destPass
                                });
                                if (chunk.error) throw new Error(chunk.error);

                                offset = chunk.offset;
                                totalCopied += chunk.copied_rows;
                                updateProgress(Math.round(((i + 1) / tables.length) * 100));

                                if (chunk.done) break;
                                await new Promise(r => setTimeout(r, 40));
                            }
                        }

                        await apiCall('finalize_copy', { dest_host: destHost, dest_port: destPort, dest_name: destName, dest_user: destUser, dest_pass: destPass });
                        updateProgress(100);
                        showStatus(`Database copy completed! ${tables.length} tables copied.`, 'success');
                    } catch (err) {
                        showStatus('Copy error: ' + err.message, 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('startCopyBtn').disabled = false;
                    }
                }

                loadTables();
                loadFiles();
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
