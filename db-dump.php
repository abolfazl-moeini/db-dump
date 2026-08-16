<?php
/**
 * Database Dump & Restore Tool v3.1
 * Resumable MySQL/MariaDB exporter & importer for shared hosting and WordPress.
 * PHP >= 7.4 | GZIP | Chunked resume | Serialized-safe search & replace
 *
 * SECURITY: set a password on first run, then delete this file and db_exports/
 * when you are finished. Do not leave it on a public server.
 */

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  PURE HELPERS (safe to load from CLI --self-test)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

function detectWpConfigDb(): array
{
    $locations = [
        __DIR__ . '/wp-config.php',
        dirname(__DIR__) . '/wp-config.php',
        dirname(__DIR__, 2) . '/wp-config.php',
    ];

    foreach ($locations as $loc) {
        if (!is_file($loc) || !is_readable($loc)) {
            continue;
        }
        $content = file_get_contents($loc);
        if ($content === false) {
            continue;
        }

        $db = [];
        foreach (['DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASSWORD' => 'pass', 'DB_CHARSET' => 'charset'] as $const => $key) {
            if (preg_match("/define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*['\"](.*?)['\"]\s*\)/s", $content, $m)) {
                $db[$key] = stripcslashes($m[1]);
            }
        }
        if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.*?)['\"]\s*\)/s", $content, $m)) {
            $parsed = parseDbHost(stripcslashes($m[1]));
            $db['host'] = $parsed['host'];
            if ($parsed['port'] !== null) {
                $db['port'] = $parsed['port'];
            }
            if ($parsed['socket'] !== null) {
                $db['socket'] = $parsed['socket'];
            }
        }

        if (!empty($db['name']) && !empty($db['user'])) {
            return $db;
        }
    }

    return [];
}

function parseDbHost(string $raw): array
{
    $raw = trim($raw);
    if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $raw, $m)) {
        return ['host' => $m[1], 'port' => (int) $m[2], 'socket' => null];
    }
    if (preg_match('#^([^:]+):(/[^:]+\.sock)$#', $raw, $m)) {
        return ['host' => $m[1], 'port' => null, 'socket' => $m[2]];
    }
    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/', $raw, $m)) {
        return ['host' => $m[1], 'port' => (int) $m[2], 'socket' => null];
    }
    if (preg_match('/^([a-zA-Z0-9._-]+):(\d+)$/', $raw, $m)) {
        return ['host' => $m[1], 'port' => (int) $m[2], 'socket' => null];
    }
    return ['host' => $raw, 'port' => null, 'socket' => null];
}

function escapeId(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function safeBasename(string $name): string
{
    $name = str_replace(["\0", '\\'], ["", '/'], $name);
    $base = basename($name);
    return str_replace(["\r", "\n", '"', "'"], '', $base);
}

function isAllowedDumpName(string $name): bool
{
    return $name !== '' && $name !== '.' && $name !== '..'
        && strpos($name, '/') === false && strpos($name, '\\') === false
        && preg_match('/\.(sql|gz|zip)$/i', $name)
        && !preg_match('/[^A-Za-z0-9._()+\- ]/', $name);
}

function isUserDumpName(string $name): bool
{
    return isAllowedDumpName($name) && $name !== 'import_work.sql';
}

function stripDefiner(string $sql): string
{
    $cleaned = preg_replace('/\s*DEFINER\s*=\s*(?:`[^`]+`|[\w\-\.]+)\s*@\s*(?:`[^`]+`|[\w\-\.%]+)/i', '', $sql);
    return $cleaned ?? $sql;
}

function fixSqlCollationCompatibility(string $sql): string
{
    $sql = preg_replace('/utf8mb4_0900_bin/i', 'utf8mb4_bin', $sql) ?? $sql;
    return preg_replace('/utf8mb4_0900_[a-z0-9_]+/i', 'utf8mb4_unicode_520_ci', $sql) ?? $sql;
}

function shouldSkipImportSql(string $sql): bool
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return true;
    }
    if (!preg_match('/^\s*(?:\/\*![\d]*\s*)?SET\b/i', $trimmed)) {
        return false;
    }
    return (bool) preg_match('/\bSQL_LOG_BIN\b|\bGTID_PURGED\b|\bMYSQLDUMP_TEMP_LOG_BIN\b/i', $trimmed);
}

function looksLikeSqlErrorIgnorable(string $err, string $sql = ''): bool
{
    if (preg_match('/unknown table|unknown view|does(?:n\'t| not) exist/i', $err)) {
        return (bool) preg_match(
            '/^\s*DROP\s+(?:TABLE|VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\s+IF\s+EXISTS\b/i',
            $sql
        );
    }
    if (preg_match('/SUPER|BINLOG ADMIN/i', $err) && preg_match('/^\s*(?:\/\*![\d]*\s*)?SET\b/i', $sql)) {
        return true;
    }
    return false;
}

/**
 * Incremental SQL splitter that survives chunk boundaries.
 * Handles quotes, doubled quotes, backslash escapes, backticks,
 * -- / # / /* comments, and MySQL /*! executable comments.
 */
class SqlStatementSplitter
{
    public string $buffer = '';
    public string $delimiter = ';';
    public bool $inString = false;
    public string $stringChar = '';
    public bool $inBlockComment = false;
    public bool $inLineComment = false;
    public bool $inBacktick = false;
    public bool $inExecutableComment = false;
    public bool $escape = false;
    private string $pending = '';

    public function toState(): array
    {
        return [
            'buffer' => $this->buffer,
            'delimiter' => $this->delimiter,
            'inString' => $this->inString,
            'stringChar' => $this->stringChar,
            'inBlockComment' => $this->inBlockComment,
            'inLineComment' => $this->inLineComment,
            'inBacktick' => $this->inBacktick,
            'inExecutableComment' => $this->inExecutableComment,
            'escape' => $this->escape,
            'pending' => $this->pending,
        ];
    }

    public function fromState(array $s): void
    {
        $this->buffer = (string) ($s['buffer'] ?? '');
        $this->delimiter = (string) ($s['delimiter'] ?? ';');
        $this->inString = !empty($s['inString']);
        $this->stringChar = (string) ($s['stringChar'] ?? '');
        $this->inBlockComment = !empty($s['inBlockComment']);
        $this->inLineComment = !empty($s['inLineComment']);
        $this->inBacktick = !empty($s['inBacktick']);
        $this->inExecutableComment = !empty($s['inExecutableComment']);
        $this->escape = !empty($s['escape']);
        $this->pending = (string) ($s['pending'] ?? '');
        if ($this->delimiter === '') {
            $this->delimiter = ';';
        }
    }

    public function ingest(string $chunk, bool $final = false): array
    {
        $statements = [];
        if ($this->pending !== '') {
            $chunk = $this->pending . $chunk;
            $this->pending = '';
        }
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            // Fast skip in line comments
            if ($this->inLineComment) {
                $skip = strcspn($chunk, "\n", $i);
                if ($skip > 0) {
                    $i += $skip;
                    if ($i >= $len) {
                        break;
                    }
                }
                $this->inLineComment = false;
                $this->buffer .= "\n";
                continue;
            }

            // Fast skip inside comments up to the next *
            if ($this->inExecutableComment) {
                $skip = strcspn($chunk, '*', $i);
                if ($skip > 0) {
                    $this->buffer .= substr($chunk, $i, $skip);
                    $i += $skip;
                    if ($i >= $len) {
                        break;
                    }
                }
            } elseif ($this->inBlockComment) {
                $skip = strcspn($chunk, '*', $i);
                if ($skip > 0) {
                    $i += $skip;
                    if ($i >= $len) {
                        break;
                    }
                }
            }

            // Fast skip in strings
            if ($this->inString && !$this->escape) {
                $skip = strcspn($chunk, "\\" . $this->stringChar, $i);
                if ($skip > 0) {
                    $this->buffer .= substr($chunk, $i, $skip);
                    $i += $skip;
                    if ($i >= $len) break;
                }
            }

            // Fast skip in backticks
            if ($this->inBacktick) {
                $skip = strcspn($chunk, "`", $i);
                if ($skip > 0) {
                    $this->buffer .= substr($chunk, $i, $skip);
                    $i += $skip;
                    if ($i >= $len) break;
                }
            }

            // Fast skip in normal SQL text
            if (!$this->inString && !$this->inBacktick && !$this->inBlockComment
                && !$this->inLineComment && !$this->inExecutableComment) {
                $specialChars = "'-#/'\"`\r\n" . $this->delimiter;
                $skip = strcspn($chunk, $specialChars, $i);
                if ($skip > 0) {
                    $this->buffer .= substr($chunk, $i, $skip);
                    $i += $skip;
                    if ($i >= $len) break;
                }
            }

            $ch = $chunk[$i];
            $next = ($i + 1 < $len) ? $chunk[$i + 1] : '';

            // Defer ambiguous trailing characters until the next chunk. This
            // keeps comments, doubled quotes, and delimiters intact when a
            // statement boundary falls exactly at the read boundary.
            if (!$final && $i === $len - 1) {
                if (($this->inString && $ch === $this->stringChar)
                    || ($this->inBacktick && $ch === '`')
                    || ($this->inBlockComment && $ch === '*')
                    || ($this->inExecutableComment && $ch === '*')
                    || (!$this->inString && !$this->inBacktick && !$this->inBlockComment
                        && !$this->inExecutableComment && ($ch === '/' || $ch === '-'))) {
                    $this->pending = $ch;
                    continue;
                }
            }

            if ($this->inExecutableComment) {
                $this->buffer .= $ch;
                if ($ch === '*' && $next === '/') {
                    $this->buffer .= $next;
                    $this->inExecutableComment = false;
                    $i++;
                }
                continue;
            }

            if ($this->inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $this->inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if ($this->inBacktick) {
                $this->buffer .= $ch;
                if ($ch === '`') {
                    if ($next === '`') {
                        $this->buffer .= $next;
                        $i++;
                    } else {
                        $this->inBacktick = false;
                    }
                }
                continue;
            }

            if ($this->inString) {
                $this->buffer .= $ch;
                if ($this->escape) {
                    $this->escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $this->escape = true;
                    continue;
                }
                if ($ch === $this->stringChar) {
                    if ($next === $this->stringChar) {
                        $this->buffer .= $next;
                        $i++;
                    } else {
                        $this->inString = false;
                        $this->stringChar = '';
                    }
                }
                continue;
            }

            if ($ch === '-' && $next === '-') {
                if ($i + 2 >= $len && !$final) {
                    $this->pending = substr($chunk, $i);
                    break;
                }
                if ($this->isDashComment($chunk, $i)) {
                    $this->appendCommentSeparator();
                    $this->inLineComment = true;
                    $i++;
                    continue;
                }
            }

            if ($ch === '#') {
                $this->appendCommentSeparator();
                $this->inLineComment = true;
                continue;
            }

            if ($ch === '/' && $next === '*') {
                $third = ($i + 2 < $len) ? $chunk[$i + 2] : '';
                if ($third === '' && !$final) {
                    $this->pending = substr($chunk, $i);
                    break;
                }
                if ($third === '!') {
                    $this->buffer .= '/*!';
                    $this->inExecutableComment = true;
                    $i += 2;
                    continue;
                }
                $this->appendCommentSeparator();
                $this->inBlockComment = true;
                $i++;
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $this->inString = true;
                $this->stringChar = $ch;
                $this->buffer .= $ch;
                continue;
            }

            if ($ch === '`') {
                $this->inBacktick = true;
                $this->buffer .= $ch;
                continue;
            }

            $this->buffer .= $ch;

            if (preg_match('/^DELIMITER\s+/i', ltrim($this->buffer))) {
                if ($ch === "\n") {
                    if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', trim($this->buffer), $m)) {
                        $this->delimiter = $m[1];
                    }
                    $this->buffer = '';
                }
                continue;
            }

            $dLen = strlen($this->delimiter);
            if ($dLen > 0 && substr($this->buffer, -$dLen) === $this->delimiter) {
                $sql = trim(substr($this->buffer, 0, -$dLen));
                $this->buffer = '';
                if ($sql !== '') {
                    $statements[] = $sql;
                }
            }
        }

        return $statements;
    }

    public function finish(): array
    {
        return $this->ingest('', true);
    }

    private function appendCommentSeparator(): void
    {
        if ($this->buffer !== '' && !preg_match('/\s$/', $this->buffer)) {
            $this->buffer .= ' ';
        }
    }

    private function isDashComment(string $chunk, int $i): bool
    {
        $after = $i + 2;
        if ($after >= strlen($chunk)) {
            return true;
        }
        $ch = $chunk[$after];
        return $ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r";
    }
}

/**
 * PHP-serialized-safe search/replace. Walks the serialized format
 * and updates s:N:"..." lengths. Never instantiates objects.
 */
class SerializedSearchReplace
{
    private string $from;
    private string $to;
    public int $count = 0;

    public function __construct(string $from, string $to)
    {
        $this->from = $from;
        $this->to = $to;
    }

    public function replace($value)
    {
        if (!is_string($value) || $value === '' || $this->from === '' || strpos($value, $this->from) === false) {
            return $value;
        }
        if ($this->isSerialized($value)) {
            try {
                $i = 0;
                $out = $this->rewrite($value, $i);
                if ($out !== null && $i === strlen($value)) {
                    return $out;
                }
            } catch (Throwable $e) {
                // Incomplete or invalid payload: leave the original value alone.
            }
            return $value;
        }
        $n = 0;
        $replaced = str_replace($this->from, $this->to, $value, $n);
        $this->count += $n;
        return $replaced;
    }

    public function isSerialized(string $data): bool
    {
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        if (!preg_match('/^([adObisC]):/', $data)) {
            return false;
        }
        return (bool) preg_match('/^[adObisC]:/', $data);
    }

