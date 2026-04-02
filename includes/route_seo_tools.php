<?php
declare(strict_types=1);

if (!function_exists('cvRouteSeoPagesEnsureTable')) {
    function cvRouteSeoPagesEnsureTable(mysqli $connection): bool
    {
        static $initialized = null;
        if (is_bool($initialized)) {
            return $initialized;
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_route_seo_pages (
  id_route_seo_page BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(191) NOT NULL,
  from_ref VARCHAR(191) NOT NULL,
  to_ref VARCHAR(191) NOT NULL,
  from_name VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) NOT NULL,
  search_count_snapshot BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_requested_at DATETIME DEFAULT NULL,
  last_travel_date_it VARCHAR(10) DEFAULT NULL,
  min_amount DECIMAL(10,2) DEFAULT NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
  title_override VARCHAR(255) DEFAULT NULL,
  meta_description_override VARCHAR(320) DEFAULT NULL,
  intro_override TEXT DEFAULT NULL,
  body_override MEDIUMTEXT DEFAULT NULL,
  hero_image_path VARCHAR(255) DEFAULT NULL,
  auto_title VARCHAR(255) NOT NULL,
  auto_meta_description VARCHAR(320) NOT NULL,
  auto_intro TEXT NOT NULL,
  auto_body MEDIUMTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  approved_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_route_seo_page),
  UNIQUE KEY uq_cv_route_seo_slug (slug),
  UNIQUE KEY uq_cv_route_seo_pair (from_ref, to_ref),
  KEY idx_cv_route_seo_status (status, updated_at),
  KEY idx_cv_route_seo_rank (search_count_snapshot, last_requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL;

        $initialized = $connection->query($sql) === true;
        if (!$initialized) {
            error_log('cvRouteSeoPagesEnsureTable error: ' . $connection->error);
            return $initialized;
        }

        $columnChecks = [
            'last_travel_date_it' => "ALTER TABLE cv_route_seo_pages ADD COLUMN last_travel_date_it VARCHAR(10) DEFAULT NULL AFTER last_requested_at",
        ];

        foreach ($columnChecks as $columnName => $alterSql) {
            $result = $connection->query("SHOW COLUMNS FROM cv_route_seo_pages LIKE '" . $connection->real_escape_string($columnName) . "'");
            if ($result instanceof mysqli_result) {
                $exists = $result->num_rows > 0;
                $result->free();
                if ($exists) {
                    continue;
                }
            }

            if ($connection->query($alterSql) !== true) {
                error_log('cvRouteSeoPagesEnsureTable alter error: ' . $connection->error);
            }
        }

        return $initialized;
    }
}

if (!function_exists('cvRouteSeoNormalizeStatus')) {
    function cvRouteSeoNormalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['draft', 'approved', 'archived'], true)) {
            return 'draft';
        }

        return $status;
    }
}

if (!function_exists('cvRouteSeoSlugify')) {
    function cvRouteSeoSlugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($transliterated) && $transliterated !== '') {
                $value = $transliterated;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        $value = trim($value, '-');

        return $value;
    }
}

if (!function_exists('cvRouteSeoBaseSlug')) {
    function cvRouteSeoBaseSlug(string $fromName, string $toName): string
    {
        $base = cvRouteSeoSlugify($fromName . '-' . $toName);
        return $base !== '' ? $base : 'tratta-autobus';
    }
}

if (!function_exists('cvRouteSeoUniqueSlug')) {
    function cvRouteSeoUniqueSlug(mysqli $connection, string $baseSlug, int $excludeId = 0): string
    {
        if (!cvRouteSeoPagesEnsureTable($connection)) {
            return $baseSlug !== '' ? $baseSlug : 'tratta-autobus';
        }

        $baseSlug = trim($baseSlug) !== '' ? trim($baseSlug) : 'tratta-autobus';
        $candidate = $baseSlug;
        $suffix = 2;

        while (true) {
            if ($excludeId > 0) {
                $statement = $connection->prepare(
                    'SELECT id_route_seo_page FROM cv_route_seo_pages WHERE slug = ? AND id_route_seo_page <> ? LIMIT 1'
                );
                if (!$statement instanceof mysqli_stmt) {
                    return $candidate;
                }
                $statement->bind_param('si', $candidate, $excludeId);
            } else {
                $statement = $connection->prepare(
                    'SELECT id_route_seo_page FROM cv_route_seo_pages WHERE slug = ? LIMIT 1'
                );
                if (!$statement instanceof mysqli_stmt) {
                    return $candidate;
                }
                $statement->bind_param('s', $candidate);
            }

            $exists = false;
            if ($statement->execute()) {
                $result = $statement->get_result();
                $exists = $result instanceof mysqli_result && $result->num_rows > 0;
            }
            $statement->close();

            if (!$exists) {
                return $candidate;
            }

            $candidate = $baseSlug . '-' . $suffix;
            $suffix++;
        }
    }
}

