<?php
/**
 * _ase.php — shared helpers for every ASE PHP endpoint (v6.1.0)
 *
 * Not an endpoint: .htaccess denies direct access. Include it with
 *   require_once __DIR__ . '/_ase.php';
 *
 * What lives here
 *   - JSON/response helpers and atomic, locked file IO
 *   - Demo-mode detection (ase.live is strictly read-only)
 *   - Server-side authentication: a signed, HttpOnly, SameSite=Strict cookie
 *   - Cron token (file based, never stored in the repository)
 *   - Tiny file-based rate limiter
 *
 * Threat model, honestly: ASE is a single-user dashboard. One 6-digit PIN is
 * the only credential. It is verified on the server (bcrypt), attempts are
 * rate-limited per IP and globally, and sessions are signed with a random
 * per-install secret. A 6-digit PIN is still weak against a determined,
 * distributed attacker: for anything sensitive, also put the directory behind
 * HTTP Basic Auth or an IP allow-list at the web server (see INSTALL.md).
 */

if (defined('ASE_LIB')) { return; }
define('ASE_LIB', '6.1.0');

define('ASE_CONFIG_FILE', __DIR__ . '/ase_config.json');
define('ASE_SECRET_FILE', __DIR__ . '/auth_secret.key');
define('ASE_CRON_FILE',   __DIR__ . '/cron_token.key');
define('ASE_RATE_FILE',   __DIR__ . '/auth_rate.json');
define('ASE_COOKIE',      'ase_sid');
define('ASE_SESSION_TTL', 30 * 86400);
define('ASE_DEFAULT_PIN', '123456');

/* ── Responses ───────────────────────────────────────────────── */

function ase_headers(): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }
}

function ase_out($data, int $code = 200): void {
    ase_headers();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ase_fail(int $code, string $msg, array $extra = []): void {
    ase_out(['ok' => false, 'error' => $msg] + $extra, $code);
}

/* ── Atomic JSON IO ──────────────────────────────────────────── */

function ase_read_json(string $file): array {
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    $dec = $raw === false ? null : json_decode($raw, true);
    return is_array($dec) ? $dec : [];
}

function ase_write_json(string $file, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}

function ase_config(): array { return ase_read_json(ASE_CONFIG_FILE); }

/**
 * Serialise every read-modify-write of ase_config.json for the rest of this
 * request (the lock is released when the script ends). Call it BEFORE reading
 * the config you intend to change. Safe to call repeatedly.
 */
function ase_config_lock(): void {
    static $fp = null;
    if ($fp) return;
    $fp = @fopen(__DIR__ . '/.ase_config.lock', 'c');
    if ($fp) flock($fp, LOCK_EX);
}

function ase_config_save(array $cfg): bool {
    $cfg['updated_at'] = gmdate('c');
    return ase_write_json(ASE_CONFIG_FILE, $cfg);
}

/** Read a secret file, creating it with random content when missing. */
function ase_secret(string $file, int $bytes = 32): string {
    if (is_file($file)) {
        $s = trim((string)@file_get_contents($file));
        if ($s !== '') return $s;
    }
    $s = bin2hex(random_bytes($bytes));
    @file_put_contents($file, $s, LOCK_EX);
    @chmod($file, 0600);
    return $s;
}

/* ── Request context ─────────────────────────────────────────── */

function ase_host(): string {
    return strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
}

/**
 * True when a file named demo.flag exists next to this script. Demo mode is
 * deliberately NOT derived from the Host header (a client can send any Host).
 * The public demo at ase.live ships with a demo.flag; nobody else needs one.
 */
function ase_is_demo(): bool {
    return php_sapi_name() !== 'cli' && is_file(__DIR__ . '/demo.flag');
}

function ase_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function ase_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'); }

/** Blocks the request in demo mode: demo is strictly read-only. */
function ase_deny_in_demo(): void {
    if (ase_is_demo()) ase_fail(403, 'Disabled in the public demo', ['demo' => true]);
}

/**
 * CSRF guard for state-changing calls. Browsers cannot attach a custom header
 * cross-origin without a CORS preflight, which ASE never grants.
 */
function ase_require_xhr(): void {
    if (($_SERVER['HTTP_X_ASE'] ?? '') !== '1') ase_fail(400, 'Missing X-ASE header');
}

/* ── Rate limiting ───────────────────────────────────────────── */

/**
 * Sliding-window limiter backed by one locked JSON file.
 *   ase_rate_hit($key, $max, $window)            reserve one slot; false = over budget
 *   ase_rate_hit($key, $max, $window, true, true) same, but fail CLOSED if the file is unusable
 *   ase_rate_refund($key)                        give back the most recent slot
 * Reserving happens before the secret is checked, so parallel guesses cannot
 * all slip through the same budget.
 */
function ase_rate_hit(string $key, int $max, int $window, bool $count = true, bool $failClosed = false): bool {
    $fp = @fopen(ASE_RATE_FILE, 'c+');
    if (!$fp) return !$failClosed;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '[]', true);
    if (!is_array($data)) $data = [];
    $now = time();
    foreach ($data as $k => $list) {
        $data[$k] = array_values(array_filter($list, function ($t) use ($now) { return $t > $now - 3600; }));
        if (!$data[$k]) unset($data[$k]);
    }
    $list = array_values(array_filter($data[$key] ?? [], function ($t) use ($now, $window) { return $t > $now - $window; }));
    $ok = count($list) < $max;
    if ($count && $ok) { $list[] = $now; }
    $data[$key] = $list;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN); fclose($fp);
    @chmod(ASE_RATE_FILE, 0600);
    return $ok;
}

