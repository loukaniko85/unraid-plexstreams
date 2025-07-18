<?php
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');
    include('/usr/local/emhttp/plugins/plexstreams/includes/common.php');
    
    header('Content-Type: application/json');
    global $display;

    $mergedStreams = [];
    if (!empty($cfg['TOKEN'])) {
        $hostCfg   = $cfg['HOST']           ?? '';
        $customCfg = $cfg['CUSTOM_SERVERS'] ?? '';
        if ($hostCfg !== '' || $customCfg !== '') {
            $docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
            require_once "$docroot/webGui/include/Wrappers.php";
            extract(parse_plugin_cfg('dynamix',true));

            $streams = getStreams($cfg);
            $mergedStreams = mergeStreams($streams, $cfg);
            
            if (isset($_REQUEST['dbg'])) {
                v_d($mergedStreams);
            }
            echo(json_encode($mergedStreams));
        } else {
            http_response_code(500);
        }

    }
