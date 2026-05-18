var serverList = [];

// Track per-stream client-side ticking timers so a paused stream's clock stops moving.
var psTimers = {};

// Bandwidth history for the header sparkline (60 most recent total-Mbps samples).
var psBwHistory = [];
// Main polling timer; set/cleared by psStartPolling/psStopPolling so the
// visibility hook can pause traffic when the dashboard tab is hidden.
var psPollTimer = null;

function psFmt(n) {
    n = parseInt(n, 10) || 0;
    return n < 10 ? '0' + n : '' + n;
}

function psStateIconClass(stream) {
    if (stream.state === 'playing') return 'fa-play';
    if (stream.state === 'paused')  return 'fa-pause';
    return 'fa-spinner fa-pulse';
}

// Cache last-seen stream payload per id so the click-handler can render the
// detail panel without waiting for the next poll. Reset on each fetch.
window.psLastStreams = window.psLastStreams || {};

function psEscape(s) {
    if (s == null) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function psBadges(stream) {
    var html = '';
    var d = (stream.streamDecision || '').toLowerCase();
    if (/transcode/.test(d)) {
        var kind = (stream.transcodeType === 'HW' || stream.transcodeType === 'CPU')
            ? ' (' + stream.transcodeType + ')' : '';
        html += '<span class="ps-badge ps-badge-trans" title="Transcoding' + kind + '">TRANSCODING' + kind + '</span>';
    } else if (/copy/.test(d)) {
        html += '<span class="ps-badge ps-badge-direct" title="Direct Stream (remuxed)">DIRECT STREAM</span>';
    } else if (d) {
        // anything else with a decision is effectively direct play
        html += '<span class="ps-badge ps-badge-direct" title="Direct Play (no re-encoding)">DIRECT PLAY</span>';
    }
    if (stream.videoResolution) {
        html += '<span class="ps-badge ps-badge-quality">' + psEscape(stream.videoResolution) + '</span>';
    }
    if (stream.bandwidth) {
        html += '<span class="ps-badge ps-badge-bw">' + psEscape(stream.bandwidth) + ' Mbps</span>';
    }
    return html;
}

function psLocationChip(stream) {
    if (!stream.locationShort) return '';
    var isWan = stream.locationShort !== 'LAN';
    var tooltip = stream.locationDisplay || (stream.locationShort + ' ' + (stream.address || ''));
    var label = '';
    if (isWan && stream.locationFlag) {
        label += '<span class="ps-flag">' + stream.locationFlag + '</span> ';
    }
    label += stream.locationShort;
    if (isWan && stream.locationGeo) {
        label += ' &middot; ' + psEscape(stream.locationGeo);
    }
    return '<span class="ps-loc ps-loc-' + (isWan ? 'wan' : 'lan') + '" title="' + psEscape(tooltip) + '">' + label + '</span>';
}

function psFmtDuration(sec) {
    sec = parseInt(sec, 10) || 0;
    if (sec < 60) return sec + 's';
    var m = Math.floor(sec / 60), h = Math.floor(m / 60);
    if (h > 0) return h + 'h ' + (m % 60) + 'm';
    return m + 'm';
}

function psFmtClock(ts) {
    if (!ts) return '';
    var d = new Date(ts * 1000);
    var hh = d.getHours(), mm = d.getMinutes();
    var ampm = hh >= 12 ? 'PM' : 'AM';
    hh = hh % 12; if (hh === 0) hh = 12;
    return hh + ':' + psFmt(mm) + ' ' + ampm;
}

function psBuildStreamNode(stream) {
    var showPoster = (typeof PS_SHOW_POSTERS === 'undefined') || PS_SHOW_POSTERS;
    var pct = parseFloat(stream.percentPlayed);
    if (!isFinite(pct)) pct = 0;
    var posterHtml = showPoster && stream.thumbUrl
        ? '<div class="ps-thumb" style="background-image:url(\'' + stream.thumbUrl + '\');"></div>'
        : '';
    var hasDuration = stream.currentPositionHours !== null && stream.currentPositionHours !== undefined;
    var timeHtml = hasDuration
        ? '<span class="ps-pos"><span class="currentPositionHours">' + psFmt(stream.currentPositionHours) + '</span>:<span class="currentPositionMinutes">' + psFmt(stream.currentPositionMinutes) + '</span>:<span class="currentPositionSeconds">' + psFmt(stream.currentPositionSeconds) + '</span></span> / ' + (stream.lengthDisplay || '--:--:--') + (stream.endTime ? ' <span class="ps-end">(<span class="endTime">' + stream.endTime + '</span>)</span>' : '')
        : 'Live';

    var startedHtml = (stream.sessionStartedAt)
        ? '<span class="ps-started" title="' + psEscape('Started ' + psFmtClock(stream.sessionStartedAt)) + '"><i class="fa fa-clock-o"></i> ' + psFmtDuration(stream.sessionDurationSec || 0) + '</span>'
        : '';

    return $(
        '<div class="ps-stream" id="' + stream.id + '" data-session-key="' + psEscape(stream.sessionKey || '') + '">' +
            '<div class="ps-row">' +
                posterHtml +
                '<div class="ps-body">' +
                    '<div class="ps-title" title="' + psEscape(stream.titleString || '') + '">' + psEscape(stream.title || '') + ' <span class="ps-badges">' + psBadges(stream) + '</span></div>' +
                    '<div class="ps-meta">' +
                        '<span class="ps-user" title="' + psEscape(stream.user || '') + '">' + psEscape(stream.user || '') + (stream.alias ? ' &middot; ' + psEscape(stream.alias) : '') + '</span>' +
                        '<span class="ps-loc-wrap">' + psLocationChip(stream) + '</span>' +
                        '<span class="ps-started-wrap">' + startedHtml + '</span>' +
                        '<span class="ps-time">' + timeHtml + '</span>' +
                    '</div>' +
                    '<div class="ps-progress-bg"><div class="ps-progress" style="width:' + pct + '%;"></div></div>' +
                '</div>' +
                '<div class="ps-state ' + (stream.state || '') + '" title="' + psEscape(uCWord(stream.state || '')) + '"><i class="fa ' + psStateIconClass(stream) + '"></i></div>' +
            '</div>' +
            '<div class="ps-detail" style="display:none;"></div>' +
        '</div>'
    );
}

function psUpdateStreamNode($container, stream) {
    $container.attr('data-session-key', stream.sessionKey || '');
    $container.find('.ps-title').attr('title', stream.titleString || '').html(
        psEscape(stream.title || '') + ' <span class="ps-badges">' + psBadges(stream) + '</span>'
    );
    $container.find('.ps-user').attr('title', stream.user || '').text(
        (stream.user || '') + (stream.alias ? ' · ' + stream.alias : '')
    );
    $container.find('.ps-loc-wrap').html(psLocationChip(stream));
    if (stream.sessionStartedAt) {
        $container.find('.ps-started-wrap').html(
            '<span class="ps-started" title="' + psEscape('Started ' + psFmtClock(stream.sessionStartedAt)) + '"><i class="fa fa-clock-o"></i> ' + psFmtDuration(stream.sessionDurationSec || 0) + '</span>'
        );
    }
    $container.find('.ps-state')
        .attr('class', 'ps-state ' + (stream.state || ''))
        .attr('title', uCWord(stream.state || ''))
        .find('i').attr('class', 'fa ' + psStateIconClass(stream));
    var pct = parseFloat(stream.percentPlayed);
    if (isFinite(pct)) {
        $container.find('.ps-progress').css('width', pct + '%');
    }
    if (stream.endTime) {
        $container.find('.endTime').text(stream.endTime);
    }
    // If the detail panel is open, refresh its contents in place.
    var $detail = $container.find('.ps-detail');
    if ($detail.is(':visible')) {
        $detail.html(psBuildDetailHtml(stream));
    }
}

function psPlexWebUrl(stream) {
    if (!stream.machineIdentifier || !stream.ratingKey) return '';
    return 'https://app.plex.tv/desktop/#!/server/' + encodeURIComponent(stream.machineIdentifier)
         + '/details?key=' + encodeURIComponent('/library/metadata/' + stream.ratingKey);
}

function psBuildDetailHtml(stream) {
    function row(label, value) {
        if (value === null || value === undefined || value === '') return '';
        return '<div class="ps-d-row"><span class="ps-d-label">' + label + '</span><span class="ps-d-val">' + value + '</span></div>';
    }
    var info  = stream.streamInfo || {};
    var video = (info.video    && info.video['@attributes'])    || null;
    var audio = (info.audio    && info.audio['@attributes'])    || null;
    var sub   = (info.subtitle && info.subtitle['@attributes']) || null;
    var canTerminate = window.PS_ALLOW_TERMINATE && stream.sessionKey;
    var webUrl = psPlexWebUrl(stream);

    function subLabel(s) {
        var lang  = s.language || s.languageCode || '';
        var name  = s.displayTitle || s.title || s.codec || lang || 'Subtitle';
        var forced = s.forced === '1' ? ' (forced)' : '';
        return psEscape(name + forced) + (s.decision ? ' &mdash; ' + psEscape(s.decision) : '');
    }

    return '' +
        '<div class="ps-d-grid">' +
            row('Server',      psEscape(stream.alias || '') + (stream['@host'] ? ' <span style="color:#888">(' + psEscape(stream['@host']) + ')</span>' : '')) +
            row('User',        psEscape(stream.user || '')) +
            row('Player',      psEscape(stream.player || '') + (stream.playerDevice ? ' &middot; ' + psEscape(stream.playerDevice) : '')) +
            row('Location',    psEscape(stream.locationDisplay || stream.locationShort || '')) +
            row('Bandwidth',   stream.bandwidth ? (psEscape(stream.bandwidth) + ' Mbps') : '') +
            row('Resolution',  psEscape(stream.videoResolution || '')) +
            row('Container',   psEscape(stream.container || '')) +
            row('Decision',    psEscape(uCWord(stream.streamDecision || '')) + (stream.transcodeType ? ' <span class="ps-badge ps-badge-trans">' + stream.transcodeType + '</span>' : '')) +
            (video ? row('Video',    psEscape(video.displayTitle || video.codec || '') + (video.decision ? ' &mdash; ' + video.decision : '')) : '') +
            (audio ? row('Audio',    psEscape(audio.displayTitle || audio.codec || '') + (audio.decision ? ' &mdash; ' + audio.decision : '')) : '') +
            (sub   ? row('Subtitle', subLabel(sub)) : '') +
        '</div>' +
        '<div class="ps-d-actions">' +
            (canTerminate
                ? '<button type="button" class="ps-btn ps-btn-kill" onclick="psTerminateStream(\'' + stream.id + '\')"><i class="fa fa-stop"></i> Terminate</button>'
                : '<span class="ps-d-hint">Enable termination in <a href="/Settings/PlexStreams">settings</a> to add a stop button.</span>') +
            (webUrl
                ? ' <a class="ps-btn" target="_blank" rel="noopener" href="' + psEscape(webUrl) + '"><i class="fa fa-external-link"></i> Open in Plex</a>'
                : '') +
        '</div>';
}

function psToggleDetail(streamId) {
    var $c = $('#' + streamId);
    if ($c.length === 0) return;
    var $detail = $c.find('.ps-detail');
    var stream = window.psLastStreams[streamId];
    if (!stream) return;
    if ($detail.is(':visible')) {
        $detail.slideUp(150);
        $c.removeClass('ps-open');
    } else {
        $detail.html(psBuildDetailHtml(stream)).slideDown(150);
        $c.addClass('ps-open');
    }
}

function psTerminateStream(streamId) {
    var s = window.psLastStreams[streamId];
    if (!s) return;
    if (!s.sessionKey) { psFlash(streamId, 'No session key', 'err'); return; }
    if (!confirm('Terminate this stream?\n\n' + (s.titleString || s.title || '') + '\nUser: ' + (s.user || ''))) return;

    $.ajax({
        url: '/plugins/plexstreams/terminateStream.php',
        type: 'POST',
        data: { host: s['@host'], sessionKey: s.sessionKey, reason: 'Terminated by Unraid admin' },
        dataType: 'json'
    }).done(function(resp) {
        if (resp && resp.ok) {
            psFlash(streamId, 'Termination sent', 'ok');
            // Optimistic remove; next poll will confirm.
            $('#' + streamId).fadeOut(400, function() { $(this).remove(); });
        } else {
            psFlash(streamId, (resp && resp.error) || 'Failed', 'err');
        }
    }).fail(function(xhr) {
        var msg = 'Failed';
        try { var j = JSON.parse(xhr.responseText); if (j.error) msg = j.error; } catch(e) {}
        psFlash(streamId, msg, 'err');
    });
}

function psFlash(streamId, text, kind) {
    var $c = $('#' + streamId);
    if ($c.length === 0) return;
    var $f = $('<div class="ps-flash ps-flash-' + (kind || 'ok') + '">' + psEscape(text) + '</div>');
    $c.find('.ps-detail').prepend($f);
    setTimeout(function() { $f.fadeOut(300, function() { $(this).remove(); }); }, 2500);
}

function psTickClock(streamId) {
    var $c = $('#' + streamId);
    if ($c.length === 0) { psStopTimer(streamId); return; }
    var $h = $c.find('.currentPositionHours');
    var $m = $c.find('.currentPositionMinutes');
    var $s = $c.find('.currentPositionSeconds');
    if (!$s.length) return;
    var sec = (parseInt($s.text(), 10) || 0) + 1;
    var min = parseInt($m.text(), 10) || 0;
    var hr  = parseInt($h.text(), 10) || 0;
    if (sec > 59) { sec = 0; min += 1; }
    if (min > 59) { min = 0; hr += 1; }
    $s.text(psFmt(sec));
    $m.text(psFmt(min));
    $h.text(psFmt(hr));
}

function psStartTimer(streamId) {
    if (psTimers[streamId]) return;
    psTimers[streamId] = setInterval(function() { psTickClock(streamId); }, 1000);
}

function psStopTimer(streamId) {
    if (psTimers[streamId]) {
        clearInterval(psTimers[streamId]);
        delete psTimers[streamId];
    }
}

function psSortMode() {
    var v = window.PS_SORT_MODE || 'started';
    return ['started','user','bandwidth'].indexOf(v) > -1 ? v : 'started';
}

function psSortStreams(streams) {
    var mode = psSortMode();
    return streams.slice().sort(function(a, b) {
        if (mode === 'user') {
            return (a.user || '').localeCompare(b.user || '');
        }
        if (mode === 'bandwidth') {
            return (parseFloat(b.bandwidth) || 0) - (parseFloat(a.bandwidth) || 0);
        }
        // 'started' — chronological, first to start at top.
        return (a.sessionStartedAt || 0) - (b.sessionStartedAt || 0);
    });
}

// Inline SVG sparkline of recent total bandwidth. ~60 samples wide × 14 tall.
function psSparkline(samples) {
    if (!samples || samples.length < 2) return '';
    var w = 60, h = 14, pad = 1;
    var max = 0;
    for (var i = 0; i < samples.length; i++) if (samples[i] > max) max = samples[i];
    if (max <= 0) return '';
    var step = (w - 2 * pad) / (samples.length - 1);
    var pts = [];
    for (var j = 0; j < samples.length; j++) {
        var x = pad + step * j;
        var y = h - pad - ((h - 2 * pad) * (samples[j] / max));
        pts.push(x.toFixed(1) + ',' + y.toFixed(1));
    }
    return '<svg class="ps-spark" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h
         + '" preserveAspectRatio="none" aria-hidden="true">'
         + '<polyline fill="none" stroke="currentColor" stroke-width="1" stroke-linejoin="round" stroke-linecap="round" points="'
         + pts.join(' ') + '"/></svg>';
}

// Stream-start notifications are fired server-side via Unraid's `notify`
// CLI in includes/common.php (ps_notify_new_stream). That uses whatever
// delivery channels the user has configured in Settings → Notification
// Settings — no browser permission to manage from this side.

function psStartPolling() {
    if (psPollTimer !== null) return;
    var ms = window.PS_REFRESH_MS || 5000;
    psPollTimer = setInterval(updateDashboardStreamsNew, ms);
}

function psStopPolling() {
    if (psPollTimer !== null) {
        clearInterval(psPollTimer);
        psPollTimer = null;
    }
}

function updateDashboardStreamsNew() {
    $.ajax('/plugins/plexstreams/ajax.php').done(function(resp){
        // ajax.php now returns { streams: [], unreachable: [] }.
        var streams     = Array.isArray(resp) ? resp : ((resp && resp.streams)     || []);
        var unreachable = (resp && resp.unreachable) || [];

        $('#retrieving_streams').remove();
        var seen = {};
        var hostStreams = {};
        var hostBw = {};
        var totalBw = 0;
        var $countSpan = $('#stream_count_container');

        if (streams.length > 0) {
            $('.no_streams, .ps-empty').remove();
            streams = psSortStreams(streams);
            streams.forEach(function(s) {
                var bw = parseFloat(s.bandwidth) || 0;
                var alias = s.alias || 'Plex';
                hostBw[alias] = (hostBw[alias] || 0) + bw;
                totalBw += bw;
            });
            streams.forEach(function(stream) {
                seen[stream.id] = true;
                window.psLastStreams[stream.id] = stream;
                hostStreams[stream.alias || 'Plex'] = (hostStreams[stream.alias || 'Plex'] || 0) + 1;
                var $container = $('#' + stream.id);
                if ($container.length === 0) {
                    $container = psBuildStreamNode(stream).appendTo('#plexstreams_streams');
                    // Click anywhere on the row (except the play/pause state icon) toggles the detail panel.
                    $container.find('.ps-row').on('click', function(ev) {
                        if ($(ev.target).closest('.ps-state').length) return;
                        psToggleDetail(stream.id);
                    });
                } else {
                    psUpdateStreamNode($container, stream);
                    // Re-append to honor current sort order without losing event handlers.
                    $container.appendTo('#plexstreams_streams');
                }
                if (stream.state === 'playing' && stream.currentPositionHours !== null && stream.currentPositionHours !== undefined) {
                    psStartTimer(stream.id);
                } else {
                    psStopTimer(stream.id);
                    // sync the displayed position so a paused stream doesn't drift
                    if (stream.currentPositionHours !== null && stream.currentPositionHours !== undefined) {
                        $container.find('.currentPositionHours').text(psFmt(stream.currentPositionHours));
                        $container.find('.currentPositionMinutes').text(psFmt(stream.currentPositionMinutes));
                        $container.find('.currentPositionSeconds').text(psFmt(stream.currentPositionSeconds));
                    }
                }
            });
            // remove stale streams (drop their dom node, ticker, and cached payload)
            $('#plexstreams_streams .ps-stream').each(function() {
                if (!seen[this.id]) {
                    psStopTimer(this.id);
                    delete window.psLastStreams[this.id];
                    $(this).remove();
                }
            });
        } else {
            // Clear cached state when nothing is streaming.
            for (var k in psTimers) { if (psTimers.hasOwnProperty(k)) psStopTimer(k); }
            $('#plexstreams_streams .ps-stream').remove();
            window.psLastStreams = {};
            if ($('#plexstreams_streams .ps-empty').length === 0) {
                $('#plexstreams_streams').append('<div class="ps-empty no_streams">' + _('There are currently no active streams') + '</div>');
            }
        }

        // Sparkline: keep 60 samples of total bandwidth.
        psBwHistory.push(totalBw);
        if (psBwHistory.length > 60) psBwHistory.shift();

        // Header line: "N streams · 12.1 Mbps  ▁▂▅▆"
        var noun = streams.length === 1 ? _('stream') : _('streams');
        var headerHtml = '<span id="plexstreams_count">' + streams.length + '</span> ' + noun;
        if (totalBw > 0) {
            headerHtml += ' &middot; <span class="ps-bw-total">' + totalBw.toFixed(1) + ' Mbps</span> '
                       + psSparkline(psBwHistory);
        }
        $countSpan.html(headerHtml);

        // Per-server breakdown + unreachable chip.
        var hostKeys = Object.keys(hostStreams);
        var hostHtml = '';
        if (hostKeys.length > 1) {
            hostKeys.forEach(function(host) {
                var bw = hostBw[host] || 0;
                hostHtml += '<div><strong>' + host + ':</strong> '
                    + hostStreams[host]
                    + (bw > 0 ? ' &middot; ' + bw.toFixed(1) + ' Mbps' : '')
                    + '</div>';
            });
        }
        if (unreachable.length > 0) {
            var names = unreachable.map(function(u) { return psEscape(u.alias || u.host); }).join(', ');
            hostHtml += '<div class="ps-warn"><i class="fa fa-exclamation-triangle"></i> '
                     + unreachable.length + ' server' + (unreachable.length === 1 ? '' : 's')
                     + ' unreachable: ' + names + '</div>';
        }
        $('#ps_host_counts').html(hostHtml);
    }).fail(function(jqXHR) {
        if (jqXHR.status == '500') {
            $('#plexstreams_streams').html('<div class="ps-empty">' + _('Please make sure you have') + ' <a href="/Settings/PlexStreams">' + _('setup') + '</a> ' + _('the plugin first') + '</div>');
        }
    });
}


function uCWord(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}

function updateServerList(dest) {
    var list = [];
    $.each($("input[name='hostbox']:checked"), function(){
        list.push($(this).val());
    });
    $('#' + dest).val(list.join(','));
}

function getServers(containerSelector, selected) {
    var url = '/plugins/plexstreams/getServers.php?useSsl=' + $('input[name="FORCE_PLEX_HTTPS"]:checked').val();
    var $host = $(containerSelector);
    $host.hide();
    $('.lds-dual-ring').show();
    selected = selected.split(',');
    $host.html('');
    $.get(url).done(function(data) {
        serverList = data.serverList;
        if (Object.keys(serverList).length > 0) {
            for (var id in serverList) {
                if (serverList.hasOwnProperty(id)) {
                    var server = serverList[id];
                    serverList[id].Connections.forEach(function(connection) {
                        if (connection !== null) {
                            var shortHost = connection.uri;
                            shortHost = shortHost.replace(connection.protocol  + '://', '');
                            if (connection.port) {
                                shortHost = shortHost.replace(':' + connection.port, '');
                            }
                            $host.append('<input type="hidden" name="ALIAS-' + shortHost + '" value="' + server.Name + '"/>');
                            $host.append('<input type="checkbox" onchange="updateServerList(\'HOST\')" name="hostbox" id="' + connection.uri + '" data-id="' + id + '"' + (selected.indexOf(connection.uri) > -1 ? ' checked="checked"' : '' ) + ' value="' + connection.uri + '" data-address="' + connection.address + '" data-name="' + server.Name + '"/> <label for="' + connection.uri + '"> ' + server.Name + ' (' +  connection.address + ':' + connection.port + ')' + (connection.local === '0' ? ' - Remote' : '') + '</label><br/>');
                        }
                    });
                }
            }
        } else {
            $host.html('<p>No Servers found, please enter server in Custom Servers Field');
        }
        $host.show();
        $('.lds-dual-ring').hide();
    });
}

function setLocalStorage(key, value, path) {
    if (path !== false) {
        key = key + '_' + window.location.pathname;
    }
    localStorage.setItem(key, value);
}
function getLocalStorage(key, default_value, path) {
    if (path !== false) {
        key = key + '_' + window.location.pathname;
    }
    var value = localStorage.getItem(key);
    if (value !== null) {
        return value
    } else if (default_value !== undefined) {
        setLocalStorage(key, default_value, path);
        return default_value
    }
}

function PopupCenter(url, title, w, h) {
    // Fixes dual-screen position                         Most browsers      Firefox
    var dualScreenLeft = window.screenLeft != undefined ? window.screenLeft : window.screenX;
    var dualScreenTop = window.screenTop != undefined ? window.screenTop : window.screenY;

    var width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
    var height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;

    var left = ((width / 2) - (w / 2)) + dualScreenLeft;
    var top = ((height / 2) - (h / 2)) + dualScreenTop;
    var newWindow = window.open(url, title, 'scrollbars=yes, width=' + w + ', height=' + h + ', top=' + top + ', left=' + left);

    // Puts focus on the newWindow
    if (window.focus) {
        newWindow.focus();
    }

    return newWindow;
}
var plex_oauth_loader = '<style>' +
        '.login-loader-container {' +
            'font-family: "Open Sans", Arial, sans-serif;' +
            'position: absolute;' +
            'top: 0;' +
            'right: 0;' +
            'bottom: 0;' +
            'left: 0;' +
        '}' +
        '.login-loader-message {' +
            'color: #282A2D;' +
            'text-align: center;' +
            'position: absolute;' +
            'left: 50%;' +
            'top: 25%;' +
            'transform: translate(-50%, -50%);' +
        '}' +
        '.login-loader {' +
            'border: 5px solid #ccc;' +
            '-webkit-animation: spin 1s linear infinite;' +
            'animation: spin 1s linear infinite;' +
            'border-top: 5px solid #282A2D;' +
            'border-radius: 50%;' +
            'width: 50px;' +
            'height: 50px;' +
            'position: relative;' +
            'left: calc(50% - 25px);' +
        '}' +
        '@keyframes spin {' +
            '0% { transform: rotate(0deg); }' +
            '100% { transform: rotate(360deg); }' +
        '}' +
    '</style>' +
    '<div class="login-loader-container">' +
        '<div class="login-loader-message">' +
            '<div class="login-loader"></div>' +
            '<br>' +
            'Redirecting to the Plex login page...' +
        '</div>' +
    '</div>';
var plex_oauth_window = null;
function closePlexOAuthWindow() {
    if (plex_oauth_window) {
        plex_oauth_window.close();
    }
}

function uuidv4() {
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, function(c) {
        var cryptoObj = window.crypto || window.msCrypto; // for IE 11
        return (c ^ cryptoObj.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    });
}

function getPlexHeaders() {
    return {
        'Accept': 'application/json',
        'X-Plex-Product': 'Unraid Plex Streams Plugin',
        'X-Plex-Version': PLUGIN_VERSION,
        'X-Plex-Client-Identifier': getLocalStorage('UnraidPlexStreams_ClientID', uuidv4(), false),
        'X-Plex-Platform': 'unraid',
        'X-Plex-Platform-Version': OS_VERSION,
        'X-Plex-Model': 'Plex OAuth',
        'X-Plex-Device': OS_VERSION,
        'X-Plex-Device-Name': 'Unraid Plex Streams Plugin',
        'X-Plex-Device-Screen-Resolution': window.screen.width + 'x' + window.screen.height,
        'X-Plex-Language': 'en'
    };
}

getPlexOAuthPin = function () {
    var x_plex_headers = getPlexHeaders();
    var deferred = $.Deferred();

    $.ajax({
        url: 'https://plex.tv/api/v2/pins?strong=true',
        type: 'POST',
        headers: x_plex_headers,
        success: function(data) {
            deferred.resolve({pin: data.id, code: data.code});
        },
        error: function() {
            closePlexOAuthWindow();
            deferred.reject();
        }
    });
    return deferred;
};

var polling = null;

function encodeData(data) {
    return Object.keys(data).map(function(key) {
        return [key, data[key]].map(encodeURIComponent).join("=");
    }).join("&");
}

function PlexOAuth(success, error, pre) {
    if (typeof pre === "function") {
        pre()
    }
    closePlexOAuthWindow();
    plex_oauth_window = PopupCenter('', 'Plex-OAuth', 600, 700);
    $(plex_oauth_window.document.body).html(plex_oauth_loader);

    getPlexOAuthPin().then(function (data) {
        var x_plex_headers = getPlexHeaders();
        const pin = data.pin;
        const code = data.code;

        var oauth_params = {
            'clientID': x_plex_headers['X-Plex-Client-Identifier'],
            'context[device][product]': x_plex_headers['X-Plex-Product'],
            'context[device][version]': x_plex_headers['X-Plex-Version'],
            'context[device][platform]': x_plex_headers['X-Plex-Platform'],
            'context[device][platformVersion]': x_plex_headers['X-Plex-Platform-Version'],
            'context[device][device]': x_plex_headers['X-Plex-Device'],
            'context[device][deviceName]': x_plex_headers['X-Plex-Device-Name'],
            'context[device][model]': x_plex_headers['X-Plex-Model'],
            'context[device][screenResolution]': x_plex_headers['X-Plex-Device-Screen-Resolution'],
            'context[device][layout]': 'desktop',
            'code': code
        }

        plex_oauth_window.location = 'https://app.plex.tv/auth/#!?' + encodeData(oauth_params);
        polling = pin;

        (function poll() {
            $.ajax({
                url: 'https://plex.tv/api/v2/pins/' + pin,
                type: 'GET',
                headers: x_plex_headers,
                success: function (data) {
                    if (data.authToken){
                        closePlexOAuthWindow();
                        getServers('#hostcontainer', $('#HOST').val());
                        if (typeof success === "function") {
                            success(data.authToken)
                        }
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    if (textStatus !== "timeout") {
                        closePlexOAuthWindow();
                        if (typeof error === "function") {
                            error()
                        }
                    }
                },
                complete: function () {
                    if (!plex_oauth_window.closed && polling === pin){
                        setTimeout(function() {poll()}, 1000);
                    }
                },
                timeout: 10000
            });
        })();
    }, function () {
        closePlexOAuthWindow();
        if (typeof error === "function") {
            error()
        }
    });
}