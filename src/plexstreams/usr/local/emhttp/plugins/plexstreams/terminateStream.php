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

    $host      = trim((string)($_POST['host']      ?? ''));
    $sessionId = trim((string)($_POST['sessionId'] ?? ''));
    $reason    = trim((string)($_POST['reason']    ?? 'Terminated by Unraid admin'));

    if ($host === '' || $sessionId === '' || empty($cfg['TOKEN'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing host, sessionId, or token.']);
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

    // Plex expects the session UUID (Session.id), not the numeric sessionKey.
    // Tautulli and other tools use GET on this endpoint — it's the proven approach.
    // The token travels as a header so it stays out of URLs (and access logs).
    $url = rtrim($host, '/') . '/status/sessions/terminate'
         . '?sessionId=' . urlencode($sessionId)
         . '&reason='    . urlencode($reason);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'X-Plex-Token: ' . $cfg['TOKEN'],
        ],
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
