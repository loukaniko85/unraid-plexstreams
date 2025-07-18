<?php
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');

    if (empty($cfg['TOKEN']) || empty($_GET['img'])) {
        http_response_code(400);
        exit;
    }

    $img  = (string)$_GET['img'];
    $host = (string)($_GET['host'] ?? '');

    // Build allowlist of Plex hosts from configured + custom servers.
    $allowedHosts = array_filter(array_merge(
        explode(',', $cfg['HOST'] ?? ''),
        explode(',', $cfg['CUSTOM_SERVERS'] ?? '')
    ));
    $allowedHosts = array_map('trim', $allowedHosts);

    $absoluteImg = str_starts_with($img, 'http://') || str_starts_with($img, 'https://');

    if ($absoluteImg) {
        // Absolute URL (Plex art served by plex.tv); only allow plex.tv subdomains.
        $parts = parse_url($img);
        if ($parts === false || empty($parts['host']) || !preg_match('/(^|\.)plex\.tv$/i', $parts['host'])) {
            http_response_code(403);
            exit;
        }
        $url = $img;
    } else {
        if ($host === '' || !in_array($host, $allowedHosts, true)) {
            http_response_code(403);
            exit;
        }
        $url = rtrim($host, '/') . '/' . ltrim($img, '/') . '?X-Plex-Token=' . urlencode($cfg['TOKEN']);
    }

    // Browser cache: 304 if client already has it.
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_BUFFERSIZE     => 12800,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $out         = curl_exec($ch);
    $headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($out === false || $statusCode < 200 || $statusCode >= 400) {
        http_response_code(502);
        exit;
    }

    $rawHeaders = substr($out, 0, $headerSize);
    $body       = substr($out, $headerSize);
    $headers    = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        $kv = explode(': ', $line, 2);
        if (count($kv) === 2) {
            $headers[$kv[0]] = trim($kv[1]);
        }
    }

    if (isset($headers['Content-Type'])) {
        $ct = $headers['Content-Type'];
        if (!preg_match('#^image/(png|jpe?g|gif|webp|x-icon|vnd\.microsoft\.icon)#i', $ct)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $ct);
    }
    foreach (['Content-Length', 'Expires', 'Cache-Control', 'Last-Modified', 'ETag'] as $h) {
        if (isset($headers[$h])) {
            header($h . ': ' . $headers[$h]);
        }
    }
    // Cache aggressively in the browser when Plex didn't say so.
    if (!isset($headers['Cache-Control'])) {
        header('Cache-Control: public, max-age=86400');
    }
    echo $body;
