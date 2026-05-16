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
?>
<style>
    /* Full streams page reuses the widget styles loaded by the widget page,
       but this page is loaded standalone so we re-declare the chip/badge
       palette here. Kept in sync with NewDashboard.page. */
    .ps-widget { font-size: 13px; padding: 8px 4px; }
    .ps-widget .ps-count-line { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .ps-widget .ps-host-counts { margin-top: 2px; font-size: 11px; color: #888; }

    .ps-stream {
        padding: 8px 0;
        border-bottom: 1px solid rgba(127,127,127,0.15);
        position: relative;
    }
    .ps-stream:last-child { border-bottom: none; }
    .ps-stream .ps-row {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
    }
    .ps-stream .ps-row:hover { background: rgba(127,127,127,0.06); }
    .ps-stream.ps-open .ps-row { background: rgba(229,160,13,0.07); }
    .ps-stream .ps-thumb {
        flex: 0 0 48px;
        height: 72px;
        background-size: cover;
        background-position: center;
        background-color: rgba(127,127,127,0.15);
        border-radius: 3px;
    }
    .ps-stream .ps-body { flex: 1 1 auto; min-width: 0; overflow: hidden; }
    .ps-stream .ps-title {
        font-weight: 600;
        line-height: 1.3;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .ps-stream .ps-title .ps-badges { white-space: nowrap; }
    .ps-stream .ps-meta {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: #888;
        margin-top: 3px;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }
    .ps-stream .ps-meta .ps-user {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 50%;
    }
    .ps-stream .ps-meta .ps-time {
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    .ps-stream .ps-progress-bg {
        height: 2px;
        background: rgba(127,127,127,0.18);
        border-radius: 1px;
        margin-top: 4px;
        overflow: hidden;
    }
    .ps-stream .ps-progress {
        height: 100%;
        background: rgba(127,127,127,0.55);
        width: 0;
        transition: width 1s linear;
    }
    .ps-stream .ps-state { flex: 0 0 14px; text-align: center; color: #888; }
    .ps-stream .ps-state.playing { color: #4caf50; }
    .ps-stream .ps-state.paused  { color: #c79320; }

    .ps-badge {
        display: inline-block;
        border: 1px solid rgba(127,127,127,0.25);
        background: rgba(127,127,127,0.10);
        color: #888;
        border-radius: 2px;
        padding: 0 4px;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase;
        margin-left: 4px;
        vertical-align: 1px;
        line-height: 14px;
    }
    .ps-badge-trans {
        color: #c79320;
        border-color: rgba(199,147,32,0.35);
        background: rgba(199,147,32,0.10);
    }
    .ps-badge-direct {
        color: #4caf50;
        border-color: rgba(76,175,80,0.30);
        background: rgba(76,175,80,0.08);
    }
    .ps-loc {
        display: inline-block;
        font-size: 10px;
        padding: 0 4px;
        border-radius: 2px;
        margin: 0 6px;
        line-height: 14px;
        border: 1px solid rgba(127,127,127,0.25);
        background: rgba(127,127,127,0.10);
        color: #888;
    }
    .ps-loc-lan { color: #4caf50; border-color: rgba(76,175,80,0.30); }
    .ps-flag { font-size: 11px; vertical-align: -1px; margin-right: 2px; }
    .ps-started { font-size: 10px; color: #888; white-space: nowrap; }
    .ps-started i { margin-right: 2px; }

    .ps-detail {
        margin-top: 4px;
        padding: 8px 10px;
        background: rgba(127,127,127,0.06);
        border-radius: 4px;
        font-size: 11px;
    }
    .ps-d-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 4px 24px;
    }
    .ps-d-row { display: flex; gap: 8px; min-width: 0; align-items: baseline; }
    .ps-d-label { color: #888; flex: 0 0 80px; white-space: nowrap; }
    .ps-d-val { color: inherit; flex: 1 1 auto; min-width: 0; word-break: break-word; overflow-wrap: anywhere; }
    .ps-d-actions { margin-top: 8px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .ps-d-hint { color: #888; font-style: italic; font-size: 10px; }
    .ps-btn {
        background: rgba(127,127,127,0.15);
        color: inherit;
        border: 1px solid rgba(127,127,127,0.25);
        border-radius: 3px;
        padding: 3px 8px;
        font-size: 11px;
        cursor: pointer;
    }
    .ps-btn:hover { background: rgba(127,127,127,0.25); }
    .ps-btn-kill { color: #e53935; border-color: rgba(229,57,53,0.4); }
    .ps-btn-kill:hover { background: rgba(229,57,53,0.15); }
    .ps-flash { padding: 4px 8px; border-radius: 3px; font-size: 11px; margin-bottom: 6px; }
    .ps-flash-ok  { background: rgba(76,175,80,0.15); color: #4caf50; }
    .ps-flash-err { background: rgba(229,57,53,0.15); color: #e53935; }

    .ps-empty { text-align: center; font-style: italic; color: #888; padding: 16px 0; }
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
    $(function() {
        // The page title shown in the browser tab.
        var t = $('title').html();
        if (t) $('title').html(t.split('/')[0] + '/Plex Streams');
        <?php if (!empty($cfg['TOKEN'])): ?>
        updateDashboardStreamsNew();
        setInterval(updateDashboardStreamsNew, window.PS_REFRESH_MS);
        <?php endif; ?>
    });
</script>
