<?php
    require_once '/usr/local/emhttp/plugins/plexstreams/includes/config.php';
    require_once '/usr/local/emhttp/plugins/plexstreams/includes/common.php';

    $docroot = $docroot ?: $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
    $_SERVER['REQUEST_URI'] = 'plexstreams';
    require_once "$docroot/webGui/include/Translations.php";

    $psCfg     = $cfg;
    $refresh   = max(2, (int)($psCfg['REFRESH_INTERVAL'] ?? 5));
    $posters   = ($psCfg['SHOW_POSTERS']     ?? '1') === '1' ? 1 : 0;
    $allowKill = ($psCfg['ALLOW_TERMINATE']  ?? '0') === '1' ? 1 : 0;
    $sortMode  = in_array($psCfg['SORT_MODE'] ?? 'started', ['started','user','bandwidth'], true)
               ? $psCfg['SORT_MODE'] : 'started';
?>
<link type="text/css" rel="stylesheet" href="<?autov('/plugins/plexstreams/css/plexstreams.css')?>">
<style>
    /* Streams-page sizing; everything shared lives in css/plexstreams.css */
    .ps-widget { font-size: 13px; padding: 8px 4px; }
    .ps-widget .ps-count-line { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .ps-widget .ps-host-counts { margin-top: 2px; font-size: 11px; color: #888; }

    .ps-stream {
        padding: 8px 0;
    }
    .ps-stream .ps-row {
        gap: 10px;
    }
    .ps-stream .ps-thumb {
        flex: 0 0 48px;
        height: 72px;
    }
    .ps-caution {
        margin: 12px 0;
        padding: 12px 16px;
        background: rgba(229,57,53,0.10);
        color: #e53935;
        border-radius: 4px;
    }
</style>

<?php if (empty($cfg['TOKEN'])): ?>
    <div class="ps-caution">
        <i class="fa fa-exclamation-triangle"></i>
        <?= _('Plex Streams is not configured.') ?>
        <a href="/Settings/PlexStreams"><?= _('Open settings') ?></a>
    </div>
<?php else: ?>
    <div class="ps-widget">
        <div id="stream_count_container" class="ps-count-line"><span id="plexstreams_count">0</span> <?= _('streams') ?></div>
        <div class="ps-host-counts" id="ps_host_counts"></div>
        <div id="plexstreams_streams" style="margin-top: 8px;">
            <div id="retrieving_streams" class="ps-empty"><?= _('Loading…') ?></div>
        </div>
    </div>
<?php endif; ?>

<script src="<?autov('/plugins/plexstreams/js/plex.js')?>"></script>
<script async>
    window.PS_REFRESH_MS      = <?= $refresh * 1000 ?>;
    window.PS_SHOW_POSTERS    = <?= $posters ?>;
    window.PS_ALLOW_TERMINATE = <?= $allowKill ?>;
    window.PS_SORT_MODE       = '<?= $sortMode ?>';
    $(function() {
        // The page title shown in the browser tab.
        var t = $('title').html();
        if (t) $('title').html(t.split('/')[0] + '/Plex Streams');
        <?php if (!empty($cfg['TOKEN'])): ?>
        updateDashboardStreamsNew();
        psStartPolling();
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                psStopPolling();
            } else {
                updateDashboardStreamsNew();
                psStartPolling();
            }
        });
        <?php endif; ?>
    });
</script>