function ase_rate_refund(string $key): void {
    $fp = @fopen(ASE_RATE_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '[]', true);
    if (is_array($data) && !empty($data[$key])) {
        array_pop($data[$key]);
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($data));
    }
    flock($fp, LOCK_UN); fclose($fp);
}

/* ── PIN storage + verification ──────────────────────────────── */

function ase_pin_is_default(array $cfg): bool {
    if (!empty($cfg['pin_pw'])) return false;
    if (!empty($cfg['pin_hash'])) return hash_equals(hash('sha256', ASE_DEFAULT_PIN), (string)$cfg['pin_hash']);
    return true;
}

/** Verify a PIN. Transparently upgrades a legacy SHA-256 hash to bcrypt. */
function ase_pin_verify(string $pin, array &$cfg): bool {
    if (!preg_match('/^\d{6}$/', $pin)) return false;
    if (!empty($cfg['pin_pw'])) return password_verify($pin, (string)$cfg['pin_pw']);
    if (!empty($cfg['pin_hash'])) {
        if (!hash_equals((string)$cfg['pin_hash'], hash('sha256', $pin))) return false;
        if ($pin !== ASE_DEFAULT_PIN) {          /* upgrade legacy hash */
            ase_config_lock();
            $cfg = ase_config();
            $cfg['pin_pw'] = password_hash($pin, PASSWORD_DEFAULT);
            unset($cfg['pin_hash']);
            ase_config_save($cfg);
        }
        return true;
    }
    return hash_equals(ASE_DEFAULT_PIN, $pin);   /* fresh install */
}

function ase_pin_set(array &$cfg, string $pin): void {
    $cfg['pin_pw'] = password_hash($pin, PASSWORD_DEFAULT);
    unset($cfg['pin_hash']);
}

/* ── Session cookie (stateless, HMAC-signed) ─────────────────── */

/** Changing the PIN, or signing out, rotates this tag and invalidates every issued cookie. */
function ase_pin_version(array $cfg): string {
    return substr(hash('sha256', (string)($cfg['pin_pw'] ?? $cfg['pin_hash'] ?? 'default') . '|' . (string)($cfg['session_epoch'] ?? '')), 0, 12);
}

/** Server-side sign-out: rotate the epoch so stolen/copied cookies stop working too. */
function ase_session_revoke_all(): void {
    ase_config_lock();
    $cfg = ase_config();
    $cfg['session_epoch'] = bin2hex(random_bytes(8));
    ase_config_save($cfg);
    ase_session_clear();
}

function ase_session_issue(array $cfg): void {
    $exp = time() + ASE_SESSION_TTL;
    $msg = 'v1.' . $exp . '.' . ase_pin_version($cfg);
    $sig = hash_hmac('sha256', $msg, ase_secret(ASE_SECRET_FILE));
    $val = $msg . '.' . $sig;
    setcookie(ASE_COOKIE, $val, [
        'expires' => $exp, 'path' => '/', 'secure' => ase_is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    $_COOKIE[ASE_COOKIE] = $val;
}

function ase_session_clear(): void {
    setcookie(ASE_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => ase_is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    unset($_COOKIE[ASE_COOKIE]);
}

/**
 * Valid session cookie? While the factory-default PIN is still active the
 * session only counts for the PIN-setup call ($forSetup = true): a stranger who
 * guesses 123456 on a fresh install can pick the PIN, but cannot read or change
 * anything else before that.
 */
function ase_authenticated(bool $forSetup = false): bool {
    $c = (string)($_COOKIE[ASE_COOKIE] ?? '');
    $p = explode('.', $c);
    if (count($p) !== 4 || $p[0] !== 'v1' || !ctype_digit($p[1]) || (int)$p[1] < time()) return false;
    $msg = $p[0] . '.' . $p[1] . '.' . $p[2];
    if (!hash_equals(hash_hmac('sha256', $msg, ase_secret(ASE_SECRET_FILE)), $p[3])) return false;
    $cfg = ase_config();
    if (!hash_equals(ase_pin_version($cfg), $p[2])) return false;
    return $forSetup || !ase_pin_is_default($cfg);
}

/* ── Cron token ──────────────────────────────────────────────── */

/** The token lives in cron_token.key (never in the repo). Null = HTTP cron disabled. */
function ase_cron_token(): ?string {
    if (!is_file(ASE_CRON_FILE)) return null;
    $t = trim((string)@file_get_contents(ASE_CRON_FILE));
    return strlen($t) >= 24 ? $t : null;
}

function ase_cron_ok(): bool {
    $tok = ase_cron_token();
    if ($tok === null) return false;
    $given = (string)($_SERVER['HTTP_X_ASE_TOKEN'] ?? ($_GET['token'] ?? ''));
    return $given !== '' && hash_equals($tok, $given);
}

/** Gate for endpoints a browser session or the cron job may call. */
function ase_require_auth(bool $allowCron = false): void {
    if (php_sapi_name() === 'cli') return;
    if ($allowCron && ase_cron_ok()) return;
    if (ase_authenticated()) return;
    ase_fail(401, 'Authentication required', ['auth' => false]);
}

/**
 * Resolve a hostname and return one public IPv4 address, or null when the name
 * is malformed, does not resolve, or resolves to ANY private/reserved range
 * (SSRF guard). Callers connect to the returned IP, so DNS cannot change
 * between the check and the connection.
 */
function ase_public_ip(string $host): ?string {
    if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/i', $host)) return null;
    $ips = @gethostbynamel($host);
    if (!$ips) return null;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    }
    return $ips[0];
}
