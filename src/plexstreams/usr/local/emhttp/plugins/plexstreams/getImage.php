<?php
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');

    if (empty($cfg['TOKEN']) || empty($_GET['img'])) {
        http_response_code(400);
        exit;
    }

    $img  = (string)$_GET['img'];
    $host = (string)($_GET['host'] ?? '');

    // Allowlist of Plex origins (scheme://host[:port]) from configured servers.
    $allowedHosts = array_filter(array_map('trim', array_merge(
        explode(',', $cfg['HOST']           ?? ''),
        explode(',', $cfg['CUSTOM_SERVERS'] ?? '')
    )));

    // Lowercased scheme://host[:port] for a URL, or null if unparseable.
    function ps_img_origin($url) {
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) return null;
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') return null;
        return $scheme . '://' . strtolower($p['host'])
             . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    function ps_img_is_allowed_origin($url, $allowedHosts) {
        $origin = ps_img_origin($url);
        if ($origin === null) return false;
        foreach ($allowedHosts as $h) {
            if (ps_img_origin($h) === $origin) return true;
        }
        return false;
    }

    // A fetch target is allowed if it's a configured Plex server origin or a
    // plex.tv host (absolute artwork / avatar URLs).
    function ps_img_allowed($url, $allowedHosts) {
        if (ps_img_is_allowed_origin($url, $allowedHosts)) return true;
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) && preg_match('/(^|\.)plex\.tv$/i', $h) === 1;
    }

    // Resolve a possibly-relative Location header against the current URL.
    function ps_img_resolve($base, $loc) {
        if (preg_match('#^https?://#i', $loc)) return $loc;
        $origin = ps_img_origin($base);
        if ($origin === null) return false;
        if ($loc === '') return $base;
        if ($loc[0] === '/') return $origin . $loc;
        $path = parse_url($base, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $dir  = substr($path, -1) === '/' ? $path : substr($path, 0, strrpos($path, '/') + 1);
        return $origin . $dir . $loc;
    }

    $absoluteImg = (strpos($img, 'http://') === 0) || (strpos($img, 'https://') === 0);

    if ($absoluteImg) {
        // Absolute URL (Plex art served by plex.tv); only allow plex.tv subdomains.
        $parts = parse_url($img);
        if ($parts === false || empty($parts['host']) || !preg_match('/(^|\.)plex\.tv$/i', $parts['host'])) {
            http_response_code(403);
            exit;
        }
        $url = $img;
    } else {
        if ($host === '' || !ps_img_is_allowed_origin($host, $allowedHosts)) {
            http_response_code(403);
            exit;
        }
        $url = rtrim($host, '/') . '/' . ltrim($img, '/');
    }

    // Forward validators to upstream so a real 304 only happens when Plex
    // confirms the asset hasn't changed (the old "304 on any conditional
    // header" short-circuit cached deleted/changed posters forever).
    $reqHeaders = [];
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $reqHeaders[] = 'If-Modified-Since: ' . $_SERVER['HTTP_IF_MODIFIED_SINCE'];
    }
    if (!empty($_SERVER['HTTP_IF_NONE_MATCH'])) {
        $reqHeaders[] = 'If-None-Match: ' . $_SERVER['HTTP_IF_NONE_MATCH'];
    }

    // Fetch with redirects followed MANUALLY: every hop target is re-validated
    // against the allowlist, so a 302 from an approved host can't bounce the
    // request somewhere arbitrary. (The token is only sent to configured Plex
    // server origins — never to third-party or plex.tv redirect targets.)
    $current    = $url;
    $out        = false;
    $headerSize = 0;
    $statusCode = 0;

    for ($hop = 0; $hop <= 3; $hop++) {
        $headers = $reqHeaders;
        if (ps_img_is_allowed_origin($current, $allowedHosts)) {
            $headers[] = 'X-Plex-Token: ' . $cfg['TOKEN'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $current,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_BUFFERSIZE     => 12800,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $out         = curl_exec($ch);
        $headerSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($out === false) {
            http_response_code(502);
            exit;
        }

        if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
            $rawRedirectHeaders = substr($out, 0, $headerSize);
            $location = null;
            foreach (explode("\r\n", $rawRedirectHeaders) as $line) {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                    break;
                }
            }
            $next = $location !== null ? ps_img_resolve($current, $location) : false;
            if ($next === false || !ps_img_allowed($next, $allowedHosts)) {
                http_response_code(403);
                exit;
            }
            $current = $next;
            continue;
        }
        break;
    }

    if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
        http_response_code(502); // redirect chain didn't settle within 3 hops
        exit;
    }
    if ($statusCode === 304) {
        http_response_code(304);
        exit;
    }
    if ($statusCode < 200 || $statusCode >= 400) {
        http_response_code(502);
        exit;
    }

    // FOLLOWLOCATION is off, so this response carries exactly one header block.
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
