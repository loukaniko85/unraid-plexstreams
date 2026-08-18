<?php
    if (isset($GLOBALS['unRaidSettings'])) {
        define('OS_VERSION', 'Unraid ' . $GLOBALS['unRaidSettings']['version']);
    }
    // Read the installed plugin version straight from the .plg so the value
    // can't drift behind releases. Falls back to "unknown" if the .plg isn't
    // on disk (shouldn't happen at runtime, but keeps OAuth headers sane).
    if (!defined('PLUGIN_VERSION')) {
        $_plgFile = '/boot/config/plugins/plexstreams/plexstreams.plg';
        $_v = '';
        if (is_file($_plgFile)) {
            $_raw = @file_get_contents($_plgFile);
            if ($_raw && preg_match('/ENTITY\s+version\s+"([^"]+)"/', $_raw, $m)) {
                $_v = $m[1];
            }
        }
        define('PLUGIN_VERSION', $_v ?: 'unknown');
    }
    if (!defined('PLEXSTREAMS_CACHE_DIR')) {
        define('PLEXSTREAMS_CACHE_DIR', '/tmp/plexstreams_cache');
    }

    // Cache files are named "<prefix>-<sha1(key)>" where the prefix is the
    // part of the key before the first colon (sess / servers / geo2 /
    // identity / misc). The prefix lets the plugin's post-install script wipe
    // selected cache types (server list, geo, identity) while keeping
    // per-session "first seen" entries — so upgrades no longer reset session
    // uptimes or re-fire new-stream notifications for in-progress streams.
    function ps_cache_file($key) {
        $prefix = 'misc';
        $pos = strpos($key, ':');
        if ($pos !== false) {
            $candidate = substr($key, 0, $pos);
            if (preg_match('/^[a-z0-9]+$/i', $candidate)) {
                $prefix = strtolower($candidate);
            }
        }
        return PLEXSTREAMS_CACHE_DIR . '/' . $prefix . '-' . sha1($key);
    }

    function ps_cache_get($key, $ttl) {
        $file = ps_cache_file($key);
        if (!is_file($file)) return null;
        if ((time() - filemtime($file)) > $ttl) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $val = @unserialize($raw);
        return $val === false ? null : $val;
    }

    function ps_cache_set($key, $value) {
        if (!is_dir(PLEXSTREAMS_CACHE_DIR)) {
            @mkdir(PLEXSTREAMS_CACHE_DIR, 0755, true);
        }
        @file_put_contents(ps_cache_file($key), serialize($value), LOCK_EX);
    }

    function countryFlag($cc) {
        if (!is_string($cc) || !preg_match('/^[A-Za-z]{2}$/', $cc)) return '';
        $cc = strtoupper($cc);
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8')
             . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
    }

    // First-seen timestamp for a session. Refreshes the cache mtime each call
    // so an active session never expires, but never overwrites the value.
    // When the session is brand new (cache miss), the key is recorded in
    // $GLOBALS['_ps_new_sessions'] so mergeStreams() can fire an Unraid
    // notification for it.
    function sessionStartTime($host, $sessionKey) {
        if (empty($sessionKey)) return null;
        $key  = 'sess:' . $host . '|' . $sessionKey;
        $cached = ps_cache_get($key, 86400);
        if (is_int($cached)) {
            @touch(ps_cache_file($key));
            return $cached;
        }
        $now = time();
        ps_cache_set($key, $now);
        $GLOBALS['_ps_new_sessions'][$key] = true;
        return $now;
    }

    // Read-and-clear: the "new" flag is consumed on first read so a session
    // can't be notified twice if it is processed more than once in a request.
    function ps_is_new_session($host, $sessionKey) {
        $key = 'sess:' . $host . '|' . $sessionKey;
        if (empty($GLOBALS['_ps_new_sessions'][$key])) return false;
        unset($GLOBALS['_ps_new_sessions'][$key]);
        return true;
    }

    // Snapshot of host reachability from the most recent getUrl() multi call.
    // ajax.php reads this to tell the client which configured hosts didn't
    // respond, so the widget can surface a "1 server unreachable" chip.
    function ps_set_host_status($status) { $GLOBALS['_ps_host_status'] = $status; }
    function ps_get_host_status()        { return $GLOBALS['_ps_host_status'] ?? []; }

    function v_d($obj) {
        echo('<pre>');
        var_dump($obj);
        echo('</pre>');
    }

    // X-Plex-Token travels as a request header, not a query parameter, so it
    // stays out of URLs — and therefore out of access logs, debug dumps, and
    // anywhere else a URL might be recorded.
    function ps_token_header($token) {
        $token = trim((string)$token);
        return $token === '' ? [] : ['X-Plex-Token: ' . $token];
    }

    // Plex's XML→array conversion collapses single-child lists into a bare
    // map (['@attributes' => ...]). ps_list() always returns a list.
    function ps_list($node) {
        if (!is_array($node) || $node === []) return [];
        if (isset($node['@attributes'])) return [$node];
        return $node;
    }

    // ---------------------------------------------------------------------
    // Network helpers. The side-effecting ones (geo lookup, machine id,
    // notifications) are wrapped in function_exists() guards so the test
    // harness can define stubs before including this file.
    // ---------------------------------------------------------------------

    if (!function_exists('getGeoData')) {
        // Returns ['display'=>string, 'country'=>cc, 'flag'=>emoji]. Cached 24h.
        function getGeoData($ip) {
            $empty = ['display' => '', 'country' => '', 'flag' => ''];
            if (empty($ip)) return $empty;
            $cacheKey = 'geo2:' . $ip;
            $cached = ps_cache_get($cacheKey, 86400);
            if (is_array($cached)) return $cached;

            $url = 'https://plex.tv/api/v2/geoip?ip_address=' . urlencode($ip);
            $resp = getUrl($url);
            $val = $empty;
            if (isset($resp['@attributes'])) {
                $a   = $resp['@attributes'];
                $cc  = $a['code'] ?? '';
                $val = [
                    'display' => ($a['city'] ?? '') . ', '
                               . (isset($a['subdivision']) ? $a['subdivision'] . ' ' : '')
                               . $cc,
                    'country' => $cc,
                    'flag'    => countryFlag($cc),
                ];
            }
            ps_cache_set($cacheKey, $val);
            return $val;
        }
    }

    if (!function_exists('ps_notify_new_stream')) {
        // Fires a notification through Unraid's own notify CLI. That honours
        // whatever delivery channels the user has configured under
        // Settings → Notification Settings (browser toast, email, agents).
        // No-op unless the plugin's NOTIFY_NEW_STREAM toggle is on and the
        // notify script is present on disk (which it is on every supported
        // Unraid version, but be defensive).
        function ps_notify_new_stream($stream, $cfg) {
            if (($cfg['NOTIFY_NEW_STREAM'] ?? '0') !== '1') return;
            $script = '/usr/local/emhttp/webGui/scripts/notify';
            if (!is_executable($script)) return;

            $who    = $stream['user']        ?? 'Someone';
            $title  = $stream['titleString'] ?? ($stream['title'] ?? 'a Plex stream');
            $server = !empty($stream['alias']) ? ' on ' . $stream['alias'] : '';

            // -e: category. -s: subject (drives the browser toast and the
            // bold heading in the archive). -d: short description (rendered
            // under the subject in the archive — Unraid prints "No
            // description" if this is omitted, so build a one-liner of
            // technical context: decision · resolution · bandwidth ·
            // location · player). -m: longer body for email / agents.
            $subject = $who . ' started ' . $title . $server;

            $dec = strtolower((string)($stream['streamDecision'] ?? ''));
            if (strpos($dec, 'transcode') !== false) {
                $decision = 'Transcoding'
                          . (!empty($stream['transcodeType']) ? ' (' . $stream['transcodeType'] . ')' : '');
            } elseif ($dec === 'copy') {
                $decision = 'Direct Stream';
            } elseif ($dec !== '') {
                $decision = 'Direct Play';
            } else {
                $decision = '';
            }

            $location = $stream['locationShort'] ?? '';
            if ($location && $location !== 'LAN' && !empty($stream['locationGeo'])) {
                $location .= ' · ' . $stream['locationGeo'];
            }

            $player = $stream['player'] ?? '';
            if ($player
                && !empty($stream['playerDevice'])
                && $stream['playerDevice'] !== $stream['player']) {
                $player .= ' on ' . $stream['playerDevice'];
            }

            $description = implode(' · ', array_filter([
                $decision,
                $stream['videoResolution'] ?? '',
                !empty($stream['bandwidth']) ? $stream['bandwidth'] . ' Mbps' : '',
                $location,
                $player,
            ]));
            if ($description === '') $description = ' '; // suppress Unraid's "No description"

            $cmd = $script
                 . ' -i normal'
                 . ' -e ' . escapeshellarg('Plex Streams')
                 . ' -s ' . escapeshellarg($subject)
                 . ' -d ' . escapeshellarg($description)
                 . ' -m ' . escapeshellarg($subject . "\n" . $description)
                 . ' add';
            @shell_exec($cmd . ' >/dev/null 2>&1');
        }
    }

    function getServers($cfg) {
        // Server topology rarely changes; cache for 5 minutes per-token to dramatically
        // speed up settings page loads and reduce hits on plex.tv.
        $cacheKey = 'servers:' . ($cfg['TOKEN'] ?? '') . ':' . ($cfg['FORCE_PLEX_HTTPS'] ?? '0');
        $cached = ps_cache_get($cacheKey, 300);
        if ($cached !== null && !isset($_REQUEST['nocache'])) {
            return $cached;
        }

        $headers = ps_token_header($cfg['TOKEN'] ?? '');
        $url  = 'https://plex.tv/devices.xml';
        $url2 = 'https://plex.tv/api/resources'
              . (($cfg['FORCE_PLEX_HTTPS'] ?? '0') === '1' ? '?includeHttps=1' : '');
        if (isset($_REQUEST['dbg'])) {
            v_d($url);
            v_d($url2);
        }
        $servers = getUrl($url, $headers);
        if ($servers !== false && $servers !== null) {
            $serverList = [];
            if (isset($servers['@attributes'])) {
                $servers = [$servers];
            }
            foreach ($servers as $server) {
                foreach (ps_list($server['Device'] ?? []) as $device) {
                    $da = $device['@attributes'] ?? [];
                    if (!isset($da['provides'])) continue;
                    $providers = explode(',', $da['provides']);
                    if (in_array('server', $providers)) {
                        $serverList[$da['clientIdentifier']] = [
                            'Name' => $da['name'],
                            'Identifier' => $da['clientIdentifier'],
                            'Connections' => []
                        ];
                    }
                }
            }
            if (count($serverList) > 0) {
                $connections = getUrl($url2, $headers);
                if ($connections !== false && $connections !== null) {
                    foreach (ps_list($connections['Device'] ?? []) as $device) {
                        $identifier = $device['@attributes']['clientIdentifier'] ?? '';
                        if ($identifier === '' || !isset($serverList[$identifier])) continue;
                        foreach (ps_list($device['Connection'] ?? []) as $connection) {
                            // ps_list() unwraps single-connection devices, which
                            // the old code silently skipped.
                            $ca = $connection['@attributes'] ?? null;
                            if (is_array($ca)) {
                                $serverList[$identifier]['Connections'][] = $ca;
                            }
                        }
                    }
                }
            }
        } else {
            return false;
        }

        ps_cache_set($cacheKey, $serverList);
        return $serverList;
    }

    function getStreams($cfg) {
        $hosts = array_filter(array_map('trim', array_merge(
            explode(',', $cfg['HOST']           ?? ''),
            explode(',', $cfg['CUSTOM_SERVERS'] ?? '')
        )));
        if (empty($cfg['TOKEN']) || empty($hosts)) {
            return [];
        }

        $urls = [];
        foreach ($hosts as $host) {
            $urls[] = rtrim($host, '/') . '/status/sessions?_m=' . time();
        }
        if (isset($_REQUEST['dbg'])) {
            v_d($urls);
        }
        return getUrl($urls, ps_token_header($cfg['TOKEN']));
    }

    if (!function_exists('getMachineIdentifier')) {
        // Per-host machineIdentifier, cached 6h. Used to build "Open in Plex Web"
        // deep links (app.plex.tv/desktop/#!/server/<id>/details?key=...).
        function getMachineIdentifier($host, $token) {
            $cacheKey = 'identity:' . $host;
            $cached = ps_cache_get($cacheKey, 21600);
            if (is_string($cached) && $cached !== '') return $cached;
            $resp = getUrl(rtrim($host, '/') . '/identity', ps_token_header($token));
            $id   = is_array($resp) ? ($resp['@attributes']['machineIdentifier'] ?? '') : '';
            if ($id !== '') ps_cache_set($cacheKey, $id);
            return $id;
        }
    }

    // Apply the standard set of cURL options to a handle (used by both
    // single and multi-curl paths).
    function _ps_curl_opts($ch, $extraHeaders = []) {
        curl_setopt_array($ch, [
            CURLOPT_HEADER         => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: application/xml',
                'Cache-Control: no-cache',
            ], $extraHeaders),
        ]);
    }

    // Parse an XML payload into the attribute-shaped array form the rest of
    // the plugin uses, without leaking libxml warnings on malformed input.
    function ps_parse_xml($body) {
        if (!is_string($body) || $body === '') return null;
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) return null;
        return json_decode(json_encode($xml), true);
    }

    // Returns the raw decoded payload for a single URL, or — for an array
    // of URLs — a map of [idx => ['url' => ..., 'content' => ...]] fetched
    // in parallel via curl_multi.
    function getUrl($urls, $extraHeaders = []) {
        if (!is_array($urls)) {
            $ch = curl_init($urls);
            _ps_curl_opts($ch, $extraHeaders);
            $body = curl_exec($ch);
            curl_close($ch);
            if ($body === false) return null;
            return ps_parse_xml($body);
        }

        $rets    = [];
        $multi   = [];
        $statusByHost = [];
        $mh      = curl_multi_init();
        foreach ($urls as $idx => $url) {
            $multi['streams-' . $idx] = curl_init($url);
            _ps_curl_opts($multi['streams-' . $idx], $extraHeaders);
            curl_multi_add_handle($mh, $multi['streams-' . $idx]);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active && $status === CURLM_OK) {
                // curl_multi_select can return -1 when no fd is ready yet;
                // nap briefly instead of busy-spinning.
                if (curl_multi_select($mh) === -1) {
                    usleep(100000);
                }
            }
        } while ($active && $status === CURLM_OK);

        foreach ($multi as $idx => $ch) {
            $effective = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body      = curl_multi_getcontent($ch);
            $parts     = parse_url($effective);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $hostKey = $parts['scheme'] . '://' . $parts['host']
                         . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $rebuiltUrl = $hostKey
                            . ($parts['path']  ?? '')
                            . (isset($parts['query']) ? '?' . $parts['query'] : '');
                $reachable = ($httpCode >= 200 && $httpCode < 500 && $body !== false && $body !== '');
                $statusByHost[$hostKey] = $reachable;
                if ($reachable) {
                    $rets[$idx] = [
                        'url'     => $rebuiltUrl,
                        'content' => ps_parse_xml($body),
                    ];
                }
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        ps_set_host_status($statusByHost);
        return $rets;
    }

    // ---------------------------------------------------------------------
    // Stream merging
    // ---------------------------------------------------------------------

    // Stable DOM-safe id per *session*. Plex media ids are per-title —
    // identical for everyone watching the same item — so keying rows on the
    // media id made two sessions of the same film collide on one widget row.
    function ps_stream_id($host, $sessionKey, $mediaId) {
        return 'ps-' . substr(sha1($host . '|' . ($sessionKey ?? '') . '|' . $mediaId), 0, 16);
    }

    // Position/duration/end-time fields for one stream. Everything starts
    // null and is only filled when the Part reports a duration — live /
    // zero-duration sessions must not inherit values from a previous stream
    // (the old inline loops leaked loop variables across iterations).
    function ps_playback_fields($itemAttrs, $partAttrs, $display) {
        $f = [
            'duration'                  => null,
            'length'                    => null,
            'lengthInSeconds'           => null,
            'lengthInMinutes'           => null,
            'lengthSeconds'             => null,
            'lengthMinutes'             => null,
            'lengthHours'               => null,
            'currentPosition'           => null,
            'currentPositionInSeconds'  => null,
            'currentPositionInMinutes'  => null,
            'currentPositionHours'      => null,
            'currentPositionMinutes'    => null,
            'currentPositionSeconds'    => null,
            'percentPlayed'             => 0,
            'currentPositionDisplay'    => null,
            'lengthDisplay'             => null,
            'endSecondsFromNow'         => null,
            'endTime'                   => null,
        ];
        if (!isset($partAttrs['duration'])) return $f;

        $duration        = (int)$partAttrs['duration'];
        $lengthInSeconds = $duration / 1000;
        $lengthInMinutes = ceil($lengthInSeconds / 60);

        $offset       = (float)((int)($itemAttrs['viewOffset'] ?? 0));
        $posInSeconds = $offset / 1000;
        $posInMinutes = ceil($posInSeconds / 60);

        $endSecondsFromNow = max(0, (int)ceil($lengthInSeconds - $posInSeconds));
        $timeFmt = (($display['time'] ?? '') === '%R' && ($display['date'] ?? '') !== '%c') ? 'H:i' : 'h:i A';

        $f['duration']                 = $duration;
        $f['length']                   = $duration;
        $f['lengthInSeconds']          = $lengthInSeconds;
        $f['lengthInMinutes']          = $lengthInMinutes;
        $f['lengthSeconds']            = floor($lengthInSeconds % 60);
        $f['lengthMinutes']            = floor(($lengthInSeconds % 3600) / 60);
        $f['lengthHours']              = floor(($lengthInSeconds % 86400) / 3600);
        $f['currentPosition']          = $offset;
        $f['currentPositionInSeconds'] = $posInSeconds;
        $f['currentPositionInMinutes'] = $posInMinutes;
        $f['currentPositionSeconds']   = floor(((int)$posInSeconds) % 60);
        $f['currentPositionMinutes']   = floor((((int)$posInSeconds) % 3600) / 60);
        $f['currentPositionHours']     = floor((((int)$posInSeconds) % 86400) / 3600);
        $f['percentPlayed']            = $lengthInMinutes > 0
            ? round(($posInMinutes / $lengthInMinutes) * 100, 0) : 0;
        $f['currentPositionDisplay']   = sprintf('%02d:%02d:%02d',
            $f['currentPositionHours'], $f['currentPositionMinutes'], $f['currentPositionSeconds']);
        $f['lengthDisplay']            = sprintf('%02d:%02d:%02d',
            $f['lengthHours'], $f['lengthMinutes'], $f['lengthSeconds']);
        $f['endSecondsFromNow']        = $endSecondsFromNow;
        $f['endTime']                  = date($timeFmt, strtotime('+ ' . $endSecondsFromNow . ' seconds'));
        return $f;
    }

    // Location chip fields: LAN/WAN label, geo lookup for WAN addresses, and
    // the long display string. A missing Session.location with a local player
    // is treated as LAN (Plex omits the attribute for some local sessions).
    function ps_location_fields($location, $address, $isLocal) {
        if ($location === null && $isLocal) {
            $location = 'LAN';
        }
        $loc = strtoupper((string)$location);
        $geo = ($loc !== 'LAN' && $address !== '')
            ? getGeoData($address)
            : ['display' => '', 'country' => '', 'flag' => ''];
        $display = ($loc === '' && $address === '')
            ? ''
            : $loc . ' (' . $address . ($geo['display'] !== '' ? ' - ' . $geo['display'] : '') . ')';
        return [
            'location'        => $location,
            'address'         => $address,
            'locationShort'   => $loc,
            'locationGeo'     => $geo['display'],
            'locationCountry' => $geo['country'],
            'locationFlag'    => $geo['flag'],
            'locationDisplay' => $display,
        ];
    }

    // Fields shared by video and audio sessions. $item is the Video/Track
    // element, $media the selected Media, $part the Part being played, and
    // $source the per-host wrapper carrying @host/shortHost.
    function ps_base_stream($type, $item, $media, $part, $source, $cfg) {
        global $display;
        $display = is_array($display) ? $display : [];

        $ia = $item['@attributes']            ?? [];
        $ma = $media['@attributes']           ?? [];
        $pa = $part['@attributes']            ?? [];
        $sa = $item['Session']['@attributes'] ?? [];
        $pl = $item['Player']['@attributes']  ?? [];
        $ua = $item['User']['@attributes']    ?? [];

        $host       = $source['@host'];
        $sessionKey = $ia['sessionKey'] ?? null;
        $mediaId    = (string)($ma['id'] ?? '');
        $state      = (string)($pl['state'] ?? '');

        $addr  = str_replace('.', '_', $source['shortHost']);
        $alias = $cfg['ALIAS-' . $addr] ?? '';

        $art   = (string)($ia['art'] ?? $ma['channelThumb'] ?? '');
        $thumb = (string)($ia['grandparentThumb'] ?? $ia['thumb'] ?? '');

        $s = [
            '@host'              => $host,
            'alias'              => $alias,
            'id'                 => ps_stream_id($host, $sessionKey, $mediaId),
            'mediaId'            => $mediaId,
            'sessionKey'         => $sessionKey,
            'sessionId'          => $sa['id'] ?? null,
            'type'               => $type,
            'player'             => $pl['product'] ?? '',
            'playerDevice'       => $pl['device'] ?? ($pl['platform'] ?? ''),
            'key'                => $ia['key'] ?? '',
            'artUrl'             => '/plugins/plexstreams/getImage.php?img=' . urlencode($art) . '&host=' . urlencode($host),
            'thumbUrl'           => '/plugins/plexstreams/getImage.php?img=' . urlencode($thumb) . '&host=' . urlencode($host),
            'user'               => $ua['title'] ?? '',
            'userAvatar'         => $ua['thumb'] ?? '',
            'state'              => $state,
            'stateIcon'          => $state === 'paused' ? 'pause' : ($state === 'playing' ? 'play' : 'buffer'),
            'bandwidth'          => round(((int)($sa['bandwidth'] ?? 0)) / 1000, 1),
            'machineIdentifier'  => getMachineIdentifier($host, $cfg['TOKEN'] ?? ''),
            'ratingKey'          => $ia['ratingKey'] ?? '',
            'streamInfo'         => [],
            'streamDecision'     => '',
            'transcodeType'      => '',
            'transcodeProgress'  => null,
            'transcodeSpeed'     => null,
            'transcodeThrottled' => false,
        ];
        $s += ps_playback_fields($ia, $pa, $display);
        $s += ps_location_fields($sa['location'] ?? null, $pl['address'] ?? '', ($pl['local'] ?? '0') === '1');

        $startTs = sessionStartTime($host, $sessionKey ?? '');
        $s['sessionStartedAt']   = $startTs;
        $s['sessionDurationSec'] = $startTs ? (time() - $startTs) : null;
        return $s;
    }

    // "Movie (2020)" or "Show - S1E2 title (2020)" style chain; titles with a
    // media origin (DVR/Live) are used as-is.
    function ps_video_title($ia, $ma) {
        if (isset($ma['origin'])) {
            return (string)($ia['title'] ?? '');
        }
        $title = (string)($ia['title'] ?? '') . (isset($ia['year']) ? ' (' . $ia['year'] . ')' : '');
        if (isset($ia['parentTitle'])) {
            $title = $ia['parentTitle'] . ' - ' . $title;
        }
        if (isset($ia['grandparentTitle']) && $ia['grandparentTitle'] !== $title) {
            $title = $ia['grandparentTitle'] . ' - ' . $title;
        }
        return $title;
    }

    // Audio titles are plain text — the client escapes everything it renders,
    // so embedding HTML here only displayed literal tags.
    function ps_audio_title($ia) {
        return (string)($ia['title'] ?? '')
             . ' - ' . (string)($ia['originalTitle'] ?? '')
             . ' - ' . (string)($ia['parentTitle'] ?? '');
    }

    // Pick the audio/video/subtitle streams out of a Part. Decisions default
    // to 'direct play' when Plex omits them; a burned subtitle gets a constant
    // annotation (it forces the video transcode).
    function ps_extract_stream_info($part) {
        $info = [];
        $sub  = null;
        foreach (ps_list($part['Stream'] ?? []) as $stream) {
            $a    = $stream['@attributes'] ?? [];
            $type = (string)($a['streamType'] ?? '');
            if ($type === '2') {
                $info['audio'] = $stream;
            } elseif ($type === '1') {
                $info['video'] = $stream;
            } elseif ($type === '3') {
                if (($a['selected'] ?? '0') === '1' || $sub === null) {
                    $sub = $stream;
                }
            }
        }
        foreach (['audio', 'video'] as $k) {
            if (isset($info[$k])) {
                $info[$k]['@attributes']['decision'] = $info[$k]['@attributes']['decision'] ?? 'direct play';
            }
        }
        if ($sub !== null) {
            $sub['@attributes']['decision'] = $sub['@attributes']['decision'] ?? 'direct play';
            if ($sub['@attributes']['decision'] === 'burn') {
                $sub['@attributes']['decision'] = 'burn (forces video transcode)';
            }
            $info['subtitle'] = $sub;
        }
        return $info;
    }

    // Transcode-only enrichment: HW/CPU classification, per-stream detail
    // strings (structured data — the client renders the line break), and the
    // speed/progress/throttled telemetry from TranscodeSession.
    function ps_apply_transcode(&$s, $video, $media) {
        if ($s['streamDecision'] !== 'transcode') return;

        $ts   = $video['TranscodeSession']['@attributes'] ?? [];
        $isHw = (($ts['transcodeHwRequested']    ?? '0') === '1')
             || (($ts['transcodeHwFullPipeline'] ?? '0') === '1')
             || !empty($ts['transcodeHwEncoding'])
             || !empty($ts['transcodeHwDecoding']);
        $s['transcodeType'] = $isHw ? 'HW' : 'CPU';

        if (isset($s['streamInfo']['video']['@attributes'])) {
            $v = &$s['streamInfo']['video']['@attributes'];
            if (($v['decision'] ?? '') === 'transcode') {
                $v['decision'] .= $isHw ? ' (HW)' : ' (CPU)';
                if (!empty($v['displayTitle']) && !empty($media['@attributes']['videoResolution'])) {
                    $v['transcodeDetail'] = $v['displayTitle'] . ' → ' . $media['@attributes']['videoResolution'];
                }
            }
            unset($v);
        }
        if (isset($s['streamInfo']['audio']['@attributes'])) {
            $a = &$s['streamInfo']['audio']['@attributes'];
            if (($a['decision'] ?? '') === 'transcode'
                && !empty($ts['sourceAudioCodec']) && !empty($ts['audioCodec'])) {
                $a['transcodeDetail'] = $ts['sourceAudioCodec'] . ' → ' . $ts['audioCodec'];
            }
            unset($a);
        }

        // Plex serialises single-precision floats — progress arrives as
        // "11.699999809265137", not "11.7". Round at the source.
        $s['transcodeProgress']  = isset($ts['progress']) ? round((float)$ts['progress'], 1) : null;
        $s['transcodeSpeed']     = isset($ts['speed'])    ? round((float)$ts['speed'], 1)   : null;
        $s['transcodeThrottled'] = (($ts['throttled'] ?? '0') === '1');
    }

    function mergeStreams($allStreams, $cfg) {
        $mergedStreams = [];

        foreach ($allStreams as $details) {
            $urlParts = is_array($details) ? parse_url($details['url'] ?? '') : false;
            if ($urlParts === false || empty($urlParts['scheme']) || empty($urlParts['host'])) continue;

            $source = is_array($details['content']) ? $details['content'] : [];
            $source['@host']     = $urlParts['scheme'] . '://' . $urlParts['host']
                                 . (isset($urlParts['port']) ? ':' . $urlParts['port'] : '');
            $source['shortHost'] = $urlParts['host'];

            foreach (ps_list($source['Video'] ?? []) as $video) {
                foreach (ps_list($video['Media'] ?? []) as $media) {
                    if (($media['@attributes']['selected'] ?? '') !== '1') continue;
                    $parts = ps_list($media['Part'] ?? []);
                    $part  = $parts ? reset($parts) : [];

                    $s = ps_base_stream('video', $video, $media, $part, $source, $cfg);
                    $s['title']           = ps_video_title($video['@attributes'] ?? [], $media['@attributes'] ?? []);
                    $s['titleString']     = $s['title'];
                    $s['videoResolution'] = $media['@attributes']['videoResolution'] ?? null;
                    $s['container']       = $media['@attributes']['container'] ?? null;
                    $s['streamInfo']      = ps_extract_stream_info($part);
                    $s['streamDecision']  = $part['@attributes']['decision'] ?? '';
                    if ($s['streamDecision'] === 'directplay') {
                        $s['streamDecision'] = 'Direct Play';
                    }
                    ps_apply_transcode($s, $video, $media);

                    if (ps_is_new_session($source['@host'], $s['sessionKey'] ?? '')) {
                        ps_notify_new_stream($s, $cfg);
                    }
                    $mergedStreams[] = $s;
                }
            }

            foreach (ps_list($source['Track'] ?? []) as $track) {
                foreach (ps_list($track['Media'] ?? []) as $media) {
                    foreach (ps_list($media['Part'] ?? []) as $part) {
                        // Audio rows are keyed off the *selected* stream only.
                        $selected = null;
                        foreach (ps_list($part['Stream'] ?? []) as $stream) {
                            if (($stream['@attributes']['selected'] ?? '0') === '1') {
                                $selected = $stream;
                                break;
                            }
                        }
                        if ($selected === null) continue;

                        $s = ps_base_stream('audio', $track, $media, $part, $source, $cfg);
                        $s['title']          = ps_audio_title($track['@attributes'] ?? []);
                        $s['titleString']    = $s['title'];
                        $s['streamDecision'] = $part['@attributes']['decision'] ?? 'Direct Play';
                        if ($s['streamDecision'] === 'directplay') {
                            $s['streamDecision'] = 'Direct Play';
                        }
                        $s['streamInfo']['audio'] = $selected;
                        $s['streamInfo']['audio']['@attributes']['decision'] =
                            $selected['@attributes']['decision'] ?? 'direct play';

                        if (ps_is_new_session($source['@host'], $s['sessionKey'] ?? '')) {
                            ps_notify_new_stream($s, $cfg);
                        }
                        $mergedStreams[] = $s;
                    }
                }
            }
        }

        return $mergedStreams;
    }
?>
