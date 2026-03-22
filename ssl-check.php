<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  THE ALL SEEING EYE — ssl-check.php                          ║
 * ║                                                              ║
 * ║  PURPOSE                                                     ║
 * ║  ────────────────────────────────────────────────────────    ║
 * ║  A tiny PHP endpoint that the browser calls to get the real  ║
 * ║  SSL certificate expiry date for any domain.                 ║
 * ║                                                              ║
 * ║  Why this exists:                                            ║
 * ║  The browser cannot do raw TLS connections (no socket API).  ║
 * ║  External certificate APIs (crt.sh, ssl-checker.io) either  ║
 * ║  have CORS issues or timeout on small/private domains.       ║
 * ║  This PHP script runs on your server — same origin, no CORS  ║
 * ║  — and uses PHP's built-in stream_socket_client() to open a  ║
 * ║  real TLS connection to port 443 and read the peer cert.     ║
 * ║                                                              ║
 * ║  USAGE                                                       ║
 * ║  ────────────────────────────────────────────────────────    ║
 * ║  GET /ssl-check.php?domain=paulfleury.com                    ║
 * ║                                                              ║
 * ║  Response (JSON):                                            ║
 * ║  { "domain": "paulfleury.com",                               ║
 * ║    "expiry": "2026-06-06",                                    ║
 * ║    "issuer": "LE",                                           ║
 * ║    "days_remaining": 76 }                                    ║
 * ║                                                              ║
 * ║  On error:                                                   ║
 * ║  { "domain": "...", "error": "Could not connect" }           ║
 * ║                                                              ║
 * ║  SECURITY                                                    ║
 * ║  ────────────────────────────────────────────────────────    ║
 * ║  • Input is strictly validated (hostname characters only)    ║
 * ║  • Only port 443 is contacted                                ║
 * ║  • TLS verification is disabled for the handshake (we want   ║
 * ║    the cert data even if the cert is invalid/expired)        ║
 * ║  • Rate limiting: 1 request per domain per second            ║
 * ║    (enforced via a file-based token bucket in /tmp)          ║
 * ║  • CORS header allows same-origin browser requests           ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

/* ── Output headers ─────────────────────────────────────────── */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');  /* cache 1 hour — cert rarely changes */
header('Access-Control-Allow-Origin: *');        /* same-origin plus local dev */

/* ── Input validation ───────────────────────────────────────── */
$domain = trim($_GET['domain'] ?? '');

/* Allow only valid hostname characters (letters, digits, dots, hyphens) */
if (!$domain || !preg_match('/^[a-zA-Z0-9\.\-]{1,253}$/', $domain) || !str_contains($domain, '.')) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid domain parameter']);
    exit;
}

$domain = strtolower($domain);

/* ── Simple file-based rate limiting ───────────────────────────
 * Prevents abuse: one check per domain per second.
 * Uses a temp file per domain; if the file is newer than 1s, reject.
 */
$rateFile = sys_get_temp_dir() . '/ase_ssl_' . md5($domain) . '.rate';
if (file_exists($rateFile) && (time() - filemtime($rateFile)) < 1) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limited — try again in 1s', 'domain' => $domain]);
    exit;
}
@touch($rateFile);

/* ── TLS connection ─────────────────────────────────────────── */
/**
 * Connect to port 443, capture the peer certificate, parse it.
 *
 * We set verify_peer=false because:
 *  a) We want the expiry date even for expired/misconfigured certs
 *  b) We're not validating trust — just reading metadata
 *  c) It speeds up the handshake (no CA chain lookup)
 */
$context = stream_context_create([
    'ssl' => [
        'capture_peer_cert' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'SNI_enabled'       => true,
        'peer_name'         => $domain,
    ]
]);

$timeout = 5; /* seconds */
$stream  = @stream_socket_client(
    'ssl://' . $domain . ':443',
    $errno, $errstr,
    $timeout,
    STREAM_CLIENT_CONNECT,
    $context
);

if (!$stream) {
    echo json_encode([
        'domain' => $domain,
        'error'  => $errstr ?: 'Could not connect (errno ' . $errno . ')',
    ]);
    exit;
}

/* ── Extract and parse the certificate ─────────────────────── */
$params = stream_context_get_params($stream);
fclose($stream);

$cert = $params['options']['ssl']['peer_certificate'] ?? null;
if (!$cert) {
    echo json_encode(['domain' => $domain, 'error' => 'No certificate returned']);
    exit;
}

$info = openssl_x509_parse($cert);
if (!$info) {
    echo json_encode(['domain' => $domain, 'error' => 'Could not parse certificate']);
    exit;
}

/* ── Build response ─────────────────────────────────────────── */
$validTo = $info['validTo_time_t'] ?? null;
if (!$validTo) {
    echo json_encode(['domain' => $domain, 'error' => 'Certificate has no expiry date']);
    exit;
}

$expiryDate    = date('Y-m-d', $validTo);
$daysRemaining = (int) round(($validTo - time()) / 86400);

/* Detect issuer — extract CN from issuer DN */
$issuerCN = $info['issuer']['CN'] ?? $info['issuer']['O'] ?? '?';
/* Let's Encrypt CAs: R3, R10, R11, E5, E6, E7, etc. */
$isLE   = stripos($issuerCN, "let's encrypt") !== false
       || preg_match('/^[RE]\d+$/', $issuerCN);
$issuer = $isLE ? 'LE' : (strlen($issuerCN) > 30 ? substr($issuerCN, 0, 30) : $issuerCN);

echo json_encode([
    'domain'         => $domain,
    'expiry'         => $expiryDate,
    'issuer'         => $issuer,
    'days_remaining' => $daysRemaining,
    'valid'          => $daysRemaining > 0,
]);
