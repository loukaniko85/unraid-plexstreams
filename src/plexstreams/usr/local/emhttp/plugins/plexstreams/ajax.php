<?php
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');
    include('/usr/local/emhttp/plugins/plexstreams/includes/common.php');
    
    header('Content-Type: application/json');
    global $display;

    $payload = ['streams' => [], 'unreachable' => []];
    if (!empty($cfg['TOKEN'])) {
        $hostCfg   = $cfg['HOST']           ?? '';
        $customCfg = $cfg['CUSTOM_SERVERS'] ?? '';
        if ($hostCfg !== '' || $customCfg !== '') {
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
        } else {
            http_response_code(500);
        }
    }
