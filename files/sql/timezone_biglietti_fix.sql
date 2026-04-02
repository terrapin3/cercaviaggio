-- Correzione timezone su biglietti.acquistato (record storici)
-- Scenario: timestamp salvato in UTC ma interpretato come ora locale.
-- Effetto atteso: spostamento a Europe/Rome (con DST) solo sull'intervallo scelto.
--
-- USO:
-- 1) Modifica @from_ts e @to_ts con la finestra realmente impattata.
-- 2) Esegui PRIMA le SELECT di preview.
-- 3) Se i dati sono corretti, esegui blocco backup+update.
--
-- Nota: CONVERT_TZ(..., '+00:00', 'Europe/Rome') richiede timezone tables MySQL caricate.

SET @from_ts = '2026-03-01 00:00:00';
SET @to_ts   = '2026-04-30 23:59:59';

-- Preview: quanti record verrebbero toccati
SELECT
  COUNT(*) AS candidate_rows
FROM biglietti b
WHERE b.acquistato BETWEEN @from_ts AND @to_ts;

-- Preview: esempi prima/dopo
SELECT
  b.id_bg,
  b.codice,
  b.acquistato AS old_acquistato,
  CONVERT_TZ(b.acquistato, '+00:00', 'Europe/Rome') AS new_acquistato
FROM biglietti b
WHERE b.acquistato BETWEEN @from_ts AND @to_ts
ORDER BY b.id_bg DESC
LIMIT 50;

-- Log di backup per fix idempotente
CREATE TABLE IF NOT EXISTS biglietti_tz_fix_log (
  id_bg INT NOT NULL PRIMARY KEY,
  codice VARCHAR(80) NOT NULL DEFAULT '',
  old_acquistato DATETIME NOT NULL,
  new_acquistato DATETIME NULL,
  fixed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Salva righe candidate (solo se non già loggate)
INSERT IGNORE INTO biglietti_tz_fix_log (id_bg, codice, old_acquistato, new_acquistato)
SELECT
  b.id_bg,
  COALESCE(b.codice, ''),
  b.acquistato,
  CONVERT_TZ(b.acquistato, '+00:00', 'Europe/Rome')
FROM biglietti b
WHERE b.acquistato BETWEEN @from_ts AND @to_ts;

-- Applica fix una sola volta (fixed_at IS NULL)
UPDATE biglietti b
JOIN biglietti_tz_fix_log l ON l.id_bg = b.id_bg
SET b.acquistato = l.new_acquistato
WHERE l.fixed_at IS NULL
  AND l.new_acquistato IS NOT NULL;

-- Marca come applicato
UPDATE biglietti_tz_fix_log
SET fixed_at = NOW()
WHERE fixed_at IS NULL
  AND new_acquistato IS NOT NULL;

-- Verifica finale
SELECT
  COUNT(*) AS fixed_rows_now
FROM biglietti_tz_fix_log
WHERE fixed_at IS NOT NULL
  AND old_acquistato BETWEEN @from_ts AND @to_ts;

SELECT
  b.id_bg,
  b.codice,
  b.acquistato
FROM biglietti b
WHERE b.acquistato BETWEEN CONVERT_TZ(@from_ts, '+00:00', 'Europe/Rome')
                      AND CONVERT_TZ(@to_ts, '+00:00', 'Europe/Rome')
ORDER BY b.id_bg DESC
LIMIT 50;
