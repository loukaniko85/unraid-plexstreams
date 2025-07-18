<?php
    // Terminate an active Plex session. Gated by ALLOW_TERMINATE in config.
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');

    header('Content-Type: application/json');

    if (($cfg['ALLOW_TERMINATE'] ?? '0') !== '1') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Stream termination is disabled in plugin settings.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST only']);
        exit;
    }

    $host       = trim((string)($_POST['host']       ?? ''));
    $sessionKey = trim((string)($_POST['sessionKey'] ?? ''));
    $reason     = trim((string)($_POST['reason']     ?? 'Terminated by Unraid admin'));

    if ($host === '' || $sessionKey === '' || empty($cfg['TOKEN'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing host, sessionKey, or token.']);
        exit;
    }
    if (!preg_match('/^\d+$/', $sessionKey)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'sessionKey must be numeric.']);
        exit;
    }

    // SSRF guard: host must be in configured Plex servers.
    $allowedHosts = array_filter(array_map('trim', array_merge(
        explode(',', $cfg['HOST']           ?? ''),
        explode(',', $cfg['CUSTOM_SERVERS'] ?? '')
    )));
    if (!in_array($host, $allowedHosts, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Host not in configured Plex servers.']);
        exit;
    }

    $url = rtrim($host, '/') . '/status/sessions/terminate'
         . '?sessionId='     . urlencode($sessionKey)
         . '&reason='        . urlencode($reason)
         . '&X-Plex-Token='  . urlencode($cfg['TOKEN']);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        echo json_encode(['ok' => true, 'status' => $status]);
    } else {
        http_response_code(502);
        echo json_encode([
            'ok'     => false,
            'error'  => $err ?: ('Plex returned HTTP ' . $status),
            'status' => $status,
            'body'   => $body,
        ]);
    }
