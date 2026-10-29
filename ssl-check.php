<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  THE ALL SEEING EYE — ssl-check.php  v2.0.0                  ║
 * ║                                                              ║
 * ║  Server-side SSL certificate expiry checker.                 ║
 * ║  Called by the browser dashboard instead of crt.sh because: ║
 * ║   - PHP can open real TLS connections (browser cannot)       ║
 * ║   - No CORS issues (same-origin request)                     ║
 * ║   - Works for any domain, including small/private ones       ║
 * ║   - crt.sh has CT log gaps and frequent timeouts             ║
 * ║                                                              ║
 * ║  BATCH MODE (v2.0.0)                                         ║
 * ║  Supports checking multiple domains in a single HTTP request ║
 * ║  to avoid 34 separate browser→PHP calls.                     ║
 * ║                                                              ║
 * ║  Single:  GET /ssl-check.php?domain=example.com              ║
 * ║  Batch:   GET /ssl-check.php?domains=a.com,b.com,c.com       ║
 * ║                                                              ║
 * ║  Response:                                                   ║
 * ║  Single: {"domain":"…","expiry":"2026-06-06","issuer":"LE",  ║
 * ║           "days_remaining":76}                               ║
 * ║  Batch:  [{"domain":"…","expiry":"…"},{"domain":"…",...}]    ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

/* ── Output headers ─────────────────────────────────────────── */
require_once __DIR__ . '/_ase.php';
ase_headers();
header('Cache-Control: private, max-age=1800');   /* same-origin only: no CORS header on purpose */

/* v6.1.0 — this endpoint opens outbound TLS connections, so it must not be an
   open scanner: sign-in required (the demo is limited to its own watchlist),
   private/reserved addresses are refused, and callers are rate-limited. */
$demo = ase_is_demo();
if (!$demo) ase_require_auth();
if (!ase_rate_hit('ssl:' . hash('sha256', ase_ip()), 400, 600)) ase_fail(429, 'Rate limit reached');
@set_time_limit(60);
$GLOBALS['ase_allowed'] = null;
if ($demo) {
    $list = is_file(__DIR__ . '/domains.list') ? file(__DIR__ . '/domains.list', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $GLOBALS['ase_allowed'] = array_flip(array_map('strtolower', array_map('trim', $list)));
}

/* ── Helper: validate a hostname ────────────────────────────── */
function is_valid_hostname(string $h): bool {
    return (bool) preg_match('/^[a-zA-Z0-9\.\-]{1,253}$/', $h)
        && str_contains($h, '.');
}

/**
 * Connect to port 443 and return cert metadata, or an error string.
 *
 * @param  string $domain
 * @param  int    $timeout  seconds
 * @return array  keys: domain, expiry, issuer, days_remaining, valid | error
 */
function check_ssl_live(string $domain, int $timeout = 5): array {
    $domain = strtolower(trim($domain));
    if ($GLOBALS['ase_allowed'] !== null && !isset($GLOBALS['ase_allowed'][$domain])) {
        return ['domain' => $domain, 'error' => 'Not in the demo watchlist'];
    }
    $ip = ase_public_ip($domain);
    if ($ip === null) {
        return ['domain' => $domain, 'error' => 'Host does not resolve to a public address'];
    }

    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false, /* want data even for expired/misconfigured certs */
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
            'peer_name'         => $domain,
        ]
    ]);

    $stream = @stream_socket_client(
        'ssl://' . $ip . ':443',
        $errno, $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$stream) {
        return ['domain' => $domain, 'error' => $errstr ?: 'Connection failed (errno '.$errno.')'];
    }

    $params = stream_context_get_params($stream);
    fclose($stream);

    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) {
        return ['domain' => $domain, 'error' => 'No certificate in handshake'];
    }

    $info = openssl_x509_parse($cert);
    if (!$info) {
        return ['domain' => $domain, 'error' => 'Certificate parse failed'];
    }

    $validTo = $info['validTo_time_t'] ?? null;
    if (!$validTo) {
        return ['domain' => $domain, 'error' => 'No expiry date in certificate'];
    }

    /* Detect issuer — Let's Encrypt uses CN patterns R3, R10, R11, E5, E6, E7… */
    $cn    = $info['issuer']['CN'] ?? $info['issuer']['O'] ?? 'Unknown';
    $isLE  = stripos($cn, "let's encrypt") !== false
          || preg_match('/^[RE]\d+$/', $cn);
    $issuer = $isLE ? 'LE' : (strlen($cn) > 25 ? substr($cn, 0, 25) : $cn);

    $days = (int) round(($validTo - time()) / 86400);

    return [
        'domain'         => $domain,
        'expiry'         => date('Y-m-d', $validTo),
        'issuer'         => $issuer,
        'days_remaining' => $days,
        'valid'          => $days > 0,
    ];
}

/**
 * Cached wrapper: a certificate does not change by the minute, and on the
 * public demo every visitor would otherwise trigger 100 TLS handshakes.
 * Successful results are kept for 6 hours in ssl_cache.json; errors are never cached.
 */
function check_ssl(string $domain): array {
    static $cache = null, $dirty = false;
    $file = __DIR__ . '/ssl_cache.json';
    if ($cache === null) {
        $cache = ase_read_json($file);
        register_shutdown_function(function () use (&$cache, &$dirty, $file) {
            if ($dirty) { ase_write_json($file, $cache); }
        });
    }
    $domain = strtolower(trim($domain));
    if (isset($cache[$domain]) && (time() - ($cache[$domain]['at'] ?? 0)) < 21600) {
        return $cache[$domain]['r'];
    }
    $r = check_ssl_live($domain);
    if (empty($r['error'])) {
        $r['days_remaining'] = (int) round((strtotime($r['expiry'] . ' 23:59:59 UTC') - time()) / 86400);
        $cache[$domain] = ['at' => time(), 'r' => $r];
        if (count($cache) > 600) { $cache = array_slice($cache, -500, null, true); }
        $dirty = true;
    }
    return $r;
}

/* ── Route: batch (preferred) ───────────────────────────────── */
if (!empty($_GET['domains'])) {
    $raw     = $_GET['domains'];
    $parts   = array_slice(explode(',', $raw), 0, 50); /* cap at 50 domains per request */
    $results = [];

    $deadline = microtime(true) + 45;                 /* total time budget */
    foreach ($parts as $raw_domain) {
        $domain = strtolower(trim($raw_domain));
        if (microtime(true) > $deadline) { $results[] = ['domain' => $domain, 'error' => 'time budget exceeded']; continue; }
        if (!is_valid_hostname($domain)) {
            $results[] = ['domain' => $domain, 'error' => 'Invalid hostname'];
            continue;
        }
        $results[] = check_ssl($domain);
    }

    echo json_encode($results);
    exit;
}

/* ── Route: single domain ───────────────────────────────────── */
$domain = strtolower(trim($_GET['domain'] ?? ''));

if (!$domain || !is_valid_hostname($domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid domain parameter']);
    exit;
}


echo json_encode(check_ssl($domain));
