<?php
// Plain-PHP test harness for the mergeStreams() parsing layer — no framework.
// Fixtures are real-shaped /status/sessions XML payloads; side-effecting
// helpers (geo, machine id, notify) are stubbed before common.php loads,
// which it honours via function_exists() guards.
//
// Run: php tests/run.php   (also runs in CI before every package build)

error_reporting(E_ALL);

define('PLEXSTREAMS_CACHE_DIR', sys_get_temp_dir() . '/ps_test_cache_' . getmypid());
if (!is_dir(PLEXSTREAMS_CACHE_DIR)) {
    mkdir(PLEXSTREAMS_CACHE_DIR, 0755, true);
}

$GLOBALS['display'] = ['time' => '%R', 'date' => '%x'];
$GLOBALS['test_notifications'] = [];

// --- Stubs ---------------------------------------------------------------
function getGeoData($ip) {
    return ['display' => 'Testville, TS AU', 'country' => 'AU', 'flag' => "\xF0\x9F\x87\xA6\xF0\x9F\x87\xBA"];
}
function getMachineIdentifier($host, $token) {
    return 'mid-' . preg_replace('/[^a-z0-9]/i', '', $host);
}
function ps_notify_new_stream($stream, $cfg) {
    $GLOBALS['test_notifications'][] = $stream['titleString'] ?? '';
}

require __DIR__ . '/../src/plexstreams/usr/local/emhttp/plugins/plexstreams/includes/common.php';

$CFG = ['TOKEN' => 'test-token', 'HOST' => 'http://plex.local:32400', 'NOTIFY_NEW_STREAM' => '0'];

$failures = 0;
function ok($cond, $label) {
    global $failures;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label\n";
    }
}

function load_streams($fixture, $cfg, $host = 'http://plex.local:32400') {
    $xml = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
    if ($xml === false) {
        fwrite(STDERR, "missing fixture: $fixture\n");
        exit(2);
    }
    $content = ps_parse_xml($xml);
    return mergeStreams([['url' => $host . '/status/sessions?_m=1', 'content' => $content]], $cfg);
}

// --- multi.xml: two people watching the same title -----------------------
$s = load_streams('multi.xml', $CFG);
ok(count($s) === 2, 'multi: two streams merged');
if (count($s) === 2) {
    ok($s[0]['id'] !== $s[1]['id'], 'multi: same-title sessions get distinct ids');
    ok(preg_match('/^ps-[0-9a-f]{16}$/', $s[0]['id']) === 1, 'multi: ids are DOM-safe');
    ok($s[0]['mediaId'] === '777' && $s[1]['mediaId'] === '777', 'multi: mediaId preserved for reference');
}

// --- mixed.xml: a live (duration-less) session after a normal movie ------
$s = load_streams('mixed.xml', $CFG);
ok(count($s) === 2, 'mixed: two streams merged');
if (count($s) === 2) {
    [$movie, $live] = $s;
    ok($movie['currentPositionHours'] !== null, 'mixed: movie has a position');
    ok($movie['lengthSeconds'] == 3 && $movie['lengthMinutes'] == 2 && $movie['lengthHours'] == 1,
        'mixed: length h/m/s decomposition (3723s = 1h 2m 3s)');
    ok($live['duration'] === null, 'mixed: live stream has no duration');
    ok($live['currentPositionHours'] === null
        && $live['currentPositionMinutes'] === null
        && $live['currentPositionSeconds'] === null,
        'mixed: live stream position fields are null (no leak from previous stream)');
    ok($live['endTime'] === null
        && $live['lengthDisplay'] === null
        && $live['currentPositionDisplay'] === null,
        'mixed: live stream has no clock/end-time fields');
    ok($live['percentPlayed'] === 0, 'mixed: live stream percentPlayed is 0');
}

// --- audio.xml ------------------------------------------------------------
$s = load_streams('audio.xml', $CFG);
ok(count($s) === 1, 'audio: one stream merged');
if (count($s) === 1) {
    $a = $s[0];
    ok($a['type'] === 'audio', 'audio: type');
    ok(strpos($a['title'], '<br') === false, 'audio: title is plain text (client escapes it)');
    ok($a['title'] === 'Song Title - Artist Name - Album Name', 'audio: title chain');
    ok($a['locationShort'] === 'LAN', 'audio: local player without location falls back to LAN');
    ok(isset($a['streamInfo']['audio']), 'audio: selected stream captured');
    ok($a['streamInfo']['audio']['@attributes']['decision'] === 'direct play', 'audio: decision defaults');
    ok($a['streamDecision'] === 'Direct Play', 'audio: directplay normalised');
    ok($a['percentPlayed'] > 0, 'audio: percentPlayed computed');
}

