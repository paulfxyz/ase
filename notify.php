<?php
/**
 * notify.php — All-Seeing-Eye email notification sender
 *
 * Sends downtime alert emails via the Resend API (https://resend.com).
 * Called by the browser JS when a domain goes DOWN during a live check,
 * and by update-stats.php during scheduled cron checks.
 *
 * The Resend API key is stored AES-256-GCM encrypted in ase_config.json.
 * The encryption key is derived from a server-side secret stored in
 * notify_secret.key (auto-generated on first use, never exposed via HTTP).
 *
 * Endpoints:
 *   POST notify.php   → send a downtime notification
 *   POST notify.php   → with {"action":"test"} → send a test email
 *
 * POST body (JSON):
 * {
 *   "domain":  "example.com",   // domain that went down
 *   "status":  "DOWN",          // "DOWN" or "UP" (recovery)
 *   "latency": null,            // ms or null
 *   "action":  "notify"         // "notify" | "test"
 * }
 *
 * Security:
 *   - API key never stored or transmitted in plaintext from this endpoint
 *   - AES-256-GCM authenticated encryption (tamper-proof)
 *   - Server-side secret derived key — not guessable from config alone
 *   - Rate limit: max 10 emails per hour (tracked via rate_limit.json)
 *   - notify_secret.key protected from web access via .htaccess
 *
 * @version 3.1.0
 * @author  Paul Fleury / Perplexity Computer
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

define('CONFIG_FILE',     __DIR__ . '/ase_config.json');
define('SECRET_FILE',     __DIR__ . '/notify_secret.key');
define('RATE_LIMIT_FILE', __DIR__ . '/notify_rate.json');
define('MAX_EMAILS_PER_HOUR', 10);

/* ── Helpers ── */

function readConfig() {
    if (!file_exists(CONFIG_FILE)) return [];
    $raw = json_decode(file_get_contents(CONFIG_FILE), true);
    return is_array($raw) ? $raw : [];
}

/**
 * Get or create the server-side secret key.
 * This key is used to derive the AES encryption key.
 * Never exposed via HTTP — protected by .htaccess.
 */
function getOrCreateSecret() {
    if (file_exists(SECRET_FILE)) {
        return trim(file_get_contents(SECRET_FILE));
    }
    /* Generate a 64-char hex secret (256 bits) */
    $secret = bin2hex(random_bytes(32));
    file_put_contents(SECRET_FILE, $secret);
    chmod(SECRET_FILE, 0600);
    return $secret;
}

/**
 * Encrypt a plaintext string using AES-256-GCM.
 * Returns base64-encoded: IV (12 bytes) + tag (16 bytes) + ciphertext.
 */
function encryptApiKey(string $plaintext, string $secret): string {
    $key    = hash('sha256', $secret, true); /* 32-byte key from secret */
    $iv     = random_bytes(12);              /* 96-bit GCM IV */
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt a base64-encoded AES-256-GCM blob.
 * Returns plaintext on success, false on failure.
 */
function decryptApiKey(string $encoded, string $secret) {
    $raw    = base64_decode($encoded);
    if (strlen($raw) < 29) return false;   /* 12 IV + 16 tag + at least 1 char */
    $key    = hash('sha256', $secret, true);
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    return openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
}

/**
 * Check and enforce rate limit (max 10 emails/hour).
 * Returns true if allowed, false if rate-limited.
 */
function checkRateLimit(): bool {
    $data  = [];
    $now   = time();
    $cutoff = $now - 3600;

    if (file_exists(RATE_LIMIT_FILE)) {
        $raw = json_decode(file_get_contents(RATE_LIMIT_FILE), true);
        if (is_array($raw)) $data = $raw;
    }

    /* Remove timestamps older than 1 hour */
    $data = array_filter($data, function($ts) use ($cutoff) { return $ts > $cutoff; });

    if (count($data) >= MAX_EMAILS_PER_HOUR) {
        return false;
    }

    $data[] = $now;
    file_put_contents(RATE_LIMIT_FILE, json_encode(array_values($data)));
    return true;
}

/**
 * Send email via Resend API.
 * Returns ['ok' => true] or ['error' => 'message'].
 */
function sendViaResend(string $apiKey, string $from, string $to, string $subject, string $html): array {
    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Content-Length: ' . strlen($payload)
            ]),
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);

    $response = @file_get_contents('https://api.resend.com/emails', false, $ctx);
    $httpCode = 0;

    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/HTTP\/[\d.]+ (\d+)/', $h, $m)) {
                $httpCode = intval($m[1]);
            }
        }
    }

    if ($response === false || ($httpCode !== 200 && $httpCode !== 201)) {
        $err = $response ? (json_decode($response, true)['message'] ?? $response) : 'Network error';
        return ['error' => 'Resend API error (' . $httpCode . '): ' . $err];
    }

    return ['ok' => true, 'id' => json_decode($response, true)['id'] ?? null];
}

