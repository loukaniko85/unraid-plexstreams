<?php
    require_once '/usr/local/emhttp/plugins/plexstreams/includes/config.php';
    require_once '/usr/local/emhttp/plugins/plexstreams/includes/common.php';

    if (empty($cfg['TOKEN']) || empty($_GET['details']) || empty($_GET['host'])) {
        http_response_code(400);
        exit('Missing token/host/details');
    }

    $host    = urldecode((string)$_GET['host']);
    $detKey  = urldecode((string)$_GET['details']);

    // SSRF guard: host must be one of the configured Plex servers.
    $allowedHosts = array_filter(array_map('trim', array_merge(
        explode(',', $cfg['HOST']           ?? ''),
        explode(',', $cfg['CUSTOM_SERVERS'] ?? '')
    )));
    if (!in_array($host, $allowedHosts, true)) {
        http_response_code(403);
        exit('Host not in configured Plex servers');
    }

    $url     = rtrim($host, '/') . $detKey . '?X-Plex-Token=' . urlencode($cfg['TOKEN']);
    $details = getUrl($url);

    $video      = $details['Video']            ?? [];
    $videoAttr  = $video['@attributes']         ?? [];
    $title      = $videoAttr['title']           ?? '';

    // Normalise repeating elements (Plex returns either a single map or a list).
    foreach (['Genre', 'Director', 'Role'] as $k) {
        if (isset($video[$k]['@attributes'])) {
            $video[$k] = [$video[$k]];
        }
    }

    $directors = array_map(fn($d) => $d['@attributes']['tag'] ?? '', $video['Director'] ?? []);
    $genres    = array_map(fn($g) => $g['@attributes']['tag'] ?? '', $video['Genre']    ?? []);
?>
<style>
body { padding: 25px; }
.movie-meta strong { display: inline-block; min-width: 70px; }
.cast-row { margin: 2px 0; }
</style>

<h1><?= htmlspecialchars($title) ?></h1>

<?php if (!empty($videoAttr['summary'])): ?>
    <p><?= htmlspecialchars($videoAttr['summary']) ?></p>
<?php endif; ?>

<p class="movie-meta">
    <?php if (!empty($videoAttr['year'])): ?>
        <strong>Year:</strong> <?= htmlspecialchars($videoAttr['year']) ?><br>
    <?php endif; ?>
    <?php if (!empty($videoAttr['studio'])): ?>
        <strong>Studio:</strong> <?= htmlspecialchars($videoAttr['studio']) ?><br>
    <?php endif; ?>
    <?php if (!empty($directors)): ?>
        <strong>Director:</strong> <?= htmlspecialchars(implode(' / ', $directors)) ?><br>
    <?php endif; ?>
    <?php if (!empty($genres)): ?>
        <strong>Genre:</strong> <?= htmlspecialchars(implode(' / ', $genres)) ?><br>
    <?php endif; ?>
    <?php if (!empty($videoAttr['contentRating'])): ?>
        <strong>Rating:</strong> <?= htmlspecialchars($videoAttr['contentRating']) ?>
    <?php endif; ?>
</p>

<?php if (!empty($video['Role'])): ?>
    <h2>Cast</h2>
    <?php foreach ($video['Role'] as $role): ?>
        <?php $attr = $role['@attributes'] ?? []; ?>
        <div class="cast-row">
            <?= htmlspecialchars($attr['tag'] ?? '') ?>
            <?php if (!empty($attr['role'])): ?>
                <span style="color:#888;">as <?= htmlspecialchars($attr['role']) ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