// --- movie.xml: transcode detail ------------------------------------------
$s = load_streams('movie.xml', $CFG);
ok(count($s) === 1, 'movie: one stream merged');
if (count($s) === 1) {
    $m = $s[0];
    ok($m['streamDecision'] === 'transcode', 'movie: transcode decision');
    ok($m['transcodeType'] === 'HW', 'movie: HW transcode detected');
    ok($m['transcodeSpeed'] == 2.4, 'movie: float32 speed string rounded to 2.4');
    ok($m['transcodeProgress'] == 11.7 && $m['transcodeProgress'] != 11.699999809265137,
        'movie: float32 progress string rounded to 11.7');
    ok($m['transcodeThrottled'] === false, 'movie: throttled flag surfaced');
    ok(strpos($m['streamInfo']['video']['@attributes']['decision'], '(HW)') !== false,
        'movie: video decision carries HW suffix');
    ok(($m['streamInfo']['video']['@attributes']['transcodeDetail'] ?? '') === '1080p (H.264) → 1080',
        'movie: video transcodeDetail is structured (no HTML)');
    ok(($m['streamInfo']['audio']['@attributes']['transcodeDetail'] ?? '') === 'aac → opus',
        'movie: audio transcodeDetail is structured');
    ok(strpos($m['streamInfo']['video']['@attributes']['decision'], '<br') === false,
        'movie: no HTML embedded in decision strings');
    ok($m['streamInfo']['subtitle']['@attributes']['decision'] === 'burn (forces video transcode)',
        'movie: subtitle burn annotated');
    ok($m['locationShort'] === 'WAN' && $m['locationGeo'] === 'Testville, TS AU',
        'movie: WAN session gets geo data');
    ok($m['sessionId'] === 'sess-uuid-alice', 'movie: session UUID (not sessionKey) surfaced for terminate');
    ok($m['machineIdentifier'] === 'mid-httpplexlocal32400', 'movie: machineIdentifier via stub');
}

// --- payload contract: every key the client reads -------------------------
$required = [
    'id', 'mediaId', 'sessionKey', 'sessionId', 'type', 'title', 'titleString',
    'user', 'userAvatar', 'state', 'stateIcon', 'bandwidth', 'location',
    'locationShort', 'locationGeo', 'locationCountry', 'locationFlag',
    'locationDisplay', 'address', 'sessionStartedAt', 'sessionDurationSec',
    'percentPlayed', 'streamDecision', 'transcodeType', 'transcodeProgress',
    'transcodeSpeed', 'transcodeThrottled', 'artUrl', 'thumbUrl',
    'machineIdentifier', 'ratingKey', 'streamInfo', 'player', 'playerDevice',
    'alias', '@host', 'key', 'duration', 'lengthDisplay',
    'currentPositionDisplay', 'endTime', 'videoResolution', 'container',
    'currentPositionHours', 'currentPositionMinutes', 'currentPositionSeconds',
];
$missing = [];
foreach (load_streams('movie.xml', $CFG) as $stream) {
    foreach ($required as $k) {
        if (!array_key_exists($k, $stream)) $missing[] = $k;
    }
}
ok($missing === [], 'contract: all client-consumed keys present'
    . ($missing ? ' (missing: ' . implode(',', array_unique($missing)) . ')' : ''));

// --- notify fires once per session, not on every poll ----------------------
$cfgNotify = $CFG + ['NOTIFY_NEW_STREAM' => '1'];
$GLOBALS['test_notifications'] = [];
load_streams('movie.xml', $cfgNotify, 'http://plex2.local:32400');
ok(count($GLOBALS['test_notifications']) === 1, 'notify: fired once for a new session');
load_streams('movie.xml', $cfgNotify, 'http://plex2.local:32400');
ok(count($GLOBALS['test_notifications']) === 1, 'notify: not re-fired for a known session');

// --- defensive shapes -------------------------------------------------------
ok(mergeStreams([['url' => 'http://plex.local:32400/status/sessions', 'content' => null]], $CFG) === [],
    'defensive: null content merges to zero streams');
ok(mergeStreams([['url' => 'not a url', 'content' => []]], $CFG) === [],
    'defensive: unparseable url is skipped');
ok(mergeStreams([], $CFG) === [], 'defensive: empty input');

// --- cleanup + summary ------------------------------------------------------
foreach (glob(PLEXSTREAMS_CACHE_DIR . '/*') ?: [] as $f) @unlink($f);
@rmdir(PLEXSTREAMS_CACHE_DIR);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