if (!function_exists('cvRouteSeoPriceLabel')) {
    function cvRouteSeoPriceLabel(?float $amount, string $currency = 'EUR'): string
    {
        if ($amount === null || $amount <= 0) {
            return 'Prezzo verificato in fase di ricerca';
        }

        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'EUR') {
            return 'Da ' . cvFormatEuro($amount) . ' €';
        }

        return 'Da ' . cvFormatEuro($amount) . ' ' . $currency;
    }
}

if (!function_exists('cvRouteSeoDefaultTitle')) {
    function cvRouteSeoDefaultTitle(string $fromName, string $toName): string
    {
        return 'Autobus ' . $fromName . ' - ' . $toName . ' | Cercaviaggio';
    }
}

if (!function_exists('cvRouteSeoDefaultMetaDescription')) {
    function cvRouteSeoDefaultMetaDescription(string $fromName, string $toName, ?float $minAmount = null, string $currency = 'EUR'): string
    {
        $base = 'Confronta i viaggi autobus da ' . $fromName . ' a ' . $toName . ' con Cercaviaggio. ';
        if ($minAmount !== null && $minAmount > 0) {
            $base .= cvRouteSeoPriceLabel($minAmount, $currency) . '. ';
        }
        $base .= 'Ricerca multi-provider con verifica live di orari, scali e disponibilità.';

        if (function_exists('mb_substr')) {
            return mb_substr($base, 0, 320);
        }

        return substr($base, 0, 320);
    }
}

if (!function_exists('cvRouteSeoDefaultIntro')) {
    function cvRouteSeoDefaultIntro(string $fromName, string $toName, ?float $minAmount = null, string $currency = 'EUR'): string
    {
        $intro = 'Cercaviaggio ti aiuta a trovare soluzioni autobus da ' . $fromName . ' a ' . $toName . ' confrontando in un’unica ricerca i vettori aderenti, i collegamenti diretti e le combinazioni con scalo sensate.';
        if ($minAmount !== null && $minAmount > 0) {
            $intro .= "\n\n" . 'Sui dati sincronizzati attuali la tratta mostra tariffe indicative ' . strtolower(cvRouteSeoPriceLabel($minAmount, $currency)) . '.';
        }

        return $intro;
    }
}

if (!function_exists('cvRouteSeoDefaultBody')) {
    function cvRouteSeoDefaultBody(
        string $fromName,
        string $toName,
        ?float $minAmount = null,
        string $currency = 'EUR',
        int $searchCount = 0
    ): string {
        $paragraphs = [
            'Questa pagina nasce per raccogliere in modo più ordinato la domanda reale sulla tratta ' . $fromName . ' - ' . $toName . ' e aiutare chi cerca un collegamento autobus a trovare più velocemente una soluzione utile.',
            'Il motore di Cercaviaggio propone soluzioni dirette quando esistono, oppure combinazioni con scalo quando il percorso complessivo resta coerente con la destinazione richiesta.',
            'Orari, disponibilità e prezzo definitivo vengono sempre verificati durante la ricerca. Cercaviaggio ottimizza e confronta le opzioni disponibili, mentre il servizio di trasporto resta in capo al vettore che opera ciascun segmento.',
        ];

        if ($minAmount !== null && $minAmount > 0) {
            $paragraphs[] = 'Sulla base dei dati sincronizzati, per questa tratta risultano importi indicativi ' . strtolower(cvRouteSeoPriceLabel($minAmount, $currency)) . '. Il valore finale può cambiare in fase di verifica live.';
        }

        if ($searchCount > 0) {
            $paragraphs[] = 'La tratta rientra tra quelle che stanno ricevendo più interesse sul sito, quindi viene monitorata per costruire una pagina più completa e utile anche lato indicizzazione.';
        }

        return implode("\n\n", $paragraphs);
    }
}

if (!function_exists('cvRouteSeoResolveImageUrl')) {
    function cvRouteSeoResolveImageUrl(string $heroImagePath): string
    {
        $heroImagePath = trim($heroImagePath);
        if ($heroImagePath === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $heroImagePath) === 1) {
            return $heroImagePath;
        }

        $normalized = ltrim($heroImagePath, '/');
        if (str_starts_with($normalized, 'assets/')) {
            $normalized = substr($normalized, 7);
        }

        return cvAsset($normalized);
    }
}

if (!function_exists('cvRouteSeoSearchUrl')) {
    /**
     * @param array<string,mixed> $page
     */
    function cvRouteSeoSearchUrl(array $page): string
    {
        $travelDateIt = trim((string) ($page['last_travel_date_it'] ?? ''));
        if ($travelDateIt === '') {
            $travelDateIt = cvIsoToItDate(cvTodayIsoDate());
        }

        $params = [
            'part' => (string) ($page['from_ref'] ?? ''),
            'arr' => (string) ($page['to_ref'] ?? ''),
            'dt1' => $travelDateIt,
            'dt2' => '',
            'ad' => 1,
            'bam' => 0,
        ];

        return rtrim(cvBaseUrl(), '/') . '/soluzioni.php?' . http_build_query($params);
    }
}

if (!function_exists('cvRouteSeoPublicUrl')) {
    function cvRouteSeoPublicUrl(string $slug): string
    {
        return rtrim(cvBaseUrl(), '/') . '/tratte-autobus/' . rawurlencode(trim($slug));
    }
}

