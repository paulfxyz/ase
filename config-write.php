<?php
/**
 * config-write.php — settings persistence (v6.1.0)
 *
 *   GET  → signed-out: { theme, authenticated:false }  (nothing sensitive)
 *          signed-in:  full settings WITHOUT PIN hash or API-key ciphertext
 *   POST → merge settings into ase_config.json. Requires a session and the
 *          X-ASE header; always refused on the public demo.
 *
 * PIN changes no longer go through here — see auth.php. The PIN hash is never
 * sent to the browser. The Resend key is encrypted at rest (AES-256-GCM, key
 * file notify_secret.key) and is write-only from the browser's point of view.
 */
require_once __DIR__ . '/_ase.php';
ase_headers();

define('CONFIG_FILE', ASE_CONFIG_FILE);

function isValidDomain($d) {
    return is_string($d)
        && strlen($d) >= 3
        && strlen($d) <= 253
        && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $d);
}

/* ── Routing ── */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $cfg = ase_config();
    $demo = ase_is_demo();
    if (!$demo && !ase_authenticated()) {
        ase_out(['theme' => $cfg['theme'] ?? null, 'authenticated' => false]);
    }
    if ($demo) {                       /* public demo: theme only, never emails or keys */
        ase_out(['theme' => $cfg['theme'] ?? null, 'demo' => true]);
    }
    $safe = $cfg;
    unset($safe['pin_hash'], $safe['pin_pw']);
    $safe['notify_api_key_set'] = !empty($safe['notify_api_key_enc']);
    unset($safe['notify_api_key_enc']);
    $safe['authenticated'] = true;
    $safe['pin_default']   = ase_pin_is_default($cfg);
    ase_out($safe);
}

if ($method === 'POST') {
    ase_deny_in_demo();
    ase_require_xhr();
    ase_require_auth();
    ase_config_lock();       /* serialise read-modify-write with auth.php */
    /* Read and decode posted body */
    $body = file_get_contents('php://input');
    $posted = json_decode($body, true);

    if (!is_array($posted)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    /* Load existing config to merge into */
    $config = ase_config();
    $changed = false;

    /* ── Theme ── */
    if (isset($posted['theme'])) {
        if (!in_array($posted['theme'], ['light', 'dark'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'theme must be "light" or "dark"']);
            exit;
        }
        $config['theme'] = $posted['theme'];
        $changed = true;
    }

    /* ── Custom domains ── */
    if (isset($posted['custom_domains'])) {
        if (!is_array($posted['custom_domains'])) {
            http_response_code(400);
            echo json_encode(['error' => 'custom_domains must be an array']);
            exit;
        }
        $domains = [];
        foreach ($posted['custom_domains'] as $d) {
            if (isValidDomain($d)) {
                $domains[] = strtolower(trim($d));
            }
        }
        /* Deduplicate and cap at 200 */
        $domains = array_slice(array_values(array_unique($domains)), 0, 200);
        $config['custom_domains'] = $domains;
        $changed = true;
    }

    /* ── Notification settings ── */
    if (isset($posted['notify_enabled'])) {
        $config['notify_enabled'] = (bool)$posted['notify_enabled'];
        $changed = true;
    }

    if (isset($posted['notify_from'])) {
        $from = filter_var(trim($posted['notify_from']), FILTER_VALIDATE_EMAIL);
        if (!$from) {
            http_response_code(400);
            echo json_encode(['error' => 'notify_from must be a valid email address']);
            exit;
        }
        $config['notify_from'] = $from;
        $changed = true;
    }

    if (isset($posted['notify_to'])) {
        $to = filter_var(trim($posted['notify_to']), FILTER_VALIDATE_EMAIL);
        if (!$to) {
            http_response_code(400);
            echo json_encode(['error' => 'notify_to must be a valid email address']);
            exit;
        }
        $config['notify_to'] = $to;
        $changed = true;
    }

    /* Resend API key — encrypted server-side before storing.
       The browser sends the plaintext key; this endpoint encrypts it
       using the same AES-256-GCM mechanism as notify.php. */
    if (isset($posted['notify_api_key'])) {
        $key = trim($posted['notify_api_key']);
        if (strlen($key) < 10) {
            http_response_code(400);
            echo json_encode(['error' => 'notify_api_key appears too short']);
            exit;
        }

        $secret = ase_secret(__DIR__ . '/notify_secret.key');

        /* Encrypt using AES-256-GCM */
        $encKey  = hash('sha256', $secret, true);
        $iv      = random_bytes(12);
        $ciphertext = openssl_encrypt($key, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);
        $config['notify_api_key_enc'] = base64_encode($iv . $tag . $ciphertext);
        $changed = true;
    }

    /* Allow clearing the API key */
    if (isset($posted['notify_api_key_clear']) && $posted['notify_api_key_clear'] === true) {
        unset($config['notify_api_key_enc']);
        $changed = true;
    }

    /* ── Notification send timestamps (cooldown persistence) ── */
    if (isset($posted['notify_last_sent'])) {
        if (!is_array($posted['notify_last_sent'])) {
            http_response_code(400);
            echo json_encode(['error' => 'notify_last_sent must be an object']);
            exit;
        }
        /* Validate: keys must be "domain:type" strings, values must be integers */
        $validTypes  = ['down', 'ssl_expiry', 'ssl_critical', 'dmarc_missing', 'dmarc_none', 'spf_missing'];
        $cleanSent   = [];
        foreach ($posted['notify_last_sent'] as $key => $ts) {
            if (!is_string($key) || !is_numeric($ts)) continue;
            $parts = explode(':', $key, 2);
            if (count($parts) !== 2) continue;
            $type = $parts[1];
            if (!in_array($type, $validTypes, true)) continue;
            $cleanSent[$key] = intval($ts);
        }
        $config['notify_last_sent'] = $cleanSent;
        $changed = true;
    }

    if (!$changed) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields provided (theme, custom_domains, notify_*)']);
        exit;
    }

    if (!ase_config_save($config)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to write config file — check directory write permissions']);
        exit;
    }

    echo json_encode(['ok' => true, 'updated_at' => $config['updated_at']]);
    exit;
}

/* Unsupported method */
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
