<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/functions.php';

function cvAccessoNormalizeAssetImagePath(string $rawPath): string
{
    $rawPath = trim($rawPath);
    if ($rawPath === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $rawPath);
    if (preg_match('#^https?://#i', $normalized) === 1) {
        $urlPath = (string) parse_url($normalized, PHP_URL_PATH);
        $needle = '/assets/';
        $pos = strpos($urlPath, $needle);
        if ($pos !== false) {
            $normalized = substr($urlPath, $pos + strlen($needle));
        } else {
            $normalized = ltrim($urlPath, '/');
        }
    }

    $normalized = ltrim($normalized, '/');
    if (str_starts_with($normalized, 'assets/')) {
        $normalized = substr($normalized, strlen('assets/'));
    }

    // Accettiamo solo asset immagine interni gestiti da questo pannello.
    if (!preg_match('#^images/(seo|blog)/#i', $normalized)) {
        return '';
    }

    return $normalized;
}

/**
 * @return string[]
 */
function cvAccessoExtractImagePathsFromHtml(string $html): array
{
    $paths = [];
    $html = trim($html);
    if ($html === '') {
        return $paths;
    }

    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach (($matches[1] ?? []) as $src) {
            $normalized = cvAccessoNormalizeAssetImagePath((string) $src);
            if ($normalized !== '') {
                $paths[] = $normalized;
            }
        }
    }

    return $paths;
}

/**
 * @return array<int,string>
 */
function cvAccessoMediaDirsByScope(string $scope): array
{
    $base = dirname(__DIR__) . '/assets/images';
    $all = [
        $base . '/seo',
        $base . '/blog',
    ];
    if ($scope === 'seo') {
        return [$base . '/seo'];
    }
    if ($scope === 'blog') {
        return [$base . '/blog'];
    }
    return $all;
}

/**
 * @return array<string,bool>
 */
function cvAccessoReferencedMediaPaths(mysqli $connection): array
{
    $used = [];

    if (function_exists('cvRouteSeoPagesEnsureTable') && cvRouteSeoPagesEnsureTable($connection)) {
        $result = $connection->query("SELECT hero_image_path FROM cv_route_seo_pages WHERE hero_image_path IS NOT NULL AND hero_image_path <> ''");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }
                $path = cvAccessoNormalizeAssetImagePath((string) ($row['hero_image_path'] ?? ''));
                if ($path !== '') {
                    $used[$path] = true;
                }
            }
            $result->free();
        }
    }

    if (function_exists('cvBlogEnsureTable') && cvBlogEnsureTable($connection)) {
        $result = $connection->query("SELECT hero_image_path, content_html, content_blocks_json FROM cv_blog_posts");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }
                $path = cvAccessoNormalizeAssetImagePath((string) ($row['hero_image_path'] ?? ''));
                if ($path !== '') {
                    $used[$path] = true;
                }

                foreach (cvAccessoExtractImagePathsFromHtml((string) ($row['content_html'] ?? '')) as $htmlPath) {
                    $used[$htmlPath] = true;
                }

                $blocks = function_exists('cvBlogDecodeBlocks')
                    ? cvBlogDecodeBlocks((string) ($row['content_blocks_json'] ?? ''))
                    : [];
                if (is_array($blocks)) {
                    foreach ($blocks as $block) {
                        if (!is_array($block)) {
                            continue;
                        }
                        $type = strtolower(trim((string) ($block['type'] ?? '')));
                        $value = trim((string) ($block['value'] ?? ''));
                        if ($value === '') {
                            continue;
                        }

                        if ($type === 'image') {
                            $imagePath = cvAccessoNormalizeAssetImagePath($value);
                            if ($imagePath !== '') {
                                $used[$imagePath] = true;
                            }
                        } elseif ($type === 'image_row') {
                            $lines = preg_split('/\R+/u', $value) ?: [];
                            foreach ($lines as $line) {
                                $line = trim((string) $line);
                                if ($line === '' || str_starts_with(strtolower($line), '[mode:')) {
                                    continue;
                                }
                                $imagePath = cvAccessoNormalizeAssetImagePath($line);
                                if ($imagePath !== '') {
                                    $used[$imagePath] = true;
                                }
                            }
                        } elseif ($type === 'html') {
                            foreach (cvAccessoExtractImagePathsFromHtml($value) as $htmlPath) {
                                $used[$htmlPath] = true;
                            }
                        }
                    }
                }
            }
            $result->free();
        }
    }

    return $used;
}