if (!function_exists('cvRouteSeoTextToHtml')) {
    function cvRouteSeoTextToHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $parts = preg_split("/\R{2,}/u", $text) ?: [];
        $html = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $html[] = '<p>' . nl2br(htmlspecialchars($part, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        return implode("\n", $html);
    }
}

if (!function_exists('cvRouteSeoHydratePageRow')) {
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function cvRouteSeoHydratePageRow(array $row): array
    {
        $status = cvRouteSeoNormalizeStatus((string) ($row['status'] ?? 'draft'));
        $titleOverride = trim((string) ($row['title_override'] ?? ''));
        $metaOverride = trim((string) ($row['meta_description_override'] ?? ''));
        $introOverride = trim((string) ($row['intro_override'] ?? ''));
        $bodyOverride = trim((string) ($row['body_override'] ?? ''));
        $minAmount = isset($row['min_amount']) && is_numeric($row['min_amount']) ? (float) $row['min_amount'] : null;
        $currency = trim((string) ($row['currency'] ?? 'EUR'));

        $row['status'] = $status;
        $row['effective_title'] = $titleOverride !== '' ? $titleOverride : (string) ($row['auto_title'] ?? '');
        $row['effective_meta_description'] = $metaOverride !== '' ? $metaOverride : (string) ($row['auto_meta_description'] ?? '');
        $row['effective_intro'] = $introOverride !== '' ? $introOverride : (string) ($row['auto_intro'] ?? '');
        $row['effective_body'] = $bodyOverride !== '' ? $bodyOverride : (string) ($row['auto_body'] ?? '');
        $row['price_label'] = cvRouteSeoPriceLabel($minAmount, $currency);
        $row['public_url'] = cvRouteSeoPublicUrl((string) ($row['slug'] ?? ''));
        $row['search_url'] = cvRouteSeoSearchUrl($row);
        $row['hero_image_url'] = cvRouteSeoResolveImageUrl((string) ($row['hero_image_path'] ?? ''));
        $row['is_public'] = $status === 'approved';

        return $row;
    }
}

if (!function_exists('cvRouteSeoTopCandidates')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvRouteSeoTopCandidates(mysqli $connection, int $limit = 20): array
    {
        if (!cvSearchRouteStatsEnsureTable($connection)) {
            return [];
        }

        $safeLimit = max(1, min(200, $limit));
        $sql = <<<SQL
SELECT
  from_ref,
  to_ref,
  from_provider_code,
  from_stop_external_id,
  from_name,
  to_provider_code,
  to_stop_external_id,
  to_name,
  search_count,
  last_requested_at,
  last_travel_date_it
FROM cv_search_route_stats
WHERE from_ref <> ''
  AND to_ref <> ''
  AND from_ref <> to_ref
ORDER BY search_count DESC, last_requested_at DESC
LIMIT {$safeLimit}
SQL;

        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $fromRef = trim((string) ($row['from_ref'] ?? ''));
            $toRef = trim((string) ($row['to_ref'] ?? ''));
            if ($fromRef === '' || $toRef === '' || $fromRef === $toRef) {
                continue;
            }

            $fromName = cvSearchRouteResolveRefName(
                $connection,
                $fromRef,
                trim((string) ($row['from_name'] ?? ''))
            );
            $toName = cvSearchRouteResolveRefName(
                $connection,
                $toRef,
                trim((string) ($row['to_name'] ?? ''))
            );

            if ($fromName === '' || $toName === '') {
                continue;
            }

            $fromParsed = cvSearchRouteParseRef($fromRef);
            $toParsed = cvSearchRouteParseRef($toRef);
            $fareInfo = cvSearchRouteBestDirectFare(
                $connection,
                (string) ($fromParsed['provider_code'] ?? ''),
                (string) ($fromParsed['stop_external_id'] ?? ''),
                (string) ($toParsed['provider_code'] ?? ''),
                (string) ($toParsed['stop_external_id'] ?? '')
            );

            $minAmount = is_array($fareInfo) && isset($fareInfo['min_amount']) ? (float) $fareInfo['min_amount'] : null;
            $currency = is_array($fareInfo) ? (string) ($fareInfo['currency'] ?? 'EUR') : 'EUR';
            $searchCount = isset($row['search_count']) ? (int) $row['search_count'] : 0;

            $rows[] = [
                'from_ref' => $fromRef,
                'to_ref' => $toRef,
                'from_name' => $fromName,
                'to_name' => $toName,
                'search_count_snapshot' => $searchCount,
                'last_requested_at' => (string) ($row['last_requested_at'] ?? ''),
                'last_travel_date_it' => (string) ($row['last_travel_date_it'] ?? ''),
                'min_amount' => $minAmount,
                'currency' => $currency,
                'auto_title' => cvRouteSeoDefaultTitle($fromName, $toName),
                'auto_meta_description' => cvRouteSeoDefaultMetaDescription($fromName, $toName, $minAmount, $currency),
                'auto_intro' => cvRouteSeoDefaultIntro($fromName, $toName, $minAmount, $currency),
                'auto_body' => cvRouteSeoDefaultBody($fromName, $toName, $minAmount, $currency, $searchCount),
            ];
        }

        $result->free();
        return $rows;
    }
}

