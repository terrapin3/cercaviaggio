# Cercaviaggio - First Step Artifacts

This folder contains provider-side API contract and DB schema drafts.

## Files
- `CONTRACT-v1.md`
  - Provider API contract for `cercaviaggio`.
  - Baseline reference: Curcio app API.
- `PATHFIND-v1.md`
  - Specifica pathfinding multi-azienda (algoritmo, ranking, checkout rules).
- `schema_cercaviaggio_v1.sql`
  - Lean central orchestrator schema (cache/order/split first).
- `schema_cercaviaggio_v2.sql`
  - Curcio-like multi-company schema.
  - Includes network/pricing/ticketing tables plus central checkout/split tables.
  - Recommended if orders must be fully concluded on `cercaviaggio`.
- `schema_cercaviaggio_sync_v1.sql`
  - Sync catalog tables (`cv_provider_*`) with unique keys `(id_provider, external_id)`.
  - Prevents overwrites between different companies.
- `bootstrap_curcio_to_cercaviaggio_v2.sql`
  - First data bootstrap from Curcio DB to `cvmbexdcercavg` (provider `curcio`).
  - Loads network/rules/prices/fleet needed by `locations` and `search`.
- `sync/config.php`
  - Importer config (DB cercaviaggio + providers endpoints).
- `sync/sync_provider.php`
  - CLI importer for provider API pull (cron-ready).
- `sync/web_sync.php`
  - Web admin interna per import manuale (provider checkbox, import singolo/totale).
- `sync/web_config.php`
  - Config sicurezza web sync (token + allowlist IP).

## Which schema to use
- Use `v1` if providers remain source-of-truth and `cercaviaggio` orchestrates only.
- Use `v2` if `cercaviaggio` becomes source-of-truth for order lifecycle and ticketing.

## Hosting separati (raccomandato)
- Produzione: API provider + cron pull su `cercaviaggio` (no import SQL cross-host).
- I file `bootstrap_*.sql` restano utili solo per bootstrap iniziale o ambienti dove i DB sono raggiungibili tra loro.

## Current API location
- `/Users/napoli/Documents/www/curciostore_repo/rest/cercaviaggio/api2.php`
- `/Users/napoli/Documents/www/curciostore_repo/rest/cercaviaggio/db_cercaviaggio.php`

## Suggested next step
1. Stabilize provider API contract (`CONTRACT-v1.md`) on Curcio.
2. Implement provider sync endpoints (`sync_stops`, `sync_lines`, `sync_trips`, `sync_fares`).
3. Build first pathfind engine following `PATHFIND-v1.md` (mono-provider -> multi-provider).
4. Then complete live checkout endpoints: `quote`, `checkout_info`, `reserve`, `book`, `booking_status`.

## Import flow (hosting separati)
1. Import `schema_cercaviaggio_sync_v1.sql` in `cvmbexdcercavg`.
2. Configure provider URL in `sync/config.php`.
3. Run initial full sync:
  - `php /Users/napoli/Documents/www/cercaviaggio/sync/sync_provider.php --provider=curcio --full=1`
4. Add cron (example every 10 min):
   - `*/10 * * * * /usr/local/bin/php /Users/napoli/Documents/www/cercaviaggio/sync/sync_provider.php --provider=curcio >> /Users/napoli/Documents/www/cercaviaggio/sync/sync.log 2>&1`

## Manual web sync (fase iniziale)
1. Configure token in `sync/web_config.php` (`access_token`).
2. Open:
   - `/Users/napoli/Documents/www/cercaviaggio/sync/web_sync.php`
3. Login with token.
4. Select providers and run import manually.
5. Use cron later reusing the same engine (`sync_provider.php`).

## Security notes
- Do not expose `web_sync.php` publicly without:
  - strong token
  - IP allowlist (`allowed_ips`)
  - HTTPS
- Keep API keys and DB passwords out of git in production (use env vars).