function cvAccessoOptimizeImageFile(string $filePath, int $quality, int $maxWidth, int $maxHeight): bool
{
    if (!function_exists('imagecreatefromjpeg') || !is_file($filePath)) {
        return false;
    }
    $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return false;
    }

    $source = null;
    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        $source = @imagecreatefromjpeg($filePath);
    } elseif ($ext === 'png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($filePath);
    } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($filePath);
    }
    if (!$source) {
        return false;
    }

    $w = imagesx($source);
    $h = imagesy($source);
    if ($w <= 0 || $h <= 0) {
        imagedestroy($source);
        return false;
    }
    $scale = min(1.0, min($maxWidth / max(1, $w), $maxHeight / max(1, $h)));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
    if (!$canvas) {
        imagedestroy($source);
        return false;
    }
    if ($ext === 'png') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
    }
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($source);

    $ok = false;
    if ($ext === 'png') {
        $ok = @imagepng($canvas, $filePath, 7);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        $ok = @imagewebp($canvas, $filePath, $quality);
    } else {
        $ok = @imagejpeg($canvas, $filePath, $quality);
    }
    imagedestroy($canvas);
    return $ok;
}

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Frontend & SEO', 'settings-frontend', $state);
    ?>
    <div class="row">
        <div class="col-md-12">
            <div class="cv-panel-card">
                <div class="cv-empty">Questa sezione è disponibile solo per l’amministratore.</div>
            </div>
        </div>
    </div>
    <?php
    cvAccessoRenderPageEnd();
    return;
}

$bundleDefinitions = cvAssetBundleDefinitions();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string) $_POST['action']);
    if (!cvAccessoValidateCsrf()) {
        $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
    } else {
        try {
            if ($action === 'clear_asset_bundles') {
                $deleted = cvAssetBundleClearAll();
                $state['messages'][] = 'Cache asset svuotata. File rimossi: ' . $deleted . '.';
            } elseif ($action === 'rebuild_asset_bundles') {
                $deleted = cvAssetBundleClearAll();
                $results = cvAssetBundleBuildAll();
                $built = count(array_filter($results, static fn(bool $value): bool => $value));
                $failed = count($results) - $built;
                $state['messages'][] = 'Bundle frontend ricreati. Rimossi ' . $deleted . ' file, ricostruiti ' . $built . ' bundle.';
                if ($failed > 0) {
                    $state['errors'][] = 'Alcuni bundle non sono stati ricreati: ' . $failed . '.';
                }
            } elseif ($action === 'rebuild_sitemap') {
                if (cvSitemapWriteFile()) {
                    $state['messages'][] = 'sitemap.xml rigenerata con successo.';
                } else {
                    $state['errors'][] = 'Impossibile rigenerare sitemap.xml.';
                }
            } elseif ($action === 'cleanup_orphan_images') {
                $scope = trim((string) ($_POST['media_scope'] ?? 'all'));
                $connection = cvAccessoRequireConnection();
                $used = cvAccessoReferencedMediaPaths($connection);
                $deleted = 0;
                foreach (cvAccessoMediaDirsByScope($scope) as $dir) {
                    if (!is_dir($dir)) {
                        continue;
                    }
                    $files = glob(rtrim($dir, '/') . '/*.{jpg,jpeg,png,webp,svg}', GLOB_BRACE);
                    if (!is_array($files)) {
                        continue;
                    }
                    foreach ($files as $filePath) {
                        if (!is_file($filePath)) {
                            continue;
                        }
                        $relative = str_replace(dirname(__DIR__) . '/assets/', '', $filePath);
                        $relative = str_replace('\\', '/', $relative);
                        if (!isset($used[$relative]) && @unlink($filePath)) {
                            $deleted++;
                        }
                    }
                }
                $state['messages'][] = 'Pulizia immagini orfane completata. Rimossi: ' . $deleted . '.';
            } elseif ($action === 'bulk_optimize_images') {
                $scope = trim((string) ($_POST['media_scope'] ?? 'all'));
                $quality = max(45, min(90, (int) ($_POST['image_quality'] ?? 78)));
                $maxWidth = max(640, min(3840, (int) ($_POST['image_max_width'] ?? 1920)));
                $maxHeight = max(640, min(3840, (int) ($_POST['image_max_height'] ?? 1200)));
                $optimized = 0;
                foreach (cvAccessoMediaDirsByScope($scope) as $dir) {
                    if (!is_dir($dir)) {
                        continue;
                    }
                    $files = glob(rtrim($dir, '/') . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
                    if (!is_array($files)) {
                        continue;
                    }
                    foreach ($files as $filePath) {
                        if (cvAccessoOptimizeImageFile($filePath, $quality, $maxWidth, $maxHeight)) {
                            $optimized++;
                        }
                    }
                }
                $state['messages'][] = 'Ottimizzazione bulk completata. Immagini processate: ' . $optimized . '.';
            }
        } catch (Throwable $exception) {
            $state['errors'][] = 'Errore gestione frontend: ' . $exception->getMessage();
        }
    }
}

