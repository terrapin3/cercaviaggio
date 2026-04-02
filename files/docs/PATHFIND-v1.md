# Cercaviaggio Pathfind v1

## Obiettivo
Trovare itinerari anche multi-azienda (es. Curcio + altra azienda) con cambi, tempi e prezzi coerenti.

## Principio
Il pathfind lavora su un grafo temporale:
- nodo: fermata in un momento;
- arco: tratto corsa con orari reali;
- cambio: arco speciale tra arrivo e prossima partenza.

## Input ricerca
- origine
- destinazione
- data/ora partenza
- passeggeri (adulti/bambini)
- vincoli opzionali (max cambi, max durata, provider esclusi)

## Pipeline
1. Caricamento dati locali da DB `cercaviaggio` (sync provider).
2. Costruzione candidati per finestra temporale (es. +36 ore).
3. Esecuzione algoritmo shortest path time-dependent.
4. Ranking soluzioni.
5. Verifica live prezzi/disponibilita` sul provider prima del checkout.

## Algoritmo consigliato
- base: Multi-criteria Dijkstra/A*
- costo composito:
  - `tempo_totale`
  - `numero_cambi`
  - `prezzo_stimato`
- hard constraints:
  - `min_change_minutes` (default 15-20)
  - no overlap orari
  - corse attive nel giorno richiesto

## Ranking v1
Score esempio:
- `score = tempo_totale_min + (cambi * 25) + (prezzo_eur * 1.5)`

Output:
- top `N` soluzioni (es. 20)
- etichetta soluzione:
  - `BEST`
  - `FASTEST`
  - `CHEAPEST`

## Caching
- cache risultati ricerca: 30-120 secondi (chiave su origine/destinazione/data/pax).
- invalidazione naturale via TTL.
- niente cache lunga su disponibilita` posti.

## Dati necessari nel DB cercaviaggio
- fermate
- linee
- corse e fermate corsa
- regole/blackout
- tariffe base/promozioni

Questi dati arrivano dagli endpoint `sync_*` dei provider.

## Contratto output verso frontend
Per ogni soluzione:
- `solution_id`
- `segments[]`
- `departure_datetime`
- `arrival_datetime`
- `duration_minutes`
- `changes`
- `estimated_total`
- `providers_involved[]`

## Regola critica checkout
Prima di confermare pagamento:
1. `quote` live su tutti i provider coinvolti;
2. `reserve` live (idempotente) su tutti i leg;
3. se uno fallisce, rollback riserve gia` aperte.

## Step implementativi consigliati
1. v1: pathfind mono-provider (Curcio) con output multi-segment pronto.
2. v2: pathfind multi-provider con 1 cambio.
3. v3: multi-provider con piu` cambi e ranking avanzato.