if (!function_exists('cvRouteSeoUpsertDraft')) {
    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    function cvRouteSeoUpsertDraft(mysqli $connection, array $candidate): array
    {
        if (!cvRouteSeoPagesEnsureTable($connection)) {
            throw new RuntimeException('Tabella route SEO non disponibile.');
        }

        $fromRef = trim((string) ($candidate['from_ref'] ?? ''));
        $toRef = trim((string) ($candidate['to_ref'] ?? ''));
        $fromName = trim((string) ($candidate['from_name'] ?? ''));
        $toName = trim((string) ($candidate['to_name'] ?? ''));
        if ($fromRef === '' || $toRef === '' || $fromName === '' || $toName === '') {
            throw new RuntimeException('Candidato route SEO non valido.');
        }

        $searchCount = max(0, (int) ($candidate['search_count_snapshot'] ?? 0));
        $lastRequestedAt = trim((string) ($candidate['last_requested_at'] ?? ''));
        $lastTravelDateIt = trim((string) ($candidate['last_travel_date_it'] ?? ''));
        $minAmount = isset($candidate['min_amount']) && is_numeric($candidate['min_amount']) ? (float) $candidate['min_amount'] : null;
        $currency = trim((string) ($candidate['currency'] ?? 'EUR'));
        if ($currency === '') {
            $currency = 'EUR';
        }
        $autoTitle = trim((string) ($candidate['auto_title'] ?? cvRouteSeoDefaultTitle($fromName, $toName)));
        $autoMeta = trim((string) ($candidate['auto_meta_description'] ?? cvRouteSeoDefaultMetaDescription($fromName, $toName, $minAmount, $currency)));
        $autoIntro = trim((string) ($candidate['auto_intro'] ?? cvRouteSeoDefaultIntro($fromName, $toName, $minAmount, $currency)));
        $autoBody = trim((string) ($candidate['auto_body'] ?? cvRouteSeoDefaultBody($fromName, $toName, $minAmount, $currency, $searchCount)));

        $minAmountValue = $minAmount ?? 0.0;

        $selectStatement = $connection->prepare(
            'SELECT id_route_seo_page, slug FROM cv_route_seo_pages WHERE from_ref = ? AND to_ref = ? LIMIT 1'
        );
        if (!$selectStatement instanceof mysqli_stmt) {
            throw new RuntimeException('Prepare select route SEO fallita.');
        }

        $selectStatement->bind_param('ss', $fromRef, $toRef);
        if (!$selectStatement->execute()) {
            $error = $selectStatement->error;
            $selectStatement->close();
            throw new RuntimeException('Select route SEO fallita: ' . $error);
        }

        $result = $selectStatement->get_result();
        $existing = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $selectStatement->close();

        if (is_array($existing)) {
            $id = (int) ($existing['id_route_seo_page'] ?? 0);
            $slug = trim((string) ($existing['slug'] ?? ''));
            if ($slug === '') {
                $slug = cvRouteSeoUniqueSlug($connection, cvRouteSeoBaseSlug($fromName, $toName), $id);
            }

            $updateStatement = $connection->prepare(
                'UPDATE cv_route_seo_pages
                 SET slug = ?, from_name = ?, to_name = ?, search_count_snapshot = ?, last_requested_at = ?, last_travel_date_it = ?, min_amount = ?, currency = ?, auto_title = ?, auto_meta_description = ?, auto_intro = ?, auto_body = ?
                 WHERE id_route_seo_page = ?'
            );
            if (!$updateStatement instanceof mysqli_stmt) {
                throw new RuntimeException('Prepare update route SEO fallita.');
            }

            $updateStatement->bind_param(
                'sssissdsssssi',
                $slug,
                $fromName,
                $toName,
                $searchCount,
                $lastRequestedAt,
                $lastTravelDateIt,
                $minAmountValue,
                $currency,
                $autoTitle,
                $autoMeta,
                $autoIntro,
                $autoBody,
                $id
            );

            if (!$updateStatement->execute()) {
                $error = $updateStatement->error;
                $updateStatement->close();
                throw new RuntimeException('Update route SEO fallita: ' . $error);
            }
            $updateStatement->close();

            return ['id' => $id, 'action' => 'updated', 'slug' => $slug];
        }

        $slug = cvRouteSeoUniqueSlug($connection, cvRouteSeoBaseSlug($fromName, $toName));
        $status = 'draft';
        $insertStatement = $connection->prepare(
            'INSERT INTO cv_route_seo_pages (
                slug, from_ref, to_ref, from_name, to_name, search_count_snapshot, last_requested_at, last_travel_date_it, min_amount, currency,
                auto_title, auto_meta_description, auto_intro, auto_body, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insertStatement instanceof mysqli_stmt) {
            throw new RuntimeException('Prepare insert route SEO fallita.');
        }

        $insertStatement->bind_param(
            'sssssissdssssss',
            $slug,
            $fromRef,
            $toRef,
            $fromName,
            $toName,
            $searchCount,
            $lastRequestedAt,
            $lastTravelDateIt,
            $minAmountValue,
            $currency,
            $autoTitle,
            $autoMeta,
            $autoIntro,
            $autoBody,
            $status
        );

        if (!$insertStatement->execute()) {
            $error = $insertStatement->error;
            $insertStatement->close();
            throw new RuntimeException('Insert route SEO fallita: ' . $error);
        }

        $id = (int) $insertStatement->insert_id;
        $insertStatement->close();

        return ['id' => $id, 'action' => 'created', 'slug' => $slug];
    }
}