    private function rewrite(string $s, int &$i): string
    {
        if ($i >= strlen($s)) {
            throw new RuntimeException('truncated');
        }
        $slice = substr($s, $i);
        $c = $slice[0];

        if ($c === 'N') {
            $this->expect($s, $i, 'N;');
            return 'N;';
        }
        if ($c === 'b') {
            if (!preg_match('/^b:[01];/', $slice, $m)) {
                throw new RuntimeException('bad bool');
            }
            $i += strlen($m[0]);
            return $m[0];
        }
        if ($c === 'i' || $c === 'd') {
            if (!preg_match('/^' . $c . ':[^;]+;/', $slice, $m)) {
                throw new RuntimeException('bad number');
            }
            $i += strlen($m[0]);
            return $m[0];
        }
        if ($c === 's') {
            if (!preg_match('/^s:(\d+):"/', $slice, $m)) {
                throw new RuntimeException('bad string header');
            }
            $i += strlen($m[0]);
            $len = (int) $m[1];
            if ($i + $len > strlen($s)) {
                throw new RuntimeException('bad string length');
            }
            $data = substr($s, $i, $len);
            $i += $len;
            $this->expect($s, $i, '";');
            $n = 0;
            $data = str_replace($this->from, $this->to, $data, $n);
            $this->count += $n;
            return 's:' . strlen($data) . ':"' . $data . '";';
        }
        if ($c === 'a') {
            if (!preg_match('/^a:(\d+):\{/', $slice, $m)) {
                throw new RuntimeException('bad array');
            }
            $i += strlen($m[0]);
            $n = (int) $m[1];
            $body = '';
            for ($k = 0; $k < $n; $k++) {
                $body .= $this->rewrite($s, $i);
                $body .= $this->rewrite($s, $i);
            }
            $this->expect($s, $i, '}');
            return 'a:' . $n . ':{' . $body . '}';
        }
        if ($c === 'O') {
            if (!preg_match('/^O:(\d+):"/', $slice, $m)) {
                throw new RuntimeException('bad object');
            }
            $i += strlen($m[0]);
            $clsLen = (int) $m[1];
            $cls = substr($s, $i, $clsLen);
            $i += $clsLen;
            $this->expect($s, $i, '":');
            $rest = substr($s, $i);
            if (!preg_match('/^(\d+):\{/', $rest, $m2)) {
                throw new RuntimeException('bad object props');
            }
            $i += strlen($m2[0]);
            $n = (int) $m2[1];
            $body = '';
            for ($k = 0; $k < $n; $k++) {
                $body .= $this->rewrite($s, $i);
                $body .= $this->rewrite($s, $i);
            }
            $this->expect($s, $i, '}');
            return 'O:' . strlen($cls) . ':"' . $cls . '":' . $n . ':{' . $body . '}';
        }
        if ($c === 'C') {
            if (!preg_match('/^C:(\d+):"/', $slice, $m)) {
                throw new RuntimeException('bad custom');
            }
            $i += strlen($m[0]);
            $clsLen = (int) $m[1];
            $cls = substr($s, $i, $clsLen);
            $i += $clsLen;
            $this->expect($s, $i, '":');
            $rest = substr($s, $i);
            if (!preg_match('/^(\d+):\{/', $rest, $m2)) {
                throw new RuntimeException('bad custom body');
            }
            $i += strlen($m2[0]);
            $rawLen = (int) $m2[1];
            $raw = substr($s, $i, $rawLen);
            $i += $rawLen;
            $this->expect($s, $i, '}');
            return 'C:' . strlen($cls) . ':"' . $cls . '":' . $rawLen . ':{' . $raw . '}';
        }
        if ($c === 'r' || $c === 'R') {
            if (!preg_match('/^' . $c . ':\d+;/', $slice, $m)) {
                throw new RuntimeException('bad ref');
            }
            $i += strlen($m[0]);
            return $m[0];
        }

        throw new RuntimeException('unknown token ' . $c);
    }

    private function expect(string $s, int &$i, string $lit): void
    {
        if (substr($s, $i, strlen($lit)) !== $lit) {
            throw new RuntimeException('expected ' . $lit);
        }
        $i += strlen($lit);
    }
}

function runSelfTests(): int
{
    $fail = 0;
    $ok = 0;
    $assert = static function (bool $cond, string $msg) use (&$fail, &$ok): void {
        if ($cond) {
            echo "OK  {$msg}\n";
            $ok++;
        } else {
            echo "FAIL {$msg}\n";
            $fail++;
        }
    };

    $split = new SqlStatementSplitter();
    $stmts = $split->ingest("SELECT 1;\nSELECT 2;\n");
    $assert($stmts === ['SELECT 1', 'SELECT 2'], 'split two statements');

    $split = new SqlStatementSplitter();
    $stmts = $split->ingest("INSERT INTO t VALUES ('a;b');\n");
    $assert($stmts === ["INSERT INTO t VALUES ('a;b')"], 'semicolon inside string');

    $split = new SqlStatementSplitter();
    $stmts = $split->ingest("INSERT INTO t VALUES ('it''s');\n");
    $assert($stmts === ["INSERT INTO t VALUES ('it''s')"], 'doubled quote');

    $split = new SqlStatementSplitter();
    $stmts = $split->ingest("INSERT INTO t VALUES ('foo\\\\');\n");
    $assert(count($stmts) === 1 && substr($stmts[0], -2) === "')", 'escaped backslash then quote');

    $split = new SqlStatementSplitter();
    $part1 = $split->ingest("INSERT INTO t VALUES ('hel");
    $part2 = $split->ingest("lo');\n");
    $assert($part1 === [] && $part2 === ["INSERT INTO t VALUES ('hello')"], 'statement spanning chunks');

    $split = new SqlStatementSplitter();
    $split->ingest("INSERT INTO t VALUES ('hel");
    $restored = new SqlStatementSplitter();
    $restored->fromState($split->toState());
    $assert($restored->ingest("lo');\n") === ["INSERT INTO t VALUES ('hello')"], 'parser state restore');

    $split = new SqlStatementSplitter();
    $out = $split->ingest("DELIMITER ;;\nCREATE PROC p() BEGIN SELECT 1; END;;\nDELIMITER ;\nSELECT 3;\n");
    $assert($out === ['CREATE PROC p() BEGIN SELECT 1; END', 'SELECT 3'], 'delimiter change');

    $split = new SqlStatementSplitter();
    $out = $split->ingest("/*!40101 SET NAMES utf8mb4 */;\n");
    $assert($out === ['/*!40101 SET NAMES utf8mb4 */'], 'executable mysql comment kept');

    $split = new SqlStatementSplitter();
    $out = $split->ingest("SELECT 1 /* ignore ; me */;\n");
    $assert($out === ['SELECT 1'], 'block comment stripped');

    $sr = new SerializedSearchReplace('http://old.test', 'https://new.test');
    $ser = serialize(['url' => 'http://old.test/path', 'n' => 1]);
    $rep = $sr->replace($ser);
    $un = unserialize($rep, ['allowed_classes' => false]);
    $assert(is_array($un) && $un['url'] === 'https://new.test/path' && $un['n'] === 1, 'serialized array replace');

    $obj = new stdClass();
    $obj->home = 'http://old.test';
    $sr = new SerializedSearchReplace('http://old.test', 'https://new.test');
    $rep = $sr->replace(serialize($obj));
    $un = unserialize($rep);
    $assert(is_object($un) && $un->home === 'https://new.test', 'serialized object replace without injection');

    $sr = new SerializedSearchReplace('old.example', 'new.example');
    $assert($sr->replace('visit old.example/path') === 'visit new.example/path', 'plain string replace');

    $nested = serialize(['theme' => ['url' => 'http://old.test', 'more' => ['http://old.test/x']]]);
    $sr = new SerializedSearchReplace('http://old.test', 'https://new.test');
    $nestedOut = unserialize($sr->replace($nested), ['allowed_classes' => false]);
    $assert($nestedOut['theme']['url'] === 'https://new.test' && $nestedOut['theme']['more'][0] === 'https://new.test/x', 'nested serialized replace');

    $assert(!looksLikeSqlErrorIgnorable('Table \'wp_posts\' already exists', 'CREATE TABLE wp_posts'), 'do not ignore already exists');
    $assert(looksLikeSqlErrorIgnorable("Unknown table 'wp_foo'", 'DROP TABLE IF EXISTS wp_foo'), 'ignore missing drop target');
    $assert(!looksLikeSqlErrorIgnorable("Unknown table 'wp_foo'", 'ALTER TABLE wp_foo ADD x INT'), 'do not ignore missing table in other SQL');

    $split = new SqlStatementSplitter();
    $out = $split->ingest("SELECT `col;name` FROM `wp-posts`;\n");
    $assert($out === ['SELECT `col;name` FROM `wp-posts`'], 'semicolon inside backticks');

    $assert(isUserDumpName('dump.sql.gz') && !isUserDumpName('import_work.sql') && !isUserDumpName('../x.sql') && !isUserDumpName('x.php.sql.'), 'dump name allowlist');
    $assert(safeBasename('a/b\\c.sql') === 'c.sql', 'basename traversal stripped');

    $host = parseDbHost('127.0.0.1:3307');
    $assert($host['host'] === '127.0.0.1' && $host['port'] === 3307, 'host:port parse');
    $sock = parseDbHost('localhost:/tmp/mysql.sock');
    $assert($sock['host'] === 'localhost' && $sock['socket'] === '/tmp/mysql.sock', 'socket parse');

    $split = new SqlStatementSplitter();
    $assert($split->ingest('SELECT/* comment */1;') === ['SELECT 1'], 'block comment preserves token boundary');

    $split = new SqlStatementSplitter();
    $split->ingest('SELECT /');
    $assert($split->ingest('* comment */ 1;') === ['SELECT  1'], 'block comment across chunks');

    $split = new SqlStatementSplitter();
    $split->ingest("SELECT 'it'");
    $assert($split->ingest("'s';") === ["SELECT 'it''s'"], 'doubled quote across chunks');

    $split = new SqlStatementSplitter();
    $out = $split->ingest("/*!50000 SET @a=1; SET @b=2 */;");
    $assert($out === ['/*!50000 SET @a=1; SET @b=2 */'], 'executable comment semicolon protected');

    $split = new SqlStatementSplitter();
    $split->ingest("SELECT 1");
    $assert($split->finish() === [], 'finish does not invent a delimiter');

    $split = new SqlStatementSplitter();
    $split->ingest('/*');
    $assert($split->ingest('!40101 SET NAMES utf8mb4 */;') === ['/*!40101 SET NAMES utf8mb4 */'], 'executable comment split after /*');

    $split = new SqlStatementSplitter();
    $split->ingest('SELECT 1');
    $split->ingest('--');
    $assert($split->ingest("foo;\nSELECT 2;") === ['SELECT 1--foo', 'SELECT 2'], '-- without space is not a comment across chunks');

    $sr = new SerializedSearchReplace('old', 'new');
    $assert($sr->replace('s:3:"old') === 's:3:"old', 'do not str_replace truncated serialized data');
    $mixed = serialize('old') . ' old';
    $assert($sr->replace($mixed) === $mixed, 'do not str_replace if serialized parse is incomplete');

    $assert(shouldSkipImportSql('SET @@SESSION.SQL_LOG_BIN= 0'), 'skip SET SQL_LOG_BIN');
    $assert(shouldSkipImportSql('SET @@GLOBAL.GTID_PURGED=/*!80000 \'+\'*/ \'\''), 'skip SET GTID_PURGED');
    $assert(shouldSkipImportSql('SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN'), 'skip SET MYSQLDUMP_TEMP_LOG_BIN');
    $assert(!shouldSkipImportSql('SET FOREIGN_KEY_CHECKS=0'), 'do not skip SET FOREIGN_KEY_CHECKS');
    $assert(shouldSkipImportSql('SET @@sql_log_bin=0'), 'skip SET @@sql_log_bin');
    $assert(shouldSkipImportSql('/*!40101 SET @OLD_SQL_LOG_BIN=@@SQL_LOG_BIN, SQL_LOG_BIN=0 */'), 'skip executable-comment SQL_LOG_BIN');
    $assert(looksLikeSqlErrorIgnorable('Access denied; you need SUPER, BINLOG ADMIN privilege(s)', 'SET @@SESSION.SQL_LOG_BIN= 0'), 'ignore SUPER error on SET');
    $assert(stripDefiner('CREATE DEFINER=`root`@`localhost` VIEW `v` AS SELECT 1') === 'CREATE VIEW `v` AS SELECT 1', 'stripDefiner on view');
    $assert(fixSqlCollationCompatibility('CREATE TABLE t (id INT) COLLATE=utf8mb4_0900_ai_ci') === 'CREATE TABLE t (id INT) COLLATE=utf8mb4_unicode_520_ci', 'convert MySQL 8 collation');
    $assert(fixSqlCollationCompatibility('COLLATE utf8mb4_0900_bin') === 'COLLATE utf8mb4_bin', 'convert MySQL 8 binary collation');

    $split = new SqlStatementSplitter();
    $assert($split->ingest("-- comment\nSELECT 1;") === ['SELECT 1'], 'line comment then statement after fast skip');

    echo "\n{$ok} passed, {$fail} failed\n";
    return $fail === 0 ? 0 : 1;
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--self-test') {
    exit(runSelfTests());
}