$bundleStatus = cvAssetBundleStatus();
$sitemapStatus = cvSitemapStatus();

cvAccessoRenderPageStart('Frontend & SEO', 'settings-frontend', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Qui gestisci la cache dei bundle statici del frontend e la generazione di <code>sitemap.xml</code>.
            La sitemap usa il dominio e il path della richiesta corrente, quindi va rigenerata dal backend sul dominio corretto.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="cv-panel-card">
            <h4>Cache bundle frontend</h4>
            <p class="cv-muted">
                CSS e JS pubblici vengono concatenati in <code>assets/cache</code>. Da qui puoi svuotare e ricreare i bundle in qualsiasi momento.
            </p>
            <div class="cv-inline-actions">
                <form method="post" style="display:inline-block;">
                    <input type="hidden" name="action" value="clear_asset_bundles">
                    <?= cvAccessoCsrfField() ?>
                    <button type="submit" class="btn btn-default">Svuota cache asset</button>
                </form>
                <form method="post" style="display:inline-block;">
                    <input type="hidden" name="action" value="rebuild_asset_bundles">
                    <?= cvAccessoCsrfField() ?>
                    <button type="submit" class="btn btn-primary">Ricrea bundle</button>
                </form>
            </div>
            <div class="cv-muted" style="margin-top:10px;">
                Directory: <code><?= cvAccessoH((string) ($bundleStatus['dir'] ?? '')) ?></code><br>
                File presenti: <strong><?= (int) ($bundleStatus['count'] ?? 0) ?></strong>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="cv-panel-card">
            <h4>Sitemap</h4>
            <p class="cv-muted">
                La sitemap viene costruita dai contenuti pubblici indicizzabili del progetto. Puoi rigenerare il file XML quando aggiungi o modifichi pagine.
            </p>
            <div class="cv-inline-actions">
                <form method="post" style="display:inline-block;">
                    <input type="hidden" name="action" value="rebuild_sitemap">
                    <?= cvAccessoCsrfField() ?>
                    <button type="submit" class="btn btn-primary">Rigenera sitemap.xml</button>
                </form>
                <a class="btn btn-default" href="<?= cvAccessoH((string) ($sitemapStatus['url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">Apri sitemap</a>
            </div>
            <div class="cv-muted" style="margin-top:10px;">
                File: <code><?= cvAccessoH((string) ($sitemapStatus['path'] ?? '')) ?></code><br>
                Stato: <strong><?= !empty($sitemapStatus['exists']) ? 'presente' : 'non ancora generata' ?></strong>
                <?php if (!empty($sitemapStatus['modified_at'])): ?>
                    <br>Ultimo aggiornamento: <strong><?= cvAccessoH(date('Y-m-d H:i:s', (int) $sitemapStatus['modified_at'])) ?></strong>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Bundle disponibili</h4>
            <div class="table-responsive">
                <table class="table cv-table">
                    <thead>
                    <tr>
                        <th>Bundle</th>
                        <th>Tipo</th>
                        <th>Asset sorgenti</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bundleDefinitions as $bundleKey => $bundleDefinition): ?>
                        <tr>
                            <td>
                                <strong><?= cvAccessoH((string) ($bundleDefinition['label'] ?? $bundleKey)) ?></strong><br>
                                <code><?= cvAccessoH($bundleKey) ?></code>
                            </td>
                            <td><?= cvAccessoH((string) ($bundleDefinition['type'] ?? '')) ?></td>
                            <td>
                                <?php foreach (($bundleDefinition['files'] ?? []) as $assetPath): ?>
                                    <div><code><?= cvAccessoH((string) $assetPath) ?></code></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4>File cache attuali</h4>
            <?php if ((int) ($bundleStatus['count'] ?? 0) === 0): ?>
                <div class="cv-empty">Nessun file cache presente.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table cv-table">
                        <thead>
                        <tr>
                            <th>File</th>
                            <th>KB</th>
                            <th>Aggiornato</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($bundleStatus['files'] ?? []) as $fileInfo): ?>
                            <tr>
                                <td><code><?= cvAccessoH((string) ($fileInfo['name'] ?? '')) ?></code></td>
                                <td><?= cvAccessoH(number_format(((int) ($fileInfo['size'] ?? 0)) / 1024, 1, ',', '.')) ?></td>
                                <td><?= cvAccessoH(date('Y-m-d H:i:s', (int) ($fileInfo['modified_at'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>URL incluse in sitemap.xml</h4>
            <div class="table-responsive">
                <table class="table cv-table">
                    <thead>
                    <tr>
                        <th>URL</th>
                        <th>Changefreq</th>
                        <th>Priority</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($sitemapStatus['pages'] ?? []) as $pageInfo): ?>
                        <?php
                        $path = trim((string) ($pageInfo['path'] ?? ''), '/');
                        $loc = $path === ''
                            ? rtrim(cvBaseUrl(), '/') . '/'
                            : rtrim(cvBaseUrl(), '/') . '/' . $path;
                        ?>
                        <tr>
                            <td><a href="<?= cvAccessoH($loc) ?>" target="_blank" rel="noopener noreferrer"><?= cvAccessoH($loc) ?></a></td>
                            <td><?= cvAccessoH((string) ($pageInfo['changefreq'] ?? '')) ?></td>
                            <td><?= cvAccessoH((string) ($pageInfo['priority'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <h4>Media: pulizia e ottimizzazione</h4>
            <p class="cv-muted">Gestione centralizzata immagini SEO tratte e blog.</p>
            <div class="row">
                <div class="col-md-6">
                    <form method="post">
                        <input type="hidden" name="action" value="cleanup_orphan_images">
                        <?= cvAccessoCsrfField() ?>
                        <div class="form-group">
                            <label>Scope</label>
                            <select name="media_scope" class="form-control">
                                <option value="all">SEO + Blog</option>
                                <option value="seo">Solo SEO tratte</option>
                                <option value="blog">Solo Blog</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-default">Cancella immagini orfane</button>
                    </form>
                </div>
                <div class="col-md-6">
                    <form method="post">
                        <input type="hidden" name="action" value="bulk_optimize_images">
                        <?= cvAccessoCsrfField() ?>
                        <div class="form-group">
                            <label>Scope</label>
                            <select name="media_scope" class="form-control">
                                <option value="all">SEO + Blog</option>
                                <option value="seo">Solo SEO tratte</option>
                                <option value="blog">Solo Blog</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Qualita (45-90)</label>
                            <input type="number" min="45" max="90" class="form-control" name="image_quality" value="78">
                        </div>
                        <div class="form-group">
                            <label>Max width</label>
                            <input type="number" min="640" max="3840" class="form-control" name="image_max_width" value="1920">
                        </div>
                        <div class="form-group">
                            <label>Max height</label>
                            <input type="number" min="640" max="3840" class="form-control" name="image_max_height" value="1200">
                        </div>
                        <button type="submit" class="btn btn-primary">Bulk optimize immagini</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
