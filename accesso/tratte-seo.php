<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/functions.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

if (!cvAccessoIsAdmin($state)) {
    http_response_code(403);
    cvAccessoRenderPageStart('Pagine tratte', 'settings-route-pages', $state);
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

$selectedPageId = max(0, (int) ($_GET['page'] ?? $_POST['page_id'] ?? 0));
$pages = [];
$selectedPage = null;
$stats = [
    'total' => 0,
    'approved' => 0,
    'draft' => 0,
    'archived' => 0,
];

/**
 * @return string|null relative path (es. images/seo/file.jpg)
 */
function cvAccessoCompressImageToTarget(string $tmpPath, string $targetPath, string $extension): bool
{
    if (!function_exists('imagecreatefromjpeg')) {
        return false;
    }

    $extension = strtolower($extension);
    $source = null;
    if (in_array($extension, ['jpg', 'jpeg'], true)) {
        $source = @imagecreatefromjpeg($tmpPath);
    } elseif ($extension === 'png') {
        $source = function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpPath) : null;
    } elseif ($extension === 'webp') {
        $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : null;
    }

    if (!$source) {
        return false;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($source);
        return false;
    }

    $maxWidth = 1920;
    $maxHeight = 1080;
    $scale = min(1.0, min($maxWidth / $width, $maxHeight / $height));
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    if (!$canvas) {
        imagedestroy($source);
        return false;
    }

    if ($extension === 'png') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
    }

    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($source);

    $saved = false;
    if ($extension === 'png') {
        $saved = @imagepng($canvas, $targetPath, 7);
    } elseif ($extension === 'webp' && function_exists('imagewebp')) {
        $saved = @imagewebp($canvas, $targetPath, 78);
    } else {
        $saved = @imagejpeg($canvas, $targetPath, 78);
    }

    imagedestroy($canvas);
    return $saved;
}

function cvAccessoHandleSeoHeroUpload(int $pageId): ?string
{
    if (!isset($_FILES['hero_image_upload']) || !is_array($_FILES['hero_image_upload'])) {
        return null;
    }

    $file = $_FILES['hero_image_upload'];
    $errorCode = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload immagine non riuscito (codice ' . $errorCode . ').');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Upload immagine non valido.');
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Formato immagine non supportato. Usa JPG, PNG, WEBP o SVG.');
    }

    $targetDir = dirname(__DIR__) . '/assets/images/seo';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Impossibile creare la cartella immagini SEO.');
    }

    $safeFilename = 'route-' . max(1, $pageId) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeFilename;

    $compressed = false;
    if ($extension !== 'svg') {
        $compressed = cvAccessoCompressImageToTarget($tmpPath, $targetPath, $extension);
    }

    if (!$compressed && !move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Impossibile salvare il file immagine.');
    }

    return 'images/seo/' . $safeFilename;
}