if (!extension_loaded('mysqli')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The mysqli PHP extension is required.';
    exit;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  CONFIGURATION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

$wpDb = detectWpConfigDb();
$authFile = __DIR__ . '/db-dump-auth.php';

function loadLocalAuthHash(string $authFile): string
{
    if (!is_file($authFile) || !is_readable($authFile)) {
        return '';
    }
    $data = include $authFile;
    if (is_array($data) && !empty($data['password_hash']) && is_string($data['password_hash'])) {
        return $data['password_hash'];
    }
    return '';
}

function writeLocalAuthHash(string $authFile, string $hash): void
{
    $export = var_export($hash, true);
    $php = <<<PHP
<?php
if (PHP_SAPI !== 'cli' && isset(\$_SERVER['SCRIPT_FILENAME']) && basename(\$_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit;
}
return ['password_hash' => {$export}];

PHP;
    $tmp = $authFile . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false) {
        throw new RuntimeException('Could not write password file.');
    }
    if (!rename($tmp, $authFile)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save password file.');
    }
    @chmod($authFile, 0640);
}

$envHash = getenv('DB_EXPORT_PASSWORD_HASH');
$envToken = getenv('DB_EXPORT_TOKEN');

$config = [
    'password_hash'    => (is_string($envHash) && $envHash !== '') ? $envHash : loadLocalAuthHash($authFile),
    'auth_token'       => (is_string($envToken) && $envToken !== '') ? $envToken : '',
    'db_host'          => getenv('DB_HOST') ?: getenv('WORDPRESS_DB_HOST') ?: ($wpDb['host'] ?? '127.0.0.1'),
    'db_name'          => getenv('DB_NAME') ?: getenv('WORDPRESS_DB_NAME') ?: ($wpDb['name'] ?? ''),
    'db_user'          => getenv('DB_USER') ?: getenv('WORDPRESS_DB_USER') ?: ($wpDb['user'] ?? ''),
    'db_pass'          => getenv('DB_PASS') ?: getenv('WORDPRESS_DB_PASSWORD') ?: ($wpDb['pass'] ?? ''),
    'db_charset'       => $wpDb['charset'] ?? 'utf8mb4',
    'db_port'          => (int) (getenv('DB_PORT') ?: ($wpDb['port'] ?? 3306)),
    'db_socket'        => $wpDb['socket'] ?? null,
    'dest_db_host'     => getenv('DEST_DB_HOST') ?: '127.0.0.1',
    'dest_db_name'     => getenv('DEST_DB_NAME') ?: '',
    'dest_db_user'     => getenv('DEST_DB_USER') ?: '',
    'dest_db_pass'     => getenv('DEST_DB_PASS') ?: '',
    'dest_db_port'     => (int) (getenv('DEST_DB_PORT') ?: 3306),
    'chunk_size'       => 10000,
    'time_limit'       => 28,
    'max_insert_bytes' => 2097152,
    'max_insert_rows'  => 1000,
    'export_dir'       => __DIR__ . '/db_exports/',
    'export_routines'  => true,
    'export_events'    => true,
    'export_triggers'  => true,
    'compression'      => 'gzip',
    'auth_file'        => $authFile,
];

$hostParsed = parseDbHost((string) $config['db_host']);
if ($hostParsed['port'] !== null && !getenv('DB_PORT')) {
    $config['db_host'] = $hostParsed['host'];
    $config['db_port'] = $hostParsed['port'];
}
if ($hostParsed['socket'] !== null && empty($config['db_socket'])) {
    $config['db_host'] = $hostParsed['host'];
    $config['db_socket'] = $hostParsed['socket'];
}

set_time_limit($config['time_limit'] + 30);

function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $fwd === 'https';
}

function scriptName(): string
{
    $name = $_SERVER['SCRIPT_NAME'] ?? '/db-dump.php';
    $base = basename($name);
    return $base !== '' ? $base : 'db-dump.php';
}

function jsonExit($data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dbConnect(array $config, ?string $host = null, ?string $user = null, ?string $pass = null, ?string $name = null, ?int $port = null): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $usingDefaultConnection = $host === null && $user === null && $pass === null && $name === null && $port === null;
    $rawHost = $host ?? $config['db_host'];
    $parsedHost = parseDbHost((string) $rawHost);
    $host = $parsedHost['host'];
    $user = $user ?? $config['db_user'];
    $pass = $pass ?? $config['db_pass'];
    $name = $name ?? $config['db_name'];
    $port = $parsedHost['port'] ?? ($port ?? (int) $config['db_port']);
    $socket = $parsedHost['socket'] ?? ($usingDefaultConnection ? ($config['db_socket'] ?? null) : null);

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Database port must be between 1 and 65535.');
    }

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database credentials are missing. Place this file next to wp-config.php or set DB_HOST/DB_NAME/DB_USER/DB_PASS.');
    }

    $db = @new mysqli($host, $user, $pass, $name, $port, $socket ?: '');
    if ($db->connect_error) {
        throw new RuntimeException('Database connection failed: ' . $db->connect_error);
    }
    $db->set_charset($config['db_charset'] ?: 'utf8mb4');
    return $db;
}

if (session_status() === PHP_SESSION_NONE) {
    $cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    if ($cookiePath === '//') {
        $cookiePath = '/';
    }
    session_start([
        'name'            => 'dbdump_sid',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure'   => isHttps(),
        'cookie_path'     => $cookiePath,
        'use_strict_mode' => true,
    ]);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!is_dir($config['export_dir'])) {
    if (!@mkdir($config['export_dir'], 0750, true) && !is_dir($config['export_dir'])) {
        $loginError = 'Cannot create export directory: ' . $config['export_dir'];
    }
}

function protectExportDir(string $dir): void
{
    $ht = $dir . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $idx = $dir . 'index.php';
    if (!is_file($idx)) {
        @file_put_contents($idx, "<?php http_response_code(403); exit;\n");
    }
    $wc = $dir . 'web.config';
    if (!is_file($wc)) {
        @file_put_contents($wc, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><deny users=\"*\" /></authorization></security></system.webServer></configuration>\n");
    }
}

if (is_dir($config['export_dir'])) {
    protectExportDir($config['export_dir']);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  AUTHENTICATION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$loginError = $loginError ?? '';
$needsSetup = ($config['password_hash'] === '');

function requestHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$key] ?? '');
}

function isTokenAuth(array $config): bool
{
    $token = $config['auth_token'];
    $header = requestHeader('X-Auth-Token');
    return $token !== '' && $header !== '' && hash_equals($token, $header);
}

function isAuthenticated(array $config): bool
{
    if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $idle = 7200;
        $abs = 43200;
        $now = time();
        if (isset($_SESSION['login_time']) && ($now - (int) $_SESSION['login_time']) > $idle) {
            return false;
        }
        if (isset($_SESSION['login_started']) && ($now - (int) $_SESSION['login_started']) > $abs) {
            return false;
        }
        $_SESSION['login_time'] = $now;
        return true;
    }
    return isTokenAuth($config);
}

function requireAuth(array $config): void
{
    if (!isAuthenticated($config)) {
        jsonExit(['error' => 'Unauthorized'], 401);
    }
}

function requireCsrf(array $config): void
{
    if (isTokenAuth($config)) {
        return;
    }
    $sent = requestHeader('X-CSRF-Token');
    if ($sent === '') {
        $sent = (string) ($_POST['csrf_token'] ?? '');
    }
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        jsonExit(['error' => 'Invalid or missing CSRF token'], 403);
    }
}

function requirePost(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        jsonExit(['error' => 'POST required'], 405);
    }
}