if (!function_exists('cvRouteSeoGenerateDrafts')) {
    /**
     * @return array<string,int>
     */
    function cvRouteSeoGenerateDrafts(mysqli $connection, int $limit = 20): array
    {
        $candidates = cvRouteSeoTopCandidates($connection, $limit);
        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
        ];

        foreach ($candidates as $candidate) {
            $result = cvRouteSeoUpsertDraft($connection, $candidate);
            $stats['processed']++;
            $action = (string) ($result['action'] ?? '');
            if ($action === 'created') {
                $stats['created']++;
            } elseif ($action === 'updated') {
                $stats['updated']++;
            }
        }

        return $stats;
    }
}

if (!function_exists('cvRouteSeoFetchAdminPages')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvRouteSeoFetchAdminPages(mysqli $connection): array
    {
        if (!cvRouteSeoPagesEnsureTable($connection)) {
            return [];
        }

        $sql = <<<SQL
SELECT
  id_route_seo_page,
  slug,
  from_ref,
  to_ref,
  from_name,
  to_name,
  search_count_snapshot,
  last_requested_at,
  last_travel_date_it,
  min_amount,
  currency,
  title_override,
  meta_description_override,
  intro_override,
  body_override,
  hero_image_path,
  auto_title,
  auto_meta_description,
  auto_intro,
  auto_body,
  status,
  approved_at,
  created_at,
  updated_at
FROM cv_route_seo_pages
ORDER BY
  CASE status WHEN 'approved' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END ASC,
  search_count_snapshot DESC,
  updated_at DESC
SQL;

        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = cvRouteSeoHydratePageRow($row);
        }

        $result->free();
        return $rows;
    }
}

if (!function_exists('cvRouteSeoFetchPageById')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvRouteSeoFetchPageById(mysqli $connection, int $id): ?array
    {
        if ($id <= 0 || !cvRouteSeoPagesEnsureTable($connection)) {
            return null;
        }

        $statement = $connection->prepare(
            'SELECT
                id_route_seo_page,
                slug,
                from_ref,
                to_ref,
                from_name,
                to_name,
                search_count_snapshot,
                last_requested_at,
                last_travel_date_it,
                min_amount,
                currency,
                title_override,
                meta_description_override,
                intro_override,
                body_override,
                hero_image_path,
                auto_title,
                auto_meta_description,
                auto_intro,
                auto_body,
                status,
                approved_at,
                created_at,
                updated_at
             FROM cv_route_seo_pages
             WHERE id_route_seo_page = ?
             LIMIT 1'
        );
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }

        $statement->bind_param('i', $id);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }

        $result = $statement->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $statement->close();
        if (!is_array($row)) {
            return null;
        }

        return cvRouteSeoHydratePageRow($row);
    }
}

if (!function_exists('cvRouteSeoFetchPublicPageBySlug')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvRouteSeoFetchPublicPageBySlug(mysqli $connection, string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !cvRouteSeoPagesEnsureTable($connection)) {
            return null;
        }

        $statement = $connection->prepare(
            'SELECT
                id_route_seo_page,
                slug,
                from_ref,
                to_ref,
                from_name,
                to_name,
                search_count_snapshot,
                last_requested_at,
                last_travel_date_it,
                min_amount,
                currency,
                title_override,
                meta_description_override,
                intro_override,
                body_override,
                hero_image_path,
                auto_title,
                auto_meta_description,
                auto_intro,
                auto_body,
                status,
                approved_at,
                created_at,
                updated_at
             FROM cv_route_seo_pages
             WHERE slug = ?
               AND status = \'approved\'
             LIMIT 1'
        );
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }

        $statement->bind_param('s', $slug);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }

        $result = $statement->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $statement->close();
        if (!is_array($row)) {
            return null;
        }

        return cvRouteSeoHydratePageRow($row);
    }
}

if (!function_exists('cvRouteSeoFetchPageAnyStatusBySlug')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvRouteSeoFetchPageAnyStatusBySlug(mysqli $connection, string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !cvRouteSeoPagesEnsureTable($connection)) {
            return null;
        }

        $statement = $connection->prepare(
            'SELECT
                id_route_seo_page,
                slug,
                from_ref,
                to_ref,
                from_name,
                to_name,
                search_count_snapshot,
                last_requested_at,
                last_travel_date_it,
                min_amount,
                currency,
                title_override,
                meta_description_override,
                intro_override,
                body_override,
                hero_image_path,
                auto_title,
                auto_meta_description,
                auto_intro,
                auto_body,
                status,
                approved_at,
                created_at,
                updated_at
             FROM cv_route_seo_pages
             WHERE slug = ?
             LIMIT 1'
        );
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }

        $statement->bind_param('s', $slug);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return null;
        }

        $row = $result->fetch_assoc();
        $statement->close();
        if (!is_array($row)) {
            return null;
        }

        return cvRouteSeoHydratePageRow($row);
    }
}

