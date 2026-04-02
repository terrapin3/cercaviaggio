# Cercaviaggio Provider API v1

## Obiettivo
Definire un contratto unico API per le aziende partner, con modello aggregatore stile Omio:
- ogni azienda mantiene il proprio DB e backend;
- `cercaviaggio` consuma solo API standard;
- nessun accesso diretto al DB del provider.

Curcio e Leonetti sono i primi provider di riferimento, ma il contratto e` pensato per aziende esterne.

## Modello operativo
1. Il provider espone endpoint `rest/cercaviaggio/api2.php?rquest=<endpoint>`.
2. `cercaviaggio` sincronizza periodicamente metadati (fermate/linee/corse/tariffe).
3. Su ricerca e checkout, `cercaviaggio` usa chiamate live per prezzo/disponibilita` finale.
4. Il checkout viene concluso su `cercaviaggio` (modello centralizzato).

## Base URL provider
- `/rest/cercaviaggio/api2.php?rquest=<endpoint>`

Esempio:
- `https://provider-domain/rest/cercaviaggio/api2.php?rquest=health`

## Versioning
- `contract_version`: `v1` obbligatorio in ogni risposta.
- Ogni breaking change richiede `v2`.

## Sicurezza
- `Content-Type: application/json`.
- Produzione: API key o firma HMAC.
- Header consigliati:
  - `X-CV-Client-Id`
  - `X-CV-Timestamp`
  - `X-CV-Signature`
  - `X-Request-Id`
  - `X-Idempotency-Key` (obbligatorio su `reserve` e `book`)

## Envelope di risposta
Success:

```json
{
  "success": true,
  "contract_version": "v1",
  "provider": "curcio",
  "request_id": "uuid-or-random",
  "data": {},
  "error": null
}
```

Errore:

```json
{
  "success": false,
  "contract_version": "v1",
  "provider": "curcio",
  "request_id": "uuid-or-random",
  "data": null,
  "error": {
    "code": "INVALID_INPUT",
    "message": "Human readable message"
  }
}
```

## Endpoint live (ricerca e vendita)
1. `health` `GET`
2. `locations` `GET`
3. `search` `GET`
4. `quote` `GET` o `POST`
5. `checkout_info` `GET`
6. `reserve` `POST` (idempotente)
7. `book` `POST` (idempotente)
8. `booking_status` `GET`

Questi endpoint servono al motore di ricerca e al checkout realtime.

### `quote` (implementato)
Scopo: bloccare il calcolo prezzo per un breve intervallo prima del booking.

Metodo:
- `GET` oppure `POST`

Parametri minimi:
- `part` (int)
- `arr` (int)
- `id_corsa` (int)
- `dt1` (`dd/mm/YYYY`)
- `ad` (int)
- `bam` (int)

Parametri opzionali:
- `fare_id` (es. `STD`, `PROMO-274`)
- `id_promo` (legacy, viene convertito in `fare_id`)
- `direction` (`outbound` o `inbound`)
- `solution_id`

Risposta `data`:
- `quote_id`
- `quote_token` (token firmato HMAC)
- `issued_at`
- `expires_at`
- `ttl_seconds`
- `trip` (corsa, fermate, orari, durata)
- `passengers`
- `pricing` (fare selezionata e importi finali)

Note operative:
- il token `quote_token` va passato invariato a `reserve/book`;
- se scaduto (`expires_at`) va richiesto un nuovo `quote`;
- il booking deve sempre riverificare disponibilita`/regole lato provider.

### `reserve` (prima versione)
- Metodo: `POST`
- Header obbligatorio: `X-Idempotency-Key`
- Input minimo: `quote_token`
- Comportamento:
  - valida firma e scadenza del `quote_token`;
  - riverifica live corsa/regole/prezzo;
  - restituisce `reservation_id`, `reservation_token` e finestra temporale breve di riserva.

### `book` (prima versione)
- Metodo: `POST`
- Header obbligatorio: `X-Idempotency-Key`
- Input minimo: `quote_token`
- Input opzionale: `reservation_token`
- Input opzionale: `shop_id`/`ShopId`, `email`, `nome`, `codice`, `codice_camb`
- Comportamento:
  - valida `quote_token` (e `reservation_token` se presente);
  - riverifica live corsa/regole/prezzo;
  - senza `shop_id`: restituisce esito `validated_only`;
  - con `shop_id` + dati cliente: finalizza i biglietti chiamando `rest/app/api2.php?rquest=saveTickets` e restituisce `status=booked`.

## Endpoint sync (catalogo provider)
Per scalare a molte aziende, ogni provider deve esporre anche endpoint incremental sync:

1. `sync_stops` `GET`
2. `sync_lines` `GET`
3. `sync_trips` `GET`
4. `sync_fares` `GET`

Parametri standard:
- `updated_since` (ISO 8601, UTC) opzionale
- `page` (default 1)
- `page_size` (max 1000)

Risposta `data` standard:

```json
{
  "items": [],
  "page": 1,
  "page_size": 500,
  "has_more": false,
  "next_page": null,
  "synced_at": "2026-03-10T16:00:00Z"
}
```

Nota:
- prima sincronizzazione: chiamata senza `updated_since`;
- sincronizzazioni successive: usare il `synced_at` della run precedente.

## Regole idempotenza
Obbligatorio per:
- `reserve`
- `book`

Stessa chiave + stesso payload:
- stessa risposta;
- mai duplicare prenotazione o ticket.

## Error code minimi
- `INVALID_INPUT`
- `UNAUTHORIZED`
- `NOT_FOUND`
- `CONFLICT`
- `SEAT_NOT_AVAILABLE`
- `PAYMENT_FAILED`
- `TIMEOUT`
- `INTERNAL_ERROR`

## Requisiti onboarding provider
1. Implementazione endpoint live + sync.
2. Test di conformita` su ambiente sandbox.
3. Validazione performance:
   - `search` p95 < 1500 ms
   - `quote` p95 < 1200 ms
4. Go-live con API key production.

## Compatibilita` Curcio (fase iniziale)
Mappature correnti da API app:
- `locations` -> `locations()`
- `search` -> `verifica_corse()`
- `quote` -> `calcolaTotale()`
- `checkout_info` -> `checkoutInfo()`
- `reserve` -> `inserisciBiglietti()`
- `book` -> `saveTickets()` / `captureOrder()`
- `booking_status` -> `checkTicketStatus()`
