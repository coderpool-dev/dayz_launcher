# Server monitoring history

`/monitoring` uses `/api/monitoring`; the launcher contract at `/api/servers` is unchanged.
Filters include map, exact game version, perspective, explicit game-mode tags, and modification style.
Map/version options and their counts come from the current list, including custom maps.
Mode tags are inferred from the server name and are not owner-verified.

## Collection

The existing `app:servers:refresh` schedule records raw snapshots before empty servers are hidden.
One snapshot per 15-minute slot is counted. Cached responses and sponsor republishing do not create observations.
SQLite (`pdo_sqlite`) stores hourly aggregates under `var/<environment>/servers/history` with eight-day retention.
The ready-to-serve summary is atomically written after collection. Back up this directory to preserve history.
History failures are logged without interrupting publication of the launcher list.

## Ranking

Ratings use seven completed days, require seven days since collection began and at least 70% observation coverage.
Day (10:00-18:00) and night (00:00-06:00) are in Europe/Moscow and require 70% coverage in their own windows.
Stability means the percentage of observed samples with at least one player. It is not uptime.
Known offline observations count as zero players; missing observations are not zeros.
Sponsors never receive ranking priority in historical collections.
Summaries older than one hour are ineligible for rankings.

`/api/monitoring/history?server=<ip:queryPort>&days=1|7` returns hourly means with null values for gaps.
Charts include an accessible list of hourly values and do not join gaps.

## Desktop launcher

The Servers view includes the same categories, map/version facets, collections and hourly graphs.
Category/map filters run before the display limit. Historical ordering overrides sponsor/favorite priority.
Normal server loading still uses `/api/servers`; the supplementary `/api/monitoring/summary` response maps
`ip:queryPort` keys to population metrics. History requests go through the native WebView bridge.
Unavailable or stale summaries never block launching a server or reading the ordinary local cache.
Population metrics are not persisted in the launcher cache, so a restart cannot revive an old rating.
Use `PREPISKA_API_URL=http://127.0.0.1:8090` when testing the launcher against the local backend.
