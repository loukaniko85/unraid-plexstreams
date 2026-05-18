<?php
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');
    include('/usr/local/emhttp/plugins/plexstreams/includes/common.php');

    header('Content-type: application/json');

    if (!empty($cfg['TOKEN'])) {
        $cfg['FORCE_PLEX_HTTPS'] = (isset($_GET['useSsl']) && $_GET['useSsl'] === '1') ? '1' : '0';
        echo(json_encode((Object)array('serverList' => getServers($cfg))));
    } else {
        http_response_code(500);
    }
