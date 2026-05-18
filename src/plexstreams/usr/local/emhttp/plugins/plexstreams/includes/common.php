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
    define('PLEXSTREAMS_CACHE_DIR', '/tmp/plexstreams_cache');

    function ps_cache_get($key, $ttl) {
        $file = PLEXSTREAMS_CACHE_DIR . '/' . sha1($key);
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
        @file_put_contents(PLEXSTREAMS_CACHE_DIR . '/' . sha1($key), serialize($value), LOCK_EX);
    }

    function countryFlag($cc) {
        if (!is_string($cc) || !preg_match('/^[A-Za-z]{2}$/', $cc)) return '';
        $cc = strtoupper($cc);
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8')
             . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
    }

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

    // Back-compat scalar wrapper (still used in a couple of places).
    function getGeo($ip) {
        $g = getGeoData($ip);
        return $g['display'];
    }

    // First-seen timestamp for a session. Refreshes the cache mtime each call
    // so an active session never expires, but never overwrites the value.
    // When the session is brand new (cache miss), the key is recorded in
    // $GLOBALS['_ps_new_sessions'] so mergeStreams() can fire an Unraid
    // notification for it.
    function sessionStartTime($host, $sessionKey) {
        if (empty($sessionKey)) return null;
        $key  = 'sess:' . $host . '|' . $sessionKey;
        $file = PLEXSTREAMS_CACHE_DIR . '/' . sha1($key);
        $cached = ps_cache_get($key, 86400);
        if (is_int($cached)) {
            @touch($file);
            return $cached;
        }
        $now = time();
        ps_cache_set($key, $now);
        $GLOBALS['_ps_new_sessions'][$key] = true;
        return $now;
    }

    function ps_is_new_session($host, $sessionKey) {
        return !empty($GLOBALS['_ps_new_sessions']['sess:' . $host . '|' . $sessionKey] ?? null);
    }

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

    function getServers($cfg) {
        // Server topology rarely changes; cache for 5 minutes per-token to dramatically
        // speed up settings page loads and reduce hits on plex.tv.
        $cacheKey = 'servers:' . ($cfg['TOKEN'] ?? '') . ':' . ($cfg['FORCE_PLEX_HTTPS'] ?? '0');
        $cached = ps_cache_get($cacheKey, 300);
        if ($cached !== null && !isset($_REQUEST['nocache'])) {
            return $cached;
        }

        $url = 'https://plex.tv/devices.xml?X-Plex-Token=' . $cfg['TOKEN'];
        $url2 = 'https://plex.tv/api/resources?X-Plex-Token=' .$cfg['TOKEN'] . ($cfg['FORCE_PLEX_HTTPS'] === '1' ? '&includeHttps=1' : '');
        if (isset($_REQUEST['dbg'])) {
            v_d($url);
        }
        $servers = getUrl($url);
        if ($servers !== false) {
            $serverList = [];
            if (isset($servers['@attributes'])) {
                $servers = [$servers];
            }
            foreach($servers as $server) {
                if (isset($server['Device']['@attributes'])) {
                    $server['Device'] = [$server['Device']];
                }
                foreach($server['Device'] as $device) {
                    if (isset($device['@attributes']['provides'])) {
                        $providers = explode(',', $device['@attributes']['provides']);
                        if (in_array('server', $providers)) {
                            $serverList[$device['@attributes']['clientIdentifier']] = [
                                'Name' => $device['@attributes']['name'],
                                'Identifier' => $device['@attributes']['clientIdentifier'],
                                'Connections' => []
                            ];
                        }
                    }
                }
                if (count($serverList) > 0) {
                    $connections = getUrl($url2);
                    if ($connections !== false) {
                        foreach($connections['Device'] as $device) {
                            $identifier = $device['@attributes']['clientIdentifier'];
                            if (isset($serverList[$identifier])) {
                                foreach($device['Connection'] as $connection) {
                                    if (isset($connection['@attributes'])) {
                                        array_push($serverList[$identifier]['Connections'], $connection['@attributes']);
                                    }
                                }
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
            $urls[] = $host . '/status/sessions?X-Plex-Token=' . $cfg['TOKEN'] . '&_m=' . time();
        }
        if (isset($_REQUEST['dbg'])) {
            v_d($urls);
        }
        return getUrl($urls);
    }

    // Per-host machineIdentifier, cached 6h. Used to build "Open in Plex Web"
    // deep links (app.plex.tv/desktop/#!/server/<id>/details?key=...).
    function getMachineIdentifier($host, $token) {
        $cacheKey = 'identity:' . $host;
        $cached = ps_cache_get($cacheKey, 21600);
        if (is_string($cached) && $cached !== '') return $cached;
        $resp = getUrl(rtrim($host, '/') . '/identity?X-Plex-Token=' . urlencode($token));
        $id   = is_array($resp) ? ($resp['@attributes']['machineIdentifier'] ?? '') : '';
        if ($id !== '') ps_cache_set($cacheKey, $id);
        return $id;
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

    // Apply the standard set of cURL options to a handle (used by both
    // single and multi-curl paths).
    function _ps_curl_opts($ch) {
        curl_setopt_array($ch, [
            CURLOPT_HEADER         => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/xml',
                'Cache-Control: no-cache',
            ],
        ]);
    }

    // Returns the raw decoded payload for a single URL, or — for an array
    // of URLs — a map of [idx => ['url' => ..., 'content' => ...]] fetched
    // in parallel via curl_multi.
    function getUrl($urls) {
        if (!is_array($urls)) {
            $ch = curl_init($urls);
            _ps_curl_opts($ch);
            $body = curl_exec($ch);
            curl_close($ch);
            if ($body === false) return null;
            return json_decode(json_encode(simplexml_load_string($body)), true);
        }

        $rets    = [];
        $multi   = [];
        $statusByHost = [];
        $mh      = curl_multi_init();
        foreach ($urls as $idx => $url) {
            $multi['streams-' . $idx] = curl_init($url);
            _ps_curl_opts($multi['streams-' . $idx]);
            curl_multi_add_handle($mh, $multi['streams-' . $idx]);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh);
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
                        'content' => json_decode(json_encode(simplexml_load_string($body)), true),
                    ];
                }
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        ps_set_host_status($statusByHost);
        return $rets;
    }

    function mergeStreams($allStreams, $cfg) {
        global $display;

        $mergedStreams = [];
        $videoStreams = [];
        foreach($allStreams as $idx=>$details) {
            $urlParts = parse_url($details['url']);
            if ($urlParts !== false) {
                $source = (is_array($details['content'])) ? $details['content'] : [];
                $source['@host'] = $urlParts['scheme'] . '://' . $urlParts['host']
                                 . (isset($urlParts['port']) ? ':' . $urlParts['port'] : '');
                $source['shortHost'] = $urlParts['host'];
                $videoStreams[] = $source;
            }
        }

        foreach($videoStreams as $streams) {
            if (isset($streams['Video'])) {
                if (isset($streams['Video']) && isset($streams['Video']['@attributes'])) {
                    $streams['Video'] = [$streams['Video']];
                }
                foreach($streams['Video'] as $idx=>$video) {
                    
                    if (isset($video['Media']['@attributes'])) {
                        $video['Media'] = [$video['Media']];
                    }
                    foreach($video['Media'] as $media) {
                        if (isset($media['@attributes']['selected']) && $media['@attributes']['selected'] === '1') {
                            // Normalise Part to a list (multi-file titles return >1).
                            if (isset($media['Part']['@attributes'])) {
                                $media['Part'] = [$media['Part']];
                            }
                            $part = is_array($media['Part'] ?? null) ? reset($media['Part']) : null;

                            if (!isset($media['@attributes']['origin'])) {
                                $title = $video['@attributes']['title'] . (isset($video['@attributes']['year']) ? ' (' . $video['@attributes']['year'] . ')' : '' );
                                if (isset($video['@attributes']['parentTitle'])) {
                                    $title = $video['@attributes']['parentTitle'] . ' - ' . $title;
                                }
                                if (isset($video['@attributes']['grandparentTitle']) && $video['@attributes']['grandparentTitle'] !== $title) {
                                    $title = $video['@attributes']['grandparentTitle'] . ' - ' . $title;
                                }
                            } else  {
                                $title = $video['@attributes']['title'];
                            }
                            if (isset($part['@attributes']['duration'])) {
                                $duration = $part['@attributes']['duration'];
                                $lengthInSeconds = $duration / 1000;
                                $lengthInMinutes = ceil($lengthInSeconds / 60 );
                                $lengthSeconds = floor(intval($lengthInSeconds)%60);
                                $lengthMinutes = floor((intval($lengthInSeconds)%3600)/60);
                                $lengthHours = floor((intval($lengthInSeconds)%86400)/3600);
                                
                                $currentPosition = floatval((int)$video['@attributes']['viewOffset']);
                                $currentPositionInSeconds = $video['@attributes']['viewOffset'] / 1000;
                                $currentPositionInMinutes = ceil($currentPositionInSeconds / 60);
                                $currentPositionSeconds = floor((int)$currentPositionInSeconds%60);
                                $currentPositionMinutes = floor(((int)$currentPositionInSeconds%3600)/60);
                                $currentPositionHours = floor(((int)$currentPositionInSeconds%86400)/3600);
                                $endSecondsFromNow = ceil($lengthInSeconds - $currentPositionInSeconds);
                                
                                $endTime = date('h:i A', strtotime('+ ' . $endSecondsFromNow . ' seconds'));
                                if ($display['time'] == '%R' && $display['date'] != '%c') {
                                    $endTime = date('H:i', strtotime('+ ' . $endSecondsFromNow . ' seconds'));
                                }
                            } else {
                                $duration = null;
                            }
                            if (isset($video['@attributes']['art'])) {
                                $artThumb = $video['@attributes']['art'];
                            } else {
                                if (isset($media['@attributes']['channelThumb'])) {
                                    $artThumb = $media['@attributes']['channelThumb'];
                                } else {
                                    $artThumb = '';
                                }
                            }

                            $addr = str_replace('.', '_', $streams['shortHost']);
                            $alias = '';
                            $aliasKey = 'ALIAS-' . $addr;
                            
                            if (isset($cfg[$aliasKey])) {
                                $alias = $cfg[$aliasKey];
                            }

                            $mergedStream = [
                                '@host' => $streams['@host'],
                                'alias' => $alias,
                                'id' => $media['@attributes']['id'],
                                'sessionKey' => $video['@attributes']['sessionKey'] ?? null,
                                'type' => 'video',
                                'player' => $video['Player']['@attributes']['product'] ?? '',
                                'playerDevice' => $video['Player']['@attributes']['device'] ?? ($video['Player']['@attributes']['platform'] ?? ''),
                                'title' => $title,
                                'titleString' => $title,
                                'key' => $video['@attributes']['key'],
                                'duration' => $duration,
                                'artUrl' => '/plugins/plexstreams/getImage.php?img=' . urlencode($artThumb) . '&host=' . urlencode($streams['@host']),
                                'thumbUrl' => '/plugins/plexstreams/getImage.php?img=' .  urlencode($video['@attributes']['grandparentThumb'] ?? $video['@attributes']['thumb']) . '&host=' . urlencode($streams['@host']),
                                'user' => $video['User']['@attributes']['title'],
                                'userAvatar' => $video['User']['@attributes']['thumb'],
                                'state' => $video['Player']['@attributes']['state'],
                                'stateIcon' => 'play',
                                'length' => $duration ?? null,
                                'lengthInSeconds' => $lengthInSeconds ?? null,
                                'lengthInMinutes' => $lengthInMinutes ?? null,
                                'lengthSeconds' => $lengthInSeconds ?? null,
                                'lengthMinutes' => $lengthMinutes ?? null,
                                'lengthHours' => $lengthHours ?? null,
                                'currentPosition' => $currentPosition ?? null,
                                'currentPositionInSeconds' =>  $currentPositionInSeconds ?? null,
                                'currentPositionInMinutes' =>  $currentPositionInMinutes ?? null,
                                'currentPositionHours' => $currentPositionHours ?? null,
                                'currentPositionMinutes' => $currentPositionMinutes ?? null,
                                'currentPositionSeconds' => $currentPositionSeconds ?? null,
                                'location' => $video['Session']['@attributes']['location'] ?? null,
                                'address' => $video['Player']['@attributes']['address'] ?? '',
                                'bandwidth' => round((int)($video['Session']['@attributes']['bandwidth'] ?? 0) / 1000, 1),
                                'videoResolution' => $media['@attributes']['videoResolution'] ?? null,
                                'container' => $media['@attributes']['container'] ?? null,
                                'endSecondsFromNow' => (isset($endSecondsFromNow) ? ceil($endSecondsFromNow) : null),
                                'endTime' => (isset($endTime) ? $endTime : null),
                                'streamInfo' => [],
                                'transcodeType' => '',
                            ];

                            if (isset($alias)) {
                                $mergedStream['alias'] = $alias;
                            }
                            $mergedStream['machineIdentifier'] = getMachineIdentifier($streams['@host'], $cfg['TOKEN'] ?? '');
                            $mergedStream['ratingKey']         = $video['@attributes']['ratingKey'] ?? '';
                            $loc = strtoupper($mergedStream['location'] ?? '');
                            $geoData = ($loc !== 'LAN' && !empty($mergedStream['address']))
                                ? getGeoData($mergedStream['address'])
                                : ['display' => '', 'country' => '', 'flag' => ''];
                            $mergedStream['locationShort']   = $loc ?: '';
                            $mergedStream['locationGeo']     = $geoData['display'];
                            $mergedStream['locationCountry'] = $geoData['country'];
                            $mergedStream['locationFlag']    = $geoData['flag'];
                            $mergedStream['locationDisplay'] = $loc . ' (' . $mergedStream['address']
                                . ($geoData['display'] !== '' ? ' - ' . $geoData['display'] : '')
                                . ')';
                            $startTs = sessionStartTime($streams['@host'], $mergedStream['sessionKey'] ?? '');
                            $mergedStream['sessionStartedAt']  = $startTs;
                            $mergedStream['sessionDurationSec'] = $startTs ? (time() - $startTs) : null;
                            
                            if ($mergedStream['duration'] !== null) {
                                $mergedStream['percentPlayed'] = ($lengthInMinutes > 0)
                                    ? round(($currentPositionInMinutes / $lengthInMinutes) * 100, 0)
                                    : 0;
                                $mergedStream['currentPositionDisplay'] = str_pad($currentPositionHours, 2, '0', STR_PAD_LEFT) . ':' . str_pad($currentPositionMinutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad($currentPositionSeconds, 2, '0', STR_PAD_LEFT);
                                $mergedStream['lengthDisplay'] = str_pad($lengthHours, 2, '0', STR_PAD_LEFT) . ':' . str_pad($lengthMinutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad($lengthSeconds, 2, '0', STR_PAD_LEFT);
                            } else {
                                $mergedStream['percentPlayed'] = 0;
                            }

                            if ($mergedStream['state'] === 'paused') {
                                $mergedStream['stateIcon'] = 'pause';
                            } else if ($mergedStream['state'] !== 'playing') {
                                $mergedStream['stateIcon'] = 'buffer';
                            }

                            // Normalise Part.Stream to a list (single stream → map).
                            $partStreams = $part['Stream'] ?? [];
                            if (isset($partStreams['@attributes'])) {
                                $partStreams = [$partStreams];
                            }
                            // Pick selected subtitle if multiple are present.
                            $subPicked = null;
                            foreach ($partStreams as $stream) {
                                $type = $stream['@attributes']['streamType'] ?? '';
                                if ($type === '2') {
                                    $mergedStream['streamInfo']['audio'] = $stream;
                                    $mergedStream['streamInfo']['audio']['@attributes']['decision'] = $mergedStream['streamInfo']['audio']['@attributes']['decision'] ?? 'direct play';
                                } else if ($type === '1') {
                                    $mergedStream['streamInfo']['video'] = $stream;
                                    $mergedStream['streamInfo']['video']['@attributes']['decision'] = $mergedStream['streamInfo']['video']['@attributes']['decision'] ?? 'direct play';
                                } else if ($type === '3') {
                                    if (($stream['@attributes']['selected'] ?? '0') === '1' || $subPicked === null) {
                                        $subPicked = $stream;
                                    }
                                }
                            }
                            if ($subPicked !== null) {
                                $mergedStream['streamInfo']['subtitle'] = $subPicked;
                                $mergedStream['streamInfo']['subtitle']['@attributes']['decision'] = $subPicked['@attributes']['decision'] ?? 'direct play';
                            }

                            $mergedStream['streamDecision'] = $part['@attributes']['decision'] ?? '';
                            if ($mergedStream['streamDecision'] === 'directplay') {
                                $mergedStream['streamDecision'] = 'Direct Play';
                            }

                            if ($mergedStream['streamDecision'] === 'transcode') {
                                $ts = $video['TranscodeSession']['@attributes'] ?? [];
                                $isHw = (
                                    (($ts['transcodeHwRequested']    ?? '0') === '1') ||
                                    (($ts['transcodeHwFullPipeline'] ?? '0') === '1') ||
                                    !empty($ts['transcodeHwEncoding']) ||
                                    !empty($ts['transcodeHwDecoding'])
                                );
                                $mergedStream['transcodeType'] = $isHw ? 'HW' : 'CPU';

                                if (isset($mergedStream['streamInfo']['video']['@attributes']['decision']) && $mergedStream['streamInfo']['video']['@attributes']['decision'] === 'transcode') {
                                    $mergedStream['streamInfo']['video']['@attributes']['decision'] .= $isHw ? ' (HW)' : ' (CPU)';
                                    if (!empty($mergedStream['streamInfo']['video']['@attributes']['displayTitle']) && !empty($media['@attributes']['videoResolution'])) {
                                        $mergedStream['streamInfo']['video']['@attributes']['decision'] .= '<br/>' . $mergedStream['streamInfo']['video']['@attributes']['displayTitle'] . ' -> ' . $media['@attributes']['videoResolution'];
                                    }
                                }
                                if (isset($mergedStream['streamInfo']['audio']['@attributes']['decision']) && $mergedStream['streamInfo']['audio']['@attributes']['decision'] === 'transcode') {
                                    if (!empty($ts['sourceAudioCodec']) && !empty($ts['audioCodec'])) {
                                        $mergedStream['streamInfo']['audio']['@attributes']['decision'] .= ' (' . $ts['sourceAudioCodec'] . ' -> ' . $ts['audioCodec'] . ')';
                                    }
                                }
                                // Subtitle decision: Plex reports 'burn' for image-based subs
                                // burned into the video, or 'copy'/'transcode' for soft tracks.
                                if (isset($mergedStream['streamInfo']['subtitle']['@attributes']['decision'])) {
                                    $sd = $mergedStream['streamInfo']['subtitle']['@attributes']['decision'];
                                    if ($sd === 'burn') {
                                        $mergedStream['streamInfo']['subtitle']['@attributes']['decision'] = 'burn (forces video transcode)';
                                    }
                                }
                            }

                            if (ps_is_new_session($streams['@host'], $mergedStream['sessionKey'] ?? '')) {
                                ps_notify_new_stream($mergedStream, $cfg);
                            }
                            $mergedStreams[] = $mergedStream;
                        }
                    }
                }
            }
            if (isset($streams['Track'])) {
                if (isset($streams['Track']) && isset($streams['Track']['@attributes'])) {
                    $streams['Track'] = [$streams['Track']];
                }
                foreach($streams['Track'] as $idx=>$audio) {
                    if (isset($audio['Media']['@attributes'])) {
                        $audio['Media'] = [$audio['Media']];
                    }
                    
                    foreach($audio['Media'] as $media) {
                        if (isset($media['Part']) && isset($media['Part']['@attributes'])) {
                            $media['Part'] = [$media['Part']];
                        }
                        foreach($media['Part'] as $part) {
                            if (isset($part['Stream']) && isset($part['Stream']['@attributes'])) {
                                $part['Stream'] = [$part['Stream']];
                            }
                            foreach ($part['Stream'] as $stream) {
                                if ($stream['@attributes']['selected'] === '1') {
                                    $title = $audio['@attributes']['title'] . ' - ' . $audio['@attributes']['originalTitle'] . '<br/><span style="font-size:8px;">' . $audio['@attributes']['parentTitle'] . '</span>';
                                    $titleString = $audio['@attributes']['title'] . ' - ' . $audio['@attributes']['originalTitle'] . ' - ' . $audio['@attributes']['parentTitle'];
                                    $duration = $part['@attributes']['duration'];
                                    $lengthInSeconds = $duration / 1000;
                                    $lengthInMinutes = ceil($lengthInSeconds / 60 );
                                    $lengthSeconds = floor($lengthInSeconds%60);
                                    $lengthMinutes = floor(($lengthInSeconds%3600)/60);
                                    $lengthHours = floor(($lengthInSeconds%86400)/3600);
                                    $currentPosition = floatval((int)$audio['@attributes']['viewOffset']);
                                    $currentPositionInSeconds = $audio['@attributes']['viewOffset'] / 1000;
                                    $currentPositionInMinutes = ceil($currentPositionInSeconds / 60);
                                    $currentPositionSeconds = floor($currentPositionInSeconds%60);
                                    $currentPositionMinutes = floor(($currentPositionInSeconds%3600)/60);
                                    $currentPositionHours = floor(($currentPositionInSeconds%86400)/3600);
                                    $endSecondsFromNow = $lengthInSeconds - $currentPositionInSeconds;
                                    $endTime = date('h:i A', strtotime('+ ' . $endSecondsFromNow . ' seconds'));
                                    if ($display['time'] == '%R' && $display['date'] != '%c') {
                                        $endTime = date('H:i', strtotime('+ ' . $endSecondsFromNow . ' seconds'));
                                    }
                                    $addr = str_replace('.', '_', $streams['shortHost']);
                                    $alias = '';
                                    if (isset($cfg['ALIAS-' . $addr])) {
                                        $alias = $cfg['ALIAS-' . $addr];
                                    }
                                    $mergedStream = [
                                        '@host' => $streams['@host'],
                                        'alias'=> $alias,
                                        'id' => $media['@attributes']['id'],
                                        'sessionKey' => $audio['@attributes']['sessionKey'] ?? null,
                                        'type' => 'audio',
                                        'player' => $audio['Player']['@attributes']['product'] ?? '',
                                        'playerDevice' => $audio['Player']['@attributes']['device'] ?? ($audio['Player']['@attributes']['platform'] ?? ''),
                                        'transcodeType' => '',
                                        'title' => $title,
                                        'titleString' => $titleString,
                                        'key' => $audio['@attributes']['key'],
                                        'duration' => $duration,
                                        'artUrl' => '/plugins/plexstreams/getImage.php?img=' . urlencode($audio['@attributes']['art']) . '&host=' . urlencode($streams['@host']),
                                        'thumbUrl' => '/plugins/plexstreams/getImage.php?img=' .  urlencode($audio['@attributes']['grandparentThumb'] ?? $audio['@attributes']['thumb']) . '&host=' . urlencode($streams['@host']),
                                        'user' => $audio['User']['@attributes']['title'],
                                        'userAvatar' => $audio['User']['@attributes']['thumb'],
                                        'state' => $audio['Player']['@attributes']['state'],
                                        'stateIcon' => 'play',
                                        'length' => $duration,
                                        'lengthInSeconds' => $lengthInSeconds,
                                        'lengthInMinutes' => $lengthInMinutes,
                                        'lengthSeconds' => $lengthInSeconds,
                                        'lengthMinutes' => $lengthMinutes,
                                        'lengthHours' => $lengthHours,
                                        'currentPosition' => $currentPosition,
                                        'currentPositionInSeconds' =>  $currentPositionInSeconds,
                                        'currentPositionInMinutes' =>  $currentPositionInMinutes,
                                        'currentPositionHours' => $currentPositionHours,
                                        'currentPositionMinutes' => $currentPositionMinutes,
                                        'currentPositionSeconds' => $currentPositionSeconds,
                                        'percentPlayed' => $lengthInMinutes > 0 ? round(($currentPositionInMinutes/ $lengthInMinutes) * 100, 0) : 0,
                                        'currentPositionDisplay' => str_pad($currentPositionHours, 2, '0', STR_PAD_LEFT) . ':' . str_pad($currentPositionMinutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad($currentPositionSeconds, 2, '0', STR_PAD_LEFT),
                                        'lengthDisplay' => str_pad($lengthHours, 2, '0', STR_PAD_LEFT) . ':' . str_pad($lengthMinutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad($lengthSeconds, 2, '0', STR_PAD_LEFT),
                                        'location' => $audio['Session']['@attributes']['location'] ?? null,
                                        'address' => $audio['Player']['@attributes']['address'] ?? '',
                                        'bandwidth' => round((int)($audio['Session']['@attributes']['bandwidth'] ?? 0) / 1000, 1),
                                        'endTime' => $endTime,
                                        'machineIdentifier' => getMachineIdentifier($streams['@host'], $cfg['TOKEN'] ?? ''),
                                        'ratingKey' => $audio['@attributes']['ratingKey'] ?? '',
                                        'streamInfo' => []
                                    ];
                                    if ($mergedStream['location'] === null
                                        && (($audio['Player']['@attributes']['local'] ?? '0') === '1')) {
                                        $mergedStream['location'] = 'LAN';
                                    }

                                    $loc = strtoupper($mergedStream['location'] ?? '');
                                    $geoData = ($loc !== 'LAN' && !empty($mergedStream['address']))
                                        ? getGeoData($mergedStream['address'])
                                        : ['display' => '', 'country' => '', 'flag' => ''];
                                    $mergedStream['locationShort']   = $loc ?: '';
                                    $mergedStream['locationGeo']     = $geoData['display'];
                                    $mergedStream['locationCountry'] = $geoData['country'];
                                    $mergedStream['locationFlag']    = $geoData['flag'];
                                    $mergedStream['locationDisplay'] = $loc . ' (' . $mergedStream['address']
                                        . ($geoData['display'] !== '' ? ' - ' . $geoData['display'] : '')
                                        . ')';
                                    $startTs = sessionStartTime($streams['@host'], $mergedStream['sessionKey'] ?? '');
                                    $mergedStream['sessionStartedAt']   = $startTs;
                                    $mergedStream['sessionDurationSec'] = $startTs ? (time() - $startTs) : null;

                                    if ($mergedStream['state'] === 'paused') {
                                        $mergedStream['stateIcon'] = 'pause';
                                    } else if ($mergedStream['state'] !== 'playing') {
                                        $mergedStream['stateIcon'] = 'buffer';
                                    }
                                    if (isset($part['@attributes']['decision'])) {
                                        $mergedStream['streamDecision'] = $part['@attributes']['decision'];
                                    } else {
                                        $mergedStream['streamDecision'] = 'Direct Play';
                                    }
                                    if ($mergedStream['streamDecision'] === 'directplay') {
                                        $mergedStream['streamDecision'] = 'Direct Play';
                                    }

                                    $mergedStream['streamInfo']['audio'] = $stream;
                                    $mergedStream['streamInfo']['audio']['@attributes']['decision'] = $mergedStream['streamInfo']['audio']['@attributes']['decision'] ?? 'direct play';

                                    if (ps_is_new_session($streams['@host'], $mergedStream['sessionKey'] ?? '')) {
                                        ps_notify_new_stream($mergedStream, $cfg);
                                    }
                                    $mergedStreams[] = $mergedStream;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $mergedStreams;
    }

?>