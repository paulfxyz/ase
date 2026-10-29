<?php
/**
 * auth.php — server-side authentication for ASE (v6.1.0)
 *
 *   GET  auth.php                       → { authenticated, demo, version }
 *   POST auth.php {action:"login", pin} → sets the signed session cookie
 *   POST auth.php {action:"logout"}
 *   POST auth.php {action:"set_pin", new}            (only while the default PIN is active)
 *   POST auth.php {action:"change_pin", current, new}
 *
 * Signing out rotates the session epoch, i.e. it signs out every device.
 *
 * Every POST needs the header "X-ASE: 1" (CSRF guard) and, outside the demo,
 * is rate-limited: 5 failed logins / 15 min per IP, 30 / hour for the whole
 * install (the budget is reserved before the PIN is checked). If you lock yourself out, delete auth_rate.json via FTP.
 */
require_once __DIR__ . '/_ase.php';

/* CLI: php auth.php set-pin 482915   — choose your PIN before the site is ever reachable */
if (php_sapi_name() === 'cli') {
    $cmd = $argv[1] ?? '';
    if ($cmd === 'set-pin' && preg_match('/^\d{6}$/', (string)($argv[2] ?? '')) && $argv[2] !== ASE_DEFAULT_PIN) {
        ase_config_lock();
        $cfg = ase_config(); ase_pin_set($cfg, $argv[2]); $cfg['session_epoch'] = bin2hex(random_bytes(8));
        echo ase_config_save($cfg) ? "PIN saved.\n" : "Could not write ase_config.json\n";
    } else {
        echo "Usage: php auth.php set-pin <6 digits, not 123456>\n";
    }
    exit;
}
ase_headers();

$demo   = ase_is_demo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    ase_out(['ok' => true, 'authenticated' => $demo ? false : ase_authenticated(), 'demo' => $demo, 'version' => ASE_LIB]);
}
if ($method !== 'POST') ase_fail(405, 'Method not allowed');

ase_require_xhr();
if ($demo) ase_fail(403, 'The public demo has no login', ['demo' => true]);

$in     = json_decode((string)file_get_contents('php://input'), true);
$in     = is_array($in) ? $in : [];
$action = (string)($in['action'] ?? '');
$cfg    = ase_config();
$ipKey  = 'fail:' . hash('sha256', ase_ip());

/** Reserve one attempt (per IP and install-wide) BEFORE checking a secret; fail closed. */
function ase_guard_attempts(string $ipKey): void {
    if (!ase_rate_hit($ipKey, 5, 900, true, true)) ase_fail(429, 'Too many attempts — try again in 15 minutes');
    if (!ase_rate_hit('fail:global', 30, 3600, true, true)) {
        ase_rate_refund($ipKey);
        ase_fail(429, 'Too many attempts — try again later');
    }
}
/** The secret was right: give the reserved attempt back. */
function ase_attempt_ok(string $ipKey): void { ase_rate_refund($ipKey); ase_rate_refund('fail:global'); }
function ase_register_fail(): void { usleep(600000); }

if ($action === 'login') {
    ase_guard_attempts($ipKey);
    $pin = (string)($in['pin'] ?? '');
    if (!ase_pin_verify($pin, $cfg)) { ase_register_fail(); ase_fail(401, 'Incorrect PIN'); }
    ase_attempt_ok($ipKey);
    ase_session_issue(ase_config());
    ase_out(['ok' => true, 'authenticated' => true, 'must_change' => ase_pin_is_default(ase_config())]);
}

if ($action === 'logout') {
    if (ase_authenticated(true)) ase_session_revoke_all();   /* signs out every device */
    else ase_session_clear();
    ase_out(['ok' => true]);
}

/* Everything below needs a valid session */
if (!ase_authenticated(true)) ase_fail(401, 'Authentication required', ['auth' => false]);
ase_config_lock();
$cfg = ase_config();   /* re-read under the lock */

$new = (string)($in['new'] ?? '');
if (!preg_match('/^\d{6}$/', $new)) ase_fail(400, 'The new PIN must be exactly 6 digits');
if ($new === ASE_DEFAULT_PIN)        ase_fail(400, 'Choose a PIN other than the default');
if (preg_match('/^(\d)\1{5}$/', $new)) ase_fail(400, 'Choose a less predictable PIN');

if ($action === 'set_pin') {
    if (!ase_pin_is_default($cfg)) ase_fail(403, 'A custom PIN is already set — use change_pin');
} elseif ($action === 'change_pin') {
    ase_guard_attempts($ipKey);
    if (!ase_pin_verify((string)($in['current'] ?? ''), $cfg)) { ase_register_fail(); ase_fail(401, 'Current PIN is incorrect'); }
    ase_attempt_ok($ipKey);
    $cfg = ase_config();
} else {
    ase_fail(400, 'Unknown action');
}

ase_pin_set($cfg, $new);
if (!ase_config_save($cfg)) ase_fail(500, 'Could not write ase_config.json — check directory permissions');
ase_session_issue($cfg);                 /* old sessions die, this browser stays signed in */
ase_out(['ok' => true]);