if (!function_exists('cvRouteSeoNormalizeNullableText')) {
    function cvRouteSeoNormalizeNullableText(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}

if (!function_exists('cvRouteSeoSavePage')) {
    function cvRouteSeoSavePage(mysqli $connection, int $id, array $payload): bool
    {
        if ($id <= 0 || !cvRouteSeoPagesEnsureTable($connection)) {
            return false;
        }

        $existing = cvRouteSeoFetchPageById($connection, $id);
        if (!is_array($existing)) {
            return false;
        }

        $slug = cvRouteSeoUniqueSlug(
            $connection,
            cvRouteSeoSlugify((string) ($payload['slug'] ?? '')) ?: (string) ($existing['slug'] ?? ''),
            $id
        );
        $titleOverride = cvRouteSeoNormalizeNullableText($payload['title_override'] ?? null);
        $metaOverride = cvRouteSeoNormalizeNullableText($payload['meta_description_override'] ?? null);
        $introOverride = cvRouteSeoNormalizeNullableText($payload['intro_override'] ?? null);
        $bodyOverride = cvRouteSeoNormalizeNullableText($payload['body_override'] ?? null);
        $heroImagePath = cvRouteSeoNormalizeNullableText($payload['hero_image_path'] ?? null);
        $status = cvRouteSeoNormalizeStatus((string) ($payload['status'] ?? 'draft'));
        $approvedAt = $status === 'approved'
            ? ((string) ($existing['approved_at'] ?? '') !== ''
                ? (string) $existing['approved_at']
                : (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s'))
            : null;

        $statement = $connection->prepare(
            'UPDATE cv_route_seo_pages
             SET slug = ?, title_override = ?, meta_description_override = ?, intro_override = ?, body_override = ?, hero_image_path = ?, status = ?, approved_at = ?
             WHERE id_route_seo_page = ?'
        );
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $statement->bind_param(
            'ssssssssi',
            $slug,
            $titleOverride,
            $metaOverride,
            $introOverride,
            $bodyOverride,
            $heroImagePath,
            $status,
            $approvedAt,
            $id
        );

        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvRouteSeoDeletePage')) {
    function cvRouteSeoDeletePage(mysqli $connection, int $id): bool
    {
        if ($id <= 0 || !cvRouteSeoPagesEnsureTable($connection)) {
            return false;
        }

        $statement = $connection->prepare('DELETE FROM cv_route_seo_pages WHERE id_route_seo_page = ?');
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $statement->bind_param('i', $id);
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvRouteSeoSitemapEntries')) {
    /**
     * @return array<int,array<string,string>>
     */
    function cvRouteSeoSitemapEntries(mysqli $connection): array
    {
        if (!cvRouteSeoPagesEnsureTable($connection)) {
            return [];
        }

        $result = $connection->query(
            "SELECT slug, updated_at FROM cv_route_seo_pages WHERE status = 'approved' ORDER BY updated_at DESC"
        );
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $entries = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $updatedAt = trim((string) ($row['updated_at'] ?? ''));
            $lastmod = $updatedAt !== '' ? date('c', strtotime($updatedAt)) : date('c');

            $entries[] = [
                'path' => 'tratte-autobus/' . $slug,
                'file' => 'tratta.php',
                'changefreq' => 'weekly',
                'priority' => '0.7',
                'lastmod' => $lastmod,
            ];
        }

        $result->free();
        return $entries;
    }
}

if (!function_exists('cvRouteSeoApprovedUrlMapByRefs')) {
    /**
     * @param array<int,array<string,string>> $pairs
     * @return array<string,string> key: from_ref|to_ref
     */
    function cvRouteSeoApprovedUrlMapByRefs(mysqli $connection, array $pairs): array
    {
        if (!cvRouteSeoPagesEnsureTable($connection)) {
            return [];
        }

        $normalized = [];
        foreach ($pairs as $pair) {
            $fromRef = trim((string) ($pair['from_ref'] ?? ''));
            $toRef = trim((string) ($pair['to_ref'] ?? ''));
            if ($fromRef === '' || $toRef === '') {
                continue;
            }
            $normalized[$fromRef . '|' . $toRef] = [
                'from_ref' => $fromRef,
                'to_ref' => $toRef,
            ];
        }

        if (count($normalized) === 0) {
            return [];
        }

        $resultMap = [];
        foreach ($normalized as $pair) {
            $statement = $connection->prepare(
                'SELECT slug FROM cv_route_seo_pages WHERE from_ref = ? AND to_ref = ? AND status = ? LIMIT 1'
            );
            if (!$statement instanceof mysqli_stmt) {
                continue;
            }

            $approved = 'approved';
            $statement->bind_param('sss', $pair['from_ref'], $pair['to_ref'], $approved);
            if (!$statement->execute()) {
                $statement->close();
                continue;
            }

            $queryResult = $statement->get_result();
            if ($queryResult instanceof mysqli_result) {
                $row = $queryResult->fetch_assoc();
                if (is_array($row)) {
                    $slug = trim((string) ($row['slug'] ?? ''));
                    if ($slug !== '') {
                        $resultMap[$pair['from_ref'] . '|' . $pair['to_ref']] = cvRouteSeoPublicUrl($slug);
                    }
                }
            }
            $statement->close();
        }

        return $resultMap;
    }
}

if (!function_exists('cvRouteSeoFetchApprovedPages')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvRouteSeoFetchApprovedPages(mysqli $connection, int $limit = 200): array
    {
        if (!cvRouteSeoPagesEnsureTable($connection)) {
            return [];
        }

        $safeLimit = max(1, min(1000, $limit));
        $result = $connection->query(
            "SELECT
                id_route_seo_page,
                slug,
                from_ref,
                to_ref,
                from_name,
                to_name,
                search_count_snapshot,
                min_amount,
                currency,
                hero_image_path,
                updated_at
             FROM cv_route_seo_pages
             WHERE status = 'approved'
             ORDER BY updated_at DESC
             LIMIT {$safeLimit}"
        );
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = cvRouteSeoHydratePageRow($row);
        }
        $result->free();
        return $rows;
    }
}

if (!function_exists('cvRouteSeoTechnicalSnapshot')) {
    /**
     * @return array<string,mixed>
     */
    function cvRouteSeoTechnicalSnapshot(mysqli $connection, array $page): array
    {
        $snapshot = [
            'provider_count' => 0,
            'provider_names' => [],
            'offers_count' => 0,
            'min_amount' => isset($page['min_amount']) ? (float) $page['min_amount'] : 0.0,
            'currency' => (string) ($page['currency'] ?? 'EUR'),
            'search_count_snapshot' => isset($page['search_count_snapshot']) ? (int) $page['search_count_snapshot'] : 0,
            'last_requested_at' => (string) ($page['last_requested_at'] ?? ''),
            'from_name' => (string) ($page['from_name'] ?? ''),
            'to_name' => (string) ($page['to_name'] ?? ''),
        ];

        $fromRef = trim((string) ($page['from_ref'] ?? ''));
        $toRef = trim((string) ($page['to_ref'] ?? ''));
        $fromParsed = cvSearchRouteParseRef($fromRef);
        $toParsed = cvSearchRouteParseRef($toRef);
        $fromId = trim((string) ($fromParsed['stop_external_id'] ?? ''));
        $toId = trim((string) ($toParsed['stop_external_id'] ?? ''));
        if ($fromId === '' || $toId === '') {
            return $snapshot;
        }

        $statement = $connection->prepare(
            'SELECT p.code AS provider_code, p.name AS provider_name, COUNT(*) AS offers_count, MIN(f.amount) AS min_amount
             FROM cv_provider_fares f
             INNER JOIN cv_providers p ON p.id_provider = f.id_provider
             WHERE f.is_active = 1
               AND p.is_active = 1
               AND f.from_stop_external_id = ?
               AND f.to_stop_external_id = ?
             GROUP BY p.code, p.name
             ORDER BY offers_count DESC, min_amount ASC'
        );
        if (!$statement instanceof mysqli_stmt) {
            return $snapshot;
        }

        $statement->bind_param('ss', $fromId, $toId);
        if (!$statement->execute()) {
            $statement->close();
            return $snapshot;
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return $snapshot;
        }

        $providerNames = [];
        $offersCount = 0;
        $bestAmount = $snapshot['min_amount'] > 0 ? (float) $snapshot['min_amount'] : 0.0;
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $providerName = trim((string) ($row['provider_name'] ?? $row['provider_code'] ?? ''));
            if ($providerName !== '') {
                $providerNames[] = $providerName;
            }
            $offersCount += isset($row['offers_count']) ? (int) $row['offers_count'] : 0;
            $rowMinAmount = isset($row['min_amount']) ? (float) $row['min_amount'] : 0.0;
            if ($rowMinAmount > 0.0 && ($bestAmount <= 0.0 || $rowMinAmount < $bestAmount)) {
                $bestAmount = $rowMinAmount;
            }
        }
        $statement->close();

        $snapshot['provider_names'] = $providerNames;
        $snapshot['provider_count'] = count($providerNames);
        $snapshot['offers_count'] = $offersCount;
        $snapshot['min_amount'] = $bestAmount;
        return $snapshot;
    }
}

if (!function_exists('cvRouteSeoBuildMapUrl')) {
    function cvRouteSeoBuildMapUrl(?float $lat, ?float $lon, string $label): string
    {
        if ($lat !== null && $lon !== null && abs($lat) > 0.0 && abs($lon) > 0.0) {
            return 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat)
                . '&mlon=' . rawurlencode((string) $lon)
                . '#map=14/' . rawurlencode((string) $lat) . '/' . rawurlencode((string) $lon);
        }

        $query = trim($label);
        if ($query === '') {
            return '';
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }
}

if (!function_exists('cvRouteSeoStopsForRef')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvRouteSeoStopsForRef(mysqli $connection, string $rawRef, int $limit = 10): array
    {
        $safeLimit = max(1, min(30, $limit));
        $parsed = cvSearchRouteParseRef($rawRef);
        $providerCode = trim((string) ($parsed['provider_code'] ?? ''));
        $externalRef = trim((string) ($parsed['stop_external_id'] ?? ''));

        if ($externalRef === '') {
            return [];
        }

        if ($providerCode === 'place') {
            $placeId = (int) $externalRef;
            if ($placeId <= 0 || !function_exists('cvPlacesTablesExist') || !cvPlacesTablesExist($connection)) {
                return [];
            }

            $sql = "SELECT
                      s.external_id,
                      s.name AS stop_name,
                      p.code AS provider_code,
                      p.name AS provider_name,
                      s.lat,
                      s.lon,
                      cps.priority,
                      cps.is_primary
                    FROM cv_place_stops cps
                    INNER JOIN cv_provider_stops s ON s.id = cps.id_stop
                    INNER JOIN cv_providers p ON p.id_provider = s.id_provider
                    WHERE cps.id_place = ?
                      AND s.is_active = 1
                      AND p.is_active = 1
                    ORDER BY cps.is_primary DESC, cps.priority ASC, s.name ASC
                    LIMIT {$safeLimit}";
            $statement = $connection->prepare($sql);
            if (!$statement instanceof mysqli_stmt) {
                return [];
            }

            $statement->bind_param('i', $placeId);
            if (!$statement->execute()) {
                $statement->close();
                return [];
            }

            $result = $statement->get_result();
            if (!$result instanceof mysqli_result) {
                $statement->close();
                return [];
            }

            $rows = [];
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }
                $stopName = trim((string) ($row['stop_name'] ?? ''));
                $providerName = trim((string) ($row['provider_name'] ?? ''));
                $providerCodeRow = trim((string) ($row['provider_code'] ?? ''));
                $lat = isset($row['lat']) ? (float) $row['lat'] : null;
                $lon = isset($row['lon']) ? (float) $row['lon'] : null;
                $label = $stopName . ($providerName !== '' ? ' - ' . $providerName : '');
                $rows[] = [
                    'external_id' => (string) ($row['external_id'] ?? ''),
                    'stop_name' => $stopName,
                    'provider_code' => $providerCodeRow,
                    'provider_name' => $providerName,
                    'lat' => $lat,
                    'lon' => $lon,
                    'map_url' => cvRouteSeoBuildMapUrl($lat, $lon, $label),
                    'is_primary' => isset($row['is_primary']) ? (int) $row['is_primary'] : 0,
                ];
            }
            $statement->close();
            return $rows;
        }

        if ($providerCode !== '') {
            $sql = "SELECT
                      s.external_id,
                      s.name AS stop_name,
                      p.code AS provider_code,
                      p.name AS provider_name,
                      s.lat,
                      s.lon
                    FROM cv_provider_stops s
                    INNER JOIN cv_providers p ON p.id_provider = s.id_provider
                    WHERE p.code = ?
                      AND s.external_id = ?
                    ORDER BY s.is_active DESC, s.id ASC
                    LIMIT {$safeLimit}";
            $statement = $connection->prepare($sql);
            if (!$statement instanceof mysqli_stmt) {
                return [];
            }

            $statement->bind_param('ss', $providerCode, $externalRef);
        } else {
            $sql = "SELECT
                      s.external_id,
                      s.name AS stop_name,
                      p.code AS provider_code,
                      p.name AS provider_name,
                      s.lat,
                      s.lon
                    FROM cv_provider_stops s
                    INNER JOIN cv_providers p ON p.id_provider = s.id_provider
                    WHERE s.external_id = ?
                    ORDER BY s.is_active DESC, s.id ASC
                    LIMIT {$safeLimit}";
            $statement = $connection->prepare($sql);
            if (!$statement instanceof mysqli_stmt) {
                return [];
            }

            $statement->bind_param('s', $externalRef);
        }

        if (!$statement->execute()) {
            $statement->close();
            return [];
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $stopName = trim((string) ($row['stop_name'] ?? ''));
            $providerName = trim((string) ($row['provider_name'] ?? ''));
            $lat = isset($row['lat']) ? (float) $row['lat'] : null;
            $lon = isset($row['lon']) ? (float) $row['lon'] : null;
            $label = $stopName . ($providerName !== '' ? ' - ' . $providerName : '');
            $rows[] = [
                'external_id' => (string) ($row['external_id'] ?? ''),
                'stop_name' => $stopName,
                'provider_code' => trim((string) ($row['provider_code'] ?? '')),
                'provider_name' => $providerName,
                'lat' => $lat,
                'lon' => $lon,
                'map_url' => cvRouteSeoBuildMapUrl($lat, $lon, $label),
                'is_primary' => 1,
            ];
        }
        $statement->close();
        return $rows;
    }
}