/**
 * Build the downtime alert HTML email body.
 */
function buildAlertEmail(string $domain, string $status, $latency): string {
    $isDown    = ($status === 'DOWN');
    $color     = $isDown ? '#ef4444' : '#10b981';
    $icon      = $isDown ? '🔴' : '🟢';
    $title     = $isDown ? "🚨 Downtime Alert: {$domain}" : "✅ Recovery: {$domain} is back online";
    $latStr    = ($latency !== null) ? "{$latency}ms" : 'N/A';
    $timeStr   = date('Y-m-d H:i:s T');

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f9fafb;margin:0;padding:24px">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);overflow:hidden">
    <div style="background:{$color};padding:20px 24px">
      <h1 style="margin:0;color:#fff;font-size:18px;font-weight:700">{$icon} {$title}</h1>
    </div>
    <div style="padding:24px">
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tr><td style="padding:8px 0;color:#6b7280;width:120px">Domain</td>
            <td style="padding:8px 0;font-weight:600;color:#111">{$domain}</td></tr>
        <tr><td style="padding:8px 0;color:#6b7280">Status</td>
            <td style="padding:8px 0;font-weight:700;color:{$color}">{$status}</td></tr>
        <tr><td style="padding:8px 0;color:#6b7280">Latency</td>
            <td style="padding:8px 0;color:#111">{$latStr}</td></tr>
        <tr><td style="padding:8px 0;color:#6b7280">Detected</td>
            <td style="padding:8px 0;color:#111">{$timeStr}</td></tr>
      </table>
      <p style="margin:16px 0 0;font-size:12px;color:#9ca3af">
        Sent by <a href="https://github.com/paulfxyz/the-all-seeing-eye" style="color:#8b5cf6">The All Seeing Eye</a>
        · <a href="https://resend.com" style="color:#9ca3af">via Resend</a>
      </p>
    </div>
  </div>
</body>
</html>
HTML;
}

/* ── Main ── */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = file_get_contents('php://input');
$posted = json_decode($body, true);
if (!is_array($posted)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$action = $posted['action'] ?? 'notify';

/* Load config */
$cfg = readConfig();
if (empty($cfg['notify_enabled'])) {
    echo json_encode(['ok' => false, 'message' => 'Notifications disabled']);
    exit;
}
if (empty($cfg['notify_api_key_enc']) || empty($cfg['notify_from']) || empty($cfg['notify_to'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Notification settings incomplete — configure in dashboard']);
    exit;
}

/* Decrypt API key */
$secret = getOrCreateSecret();
$apiKey = decryptApiKey($cfg['notify_api_key_enc'], $secret);
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to decrypt API key — reconfigure in dashboard']);
    exit;
}

$from = $cfg['notify_from'];
$to   = $cfg['notify_to'];

/* ── Handle test email ── */
if ($action === 'test') {
    $subject = '✅ Test — The All Seeing Eye notifications working';
    $html = buildAlertEmail('test.example.com', 'TEST', null);
    $result = sendViaResend($apiKey, $from, $to, $subject, $html);
    echo json_encode($result);
    exit;
}

/* ── Handle downtime/recovery notification ── */
$domain  = $posted['domain']  ?? '';
$status  = $posted['status']  ?? 'DOWN';
$latency = isset($posted['latency']) && $posted['latency'] !== null ? intval($posted['latency']) : null;

if (!$domain) {
    http_response_code(400);
    echo json_encode(['error' => 'domain required']);
    exit;
}

/* Rate limit */
if (!checkRateLimit()) {
    echo json_encode(['ok' => false, 'message' => 'Rate limit reached (10 emails/hour)']);
    exit;
}

$subject = $status === 'DOWN'
    ? "🚨 DOWN: {$domain} is unreachable"
    : "✅ RECOVERED: {$domain} is back online";

$html   = buildAlertEmail($domain, $status, $latency);
$result = sendViaResend($apiKey, $from, $to, $subject, $html);
echo json_encode($result);
