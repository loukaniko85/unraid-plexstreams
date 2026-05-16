<?php
    if (isset($GLOBALS['unRaidSettings'])) {
        define('OS_VERSION', 'Unraid ' . $GLOBALS['unRaidSettings']['version']);
    }
    define('PLUGIN_VERSION', '2026.05.15');
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
        return $now;
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
        $hosts = explode(',', $cfg['HOST']);
        $extraHosts = explode(',', $cfg['CUSTOM_SERVERS']);
        $hosts = array_merge($hosts, $extraHosts);

        $streams = [];
        $schedules = [];
        foreach($hosts as $host) {
            if (isset($cfg['TOKEN']) && !empty($cfg['TOKEN'])) {
                $streams[] = $host . "/status/sessions?X-Plex-Token=" . $cfg['TOKEN'] .'&_m=' .time();
                $schedules[] = $host ."/media/subscriptions/scheduled?X-Plex-Token=" .$cfg['TOKEN'];
                if (isset($_REQUEST['dbg'])) {
                    v_d($streams);
                    v_d($schedules);
                }
            }
        }
        $combined = $streams;
        array_push($combined , ...$schedules);
        if (isset($cfg['TOKEN']) && !empty($cfg['TOKEN'])) {
            $responses = getUrl($combined);
        } else {
            $responses = [];
        }

        return $responses;
    }

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

        $rets   = [];
        $multi  = [];
        $mh     = curl_multi_init();
        foreach ($urls as $idx => $url) {
            $prefix = '';
            if (stripos($url, 'sessions')  !== false) $prefix = 'streams-';
            elseif (stripos($url, 'schedule') !== false) $prefix = 'schedules-';
            $id = $prefix . $idx;
            $multi[$id] = curl_init($url);
            _ps_curl_opts($multi[$id]);
            curl_multi_add_handle($mh, $multi[$id]);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh);
        } while ($active && $status === CURLM_OK);

        foreach ($multi as $idx => $ch) {
            $effective = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $body      = curl_multi_getcontent($ch);
            $parts     = parse_url($effective);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $rebuiltUrl = $parts['scheme'] . '://' . $parts['host']
                            . (isset($parts['port']) ? ':' . $parts['port'] : '')
                            . ($parts['path']  ?? '')
                            . (isset($parts['query']) ? '?' . $parts['query'] : '');
                $rets[$idx] = [
                    'url'     => $rebuiltUrl,
                    'content' => json_decode(json_encode(simplexml_load_string($body)), true),
                ];
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $rets;
    }

    function mergeStreams($allStreams, $cfg) {
        global $display;

        $mergedStreams = [];
        $videoStreams = [];
        $schedules = [];
        foreach($allStreams as $idx=>$details) {
            $urlParts = parse_url($details['url']);
            if ($urlParts !== false) {
                $source = (is_array($details['content'])) ? $details['content'] : [];
                $source['@host'] = $urlParts['scheme'] . '://' . $urlParts['host'] . ':' . $urlParts['port'];
                $source['shortHost'] = $urlParts['host'];
                if (stripos($idx, 'streams-') !== false) {
                    $videoStreams[] = $source;
                } else if (stripos($idx, 'schedules-') !== false) {
                    $schedules[] = $source;
                }
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
                            if (isset($media['Part']['@attributes']['duration'])) {
                                $duration = $media['Part']['@attributes']['duration'];
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
                                $mergedStream['percentPlayed'] = round(($currentPositionInMinutes/ $lengthInMinutes) * 100, 0);
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

                            foreach ($media['Part']['Stream'] as $stream) {
                                if ($stream['@attributes']['streamType'] === '2') {
                                    $mergedStream['streamInfo']['audio'] = $stream;
                                    $mergedStream['streamInfo']['audio']['@attributes']['decision'] = $mergedStream['streamInfo']['audio']['@attributes']['decision'] ?? 'direct play';
                                } else if ($stream['@attributes']['streamType'] === '1') {
                                    $mergedStream['streamInfo']['video'] = $stream;
                                    $mergedStream['streamInfo']['video']['@attributes']['decision'] = $mergedStream['streamInfo']['video']['@attributes']['decision'] ?? 'direct play';
                                }
                            }
                            
                            $mergedStream['streamDecision'] = $media['Part']['@attributes']['decision'] ?? '';
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
                                        'percentPlayed' => $lengthInMinutes > 0 ? round(($currentPositionInMinutes/ $lengthInMinutes) * 100, 0) : '',
                                        'currentPositionDisplay' => str_pad($currentPositionHours, 2, '0', STR_PAD_LEFT) . ':' . str_pad($currentPositionMinutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad($currentPositionSeconds, 2, '0', STR_PAD_LEFT),
                                        'lengthDisplay' => str_pad($lengthHours, 2, '0', STR_PAD_LEFT) . ':' . str_pad($lengthMinutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad($lengthSeconds, 2, '0', STR_PAD_LEFT),
                                        'location' => $audio['Session']['@attributes']['location'],
                                        'address' => $audio['Player']['@attributes']['address'],
                                        'bandwidth' => round((int)$audio['Session']['@attributes']['bandwidth'] / 1000, 1),
                                        'endTime' => $endTime,
                                        'streamInfo' => []
                                    ];
                                    if ($mergedStream['location'] === null) {
                                        if ($audio['Player']['@attributes']['local'] == "1") {
                                            $mergedStream['location'] = 'LAN';
                                        }
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

                                    $mergedStreams[] = $mergedStream;
                                }
                            }
                        }
                    }
                }
            }
        }

        // if (isset($scheduled) && isset($scheduled['@attributes'])) {
        //     $streams['Scheduled'] = [$streams['Scheduled']];
        //     foreach($streams['Scheduled'] as $scheduled) {

        //     }
        // }

        return $mergedStreams;
    }

?>