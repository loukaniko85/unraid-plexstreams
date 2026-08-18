<?php
    include('/usr/local/emhttp/plugins/plexstreams/includes/config.php');
    include('/usr/local/emhttp/plugins/plexstreams/includes/common.php');

    header('Content-type: application/json');

    if (empty($cfg['TOKEN'])) {
        http_response_code(500);
        exit;
    }

    $cfg['FORCE_PLEX_HTTPS'] = (isset($_GET['useSsl']) && $_GET['useSsl'] === '1') ? '1' : '0';
    $list = getServers($cfg);
    if ($list === false) {
        // plex.tv didn't answer — tell the client instead of returning a
        // body it can't parse (the old `serverList: false` broke Object.keys).
        http_response_code(502);
        echo json_encode(['serverList' => new stdClass(), 'error' => 'plex.tv did not respond']);
        exit;
    }
    echo json_encode((object)['serverList' => $list]);