try {
    $connection = cvAccessoRequireConnection();
    cvRouteSeoPagesEnsureTable($connection);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = trim((string) $_POST['action']);
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            try {
                if ($action === 'generate_route_seo_pages') {
                    $limit = max(1, min(100, (int) ($_POST['generate_limit'] ?? 20)));
                    $result = cvRouteSeoGenerateDrafts($connection, $limit);
                    cvSitemapWriteFile();
                    $state['messages'][] = 'Bozze SEO aggiornate. Processate ' . (int) ($result['processed'] ?? 0) . ' tratte, create ' . (int) ($result['created'] ?? 0) . ', aggiornate ' . (int) ($result['updated'] ?? 0) . '.';
                } elseif ($action === 'save_route_seo_page') {
                    $pageId = max(0, (int) ($_POST['page_id'] ?? 0));
                    if ($pageId <= 0) {
                        throw new RuntimeException('Pagina tratta non valida.');
                    }

                    $uploadedHeroImagePath = cvAccessoHandleSeoHeroUpload($pageId);
                    $heroImagePathValue = (string) ($_POST['hero_image_path'] ?? '');
                    if (is_string($uploadedHeroImagePath) && trim($uploadedHeroImagePath) !== '') {
                        $heroImagePathValue = $uploadedHeroImagePath;
                    }

                    $ok = cvRouteSeoSavePage($connection, $pageId, [
                        'slug' => (string) ($_POST['slug'] ?? ''),
                        'status' => (string) ($_POST['status'] ?? 'draft'),
                        'title_override' => (string) ($_POST['title_override'] ?? ''),
                        'meta_description_override' => (string) ($_POST['meta_description_override'] ?? ''),
                        'intro_override' => (string) ($_POST['intro_override'] ?? ''),
                        'body_override' => (string) ($_POST['body_override'] ?? ''),
                        'hero_image_path' => $heroImagePathValue,
                    ]);
                    if (!$ok) {
                        throw new RuntimeException('Salvataggio pagina tratta non riuscito.');
                    }

                    $selectedPageId = $pageId;
                    cvSitemapWriteFile();
                    $state['messages'][] = 'Pagina tratta salvata.';
                } elseif ($action === 'delete_route_seo_page') {
                    $pageId = max(0, (int) ($_POST['page_id'] ?? 0));
                    if ($pageId <= 0) {
                        throw new RuntimeException('Pagina tratta non valida.');
                    }

                    $ok = cvRouteSeoDeletePage($connection, $pageId);
                    if (!$ok) {
                        throw new RuntimeException('Eliminazione pagina tratta non riuscita.');
                    }

                    if ($selectedPageId === $pageId) {
                        $selectedPageId = 0;
                    }
                    cvSitemapWriteFile();
                    $state['messages'][] = 'Pagina tratta eliminata.';
                }
            } catch (Throwable $exception) {
                $state['errors'][] = 'Errore gestione pagine tratte: ' . $exception->getMessage();
            }
        }
    }

    $pages = cvRouteSeoFetchAdminPages($connection);
    $stats['total'] = count($pages);
    foreach ($pages as $page) {
        $status = (string) ($page['status'] ?? 'draft');
        if (!isset($stats[$status])) {
            $stats[$status] = 0;
        }
        $stats[$status]++;
    }

    if ($selectedPageId <= 0 && count($pages) > 0) {
        $selectedPageId = (int) ($pages[0]['id_route_seo_page'] ?? 0);
    }

    if ($selectedPageId > 0) {
        $selectedPage = cvRouteSeoFetchPageById($connection, $selectedPageId);
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione pagine tratte: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Pagine tratte', 'settings-route-pages', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            Qui generi e controlli le pagine indicizzabili per le tratte più richieste.
            Le bozze nascono dai dati reali di ricerca, ma diventano pubbliche solo quando le approvi tu.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="cv-panel-card">
            <h4>Generazione bozze</h4>
            <p class="cv-muted">
                Le bozze vengono create o aggiornate partendo dalle tratte più richieste registrate nel database.
                Non pubblichiamo nulla in automatico: prima approvi tu, poi la pagina entra in sitemap.
            </p>
            <form method="post" class="form-inline" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <input type="hidden" name="action" value="generate_route_seo_pages">
                <?= cvAccessoCsrfField() ?>
                <div class="form-group">
                    <label for="generate_limit">Top tratte da elaborare</label>
                    <input id="generate_limit" type="number" min="1" max="100" name="generate_limit" value="20" class="form-control">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <button type="submit" class="btn btn-primary">Genera / aggiorna bozze</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Stato attuale</h4>
            <div class="row">
                <div class="col-sm-3">
                    <div class="cv-stat-card">
                        <div class="cv-stat-value"><?= (int) ($stats['total'] ?? 0) ?></div>
                        <div class="cv-stat-label">Totali</div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="cv-stat-card">
                        <div class="cv-stat-value"><?= (int) ($stats['approved'] ?? 0) ?></div>
                        <div class="cv-stat-label">Pubbliche</div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="cv-stat-card">
                        <div class="cv-stat-value"><?= (int) ($stats['draft'] ?? 0) ?></div>
                        <div class="cv-stat-label">Bozze</div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="cv-stat-card">
                        <div class="cv-stat-value"><?= (int) ($stats['archived'] ?? 0) ?></div>
                        <div class="cv-stat-label">Archiviate</div>
                    </div>
                </div>
            </div>
            <div class="cv-muted" style="margin-top:10px;">
                Quando salvi o pubblichi una pagina, <code>sitemap.xml</code> viene rigenerata automaticamente.
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="cv-panel-card">
            <h4>Elenco pagine tratta</h4>
            <?php if (count($pages) === 0): ?>
                <div class="cv-empty">Nessuna bozza disponibile. Genera le prime pagine dalle tratte più cercate.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table cv-table">
                        <thead>
                        <tr>
                            <th>Tratta</th>
                            <th>Stato</th>
                            <th>Domanda</th>
                            <th>Prezzo</th>
                            <th>Aggiornata</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pages as $page): ?>
                            <?php
                            $pageId = (int) ($page['id_route_seo_page'] ?? 0);
                            $status = (string) ($page['status'] ?? 'draft');
                            $statusLabel = $status === 'approved' ? 'Pubblica' : ($status === 'archived' ? 'Archiviata' : 'Bozza');
                            $statusClass = $status === 'approved' ? 'cv-home-badge-ok' : ($status === 'archived' ? 'cv-home-badge-off' : 'cv-home-badge-info');
                            $rowStyle = $selectedPageId === $pageId ? ' style="background:#f3f8fd;"' : '';
                            ?>
                            <tr<?= $rowStyle ?>>
                                <td>
                                    <strong>
                                        <a href="<?= cvAccessoH(cvAccessoUrl('tratte-seo.php?page=' . $pageId)) ?>"><?= cvAccessoH((string) ($page['from_name'] ?? '')) ?> → <?= cvAccessoH((string) ($page['to_name'] ?? '')) ?></a>
                                    </strong>
                                    <br>
                                    <code><?= cvAccessoH((string) ($page['slug'] ?? '')) ?></code>
                                </td>
                                <td><span class="cv-home-badge <?= cvAccessoH($statusClass) ?>"><?= cvAccessoH($statusLabel) ?></span></td>
                                <td><?= (int) ($page['search_count_snapshot'] ?? 0) ?></td>
                                <td><?= cvAccessoH((string) ($page['price_label'] ?? '')) ?></td>
                                <td><?= cvAccessoH((string) ($page['updated_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-5">
        <div class="cv-panel-card">
            <?php if (!is_array($selectedPage)): ?>
                <div class="cv-empty">Seleziona una pagina tratta dalla tabella per modificare testi, immagine e stato di pubblicazione.</div>
            <?php else: ?>
                <h4>Editor pagina tratta</h4>
                <div class="cv-muted" style="margin-bottom:10px;">
                    La pagina pubblica resta standard se lasci i campi vuoti. Gli override manuali hanno priorità sul testo auto-generato.
                </div>

                <div class="cv-doc-note" style="margin-bottom:12px;">
                    <strong><?= cvAccessoH((string) ($selectedPage['from_name'] ?? '')) ?> → <?= cvAccessoH((string) ($selectedPage['to_name'] ?? '')) ?></strong><br>
                    URL pubblico: <a href="<?= cvAccessoH((string) ($selectedPage['public_url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer"><?= cvAccessoH((string) ($selectedPage['public_url'] ?? '')) ?></a><br>
                    Link ricerca: <a href="<?= cvAccessoH((string) ($selectedPage['search_url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">Apri la ricerca di questa tratta</a>
                    <?php if ((string) ($selectedPage['status'] ?? '') !== 'approved'): ?>
                        <br><strong>Nota:</strong> finché lo stato è <code><?= cvAccessoH((string) ($selectedPage['status'] ?? 'draft')) ?></code>, la URL pubblica resta in modalità bozza/non indicizzabile.
                    <?php endif; ?>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_route_seo_page">
                    <input type="hidden" name="page_id" value="<?= (int) ($selectedPage['id_route_seo_page'] ?? 0) ?>">
                    <?= cvAccessoCsrfField() ?>

                    <div class="form-group">
                        <label for="slug">Slug</label>
                        <input id="slug" name="slug" class="form-control" value="<?= cvAccessoH((string) ($selectedPage['slug'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label for="status">Stato</label>
                        <select id="status" name="status" class="form-control">
                            <?php foreach (['draft' => 'Bozza', 'approved' => 'Pubblica', 'archived' => 'Archiviata'] as $statusValue => $statusLabel): ?>
                                <option value="<?= cvAccessoH($statusValue) ?>"<?= (string) ($selectedPage['status'] ?? '') === $statusValue ? ' selected' : '' ?>><?= cvAccessoH($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="hero_image_path">Immagine hero</label>
                        <input id="hero_image_path" name="hero_image_path" class="form-control" value="<?= cvAccessoH((string) ($selectedPage['hero_image_path'] ?? '')) ?>" placeholder="images/seo/tratta.jpg oppure URL completa">
                        <div class="cv-muted" style="margin-top:6px;">Se lasci vuoto, la pagina usa solo l’impostazione grafica standard.</div>
                    </div>

                    <div class="form-group">
                        <label for="hero_image_upload">Carica immagine hero</label>
                        <input id="hero_image_upload" type="file" name="hero_image_upload" class="form-control" accept=".jpg,.jpeg,.png,.webp,.svg">
                        <div class="cv-muted" style="margin-top:6px;">Upload diretto nel progetto: <code>assets/images/seo/</code>. Se carichi un file, sovrascrive il valore del campo sopra.</div>
                        <?php if (trim((string) ($selectedPage['hero_image_url'] ?? '')) !== ''): ?>
                            <div style="margin-top:8px;">
                                <img src="<?= cvAccessoH((string) $selectedPage['hero_image_url']) ?>" alt="Anteprima hero" style="max-width:100%; border-radius:10px; border:1px solid #d7e2ec;">
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="title_override">Titolo SEO override</label>
                        <input id="title_override" name="title_override" class="form-control" value="<?= cvAccessoH((string) ($selectedPage['title_override'] ?? '')) ?>" placeholder="<?= cvAccessoH((string) ($selectedPage['auto_title'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label for="meta_description_override">Meta description override</label>
                        <textarea id="meta_description_override" name="meta_description_override" class="form-control" rows="3" placeholder="<?= cvAccessoH((string) ($selectedPage['auto_meta_description'] ?? '')) ?>"><?= cvAccessoH((string) ($selectedPage['meta_description_override'] ?? '')) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="intro_override">Testo intro override</label>
                        <textarea id="intro_override" name="intro_override" class="form-control" rows="5" placeholder="<?= cvAccessoH((string) ($selectedPage['auto_intro'] ?? '')) ?>"><?= cvAccessoH((string) ($selectedPage['intro_override'] ?? '')) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="body_override">Corpo pagina override</label>
                        <textarea id="body_override" name="body_override" class="form-control" rows="10" placeholder="<?= cvAccessoH((string) ($selectedPage['auto_body'] ?? '')) ?>"><?= cvAccessoH((string) ($selectedPage['body_override'] ?? '')) ?></textarea>
                    </div>

                    <div class="cv-inline-actions">
                        <button type="submit" class="btn btn-primary">Salva pagina</button>
                    </div>
                </form>

                <form method="post" onsubmit="return confirm('Eliminare questa pagina tratta?');" style="margin-top:10px;">
                    <input type="hidden" name="action" value="delete_route_seo_page">
                    <input type="hidden" name="page_id" value="<?= (int) ($selectedPage['id_route_seo_page'] ?? 0) ?>">
                    <?= cvAccessoCsrfField() ?>
                    <button type="submit" class="btn btn-default">Elimina pagina</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (is_array($selectedPage)): ?>
            <div class="cv-panel-card" style="margin-top:15px;">
                <h4>Contenuto automatico attuale</h4>
                <div class="cv-muted"><strong>Titolo</strong><br><?= cvAccessoH((string) ($selectedPage['auto_title'] ?? '')) ?></div>
                <div class="cv-muted" style="margin-top:10px;"><strong>Meta description</strong><br><?= cvAccessoH((string) ($selectedPage['auto_meta_description'] ?? '')) ?></div>
                <div class="cv-muted" style="margin-top:10px;"><strong>Intro</strong><br><?= nl2br(cvAccessoH((string) ($selectedPage['auto_intro'] ?? ''))) ?></div>
                <div class="cv-muted" style="margin-top:10px;"><strong>Body</strong><br><?= nl2br(cvAccessoH((string) ($selectedPage['auto_body'] ?? ''))) ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
cvAccessoRenderPageEnd();
