## Plex Streams

Dashboard widget for Unraid that shows what's currently streaming on your Plex Media Server — who's watching what, where they are, whether Plex is transcoding, and how much bandwidth each session is using.

Fork of [dorgan/Unraid-plexstreams](https://github.com/dorgan/Unraid-plexstreams), which hadn't been touched since late 2023.

### Install

In Unraid, **Plugins → Install Plugin**, paste this URL:

```
https://raw.githubusercontent.com/loukaniko85/unraid-plexstreams/master/plexstreams.plg
```

Then open **Settings → Plex Streams**, click **Get Plex Token**, sign in, and pick your server(s).

The plugin appears as **Plex Streams** in the Plugins list and adds a dashboard widget, a sidebar entry, and a settings page.

### What you see

Each stream shows the title and poster, the user, where they're streaming from (LAN — or WAN with country flag and city), how long they've been watching, current position, and a colored chip telling you what Plex is doing:

| Badge | Meaning | Server cost |
|---|---|---|
| **TRANSCODING (HW)** | Re-encoding on the GPU | Some |
| **TRANSCODING (CPU)** | Re-encoding on the CPU | High |
| **DIRECT STREAM** | Remuxing the container only | Low |
| **DIRECT PLAY** | Streaming the file untouched | None |

Plus resolution (4K / 1080p / SD) and bandwidth (e.g. `11 Mbps`).

Click a row to expand the full session details — player, device, codec, container, subtitle track, full geoip — plus an **Open in Plex** button that deep-links to the title on `app.plex.tv`. If you turn on **Allow Stream Termination**, a red Terminate button appears that kills the session.

The header line shows total streams and total bandwidth (`2 streams · 12.1 Mbps`), plus a tiny sparkline of recent bandwidth. There's a sort selector (start time / user / bandwidth) and a bell icon for opt-in desktop notifications when a new stream starts — both per-browser. If a configured Plex server stops responding, a `1 server unreachable` chip appears instead of it silently dropping out. With more than one server, a per-server breakdown shows underneath.

Polling pauses while the dashboard tab is hidden and refreshes immediately when you come back, so the widget isn't hammering Plex when nobody's watching.

### Settings

| Setting | Default | What it does |
|---|---|---|
| Show Posters | On | Poster thumbnail next to each stream |
| Refresh Interval | 5 s | How often the widget polls Plex (2–60 s) |
| Allow Stream Termination | Off | Adds a Terminate button to the per-stream detail panel |

### Release notes

See the **CHANGES** block at the top of [`plexstreams.plg`](plexstreams.plg) (also shown in Unraid's Plugins UI after install) for what's in the current release and what came from upstream.

### Credit

Original plugin by Donald Organ. This fork is maintained by **loukaniko**.

### License

MIT — see [LICENSE](LICENSE).