function sessionLogin(): void
{
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['login_started'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'POST' && isset($_POST['setup_password'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $loginError = 'Invalid form token. Refresh and try again.';
    } elseif (!$needsSetup) {
        $loginError = 'A password is already configured.';
    } else {
        $pass = (string) $_POST['setup_password'];
        $confirm = (string) ($_POST['setup_password_confirm'] ?? '');
        if (strlen($pass) < 8) {
            $loginError = 'Password must be at least 8 characters.';
        } elseif (!hash_equals($pass, $confirm)) {
            $loginError = 'Passwords do not match.';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                writeLocalAuthHash($config['auth_file'], $hash);
                $config['password_hash'] = $hash;
                $needsSetup = false;
                sessionLogin();
                header('Location: ' . scriptName());
                exit;
            } catch (Throwable $e) {
                $loginError = $e->getMessage();
            }
        }
    }
} elseif ($requestMethod === 'POST' && isset($_POST['password'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $loginError = 'Invalid form token. Refresh and try again.';
    } elseif ($needsSetup) {
        $loginError = 'Set a password first.';
    } elseif (!password_verify((string) $_POST['password'], $config['password_hash'])) {
        $loginError = 'Invalid password.';
        usleep(400000);
    } else {
        sessionLogin();
        header('Location: ' . scriptName());
        exit;
    }
}

if (isset($_GET['logout'])) {
    if ($requestMethod === 'POST') {
        $csrf = (string) ($_POST['csrf_token'] ?? '');
        if ($csrf !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
    }
    header('Location: ' . scriptName());
    exit;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  FILE MANAGEMENT
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$fileActions = ['download', 'delete', 'list_files', 'upload_file', 'upload_chunk'];
if (isset($_GET['action']) && in_array($_GET['action'], $fileActions, true)) {
    requireAuth($config);

    $action = $_GET['action'];
    if (in_array($action, ['delete', 'upload_file', 'upload_chunk'], true)) {
        requirePost();
        requireCsrf($config);
    }

    $reqFile = safeBasename((string) ($_GET['file'] ?? ''));
    $targetPath = $config['export_dir'] . $reqFile;

    if ($action === 'upload_chunk') {
        $name = safeBasename((string) ($_POST['filename'] ?? ''));
        $chunkIndex = (int) ($_POST['chunk_index'] ?? -1);
        $totalChunks = (int) ($_POST['total_chunks'] ?? 0);

        if (!isUserDumpName($name)) {
            jsonExit(['error' => 'Only .sql, .sql.gz, and .zip files with safe names are allowed'], 400);
        }
        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            jsonExit(['error' => 'Invalid chunk index'], 400);
        }
        if (empty($_FILES['chunk'])
            || (($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            || empty($_FILES['chunk']['tmp_name'])
            || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            jsonExit(['error' => 'Missing chunk data'], 400);
        }

        $maxChunkBytes = 16 * 1024 * 1024;
        $maxFileBytes = 2147483647;
        $chunkBytes = (int) filesize($_FILES['chunk']['tmp_name']);
        if ($chunkBytes < 1 || $chunkBytes > $maxChunkBytes) {
            jsonExit(['error' => 'Invalid chunk size'], 400);
        }

        $partPath = $config['export_dir'] . $name . '.part';
        $metaPath = $partPath . '.meta';
        $lockPath = $partPath . '.lock';
        $finalPath = $config['export_dir'] . $name;

        if ($chunkIndex === 0 && is_file($finalPath)) {
            jsonExit(['error' => 'A dump with that name already exists. Delete it first or upload with a new name.'], 409);
        }

        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            jsonExit(['error' => 'Could not lock upload'], 500);
        }

        try {
            if ($chunkIndex === 0) {
                @unlink($partPath);
                file_put_contents($metaPath, '0', LOCK_EX);
            }

            $expected = is_file($metaPath) ? (int) file_get_contents($metaPath) : -1;
            if ($chunkIndex !== $expected) {
                jsonExit(['error' => 'Upload chunks arrived out of order. Start the upload again.'], 409);
            }

            $in = fopen($_FILES['chunk']['tmp_name'], 'rb');
            $out = fopen($partPath, $chunkIndex === 0 ? 'wb' : 'ab');
            if (!$in || !$out) {
                if ($in) {
                    fclose($in);
                }
                if ($out) {
                    fclose($out);
                }
                jsonExit(['error' => 'Could not write upload chunk'], 500);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            clearstatcache(true, $partPath);
            if ((int) filesize($partPath) > $maxFileBytes) {
                @unlink($partPath);
                @unlink($metaPath);
                jsonExit(['error' => 'Upload exceeds the 2 GB limit'], 400);
            }

            file_put_contents($metaPath, (string) ($chunkIndex + 1), LOCK_EX);

            if ($chunkIndex === $totalChunks - 1) {
                if (!@rename($partPath, $finalPath)) {
                    @unlink($partPath);
                    @unlink($metaPath);
                    jsonExit(['error' => 'Could not finalize uploaded file'], 500);
                }
                @unlink($metaPath);
                @chmod($finalPath, 0640);
                jsonExit(['success' => true, 'done' => true, 'filename' => $name, 'size' => filesize($finalPath)]);
            }

            jsonExit(['success' => true, 'done' => false, 'chunk_index' => $chunkIndex]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    if ($action === 'upload_file') {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            jsonExit(['error' => 'No file uploaded'], 400);
        }
        $up = $_FILES['file'];
        if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonExit(['error' => 'Upload error code: ' . (int) $up['error']], 400);
        }
        $name = safeBasename((string) ($up['name'] ?? ''));
        if (!isUserDumpName($name)) {
            jsonExit(['error' => 'Only .sql, .sql.gz, and .zip files with safe names are allowed'], 400);
        }
        $dest = $config['export_dir'] . $name;
        if (is_file($dest)) {
            jsonExit(['error' => 'A dump with that name already exists. Delete it first or upload with a new name.'], 409);
        }
        if (!is_uploaded_file($up['tmp_name']) || !move_uploaded_file($up['tmp_name'], $dest)) {
            jsonExit(['error' => 'Failed to save uploaded file'], 500);
        }
        @chmod($dest, 0640);
        jsonExit(['success' => true, 'filename' => $name, 'size' => filesize($dest)]);
    }

    if ($action === 'download') {
        if (!$reqFile || !isUserDumpName($reqFile) || !is_file($targetPath) || !is_readable($targetPath)) {
            jsonExit(['error' => 'File not found'], 404);
        }
        $fileSize = (int) filesize($targetPath);
        $file = fopen($targetPath, 'rb');
        if ($file === false) {
            jsonExit(['error' => 'Cannot read file'], 500);
        }

        $start = 0;
        $end = $fileSize > 0 ? $fileSize - 1 : 0;

        if ($fileSize === 0) {
            header('HTTP/1.1 200 OK');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $reqFile . '"');
            header('Content-Length: 0');
            header('Accept-Ranges: bytes');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            fclose($file);
            exit;
        }

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $matches)) {
            $start = (int) $matches[1];
            if ($matches[2] !== '') {
                $end = (int) $matches[2];
            }
        }
        if ($start < 0 || $end < $start || $start >= $fileSize) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $fileSize);
            fclose($file);
            exit;
        }
        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }

        if ($start > 0 || $end < $fileSize - 1) {
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        } else {
            header('HTTP/1.1 200 OK');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $reqFile . '"');
        header('Content-Length: ' . ($end - $start + 1));
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        set_time_limit(0);
        fseek($file, $start);
        $remaining = $end - $start + 1;
        while (!feof($file) && $remaining > 0) {
            $chunk = min(8192, $remaining);
            $buf = fread($file, $chunk);
            if ($buf === false) {
                break;
            }
            echo $buf;
            flush();
            $remaining -= strlen($buf);
        }
        fclose($file);
        exit;
    }

    if ($action === 'delete') {
        if (!$reqFile || !isUserDumpName($reqFile) || !is_file($targetPath)) {
            jsonExit(['error' => 'File not found'], 404);
        }
        if (!@unlink($targetPath)) {
            jsonExit(['error' => 'Could not delete file'], 500);
        }
        jsonExit(['success' => true]);
    }

    if ($action === 'list_files') {
        $files = array_merge(
            glob($config['export_dir'] . '*.sql') ?: [],
            glob($config['export_dir'] . '*.gz') ?: [],
            glob($config['export_dir'] . '*.zip') ?: []
        );
        $skip = ['import_work.sql'];
        $result = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (in_array($base, $skip, true) || strpos($base, '.') === 0) {
                continue;
            }
            $result[] = [
                'name' => $base,
                'size' => filesize($file),
                'date' => filemtime($file),
            ];
        }
        usort($result, static function ($a, $b) {
            return $b['date'] <=> $a['date'];
        });
        jsonExit($result);
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  GET TABLES
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if (isset($_GET['action']) && $_GET['action'] === 'get_tables') {
    requireAuth($config);
    try {
        $db = dbConnect($config);
        $tables = [];
        $result = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if (!$result) {
            throw new RuntimeException('Could not list database tables: ' . $db->error);
        }
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        $result->free();
        $db->close();
        jsonExit(['tables' => $tables, 'db_name' => $config['db_name']]);
    } catch (Throwable $e) {
        jsonExit(['error' => $e->getMessage()], 500);
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  COPY DATABASE
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if (isset($_GET['action']) && in_array($_GET['action'], ['copy_table_chunk', 'finalize_copy'], true)) {
    requireAuth($config);
    requirePost();
    requireCsrf($config);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        jsonExit(['error' => 'Invalid JSON payload'], 400);
    }

    $destHost = (string) ($payload['dest_host'] ?? $config['dest_db_host']);
    $destPort = (int) ($payload['dest_port'] ?? $config['dest_db_port']);
    $destName = (string) ($payload['dest_name'] ?? $config['dest_db_name']);
    $destUser = (string) ($payload['dest_user'] ?? $config['dest_db_user']);
    $destPass = (string) ($payload['dest_pass'] ?? $config['dest_db_pass']);

    if ($_GET['action'] === 'copy_table_chunk') {
        $table = (string) ($payload['table'] ?? '');
        $offset = isset($payload['offset']) ? (int) $payload['offset'] : 0;
        $chunkSize = isset($payload['chunk_size']) ? (int) $payload['chunk_size'] : 5000;
        $chunkSize = max(1, min(20000, $chunkSize));
        $offset = max(0, $offset);

        if ($destHost === '' || $destName === '' || $destUser === '' || $table === '') {
            jsonExit(['error' => 'Missing destination database credentials or table'], 400);
        }

        try {
            $srcDb = dbConnect($config);
            $destDb = dbConnect($config, $destHost, $destUser, $destPass, $destName, $destPort);
            if (!$destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 0") || !$destDb->query("SET SESSION UNIQUE_CHECKS = 0")) {
                throw new RuntimeException('Could not configure destination database session: ' . $destDb->error);
            }

            $safeTable = escapeId($table);
            $exists = $srcDb->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tableFound = false;
            if ($exists) {
                while ($row = $exists->fetch_row()) {
                    if ((string) ($row[0] ?? '') === $table) {
                        $tableFound = true;
                        break;
                    }
                }
                $exists->free();
            }
            if (!$tableFound) {
                throw new InvalidArgumentException('Source table not found: ' . $table);
            }

            if ($offset === 0) {
                $res = $srcDb->query("SHOW CREATE TABLE {$safeTable}");
                if (!$res) {
                    throw new RuntimeException("Failed to get structure for {$table}: " . $srcDb->error);
                }
                $row = $res->fetch_assoc();
                $createSql = $row['Create Table'] ?? '';
                $res->free();
                if (!$destDb->query("DROP TABLE IF EXISTS {$safeTable}")) {
                    throw new RuntimeException("Failed to remove destination table {$table}: " . $destDb->error);
                }
                if (!$destDb->query($createSql)) {
                    throw new RuntimeException("Failed to create table {$table}: " . $destDb->error);
                }
            }

            $copiedRows = 0;
            $dataResult = $srcDb->query("SELECT * FROM {$safeTable} LIMIT {$chunkSize} OFFSET {$offset}", MYSQLI_USE_RESULT);
            if (!$dataResult) {
                throw new RuntimeException("Failed to read data from {$table}: " . $srcDb->error);
            }

            $columns = [];
            foreach ($dataResult->fetch_fields() as $f) {
                $columns[] = escapeId($f->name);
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
            if ($batchVals) {
                $insertSql = "INSERT INTO {$safeTable} ({$colsStr}) VALUES " . implode(',', $batchVals);
                if (!$destDb->query($insertSql)) {
                    throw new RuntimeException("Insert failed on {$table}: " . $destDb->error);
                }
            }
            $dataResult->free();
            $srcDb->close();
            $destDb->close();

            $nextOffset = $offset + $copiedRows;
            jsonExit([
                'success' => true,
                'table' => $table,
                'offset' => $nextOffset,
                'copied_rows' => $copiedRows,
                'total_copied' => $nextOffset,
                'done' => $copiedRows < $chunkSize,
            ]);
        } catch (InvalidArgumentException $e) {
            jsonExit(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            jsonExit(['error' => $e->getMessage()], 500);
        }
    }

    if ($_GET['action'] === 'finalize_copy') {
        try {
            $srcDb = dbConnect($config);
            $destDb = dbConnect($config, $destHost, $destUser, $destPass, $destName, $destPort);
            if (!$destDb->query("SET SESSION FOREIGN_KEY_CHECKS = 0")) {
                throw new RuntimeException('Could not configure destination database session: ' . $destDb->error);
            }

            $views = $srcDb->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
            if (!$views) {
                throw new RuntimeException('Could not list source views: ' . $srcDb->error);
            }
            $copiedViews = 0;
            while ($row = $views->fetch_row()) {
                $viewId = escapeId($row[0]);
                $cr = $srcDb->query("SHOW CREATE VIEW {$viewId}");
                if (!$cr) {
                    throw new RuntimeException("Could not read source view {$row[0]}: " . $srcDb->error);
                }
                $vrow = $cr->fetch_assoc();
                $cr->free();
                $sql = stripDefiner((string) ($vrow['Create View'] ?? ''));
                if (!$destDb->query("DROP VIEW IF EXISTS {$viewId}")) {
                    throw new RuntimeException("Failed to remove destination view {$row[0]}: " . $destDb->error);
                }
                if ($sql !== '') {
                    if (!$destDb->query($sql)) {
                        throw new RuntimeException("Failed to create destination view {$row[0]}: " . $destDb->error);
                    }
                    $copiedViews++;
                } else {
                    throw new RuntimeException("Empty definition returned for source view {$row[0]}.");
                }
            }
            $views->free();
            $srcDb->close();
            $destDb->close();
            jsonExit(['success' => true, 'message' => "Copy finished. {$copiedViews} view(s) copied."]);
        } catch (InvalidArgumentException $e) {
            jsonExit(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            jsonExit(['error' => $e->getMessage()], 500);
        }
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  IMPORTER
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class DatabaseImporter
{
    private array $config;
    private string $stateFile;
    private string $lockFile;
    private ?mysqli $db = null;

    public function __construct(array $config, string $stateFile, string $lockFile)
    {
        $this->config = $config;
        $this->stateFile = $stateFile;
        $this->lockFile = $lockFile;
    }

    private function connect(): void
    {
        $this->db = dbConnect($this->config);
        $this->db->query("SET SESSION FOREIGN_KEY_CHECKS = 0");
        $this->db->query("SET SESSION UNIQUE_CHECKS = 0");
        $this->db->query("SET SESSION SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        $this->db->query("SET SESSION max_allowed_packet = 1073741824");
        $this->db->query("SET SESSION autocommit = 0");
    }

    public function init(array $options): array
    {
        $lock = $this->acquireLock();
        try {
            return $this->initLocked($options);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function initLocked(array $options): array
    {
        $fileName = safeBasename((string) ($options['file'] ?? ''));
        $filePath = $this->config['export_dir'] . $fileName;

        if (!isUserDumpName($fileName) || !is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('Dump file not found.');
        }

        $searchOld = (string) ($options['search_old'] ?? '');
        $searchNew = (string) ($options['search_new'] ?? '');
        $wpReplace = !empty($options['wp_search_replace']);

        $state = [
            'status' => 'running',
            'phase' => 'prepare',
            'source_name' => $fileName,
            'file_name' => $fileName,
            'file_size' => filesize($filePath) ?: 0,
            'offset' => 0,
            'queries_count' => 0,
            'started_at' => time(),
            'last_chunk_at' => time(),
            'search_old' => $searchOld,
            'search_new' => $searchNew,
            'wp_search_replace' => $wpReplace,
            'parser' => (new SqlStatementSplitter())->toState(),
            'replace_tables' => [],
            'replace_index' => 0,
            'replace_offset' => 0,
            'replace_count' => 0,
            'errors' => [],
        ];

        $this->saveState($state);
        return [
            'done' => false,
            'phase' => 'prepare',
            'offset' => 0,
            'total_size' => $state['file_size'],
            'percent' => 0,
            'queries_count' => 0,
            'message' => "Preparing {$fileName} (" . number_format($state['file_size'] / 1048576, 2) . ' MB)',
        ];
    }

    public function processChunk(): array
    {
        $lock = $this->acquireLock();
        try {
            $state = $this->getState();
            if (!$state) {
                throw new RuntimeException('No import in progress.');
            }
            if (($state['status'] ?? '') === 'done') {
                return $this->progress($state, true, 'Import already completed.');
            }

            $startTime = microtime(true);
            $timeLimit = (float) $this->config['time_limit'];

            if (($state['phase'] ?? '') === 'prepare') {
                $state = $this->prepareSource($state);
                $this->saveState($state);
                return $this->progress($state, false, 'Source ready. Importing SQL…');
            }

            if (($state['phase'] ?? '') === 'replacing') {
                $this->connect();
                $this->db->query('START TRANSACTION');
                try {
                    $state = $this->replaceChunk($state, $startTime, $timeLimit);
                    $this->db->query('COMMIT');
                } catch (Throwable $e) {
                    $this->db->query('ROLLBACK');
                    throw $e;
                }
                $this->saveState($state);
                $done = ($state['status'] ?? '') === 'done';
                return $this->progress(
                    $state,
                    $done,
                    $done
                        ? "Import completed! {$state['queries_count']} queries. WP replace: {$state['replace_count']} value(s)."
                        : "Search & replace… {$state['replace_count']} value(s) updated"
                );
            }

            $this->connect();
            $this->db->query('START TRANSACTION');

            $filePath = $this->config['export_dir'] . $state['file_name'];
            $fp = fopen($filePath, 'rb');
            if ($fp === false) {
                throw new RuntimeException('Failed to open file: ' . $state['file_name']);
            }
            if (($state['offset'] ?? 0) > 0) {
                fseek($fp, (int) $state['offset']);
            }

            $splitter = new SqlStatementSplitter();
            $splitter->fromState($state['parser'] ?? []);

            try {
                while (!feof($fp)) {
                    $block = fread($fp, 524288);
                    if ($block === false || $block === '') {
                        break;
                    }
                    $state['offset'] = ftell($fp);
                    $statements = $splitter->ingest($block);
                    foreach ($statements as $sql) {
                        if ($this->execImportSql($sql, $state)) {
                            $state['queries_count']++;
                        }
                    }
                    if ((microtime(true) - $startTime) > ($timeLimit - 2)) {
                        break;
                    }
                }

                $eof = feof($fp);
                fclose($fp);
                $fp = null;
                $this->db->query('COMMIT');
            } catch (Throwable $e) {
                if (is_resource($fp) || is_object($fp)) {
                    fclose($fp);
                }
                $this->db->query('ROLLBACK');
                throw $e;
            }

            $state['parser'] = $splitter->toState();
            $state['last_chunk_at'] = time();

            if ($eof) {
                $this->db->query('START TRANSACTION');
                try {
                    foreach ($splitter->finish() as $sql) {
                        if ($this->execImportSql($sql, $state)) {
                            $state['queries_count']++;
                        }
                    }
                    $tail = trim($splitter->buffer);
                    if ($tail !== '' && !preg_match('/^DELIMITER\s+\S+\s*$/i', $tail)) {
                        if ($this->execImportSql($tail, $state)) {
                            $state['queries_count']++;
                        }
                        $splitter->buffer = '';
                        $state['parser'] = $splitter->toState();
                    }
                    $this->db->query('COMMIT');
                } catch (Throwable $e) {
                    $this->db->query('ROLLBACK');
                    throw $e;
                }
                $this->db->query('SET SESSION FOREIGN_KEY_CHECKS = 1');
                $this->db->query('SET SESSION UNIQUE_CHECKS = 1');
                $this->db->query('SET SESSION autocommit = 1');

                if (!empty($state['wp_search_replace']) && ($state['search_old'] ?? '') !== '' && ($state['search_new'] ?? '') !== '') {
                    $state['phase'] = 'replacing';
                    $state['replace_tables'] = $this->listReplaceTargets();
                    $state['replace_index'] = 0;
                    $state['replace_offset'] = 0;
                    $this->saveState($state);
                    return $this->progress($state, false, 'SQL imported. Starting WordPress search & replace…');
                }

                $state['status'] = 'done';
                $state['phase'] = 'completed';
                $this->cleanupWorkFile($state);
                $this->saveState($state);
                $errNote = empty($state['errors']) ? '' : ' Notices: ' . count($state['errors']) . ' non-fatal SQL warning(s).';
                return $this->progress($state, true, "Import completed! {$state['queries_count']} queries executed.{$errNote}");
            }

            $this->saveState($state);
            return $this->progress($state, false, null);
        } finally {
            $this->releaseLock($lock);
            if ($this->db instanceof mysqli) {
                $this->db->close();
                $this->db = null;
            }
        }
    }

    private function prepareSource(array $state): array
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $src = $this->config['export_dir'] . $state['source_name'];
        $name = $state['source_name'];

        if (preg_match('/\.sql$/i', $name)) {
            $state['file_name'] = $name;
            $state['file_size'] = (int) (filesize($src) ?: 0);
            $state['phase'] = 'importing';
            return $state;
        }

        $srcMtime = is_file($src) ? (int) filemtime($src) : 0;
        $srcSize = is_file($src) ? (int) filesize($src) : 0;
        $destName = 'import_work.sql';
        $dest = $this->config['export_dir'] . $destName;
        $metaPath = $dest . '.meta';

        // Reuse a previous extract only when dest size still matches the
        // size recorded after a finished write. Delete the meta first when
        // extracting so a killed write cannot look like a cache hit.
        if (is_file($dest) && is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true);
            $destSize = (int) filesize($dest);
            if (is_array($meta)
                && ($meta['src'] ?? '') === $name
                && (int) ($meta['mtime'] ?? 0) === $srcMtime
                && (int) ($meta['size'] ?? 0) === $srcSize
                && (int) ($meta['bytes'] ?? 0) === $destSize
                && $destSize > 0) {
                $state['file_name'] = $destName;
                $state['file_size'] = $destSize;
                $state['phase'] = 'importing';
                return $state;
            }
        }

        @unlink($metaPath);

        if (preg_match('/\.zip$/i', $name)) {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('ZipArchive is not available. Upload a .sql or .sql.gz file.');
            }
            $zip = new ZipArchive();
            if ($zip->open($src) !== true) {
                throw new RuntimeException('Could not open ZIP archive.');
            }
            $found = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false) {
                    continue;
                }
                $base = basename(str_replace('\\', '/', $entry));
                if (strpos($entry, '..') !== false || $base === '' || $base[0] === '.') {
                    continue;
                }
                if (preg_match('/\.sql$/i', $base)) {
                    $found = $entry;
                    break;
                }
            }
            if ($found === null) {
                $zip->close();
                throw new RuntimeException('ZIP archive does not contain a .sql file.');
            }
            $stream = $zip->getStream($found);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException('Could not read SQL file inside ZIP.');
            }
            $out = fopen($dest, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException('Could not write extracted SQL.');
            }
            $copied = stream_copy_to_stream($stream, $out);
            if ($copied === false) {
                fclose($out);
                fclose($stream);
                $zip->close();
                @unlink($dest);
                throw new RuntimeException('Could not extract SQL file from ZIP archive.');
            }
            fclose($out);
            fclose($stream);
            $zip->close();

            $this->writeExtractMeta($metaPath, $name, $srcMtime, $srcSize, $dest);
            $state['file_name'] = $destName;
            $state['file_size'] = (int) filesize($dest) ?: 0;
            $state['phase'] = 'importing';
            return $state;
        }

        if (preg_match('/\.gz$/i', $name)) {
            if (!function_exists('gzopen')) {
                throw new RuntimeException('GZIP support is not available. Upload a .sql or .zip file.');
            }
            $in = gzopen($src, 'rb');
            if ($in === false) {
                throw new RuntimeException('Could not open gzip dump.');
            }
            $out = fopen($dest, 'wb');
            if ($out === false) {
                gzclose($in);
                throw new RuntimeException('Could not write decompressed SQL.');
            }
            set_time_limit(0);
            while (!gzeof($in)) {
                $buf = gzread($in, 1048576);
                if ($buf === false) {
                    fclose($out);
                    gzclose($in);
                    @unlink($dest);
                    throw new RuntimeException('Could not read gzip dump.');
                }
                if ($buf === '') {
                    if (!gzeof($in)) {
                        fclose($out);
                        gzclose($in);
                        @unlink($dest);
                        throw new RuntimeException('GZIP dump ended unexpectedly.');
                    }
                    break;
                }
                $remaining = $buf;
                while ($remaining !== '') {
                    $written = fwrite($out, $remaining);
                    if ($written === false || $written === 0) {
                        fclose($out);
                        gzclose($in);
                        @unlink($dest);
                        throw new RuntimeException('Could not write decompressed SQL.');
                    }
                    $remaining = $written < strlen($remaining) ? substr($remaining, $written) : '';
                }
            }
            if (function_exists('gzerrno')) {
                $gzErrno = gzerrno($in);
                $okCodes = [0];
                if (defined('Z_OK')) {
                    $okCodes[] = (int) Z_OK;
                }
                if (defined('Z_STREAM_END')) {
                    $okCodes[] = (int) Z_STREAM_END;
                }
                if (!in_array($gzErrno, $okCodes, true)) {
                    $gzMessage = function_exists('gzerror') ? (string) gzerror($in) : ('code ' . $gzErrno);
                    fclose($out);
                    gzclose($in);
                    @unlink($dest);
                    throw new RuntimeException('Invalid or truncated gzip dump: ' . $gzMessage);
                }
            }
            fclose($out);
            gzclose($in);

            $this->writeExtractMeta($metaPath, $name, $srcMtime, $srcSize, $dest);
            $state['file_name'] = $destName;
            $state['file_size'] = (int) filesize($dest) ?: 0;
            $state['phase'] = 'importing';
            return $state;
        }

        $state['phase'] = 'importing';
        return $state;
    }

    private function writeExtractMeta(string $metaPath, string $name, int $srcMtime, int $srcSize, string $dest): void
    {
        clearstatcache(true, $dest);
        file_put_contents($metaPath, json_encode([
            'src'   => $name,
            'mtime' => $srcMtime,
            'size'  => $srcSize,
            'bytes' => (int) filesize($dest),
        ]), LOCK_EX);
        @chmod($metaPath, 0640);
    }

    private function execImportSql(string $sql, array &$state): bool
    {
        $sql = trim($sql);
        if ($sql === '' || shouldSkipImportSql($sql)) {
            return false;
        }

        $sql = stripDefiner($sql);
        $sql = fixSqlCollationCompatibility($sql);

        if (($state['search_old'] ?? '') !== '' && ($state['search_new'] ?? '') !== '' && empty($state['wp_search_replace'])) {
            $sql = str_replace($state['search_old'], $state['search_new'], $sql);
        }

        if (!$this->db->query($sql)) {
            $err = $this->db->error;

            // If unknown collation error, retry with standard utf8mb4_unicode_ci
            if (preg_match('/unknown collation/i', $err)) {
                $retrySql = preg_replace('/COLLATE\s*=\s*[a-zA-Z0-9_]+/i', 'COLLATE=utf8mb4_unicode_ci', $sql);
                $retrySql = preg_replace('/COLLATE\s+[a-zA-Z0-9_]+/i', 'COLLATE utf8mb4_unicode_ci', (string) $retrySql);
                if ($this->db->query((string) $retrySql)) {
                    return true;
                }
                $err = $this->db->error;
            }

            if (looksLikeSqlErrorIgnorable($err, $sql)) {
                return false;
            }
            $state['errors'][] = $err;
            if (count($state['errors']) > 20) {
                $state['errors'] = array_slice($state['errors'], -20);
            }
            if (preg_match('/syntax|access denied|command denied|gone away|packet/i', $err)) {
                throw new RuntimeException('SQL import failed: ' . $err . ' — ' . substr($sql, 0, 180));
            }
            error_log('SQL Import Notice: ' . $err . ' in ' . substr($sql, 0, 150));
            return false;
        }
        return true;
    }

    private function listReplaceTargets(): array
    {
        $out = [];
        $res = $this->db->query('SHOW TABLES');
        if (!$res) {
            throw new RuntimeException('Could not list tables for search & replace: ' . $this->db->error);
        }
        while ($row = $res->fetch_row()) {
            $t = $row[0];
            $meta = $this->describeTable($t);
            if ($meta !== null) {
                $out[] = $meta;
            }
        }
        $res->free();
        return $out;
    }

    private function describeTable(string $table): ?array
    {
        $safeT = escapeId($table);
        $colsRes = $this->db->query("SHOW COLUMNS FROM {$safeT}");
        if (!$colsRes) {
            return null;
        }
        $pks = [];
        $textCols = [];
        while ($col = $colsRes->fetch_assoc()) {
            if (($col['Key'] ?? '') === 'PRI') {
                $pks[] = $col['Field'];
            }
            if (preg_match('/char|text|blob/i', (string) ($col['Type'] ?? ''))) {
                $textCols[] = $col['Field'];
            }
        }
        $colsRes->free();
        if (!$pks || !$textCols) {
            return null;
        }
        return ['name' => $table, 'pks' => $pks, 'text' => $textCols];
    }

    private function replaceChunk(array $state, float $startTime, float $timeLimit): array
    {
        $old = (string) $state['search_old'];
        $new = (string) $state['search_new'];
        $replacer = new SerializedSearchReplace($old, $new);
        $tables = $state['replace_tables'];
        $idx = (int) $state['replace_index'];
        $offset = (int) $state['replace_offset'];
        $batch = 200;

        while ($idx < count($tables)) {
            $t = $tables[$idx];
            $safeT = escapeId($t['name']);
            $colList = implode(', ', array_map('escapeId', array_merge($t['pks'], $t['text'])));
            $sql = "SELECT {$colList} FROM {$safeT} LIMIT {$batch} OFFSET {$offset}";
            $rowsRes = $this->db->query($sql);
            if (!$rowsRes) {
                throw new RuntimeException("Could not read {$t['name']} for search & replace: " . $this->db->error);
            }
            $fetched = 0;
            while ($r = $rowsRes->fetch_assoc()) {
                $fetched++;
                $updates = [];
                foreach ($t['text'] as $c) {
                    $val = $r[$c];
                    if ($val === null || strpos((string) $val, $old) === false) {
                        continue;
                    }
                    $newVal = $replacer->replace($val);
                    if ($newVal !== $val) {
                        $updates[] = escapeId($c) . " = '" . $this->db->real_escape_string((string) $newVal) . "'";
                    }
                }
                if ($updates) {
                    $where = [];
                    foreach ($t['pks'] as $pk) {
                        $where[] = escapeId($pk) . " = '" . $this->db->real_escape_string((string) $r[$pk]) . "'";
                    }
                    $upSql = "UPDATE {$safeT} SET " . implode(', ', $updates) . ' WHERE ' . implode(' AND ', $where);
                    if (!$this->db->query($upSql)) {
                        throw new RuntimeException("Search & replace update failed on {$t['name']}: " . $this->db->error);
                    }
                }
            }
            $rowsRes->free();
            $state['replace_count'] = $replacer->count + (int) ($state['replace_count'] ?? 0);
            $replacer->count = 0;

            if ($fetched < $batch) {
                $idx++;
                $offset = 0;
            } else {
                $offset += $fetched;
            }

            if ((microtime(true) - $startTime) > ($timeLimit - 2)) {
                break;
            }
        }

        $state['replace_index'] = $idx;
        $state['replace_offset'] = $offset;
        if ($idx >= count($tables)) {
            $state['status'] = 'done';
            $state['phase'] = 'completed';
            $this->cleanupWorkFile($state);
        }
        return $state;
    }

    private function progress(array $state, bool $done, ?string $message): array
    {
        $size = (int) ($state['file_size'] ?? 0);
        $offset = (int) ($state['offset'] ?? 0);
        if (($state['phase'] ?? '') === 'replacing') {
            $total = max(1, count($state['replace_tables'] ?? []));
            $pct = min(99, 90 + (int) floor((((int) $state['replace_index']) / $total) * 10));
        } else {
            $pct = ($size > 0) ? min(99, (int) round(($offset / $size) * 100)) : 0;
        }
        if ($done) {
            $pct = 100;
        }
        if ($message === null) {
            $message = "Importing… {$pct}% (" . number_format($offset / 1048576, 1) . ' MB / ' . number_format($size / 1048576, 1) . " MB, {$state['queries_count']} queries)";
        }
        return [
            'done' => $done,
            'phase' => $state['phase'] ?? 'importing',
            'offset' => $offset,
            'total_size' => $size,
            'percent' => $pct,
            'queries_count' => (int) ($state['queries_count'] ?? 0),
            'message' => $message,
        ];
    }

    private function cleanupWorkFile(array $state): void
    {
        if (($state['file_name'] ?? '') === 'import_work.sql') {
            $work = $this->config['export_dir'] . 'import_work.sql';
            @unlink($work);
            @unlink($work . '.meta');
        }
    }

    private function acquireLock()
    {
        $fp = fopen($this->lockFile, 'c+');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            throw new RuntimeException('Another import request is already running.');
        }
        return $fp;
    }

    private function releaseLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function saveState(array $state): void
    {
        $tmp = $this->stateFile . '.tmp';
        if (file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new RuntimeException('Could not save import progress.');
        }
    }

    private function getState(): ?array
    {
        if (!is_file($this->stateFile)) {
            return null;
        }
        $content = file_get_contents($this->stateFile);
        return $content ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  EXPORTER
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
        $this->db = dbConnect($config);
        $this->db->query("SET SESSION net_write_timeout = 600, wait_timeout = 600, net_read_timeout = 600");
        $this->db->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    }

    public function __destruct()
    {
        if (isset($this->db) && $this->db instanceof mysqli) {
            @$this->db->close();
        }
    }

    public function init(array $options = []): array
    {
        $lock = $this->acquireLock();
        try {
            return $this->initLocked($options);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function initLocked(array $options): array
    {
        $this->cleanup();

        $compression = (($options['compression'] ?? 'gzip') === 'gzip') ? 'gzip' : 'none';
        $selectedTables = $options['tables'] ?? [];
        if (!is_array($selectedTables)) {
            $selectedTables = [];
        }

        $tables = [];
        $views = [];
        $rowCounts = [];
        $dbId = escapeId($this->config['db_name']);

        $result = $this->db->query("SHOW TABLE STATUS FROM {$dbId}");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rowCounts[$row['Name']] = (int) ($row['Rows'] ?? 0);
            }
            $result->free();
        }

        $result = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if (!$result) {
            throw new RuntimeException('Could not list tables: ' . $this->db->error);
        }
        while ($row = $result->fetch_row()) {
            $tableName = $row[0];
            if ($selectedTables && !in_array($tableName, $selectedTables, true)) {
                continue;
            }
            $tables[] = [
                'name' => $tableName,
                'rows' => $rowCounts[$tableName] ?? 0,
                'pk'   => $this->detectPrimaryKey($tableName),
            ];
        }
        $result->free();

        if (!$tables) {
            throw new RuntimeException('No tables selected.');
        }

        $result = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        if ($result) {
            while ($row = $result->fetch_row()) {
                $views[] = $row[0];
            }
            $result->free();
        }

        $ext = ($compression === 'gzip') ? '.sql.gz' : '.sql';
        $safeDb = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $this->config['db_name']);
        $fileName = 'dump_' . $safeDb . '_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . $ext;

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
            'message' => 'Export initialized for ' . count($tables) . ' table(s).',
        ];
    }

    private function detectPrimaryKey(string $tableName): ?array
    {
        $result = $this->db->query('SHOW KEYS FROM ' . escapeId($tableName) . " WHERE Key_name = 'PRIMARY'");
        if (!$result) {
            return null;
        }
        $pkColumns = [];
        while ($row = $result->fetch_assoc()) {
            $pkColumns[(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        $result->free();
        ksort($pkColumns);
        $pkColumns = array_values($pkColumns);
        return $pkColumns ? $pkColumns : null;
    }

    public function processChunk(): array
    {
        $lock = $this->acquireLock();
        try {
            $startTime = microtime(true);
            $state = $this->getState();
            if (!$state) {
                throw new RuntimeException('No export in progress.');
            }
            if (($state['status'] ?? '') === 'done') {
                return [
                    'done' => true,
                    'phase' => 'completed',
                    'rows' => $state['exported_rows'],
                    'total' => $state['total_rows'],
                    'message' => 'Export already completed.',
                ];
            }

            $fp = $this->openForWrite($state);
            if (!$fp) {
                throw new RuntimeException('Failed to open file for writing');
            }

            try {
                switch ($state['phase']) {
                    case 'structure':
                        $state = $this->exportStructures($fp, $state, $startTime);
                        break;
                    case 'data':
                        $state = $this->exportDataChunk($fp, $state, $startTime);
                        break;
                    case 'triggers':
                        $state = $this->exportTriggers($fp, $state);
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
                        $state['phase'] = 'footer';
                }
                $state['last_chunk_at'] = time();
                $state['chunks_done']++;
                $state['file_size'] = $this->getFileSize($state);
            } finally {
                $this->closeWrite($fp, $state);
            }

            $this->saveState($state);
            return [
                'done'    => $state['status'] === 'done',
                'phase'   => $state['phase'],
                'rows'    => $state['exported_rows'],
                'total'   => $state['total_rows'],
                'message' => sprintf('Phase: %s | %s rows', strtoupper((string) $state['phase']), number_format((int) $state['exported_rows'])),
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function nextPhase(string $after, array $state): string
    {
        $order = ['structure', 'data'];
        if (!empty($this->config['export_triggers'])) {
            $order[] = 'triggers';
        }
        if (!empty($state['views'])) {
            $order[] = 'views';
        }
        if (!empty($this->config['export_routines'])) {
            $order[] = 'routines';
        }
        if (!empty($this->config['export_events'])) {
            $order[] = 'events';
        }
        $order[] = 'footer';
        $i = array_search($after, $order, true);
        if ($i === false) {
            return 'footer';
        }
        return $order[$i + 1] ?? 'footer';
    }

    private function openForWrite(array $state)
    {
        $path = $this->config['export_dir'] . $state['file_name'];
        if ($state['compression'] === 'gzip') {
            if (!function_exists('gzopen')) {
                throw new RuntimeException('GZIP support is not available. Choose plain SQL export.');
            }
            $fp = gzopen($path, 'ab1');
            if ($fp === false) {
                throw new RuntimeException('Could not open dump file for writing.');
            }
            return $fp;
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new RuntimeException('Could not open dump file for writing.');
        }
        return $fp;
    }

    private function closeWrite($fp, array $state): void
    {
        if (!is_resource($fp) && !is_object($fp)) {
            return;
        }
        if ($state['compression'] === 'gzip') {
            gzclose($fp);
        } else {
            fclose($fp);
        }
    }

    private function fwriteChecked($fp, string $data, array $state): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = ($state['compression'] === 'gzip') ? gzwrite($fp, $remaining) : fwrite($fp, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write to dump file.');
            }
            if ($written < strlen($remaining)) {
                $remaining = substr($remaining, $written);
            } else {
                break;
            }
        }
    }

    private function getFileSize(array $state): int
    {
        $path = $this->config['export_dir'] . $state['file_name'];
        clearstatcache(true, $path);
        return is_file($path) ? (int) filesize($path) : 0;
    }

    private function exportStructures($fp, array $state, float $startTime): array
    {
        $startIdx = (int) ($state['structure_index'] ?? 0);
        if ($startIdx === 0) {
            $this->fwriteChecked($fp, "\n-- TABLE STRUCTURES\n\n", $state);
        }

        for ($i = $startIdx; $i < count($state['tables']); $i++) {
            $tableName = $state['tables'][$i]['name'];
            $tableId = escapeId($tableName);
            $this->fwriteChecked($fp, "DROP TABLE IF EXISTS {$tableId};\n", $state);
            $result = $this->db->query("SHOW CREATE TABLE {$tableId}");
            if (!$result) {
                throw new RuntimeException("Failed to get structure for {$tableName}: " . $this->db->error);
            }
            $row = $result->fetch_row();
            $result->free();
            if (!isset($row[1]) || $row[1] === '') {
                throw new RuntimeException("Empty structure returned for {$tableName}.");
            }
            $this->fwriteChecked($fp, $row[1] . ";\n\n", $state);
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
        $tableIndex = (int) $state['current_table'];
        $lastPk = $state['last_pk'];
        $offset = (int) $state['current_offset'];
        $chunkSize = (int) $this->config['chunk_size'];

        while ($tableIndex < count($state['tables'])) {
            $table = $state['tables'][$tableIndex];
            $tableName = $table['name'];
            $tableId = escapeId($tableName);

            if ($lastPk === null && $offset === 0) {
                $this->fwriteChecked($fp, "\n-- Data for {$tableId}\nALTER TABLE {$tableId} DISABLE KEYS;\n", $state);
            }

            $query = $this->buildPaginationQuery($tableName, $table, $lastPk, $offset, $chunkSize);
            $result = $this->db->query($query, MYSQLI_USE_RESULT);
            if (!$result) {
                throw new RuntimeException("Failed to read {$tableName}: " . $this->db->error);
            }

            $fields = $result->fetch_fields();
            if (!isset($this->fieldTypeCache[$tableName])) {
                $this->fieldTypeCache[$tableName] = [
                    'columns' => array_map(static function ($f) {
                        return escapeId($f->name);
                    }, $fields),
                    'numeric' => array_map([$this, 'isNumericField'], $fields),
                    'binary'  => array_map([$this, 'isBinaryField'], $fields),
                    'pkIndexes' => $this->findPkIndexes($fields, $table['pk'] ?? null),
                ];
            }

            $cache = $this->fieldTypeCache[$tableName];
            $insertBase = "INSERT INTO {$tableId} (" . implode(', ', $cache['columns']) . ") VALUES\n";
            $rowCount = $this->writeRows($fp, $result, $cache, $insertBase, $lastPk, $state);
            $result->free();

            $state['exported_rows'] += $rowCount;
            if (empty($table['pk'])) {
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

        $state['current_table'] = $tableIndex;
        $state['last_pk'] = $lastPk;
        $state['current_offset'] = $offset;

        if ($tableIndex >= count($state['tables'])) {
            $state['phase'] = $this->nextPhase('data', $state);
        }
        return $state;
    }

    private function buildPaginationQuery(string $tableName, array $table, $lastPk, int $offset, int $limit): string
    {
        $tableId = escapeId($tableName);
        $pk = $table['pk'] ?? null;
        if (is_array($pk) && $pk) {
            $pkIds = array_map('escapeId', $pk);
            if ($lastPk === null || !is_array($lastPk) || count($lastPk) !== count($pk)) {
                return "SELECT * FROM {$tableId} ORDER BY " . implode(', ', $pkIds) . " ASC LIMIT {$limit}";
            }
            $escaped = [];
            foreach ($lastPk as $v) {
                $escaped[] = "'" . $this->db->real_escape_string((string) $v) . "'";
            }
            return "SELECT * FROM {$tableId} WHERE (" . implode(', ', $pkIds) . ') > (' . implode(', ', $escaped) . ') ORDER BY ' . implode(', ', $pkIds) . " ASC LIMIT {$limit}";
        }
        return "SELECT * FROM {$tableId} ORDER BY 1 LIMIT {$offset},{$limit}";
    }

    private function findPkIndexes(array $fields, ?array $pk): array
    {
        if (!$pk) {
            return [];
        }
        $map = [];
        foreach ($fields as $i => $f) {
            $map[$f->name] = $i;
        }
        $indexes = [];
        foreach ($pk as $col) {
            if (!isset($map[$col])) {
                return [];
            }
            $indexes[] = $map[$col];
        }
        return $indexes;
    }

    private function writeRows($fp, $result, array $cache, string $insertBase, &$lastPk, array $state): int
    {
        $insertValues = [];
        $currentSize = strlen($insertBase);
        $rowsInInsert = 0;
        $rowCount = 0;
        $maxBytes = $this->config['max_insert_bytes'];
        $maxRows = $this->config['max_insert_rows'];

        while ($row = $result->fetch_row()) {
            $rowCount++;
            if (!empty($cache['pkIndexes'])) {
                $vals = [];
                foreach ($cache['pkIndexes'] as $idx) {
                    $vals[] = $row[$idx];
                }
                $lastPk = $vals;
            }

            $vals = [];
            foreach ($row as $i => $value) {
                if ($value === null) {
                    $vals[] = 'NULL';
                } elseif (!empty($cache['numeric'][$i])) {
                    $vals[] = $value;
                } elseif (!empty($cache['binary'][$i])) {
                    $vals[] = '0x' . bin2hex($value);
                } else {
                    $vals[] = "'" . $this->db->real_escape_string($value) . "'";
                }
            }

            $tuple = '(' . implode(',', $vals) . ')';
            $tupleSize = strlen($tuple) + 2;
            if ($insertValues && ($currentSize + $tupleSize > $maxBytes || $rowsInInsert >= $maxRows)) {
                $this->fwriteChecked($fp, $insertBase . implode(",\n", $insertValues) . ";\n", $state);
                $insertValues = [];
                $currentSize = strlen($insertBase);
                $rowsInInsert = 0;
            }
            $insertValues[] = $tuple;
            $rowsInInsert++;
            $currentSize += $tupleSize;
        }

        if ($insertValues) {
            $this->fwriteChecked($fp, $insertBase . implode(",\n", $insertValues) . ";\n", $state);
        }
        return $rowCount;
    }

    private function exportTriggers($fp, array $state): array
    {
        $this->fwriteChecked($fp, "\n-- TRIGGERS\n\n", $state);
        $res = $this->db->query('SHOW TRIGGERS');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $name = $row['Trigger'] ?? '';
                if ($name === '') {
                    continue;
                }
                $show = $this->db->query('SHOW CREATE TRIGGER ' . escapeId($name));
                if ($show) {
                    $cr = $show->fetch_assoc();
                    $sql = stripDefiner((string) ($cr['SQL Original Statement'] ?? ''));
                    if ($sql !== '') {
                        $this->fwriteChecked($fp, 'DROP TRIGGER IF EXISTS ' . escapeId($name) . ";\nDELIMITER ;;\n" . $sql . " ;;\nDELIMITER ;\n\n", $state);
                    }
                    $show->free();
                }
            }
            $res->free();
        }
        $state['phase'] = $this->nextPhase('triggers', $state);
        return $state;
    }

    private function exportViews($fp, array $state): array
    {
        foreach ($state['views'] as $view) {
            $viewId = escapeId($view);
            $this->fwriteChecked($fp, "DROP VIEW IF EXISTS {$viewId};\n", $state);
            $res = $this->db->query("SHOW CREATE VIEW {$viewId}");
            if ($res) {
                $row = $res->fetch_assoc();
                $sql = stripDefiner((string) ($row['Create View'] ?? ''));
                if ($sql !== '') {
                    $this->fwriteChecked($fp, $sql . ";\n\n", $state);
                }
                $res->free();
            }
        }
        $state['phase'] = $this->nextPhase('views', $state);
        return $state;
    }

    private function exportRoutines($fp, array $state): array
    {
        $dbEsc = $this->db->real_escape_string($this->config['db_name']);
        $query = "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = '{$dbEsc}'";
        $res = $this->db->query($query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $routineId = escapeId($row['ROUTINE_NAME']);
                $routineType = strtoupper($row['ROUTINE_TYPE']);
                if (!in_array($routineType, ['FUNCTION', 'PROCEDURE'], true)) {
                    continue;
                }
                $this->fwriteChecked($fp, "DROP {$routineType} IF EXISTS {$routineId};\nDELIMITER ;;\n", $state);
                $show = $this->db->query("SHOW CREATE {$routineType} {$routineId}");
                if ($show) {
                    $createRow = $show->fetch_assoc();
                    $key = ($routineType === 'FUNCTION') ? 'Create Function' : 'Create Procedure';
                    if (!empty($createRow[$key])) {
                        $this->fwriteChecked($fp, stripDefiner($createRow[$key]) . " ;;\n", $state);
                    }
                    $show->free();
                }
                $this->fwriteChecked($fp, "DELIMITER ;\n\n", $state);
            }
            $res->free();
        }
        $state['phase'] = $this->nextPhase('routines', $state);
        return $state;
    }

    private function exportEvents($fp, array $state): array
    {
        $dbEsc = $this->db->real_escape_string($this->config['db_name']);
        $res = $this->db->query("SELECT EVENT_NAME FROM INFORMATION_SCHEMA.EVENTS WHERE EVENT_SCHEMA = '{$dbEsc}'");
        if ($res) {
            $this->fwriteChecked($fp, "\n-- EVENTS\n\n", $state);
            while ($row = $res->fetch_assoc()) {
                $id = escapeId($row['EVENT_NAME']);
                $show = $this->db->query("SHOW CREATE EVENT {$id}");
                if ($show) {
                    $cr = $show->fetch_assoc();
                    $sql = stripDefiner((string) ($cr['Create Event'] ?? ''));
                    if ($sql !== '') {
                        $this->fwriteChecked($fp, "DROP EVENT IF EXISTS {$id};\nDELIMITER ;;\n{$sql} ;;\nDELIMITER ;\n\n", $state);
                    }
                    $show->free();
                }
            }
            $res->free();
        }
        $state['phase'] = $this->nextPhase('events', $state);
        return $state;
    }

    private function writeHeader(array $state): void
    {
        $fp = $this->openForWrite($state);
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->config['db_charset']) ?: 'utf8mb4';
        $headerData = "-- Database Export\n-- Generated: " . date('c') . "\n-- Database: " . $this->config['db_name'] . "\n\n"
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
        $footerData = "\n-- RESTORE SETTINGS\nSET SQL_MODE=@OLD_SQL_MODE;\nSET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\nSET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n-- Finished at: " . date('c') . "\n";
        $this->fwriteChecked($fp, $footerData, $state);
        $state['status'] = 'done';
        $state['phase'] = 'completed';
        return $state;
    }

    private function isNumericField(object $field): bool
    {
        return in_array($field->type, [
            MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG,
            MYSQLI_TYPE_INT24, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL,
            MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE,
        ], true);
    }

    private function isBinaryField(object $field): bool
    {
        return in_array($field->type, [
            MYSQLI_TYPE_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_TINY_BLOB,
            MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING,
        ], true) && ($field->flags & MYSQLI_BINARY_FLAG);
    }

    private function acquireLock()
    {
        $fp = fopen($this->lockFile, 'c+');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            throw new RuntimeException('Another export request is already running.');
        }
        return $fp;
    }

    private function releaseLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function saveState(array $state): void
    {
        $tmp = $this->stateFile . '.tmp';
        if (file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new RuntimeException('Could not save export progress.');
        }
    }

    private function getState(): ?array
    {
        if (!is_file($this->stateFile)) {
            return null;
        }
        $content = file_get_contents($this->stateFile);
        return $content ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function cleanup(): void
    {
        @unlink($this->stateFile);
        @unlink($this->stateFile . '.tmp');
    }
}

function destroyTool(array $config): array
{
    $deleted = [];
    $failed = [];
    $dir = $config['export_dir'];
    if (is_dir($dir)) {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . $item;
            if (is_file($path)) {
                if (@unlink($path)) {
                    $deleted[] = 'db_exports/' . $item;
                } else {
                    $failed[] = 'db_exports/' . $item;
                }
            }
        }
        @rmdir($dir);
    }
    if (!empty($config['auth_file']) && is_file($config['auth_file'])) {
        if (@unlink($config['auth_file'])) {
            $deleted[] = basename($config['auth_file']);
        } else {
            $failed[] = basename($config['auth_file']);
        }
    }
    $self = __FILE__;
    if (@unlink($self)) {
        $deleted[] = basename($self);
    } else {
        $failed[] = basename($self);
    }
    return ['deleted' => $deleted, 'failed' => $failed];
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  JSON API
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if (isset($_GET['action'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-store');

    requireAuth($config);

    $action = (string) $_GET['action'];
    $mutating = ['init', 'process', 'init_import', 'process_import', 'destroy'];
    if (in_array($action, $mutating, true)) {
        requirePost();
        requireCsrf($config);
    }

    $exportStateFile = $config['export_dir'] . 'export_state.json';
    $exportLockFile  = $config['export_dir'] . 'export.lock';
    $importStateFile = $config['export_dir'] . 'import_state.json';
    $importLockFile  = $config['export_dir'] . 'import.lock';

    try {
        if ($action === 'init') {
            $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $exporter = new DatabaseExporter($config, $exportStateFile, $exportLockFile);
            jsonExit($exporter->init($input));
        }
        if ($action === 'process') {
            $exporter = new DatabaseExporter($config, $exportStateFile, $exportLockFile);
            jsonExit($exporter->processChunk());
        }
        if ($action === 'init_import') {
            $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $importer = new DatabaseImporter($config, $importStateFile, $importLockFile);
            jsonExit($importer->init($input));
        }
        if ($action === 'process_import') {
            $importer = new DatabaseImporter($config, $importStateFile, $importLockFile);
            jsonExit($importer->processChunk());
        }
        if ($action === 'destroy') {
            $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
            if (($input['confirm'] ?? '') !== 'DELETE') {
                jsonExit(['error' => 'Confirmation failed'], 400);
            }
            jsonExit(array_merge(['success' => true], destroyTool($config)));
        }
        jsonExit(['error' => 'Unknown action'], 400);
    } catch (Throwable $e) {
        jsonExit(['error' => $e->getMessage(), 'done' => false], 500);
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  UI
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; img-src 'self' data:");
$csrf = htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
$self = htmlspecialchars(scriptName(), ENT_QUOTES, 'UTF-8');
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
            gap: 12px;
        }
        h1 { font-size: 24px; font-weight: 700; color: #0f172a; }
        .subtitle { font-size: 13px; color: #64748b; margin-bottom: 24px; }
        .badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .logout {
            color: #ef4444;
            background: none;
            border: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            width: auto;
            padding: 0;
        }
        .logout:hover { text-decoration: underline; transform: none; box-shadow: none; }
        .warn {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 12px;
            margin-bottom: 16px;
            line-height: 1.45;
        }
        .tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 24px;
            overflow-x: auto;
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
            white-space: nowrap;
        }
        .tab-btn:hover { color: #0284c7; transform: none; box-shadow: none; }
        .tab-btn.active { color: #0284c7; border-bottom-color: #0284c7; }
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
        .help-text { font-size: 12px; color: #64748b; margin-top: 4px; }
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
        button:hover { transform: translateY(-1px); box-shadow: 0 8px 16px rgba(2, 132, 199, 0.25); }
        button:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-sm { padding: 6px 12px; font-size: 12px; width: auto; }
        a.btn-sm {
            display: inline-block;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            line-height: 20px;
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
            gap: 10px;
        }
        .file-info { display: flex; flex-direction: column; min-width: 0; }
        .file-name { font-weight: 600; font-size: 13px; color: #0f172a; word-break: break-all; }
        .file-meta { font-size: 11px; color: #64748b; margin-top: 2px; }
        .file-actions { display: flex; gap: 6px; flex-shrink: 0; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!isAuthenticated($config)): ?>
            <div class="header">
                <h1>Database Tool</h1>
                <span class="badge">v3.1</span>
            </div>
            <?php if ($needsSetup): ?>
                <div class="subtitle">Create a password before this tool can run. It is stored as a hash in db-dump-auth.php.</div>
                <?php if ($loginError): ?>
                    <div class="status error" style="display:block; margin-bottom: 16px;">
                        <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="<?= $self ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="input-group">
                        <label for="setup_password">New password</label>
                        <input type="password" id="setup_password" name="setup_password" required minlength="8" autocomplete="new-password" autofocus>
                    </div>
                    <div class="input-group">
                        <label for="setup_password_confirm">Confirm password</label>
                        <input type="password" id="setup_password_confirm" name="setup_password_confirm" required minlength="8" autocomplete="new-password">
                        <div class="help-text">Minimum 8 characters. Delete this file when the job is done.</div>
                    </div>
                    <button type="submit">Save password and continue</button>
                </form>
            <?php else: ?>
                <div class="subtitle">Enter password to manage exports and imports</div>
                <?php if ($loginError): ?>
                    <div class="status error" style="display:block; margin-bottom: 16px;">
                        <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="<?= $self ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="input-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required autofocus autocomplete="current-password" placeholder="Enter password">
                    </div>
                    <button type="submit">Sign In</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="header">
                <h1>
                    Database Tool
                    <span class="badge"><?= htmlspecialchars($config['db_name'] ?: 'No DB Selected', ENT_QUOTES, 'UTF-8') ?></span>
                </h1>
                <form method="POST" action="<?= $self ?>?logout=1" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button type="submit" class="logout">Logout</button>
                </form>
            </div>
            <div class="subtitle">Connected to <?= htmlspecialchars((string) $config['db_host'], ENT_QUOTES, 'UTF-8') ?>:<?= (int) $config['db_port'] ?></div>
            <div class="warn">This file can drop tables and rewrite the database. Delete <code>db-dump.php</code>, <code>db-dump-auth.php</code>, and <code>db_exports/</code> when you are finished. On nginx, also deny HTTP access to <code>/db_exports/</code>.</div>

            <div class="tabs">
                <button type="button" class="tab-btn active" data-tab="importTab">Import / Restore</button>
                <button type="button" class="tab-btn" data-tab="exportTab">Export Dump</button>
                <button type="button" class="tab-btn" data-tab="copyTab">Direct DB Copy</button>
                <button type="button" class="tab-btn" data-tab="filesTab">Backup Files</button>
            </div>

            <div id="importTab" class="tab-content active">
                <div class="input-group">
                    <label for="importFileSelect">Select SQL / GZ dump from server</label>
                    <select id="importFileSelect">
                        <option value="">-- Choose a file from db_exports/ --</option>
                    </select>
                </div>
                <div class="input-group" style="border: 2px dashed #0284c7; background: #f0f9ff; padding: 16px; border-radius: 8px; text-align: center;">
                    <label style="cursor: pointer; display: block;">
                        <strong style="color: #0369a1;">Or select a local file to upload &amp; import (.sql, .sql.gz, .zip)</strong>
                        <input type="file" id="uploadFileInput" accept=".sql,.gz,.zip" style="margin-top: 8px;">
                    </label>
                    <div id="uploadStatusText" class="help-text" style="color: #0369a1; margin-top: 6px;">Files are automatically uploaded in small chunks (bypassing hosting size limits)</div>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 20px;">
                    <label style="font-weight: 700; margin-bottom: 8px;">Domain / URL search &amp; replace (optional)</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label for="searchOld" style="font-size: 12px;">Old URL / string</label>
                            <input type="text" id="searchOld" placeholder="http://localhost:8080">
                        </div>
                        <div>
                            <label for="searchNew" style="font-size: 12px;">New URL / string</label>
                            <input type="text" id="searchNew" placeholder="https://example.com">
                        </div>
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 12px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="wpSearchReplace" checked style="width: auto;">
                        Safely update WordPress serialized strings after import
                    </label>
                </div>
                <button type="button" id="startImportBtn" class="btn-success">Start Database Import</button>
            </div>

            <div id="exportTab" class="tab-content">
                <div class="input-group">
                    <label for="compression">Compression</label>
                    <select id="compression">
                        <option value="gzip" selected>GZIP (.sql.gz) — recommended</option>
                        <option value="none">Plain SQL (.sql)</option>
                    </select>
                </div>
                <div class="table-selector">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label style="margin: 0;">Select tables</label>
                        <div>
                            <button type="button" class="btn-sm btn-secondary" id="selectAllBtn">All</button>
                            <button type="button" class="btn-sm btn-secondary" id="selectNoneBtn">None</button>
                        </div>
                    </div>
                    <div class="table-list" id="tableList">
                        <div style="padding: 10px; color: #94a3b8; text-align: center;">Loading tables...</div>
                    </div>
                </div>
                <button type="button" id="startExportBtn">Start Database Export</button>
            </div>

            <div id="copyTab" class="tab-content">
                <div class="input-group">
                    <label for="destHost">Destination host</label>
                    <input type="text" id="destHost" placeholder="127.0.0.1" autocomplete="off">
                </div>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
                    <div class="input-group">
                        <label for="destName">Destination database</label>
                        <input type="text" id="destName" placeholder="destination_db" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label for="destPort">Port</label>
                        <input type="number" id="destPort" value="3306">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="input-group">
                        <label for="destUser">Username</label>
                        <input type="text" id="destUser" placeholder="db_user" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label for="destPass">Password</label>
                        <input type="password" id="destPass" placeholder="••••••••" autocomplete="new-password">
                    </div>
                </div>
                <button type="button" id="startCopyBtn">Start Direct Copy</button>
            </div>

            <div id="filesTab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 15px;">Files in db_exports/</h3>
                    <button type="button" class="btn-sm btn-secondary" id="refreshFilesBtn">Refresh</button>
                </div>
                <ul class="file-list" id="fileList"></ul>
                <button type="button" id="destroyBtn" class="btn-danger" style="margin-top: 16px;">Delete this tool and all dumps</button>
            </div>

            <div class="progress-bar" id="progressBar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="status" id="statusBox"></div>

            <script>
                const API_URL = <?= json_encode(scriptName(), JSON_UNESCAPED_SLASHES) ?>;
                const CSRF = <?= json_encode((string) $_SESSION['csrf_token']) ?>;
                let isRunning = false;

                function escapeHtml(s) {
                    return String(s).replace(/[&<>"'`]/g, c => ({
                        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;'
                    }[c]));
                }

                function switchTab(tabId) {
                    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === tabId));
                }

                function formatBytes(bytes) {
                    if (!bytes) return '0 Bytes';
                    const k = 1024;
                    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                    const i = Math.min(sizes.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                }

                function showStatus(msg, type = 'info') {
                    const el = document.getElementById('statusBox');
                    el.textContent = msg;
                    el.className = 'status ' + type;
                    el.style.display = 'block';
                }

                function updateProgress(pct) {
                    document.getElementById('progressBar').style.display = 'block';
                    document.getElementById('progressFill').style.width = Math.min(100, Math.max(0, pct)) + '%';
                }

                async function apiCall(action, body = null, params = null) {
                    const url = new URL(API_URL, window.location.href);
                    url.searchParams.set('action', action);
                    if (params) {
                        Object.keys(params).forEach(k => url.searchParams.set(k, params[k]));
                    }
                    const opts = {
                        method: body !== null ? 'POST' : 'GET',
                        headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    };
                    if (body !== null) {
                        opts.headers['Content-Type'] = 'application/json';
                        opts.body = JSON.stringify(body);
                    }
                    const res = await fetch(url.toString(), opts);
                    const text = await res.text();
                    let data;
                    try { data = JSON.parse(text); } catch (e) {
                        throw new Error(res.ok ? 'Invalid JSON response' : ('HTTP ' + res.status));
                    }
                    if (!res.ok || data.error) {
                        throw new Error(data.error || ('HTTP ' + res.status));
                    }
                    return data;
                }

                async function loadTables() {
                    try {
                        const data = await apiCall('get_tables');
                        const list = document.getElementById('tableList');
                        if (!data.tables || data.tables.length === 0) {
                            list.innerHTML = '<div style="padding:10px;color:#94a3b8;text-align:center;">No tables found</div>';
                            return;
                        }
                        list.innerHTML = data.tables.map((t, i) => `
                            <div class="table-item">
                                <input type="checkbox" id="tbl_${i}" value="${escapeHtml(t)}" checked>
                                <label for="tbl_${i}">${escapeHtml(t)}</label>
                            </div>
                        `).join('');
                    } catch (e) {
                        document.getElementById('tableList').innerHTML = '<div style="padding:10px;color:#991b1b;">' + escapeHtml(e.message) + '</div>';
                    }
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
                                        <span class="file-name">${escapeHtml(f.name)}</span>
                                        <span class="file-meta">${escapeHtml(formatBytes(f.size))} • ${escapeHtml(new Date(f.date * 1000).toLocaleString())}</span>
                                    </div>
                                    <div class="file-actions">
                                        <button type="button" class="btn-sm btn-success" data-restore="${escapeHtml(f.name)}">Restore</button>
                                        <a class="btn-sm" style="display:inline-block;text-decoration:none;line-height:20px;" href="${escapeHtml(API_URL)}?action=download&amp;file=${encodeURIComponent(f.name)}">Download</a>
                                        <button type="button" class="btn-sm btn-danger" data-delete="${escapeHtml(f.name)}">Delete</button>
                                    </div>
                                </li>
                            `).join('');
                            select.innerHTML = '<option value="">-- Choose a file from db_exports/ --</option>' + files.map(f =>
                                `<option value="${escapeHtml(f.name)}">${escapeHtml(f.name)} (${escapeHtml(formatBytes(f.size))})</option>`
                            ).join('');
                        } else {
                            list.innerHTML = '<li style="color:#94a3b8; justify-content:center;">No backup files found in db_exports/</li>';
                            select.innerHTML = '<option value="">-- No backup files found --</option>';
                        }
                    } catch (e) {
                        console.error('Failed to load files', e);
                    }
                }

                async function deleteFile(filename) {
                    if (!confirm('Delete ' + filename + '?')) return;
                    try {
                        await apiCall('delete', {}, { file: filename });
                        await loadFiles();
                    } catch (e) {
                        showStatus('Delete failed: ' + e.message, 'error');
                    }
                }

                let isUploading = false;

                async function uploadFileInChunks(file, onProgress) {
                    if (!file || !file.size) {
                        throw new Error('File is empty');
                    }
                    const filename = (file.name || '').split(/[/\\\\]/).pop();
                    if (!/\.(sql|gz|zip)$/i.test(filename)) {
                        throw new Error('Only .sql, .sql.gz, and .zip files are allowed');
                    }
                    const chunkSize = 2 * 1024 * 1024;
                    const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
                    const startTime = Date.now();

                    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                        const start = chunkIndex * chunkSize;
                        const end = Math.min(start + chunkSize, file.size);
                        const chunkBlob = file.slice(start, end);

                        const formData = new FormData();
                        formData.append('filename', filename);
                        formData.append('chunk_index', chunkIndex.toString());
                        formData.append('total_chunks', totalChunks.toString());
                        formData.append('chunk', chunkBlob, filename);

                        const url = new URL(API_URL, window.location.href);
                        url.searchParams.set('action', 'upload_chunk');

                        const res = await fetch(url.toString(), {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
                            body: formData,
                            credentials: 'same-origin'
                        });

                        const data = await res.json();
                        if (!res.ok || data.error) {
                            throw new Error(data.error || ('HTTP ' + res.status));
                        }

                        if (onProgress) {
                            const percent = Math.round((end / file.size) * 100);
                            const elapsedSec = (Date.now() - startTime) / 1000;
                            const speedBps = elapsedSec > 0 ? (end / elapsedSec) : 0;
                            const remainingBytes = file.size - end;
                            const etaSec = speedBps > 0 ? Math.ceil(remainingBytes / speedBps) : 0;
                            onProgress(percent, end, file.size, speedBps, etaSec);
                        }
                    }
                    return filename;
                }

                async function uploadSelectedFile(file) {
                    if (isUploading) return;
                    isUploading = true;
                    showStatus('Uploading ' + file.name + ' (0%)...', 'info');
                    updateProgress(0);

                    try {
                        const uploadedName = await uploadFileInChunks(file, (pct, bytes, total, speed, eta) => {
                            updateProgress(pct);
                            const speedText = speed > 0 ? (' • ' + formatBytes(speed) + '/s') : '';
                            const etaText = eta > 0 ? (' • ' + eta + 's remaining') : '';
                            showStatus('Uploading ' + file.name + ': ' + pct + '% (' + formatBytes(bytes) + ' / ' + formatBytes(total) + speedText + etaText + ')', 'info');
                        });

                        await loadFiles();
                        const select = document.getElementById('importFileSelect');
                        select.value = uploadedName;
                        updateProgress(100);
                        showStatus('✓ Upload complete: ' + uploadedName + ' (' + formatBytes(file.size) + '). Ready to import.', 'success');
                        return uploadedName;
                    } catch (e) {
                        showStatus('Upload failed: ' + e.message, 'error');
                        throw e;
                    } finally {
                        isUploading = false;
                    }
                }

                async function uploadDumpFile() {
                    const fileInput = document.getElementById('uploadFileInput');
                    if (!fileInput.files || fileInput.files.length === 0) return;
                    const file = fileInput.files[0];
                    try {
                        await uploadSelectedFile(file);
                    } catch (e) {
                        console.error(e);
                    }
                }

                async function startImport() {
                    if (isRunning || isUploading) {
                        alert(isUploading ? 'File upload is still in progress. Please wait a moment.' : 'An operation is already running.');
                        return;
                    }

                    let file = document.getElementById('importFileSelect').value;
                    const fileInput = document.getElementById('uploadFileInput');
                    const pickedFile = (!file && fileInput.files && fileInput.files.length > 0) ? fileInput.files[0] : null;
                    const label = file || (pickedFile ? pickedFile.name : '');

                    if (!label) {
                        alert('Please select a dump file to import (choose from dropdown or select a file to upload)');
                        return;
                    }

                    if (!confirm('WARNING: Importing ' + label + ' will execute SQL and may overwrite tables. Continue?')) return;

                    if (pickedFile) {
                        try {
                            file = await uploadSelectedFile(pickedFile);
                        } catch (e) {
                            return;
                        }
                    }

                    isRunning = true;
                    document.getElementById('startImportBtn').disabled = true;
                    showStatus('Initializing database import...', 'info');
                    updateProgress(0);
                    try {
                        const init = await apiCall('init_import', {
                            file,
                            search_old: document.getElementById('searchOld').value.trim(),
                            search_new: document.getElementById('searchNew').value.trim(),
                            wp_search_replace: document.getElementById('wpSearchReplace').checked
                        });
                        const importStartTime = Date.now();
                        while (true) {
                            const chunk = await apiCall('process_import', {});
                            updateProgress(chunk.percent || 0);

                            let extra = '';
                            if (!chunk.done && chunk.offset > 0 && chunk.total_size > 0) {
                                const elapsed = (Date.now() - importStartTime) / 1000;
                                const speed = elapsed > 0 ? (chunk.offset / elapsed) : 0;
                                const remaining = speed > 0 ? Math.ceil((chunk.total_size - chunk.offset) / speed) : 0;
                                const speedText = speed > 0 ? (' • ' + formatBytes(speed) + '/s') : '';
                                const etaText = remaining > 0 ? (' • ' + (remaining >= 60 ? Math.ceil(remaining / 60) + 'm' : remaining + 's') + ' remaining') : '';
                                extra = speedText + etaText;
                            }

                            showStatus((chunk.message || '') + extra, chunk.done ? 'success' : 'info');
                            if (chunk.done) break;
                            await new Promise(r => setTimeout(r, 40));
                        }
                    } catch (err) {
                        showStatus('Import error: ' + err.message, 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('startImportBtn').disabled = false;
                    }
                }

                async function startExport() {
                    if (isRunning) return;
                    const selected = Array.from(document.querySelectorAll('#tableList input:checked')).map(c => c.value);
                    if (selected.length === 0) { alert('Please select at least one table'); return; }
                    isRunning = true;
                    document.getElementById('startExportBtn').disabled = true;
                    showStatus('Initializing export...', 'info');
                    updateProgress(0);
                    try {
                        const init = await apiCall('init', {
                            compression: document.getElementById('compression').value,
                            tables: selected
                        });
                        showStatus(init.message, 'info');
                        while (true) {
                            const chunk = await apiCall('process', {});
                            const pct = chunk.total > 0 ? Math.round((chunk.rows / chunk.total) * 100) : 50;
                            updateProgress(pct);
                            showStatus(chunk.message, chunk.done ? 'success' : 'info');
                            if (chunk.done) { await loadFiles(); break; }
                            await new Promise(r => setTimeout(r, 80));
                        }
                    } catch (err) {
                        showStatus('Export error: ' + err.message, 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('startExportBtn').disabled = false;
                    }
                }

                async function startCopy() {
                    if (isRunning) return;
                    const destHost = document.getElementById('destHost').value.trim();
                    const destName = document.getElementById('destName').value.trim();
                    const destUser = document.getElementById('destUser').value.trim();
                    const destPass = document.getElementById('destPass').value;
                    const destPort = parseInt(document.getElementById('destPort').value, 10) || 3306;
                    if (!destHost || !destName || !destUser) {
                        alert('Please fill in destination host, database name, and username');
                        return;
                    }
                    const tablesData = await apiCall('get_tables');
                    const tables = tablesData.tables || [];
                    if (tables.length === 0) { alert('No tables found to copy'); return; }
                    if (!confirm('Copy ' + tables.length + ' tables to ' + destName + ' on ' + destHost + '?')) return;

                    isRunning = true;
                    document.getElementById('startCopyBtn').disabled = true;
                    showStatus('Starting database copy...', 'info');
                    updateProgress(0);
                    try {
                        for (let i = 0; i < tables.length; i++) {
                            const table = tables[i];
                            let offset = 0;
                            showStatus('Copying table (' + (i + 1) + '/' + tables.length + '): ' + table + '...', 'info');
                            while (true) {
                                const chunk = await apiCall('copy_table_chunk', {
                                    table, offset, chunk_size: 5000,
                                    dest_host: destHost, dest_port: destPort, dest_name: destName, dest_user: destUser, dest_pass: destPass
                                });
                                offset = chunk.offset;
                                updateProgress(Math.round(((i + (chunk.done ? 1 : 0.5)) / tables.length) * 100));
                                if (chunk.done) break;
                                await new Promise(r => setTimeout(r, 40));
                            }
                        }
                        const fin = await apiCall('finalize_copy', {
                            dest_host: destHost, dest_port: destPort, dest_name: destName, dest_user: destUser, dest_pass: destPass
                        });
                        updateProgress(100);
                        showStatus(fin.message || ('Database copy completed! ' + tables.length + ' tables copied.'), 'success');
                    } catch (err) {
                        showStatus('Copy error: ' + err.message, 'error');
                    } finally {
                        isRunning = false;
                        document.getElementById('startCopyBtn').disabled = false;
                    }
                }

                async function destroyTool() {
                    if (!confirm('This permanently deletes db-dump.php, the password file, and every file in db_exports/.')) return;
                    const phrase = prompt('Type DELETE to confirm');
                    if (phrase !== 'DELETE') return;
                    try {
                        const data = await apiCall('destroy', { confirm: 'DELETE' });
                        const failed = (data.failed || []).join(', ');
                        showStatus(failed ? ('Partially deleted. Could not remove: ' + failed) : 'Tool and dumps deleted. You can close this tab.', failed ? 'error' : 'success');
                    } catch (e) {
                        showStatus('Destroy failed: ' + e.message, 'error');
                    }
                }

                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
                });
                document.getElementById('selectAllBtn').addEventListener('click', () => {
                    document.querySelectorAll('#tableList input[type="checkbox"]').forEach(c => { c.checked = true; });
                });
                document.getElementById('selectNoneBtn').addEventListener('click', () => {
                    document.querySelectorAll('#tableList input[type="checkbox"]').forEach(c => { c.checked = false; });
                });
                document.getElementById('refreshFilesBtn').addEventListener('click', loadFiles);
                document.getElementById('uploadFileInput').addEventListener('change', uploadDumpFile);
                document.getElementById('startImportBtn').addEventListener('click', startImport);
                document.getElementById('startExportBtn').addEventListener('click', startExport);
                document.getElementById('startCopyBtn').addEventListener('click', startCopy);
                document.getElementById('destroyBtn').addEventListener('click', destroyTool);
                document.getElementById('fileList').addEventListener('click', (e) => {
                    const restore = e.target.closest('[data-restore]');
                    const del = e.target.closest('[data-delete]');
                    if (restore) {
                        switchTab('importTab');
                        document.getElementById('importFileSelect').value = restore.getAttribute('data-restore');
                    }
                    if (del) deleteFile(del.getAttribute('data-delete'));
                });

                loadTables();
                loadFiles();
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
