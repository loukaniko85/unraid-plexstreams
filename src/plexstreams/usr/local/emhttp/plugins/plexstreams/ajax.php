<?php
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');
    include('/usr/local/emhttp/plugins/plexstreams/includes/common.php');

    header('Content-Type: application/json');
    global $display;

    $payload = ['streams' => [], 'unreachable' => []];

    // Unconfigured (no token, or no servers selected) → 500 so the client
    // shows the "set up the plugin first" hint instead of a misleading
    // "no active streams".
    $hasServers = trim($cfg['HOST'] ?? '') !== '' || trim($cfg['CUSTOM_SERVERS'] ?? '') !== '';
    if (empty($cfg['TOKEN']) || !$hasServers) {
        http_response_code(500);
        echo json_encode($payload);
        exit;
    }

    $docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
    require_once "$docroot/webGui/include/Wrappers.php";
    extract(parse_plugin_cfg('dynamix', true));

    $streams = getStreams($cfg);
    $payload['streams'] = mergeStreams($streams, $cfg);

    // Hosts that didn't respond (or returned 5xx). Friendly name from
    // ALIAS-<addr> if we have it; otherwise just the host string.
    foreach (ps_get_host_status() as $host => $reachable) {
        if ($reachable) continue;
        $addr = parse_url($host, PHP_URL_HOST) ?? $host;
        $alias = $cfg['ALIAS-' . str_replace('.', '_', $addr)] ?? '';
        $payload['unreachable'][] = ['host' => $host, 'alias' => $alias ?: $addr];
    }

    if (isset($_REQUEST['dbg'])) {
        v_d($payload);
    }
    echo json_encode($payload);
