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
    cvAccessoRenderPageStart('Blog', 'settings-blog', $state);
    echo '<div class="cv-empty">Questa sezione e disponibile solo per l amministratore.</div>';
    cvAccessoRenderPageEnd();
    return;
}

function cvAccessoBlogCompressUpload(string $tmpPath, string $targetPath, string $extension): bool
{
    if (!function_exists('imagecreatefromjpeg')) {
        return false;
    }
    $extension = strtolower($extension);
    $source = null;
    if (in_array($extension, ['jpg', 'jpeg'], true)) {
        $source = @imagecreatefromjpeg($tmpPath);
    } elseif ($extension === 'png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($tmpPath);
    } elseif ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($tmpPath);
    }
    if (!$source) {
        return false;
    }
    $w = imagesx($source);
    $h = imagesy($source);
    $maxW = 1920;
    $maxH = 1200;
    $scale = min(1.0, min($maxW / max(1, $w), $maxH / max(1, $h)));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
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
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($source);
    $ok = false;
    if ($extension === 'png') {
        $ok = @imagepng($canvas, $targetPath, 7);
    } elseif ($extension === 'webp' && function_exists('imagewebp')) {
        $ok = @imagewebp($canvas, $targetPath, 78);
    } else {
        $ok = @imagejpeg($canvas, $targetPath, 78);
    }
    imagedestroy($canvas);
    return $ok;
}

/**
 * @return string|null
 */
function cvAccessoBlogUploadHero(int $postId): ?string
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
        throw new RuntimeException('Upload immagine blog non riuscito.');
    }
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Upload immagine blog non valido.');
    }
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
        throw new RuntimeException('Formato immagine blog non supportato.');
    }

    $targetDir = dirname(__DIR__) . '/assets/images/blog';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Impossibile creare directory immagini blog.');
    }
    $name = 'blog-' . max(1, $postId) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
    $target = $targetDir . '/' . $name;

    $compressed = false;
    if ($extension !== 'svg') {
        $compressed = cvAccessoBlogCompressUpload($tmpPath, $target, $extension);
    }
    if (!$compressed && !move_uploaded_file($tmpPath, $target)) {
        throw new RuntimeException('Impossibile salvare immagine blog.');
    }
    return 'images/blog/' . $name;
}

/**
 * @return array<int,array{type:string,value:string}>
 */
function cvAccessoBlogNormalizeBlocksFromJson(string $json): array
{
    $blocks = function_exists('cvBlogDecodeBlocks') ? cvBlogDecodeBlocks($json) : [];
    if (!is_array($blocks)) {
        return [];
    }
    $normalized = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = strtolower(trim((string) ($block['type'] ?? '')));
        if (!in_array($type, ['heading', 'text', 'list', 'image', 'image_row', 'quote', 'separator', 'html'], true)) {
            continue;
        }
        $value = (string) ($block['value'] ?? '');
        $normalized[] = ['type' => $type, 'value' => $value];
    }
    return $normalized;
}

/**
 * @return string[]
 */
function cvAccessoBlogUploadContentImages(int $postId, string $fieldName = 'content_images_upload', int $maxFiles = 20): array
{
    $result = [];
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $result;
    }

    $files = $_FILES[$fieldName];
    $names = isset($files['name']) && is_array($files['name']) ? $files['name'] : [];
    $tmpNames = isset($files['tmp_name']) && is_array($files['tmp_name']) ? $files['tmp_name'] : [];
    $errors = isset($files['error']) && is_array($files['error']) ? $files['error'] : [];
    $maxFiles = max(1, min(30, $maxFiles));

    $targetDir = dirname(__DIR__) . '/assets/images/blog';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Impossibile creare directory immagini blog.');
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
    foreach ($names as $idx => $originalNameRaw) {
        if (count($result) >= $maxFiles) {
            break;
        }
        $errorCode = (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload immagini contenuto non riuscito.');
        }

        $tmpPath = (string) ($tmpNames[$idx] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            continue;
        }

        $originalName = (string) $originalNameRaw;
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            continue;
        }

        $name = 'blog-block-' . max(1, $postId) . '-' . date('Ymd-His') . '-' . $idx . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
        $target = $targetDir . '/' . $name;

        $compressed = false;
        if ($extension !== 'svg') {
            $compressed = cvAccessoBlogCompressUpload($tmpPath, $target, $extension);
        }
        if (!$compressed && !move_uploaded_file($tmpPath, $target)) {
            continue;
        }
        $result[] = 'images/blog/' . $name;
    }

    return $result;
}

$selectedId = max(0, (int) ($_GET['post'] ?? $_POST['post_id'] ?? 0));
$posts = [];
$selectedPost = null;
$selectedBlocks = [];

try {
    $connection = cvAccessoRequireConnection();
    cvBlogEnsureTable($connection);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $action = trim((string) $_POST['action']);
            if ($action === 'save_blog_post') {
                $postId = max(0, (int) ($_POST['post_id'] ?? 0));
                $uploaded = cvAccessoBlogUploadHero($postId);
                $hero = (string) ($_POST['hero_image_path'] ?? '');
                if (is_string($uploaded) && $uploaded !== '') {
                    $hero = $uploaded;
                }

                $blocks = cvAccessoBlogNormalizeBlocksFromJson((string) ($_POST['content_blocks_json'] ?? ''));
                $uploadedContentImages = cvAccessoBlogUploadContentImages($postId, 'content_images_upload', 20);
                foreach ($uploadedContentImages as $uploadedImagePath) {
                    $blocks[] = [
                        'type' => 'image',
                        'value' => $uploadedImagePath,
                    ];
                }
                $uploadedImageRow = cvAccessoBlogUploadContentImages($postId, 'content_image_row_upload', 5);
                if (count($uploadedImageRow) > 0) {
                    $blocks[] = [
                        'type' => 'image_row',
                        'value' => '[mode:static]' . "\n" . implode("\n", array_slice($uploadedImageRow, 0, 5)),
                    ];
                }
                $blocksJson = count($blocks) > 0
                    ? (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '';

                $savedId = cvBlogSavePost($connection, $postId, [
                    'slug' => (string) ($_POST['slug'] ?? ''),
                    'title' => (string) ($_POST['title'] ?? ''),
                    'excerpt' => (string) ($_POST['excerpt'] ?? ''),
                    'content_html' => (string) ($_POST['content_html'] ?? ''),
                    'content_blocks_json' => $blocksJson,
                    'hero_image_path' => $hero,
                    'status' => (string) ($_POST['status'] ?? 'draft'),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 100),
                ]);
                if ($savedId <= 0) {
                    throw new RuntimeException('Salvataggio articolo non riuscito.');
                }
                cvSitemapWriteFile();
                $selectedId = $savedId;
                $state['messages'][] = 'Articolo salvato.';
            } elseif ($action === 'delete_blog_post') {
                $postId = max(0, (int) ($_POST['post_id'] ?? 0));
                if ($postId > 0 && cvBlogDeletePost($connection, $postId)) {
                    cvSitemapWriteFile();
                    $state['messages'][] = 'Articolo eliminato.';
                    if ($selectedId === $postId) {
                        $selectedId = 0;
                    }
                }
            } elseif ($action === 'duplicate_blog_post') {
                $postId = max(0, (int) ($_POST['post_id'] ?? 0));
                $newId = $postId > 0 ? cvBlogDuplicatePost($connection, $postId) : 0;
                if ($newId > 0) {
                    $selectedId = $newId;
                    $state['messages'][] = 'Articolo duplicato in bozza.';
                } else {
                    $state['errors'][] = 'Duplicazione articolo non riuscita.';
                }
            } elseif ($action === 'reorder_blog_posts') {
                $orderRaw = trim((string) ($_POST['order'] ?? ''));
                $ids = [];
                foreach (explode(',', $orderRaw) as $idToken) {
                    $id = (int) trim($idToken);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                $updated = cvBlogUpdateOrder($connection, $ids);
                $state['messages'][] = 'Ordine articoli aggiornato (' . $updated . ').';
            }
        }
    }

    $posts = cvBlogFetchAdminPosts($connection);
    if ($selectedId <= 0 && count($posts) > 0) {
        $selectedId = (int) ($posts[0]['id_blog_post'] ?? 0);
    }
    if ($selectedId > 0) {
        $selectedPost = cvBlogFetchPostById($connection, $selectedId);
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione blog: ' . $exception->getMessage();
}

if (is_array($selectedPost)) {
    $selectedBlocks = cvAccessoBlogNormalizeBlocksFromJson((string) ($selectedPost['content_blocks_json'] ?? ''));
    if (count($selectedBlocks) === 0) {
        $legacyContent = trim((string) ($selectedPost['content_html'] ?? ''));
        if ($legacyContent !== '') {
            $selectedBlocks = [
                [
                    'type' => 'text',
                    'value' => $legacyContent,
                ],
            ];
        }
    }
}

cvAccessoRenderPageStart('Blog', 'settings-blog', $state);
?>
<div class="row">
  <div class="col-md-7">
    <div class="cv-panel-card">
      <h4>Articoli blog</h4>
      <p class="cv-muted">Trascina le righe per cambiare l ordine di pubblicazione.</p>
      <?php if (count($posts) === 0): ?>
        <div class="cv-empty">Nessun articolo ancora.</div>
      <?php else: ?>
        <form method="post" id="blogOrderForm">
          <input type="hidden" name="action" value="reorder_blog_posts">
          <input type="hidden" name="order" id="blogOrderField" value="">
          <?= cvAccessoCsrfField() ?>
          <table class="table cv-table">
            <thead><tr><th></th><th>Titolo</th><th>Stato</th><th>Ordine</th></tr></thead>
            <tbody id="blogRows">
            <?php foreach ($posts as $post): ?>
              <?php $id = (int) ($post['id_blog_post'] ?? 0); ?>
              <tr draggable="true" data-id="<?= $id ?>">
                <td style="cursor:move;">&#x2630;</td>
                <td><a href="<?= cvAccessoH(cvAccessoUrl('blog.php?post=' . $id)) ?>"><?= cvAccessoH((string) ($post['title'] ?? '')) ?></a></td>
                <td><?= cvAccessoH((string) ($post['status'] ?? '')) ?></td>
                <td><?= (int) ($post['sort_order'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button type="submit" class="btn btn-default">Salva ordine</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-md-5">
    <div class="cv-panel-card">
      <h4><?= is_array($selectedPost) ? 'Modifica articolo' : 'Nuovo articolo' ?></h4>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_blog_post">
        <input type="hidden" name="post_id" value="<?= (int) ($selectedPost['id_blog_post'] ?? 0) ?>">
        <?= cvAccessoCsrfField() ?>
        <div class="form-group"><label>Titolo</label><input class="form-control" name="title" value="<?= cvAccessoH((string) ($selectedPost['title'] ?? '')) ?>"></div>
        <div class="form-group"><label>Slug</label><input class="form-control" name="slug" value="<?= cvAccessoH((string) ($selectedPost['slug'] ?? '')) ?>"></div>
        <div class="form-group"><label>Stato</label>
          <select class="form-control" name="status">
            <?php $statusValue = (string) ($selectedPost['status'] ?? 'draft'); ?>
            <option value="draft"<?= $statusValue === 'draft' ? ' selected' : '' ?>>Bozza</option>
            <option value="published"<?= $statusValue === 'published' ? ' selected' : '' ?>>Pubblicato</option>
            <option value="archived"<?= $statusValue === 'archived' ? ' selected' : '' ?>>Archiviato</option>
          </select>
        </div>
        <div class="form-group"><label>Ordine</label><input type="number" min="1" class="form-control" name="sort_order" value="<?= (int) ($selectedPost['sort_order'] ?? 100) ?>"></div>
        <div class="form-group"><label>Excerpt</label><textarea class="form-control" rows="3" name="excerpt"><?= cvAccessoH((string) ($selectedPost['excerpt'] ?? '')) ?></textarea></div>
        <div class="form-group">
          <label>Contenuto (editor a blocchi)</label>
          <div class="cv-block-toolbar">
            <button type="button" class="btn btn-default btn-sm" data-add-block="heading">+ Titolo sezione</button>
            <button type="button" class="btn btn-default btn-sm" data-add-block="text">+ Paragrafo</button>
            <button type="button" class="btn btn-default btn-sm" data-add-block="list">+ Lista</button>
            <button type="button" class="btn btn-default btn-sm" data-add-block="image">+ Immagine</button>
            <button type="button" class="btn btn-default btn-sm" data-add-block="image_row">+ Riga immagini</button>
            <button type="button" class="btn btn-default btn-sm" data-add-block="quote">+ Citazione</button>
            <button type="button" class="btn btn-default btn-sm" data-add-block="separator">+ Separatore</button>
            <button type="button" class="btn btn-default btn-sm" data-add-block="html">+ HTML</button>
          </div>
          <div id="blogBlocksEditor" class="cv-block-editor" data-initial='<?= cvAccessoH((string) json_encode($selectedBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'></div>
          <input type="hidden" name="content_blocks_json" id="contentBlocksJson" value="">
          <p class="cv-muted" style="margin-top:8px;">Editor a righe: aggiungi blocchi e riordina con i pulsanti su/giu. Le immagini multiple vengono inserite in coda.</p>
          <div class="cv-block-preview-wrap">
            <div class="cv-block-preview-title">Anteprima rapida</div>
            <div id="blogBlocksPreview" class="cv-block-preview"></div>
          </div>
        </div>
        <div class="form-group"><label>Upload immagini contenuto (multiple)</label><input type="file" class="form-control" name="content_images_upload[]" accept=".jpg,.jpeg,.png,.webp,.svg" multiple></div>
        <div class="form-group"><label>Upload riga immagini (max 5)</label><input type="file" class="form-control" name="content_image_row_upload[]" accept=".jpg,.jpeg,.png,.webp,.svg" multiple></div>
        <details class="form-group">
          <summary style="cursor:pointer;">Contenuto legacy (fallback)</summary>
          <textarea class="form-control" rows="6" name="content_html"><?= cvAccessoH((string) ($selectedPost['content_html'] ?? '')) ?></textarea>
        </details>
        <div class="form-group"><label>Path immagine</label><input class="form-control" name="hero_image_path" value="<?= cvAccessoH((string) ($selectedPost['hero_image_path'] ?? '')) ?>"></div>
        <div class="form-group"><label>Upload immagine</label><input type="file" class="form-control" name="hero_image_upload" accept=".jpg,.jpeg,.png,.webp,.svg"></div>
        <?php if (is_array($selectedPost) && trim((string) ($selectedPost['hero_image_url'] ?? '')) !== ''): ?>
          <img src="<?= cvAccessoH((string) $selectedPost['hero_image_url']) ?>" alt="" style="max-width:100%;border:1px solid #d7e2ec;border-radius:8px;margin-bottom:10px;">
        <?php endif; ?>
        <div class="cv-inline-actions">
          <button type="submit" class="btn btn-primary">Salva articolo</button>
        </div>
      </form>
      <?php if (is_array($selectedPost)): ?>
        <form method="post" style="margin-top:10px;">
          <input type="hidden" name="action" value="duplicate_blog_post">
          <input type="hidden" name="post_id" value="<?= (int) ($selectedPost['id_blog_post'] ?? 0) ?>">
          <?= cvAccessoCsrfField() ?>
          <button type="submit" class="btn btn-default">Duplica articolo</button>
        </form>
        <form method="post" onsubmit="return confirm('Eliminare articolo?');" style="margin-top:10px;">
          <input type="hidden" name="action" value="delete_blog_post">
          <input type="hidden" name="post_id" value="<?= (int) ($selectedPost['id_blog_post'] ?? 0) ?>">
          <?= cvAccessoCsrfField() ?>
          <button type="submit" class="btn btn-default">Elimina</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
  (function () {
    const tbody = document.getElementById('blogRows');
    const orderField = document.getElementById('blogOrderField');
    if (!tbody || !orderField) return;
    let dragRow = null;
    tbody.querySelectorAll('tr').forEach((row) => {
      row.addEventListener('dragstart', () => { dragRow = row; row.classList.add('dragging'); });
      row.addEventListener('dragend', () => { row.classList.remove('dragging'); dragRow = null; refreshOrder(); });
      row.addEventListener('dragover', (event) => {
        event.preventDefault();
        if (!dragRow || dragRow === row) return;
        const rect = row.getBoundingClientRect();
        const after = (event.clientY - rect.top) > rect.height / 2;
        if (after) row.after(dragRow); else row.before(dragRow);
      });
    });
    function refreshOrder() {
      const ids = [];
      tbody.querySelectorAll('tr[data-id]').forEach((row) => ids.push(row.getAttribute('data-id')));
      orderField.value = ids.join(',');
    }
    refreshOrder();
  })();

  (function () {
    const editor = document.getElementById('blogBlocksEditor');
    const hiddenField = document.getElementById('contentBlocksJson');
    if (!editor || !hiddenField) return;
    const escHtml = (text) => String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

    let blocks = [];
    try {
      const initial = JSON.parse(editor.getAttribute('data-initial') || '[]');
        if (Array.isArray(initial)) {
          blocks = initial.map((item) => ({
            type: String(item.type || 'text').toLowerCase(),
            value: String(item.value || '')
          })).filter((item) => ['heading', 'text', 'list', 'image', 'image_row', 'quote', 'separator', 'html'].includes(item.type));
        }
    } catch (e) {
      blocks = [];
    }

    const preview = document.getElementById('blogBlocksPreview');

    const parseImageRowValue = (rawValue) => {
      const lines = String(rawValue || '').split(/\r?\n/);
      let mode = 'static';
      if (lines.length > 0) {
        const first = String(lines[0] || '').trim().toLowerCase();
        if (first === '[mode:gallery]') {
          mode = 'gallery';
          lines.shift();
        } else if (first === '[mode:static]') {
          mode = 'static';
          lines.shift();
        }
      }
      return {
        mode,
        imagesText: lines.join('\n').trim()
      };
    };

    const composeImageRowValue = (mode, imagesText) => {
      const cleanMode = mode === 'gallery' ? 'gallery' : 'static';
      const body = String(imagesText || '').trim();
      if (!body) return '';
      return '[mode:' + cleanMode + ']\n' + body;
    };

    const createField = (block, idx) => {
      const value = block.value || '';
      if (block.type === 'separator') {
        return '<div class="cv-block-separator-preview">Separatore orizzontale</div>';
      }
      const placeholderMap = {
        heading: 'Titolo sezione...',
        text: 'Testo paragrafo...',
        list: 'Un elemento per riga...',
        image: 'Path immagine (es: images/blog/file.jpg)',
        image_row: 'Una path per riga (max 5 immagini)...',
        quote: 'Testo citazione...',
        html: 'HTML custom...'
      };
      if (block.type === 'image_row') {
        const parsed = parseImageRowValue(value);
        return '' +
          '<div class="cv-block-image-row-controls">' +
            '<label class="cv-label" style="margin:0 0 6px 0;">Modalita riga immagini</label>' +
            '<select class="form-control cv-block-image-row-mode" data-idx="' + idx + '">' +
              '<option value="static"' + (parsed.mode === 'static' ? ' selected' : '') + '>Immagini statiche</option>' +
              '<option value="gallery"' + (parsed.mode === 'gallery' ? ' selected' : '') + '>Apri in galleria modale</option>' +
            '</select>' +
          '</div>' +
          '<textarea class="form-control cv-block-value" data-idx="' + idx + '" rows="5" placeholder="' + escHtml(placeholderMap.image_row || '') + '">' + escHtml(parsed.imagesText) + '</textarea>';
      }
      const rows = block.type === 'html' ? 5 : (block.type === 'text' ? 4 : (block.type === 'list' ? 4 : (block.type === 'image_row' ? 5 : 2)));
      return '<textarea class="form-control cv-block-value" data-idx="' + idx + '" rows="' + rows + '" placeholder="' + escHtml(placeholderMap[block.type] || '') + '">' + escHtml(value) + '</textarea>';
    };

    const renderPreview = () => {
      if (!preview) return;
      const html = [];
      blocks.forEach((block) => {
        const value = String(block.value || '').trim();
        if (block.type === 'separator') {
          html.push('<hr>');
          return;
        }
        if (value === '') return;
        if (block.type === 'heading') {
          html.push('<h4>' + escHtml(value) + '</h4>');
        } else if (block.type === 'text') {
          html.push('<p>' + escHtml(value).replace(/\n/g, '<br>') + '</p>');
        } else if (block.type === 'list') {
          const items = value.split(/\r?\n/).map((x) => x.trim()).filter(Boolean);
          if (items.length > 0) {
            html.push('<ul>' + items.map((item) => '<li>' + escHtml(item) + '</li>').join('') + '</ul>');
          }
        } else if (block.type === 'quote') {
          html.push('<blockquote>' + escHtml(value).replace(/\n/g, '<br>') + '</blockquote>');
        } else if (block.type === 'image') {
          html.push('<div class="cv-block-preview-image">' + escHtml(value) + '</div>');
        } else if (block.type === 'image_row') {
          const parsed = parseImageRowValue(value);
          const items = parsed.imagesText.split(/\r?\n/).map((x) => x.trim()).filter(Boolean).slice(0, 5);
          const modeLabel = parsed.mode === 'gallery' ? 'galleria' : 'statiche';
          html.push('<div class="cv-block-preview-image-row">Riga immagini: ' + items.length + ' (' + modeLabel + ')</div>');
        } else if (block.type === 'html') {
          html.push('<div class="cv-block-preview-html">Blocco HTML custom</div>');
        }
      });
      preview.innerHTML = html.length ? html.join('') : '<div class="cv-muted">Nessun blocco inserito.</div>';
    };

    const render = () => {
      editor.innerHTML = '';
      blocks.forEach((block, idx) => {
        const row = document.createElement('div');
        row.className = 'cv-block-row';
        row.setAttribute('data-idx', String(idx));
        row.innerHTML =
          '<div class="cv-block-head">' +
            '<strong class="cv-block-type">' + block.type.toUpperCase() + '</strong>' +
            '<div class="cv-block-actions">' +
              '<button type="button" class="btn btn-default btn-xs" data-act="up" data-idx="' + idx + '">↑</button>' +
              '<button type="button" class="btn btn-default btn-xs" data-act="down" data-idx="' + idx + '">↓</button>' +
              '<button type="button" class="btn btn-default btn-xs" data-act="remove" data-idx="' + idx + '">Elimina</button>' +
            '</div>' +
          '</div>' +
          createField(block, idx);
        editor.appendChild(row);
      });
      serialize();
    };

    const serialize = () => {
      editor.querySelectorAll('.cv-block-row').forEach((row) => {
        const idx = Number(row.getAttribute('data-idx') || '-1');
        if (idx < 0 || !blocks[idx]) return;
        const block = blocks[idx];
        if (block.type === 'image_row') {
          const textarea = row.querySelector('.cv-block-value');
          const modeSelect = row.querySelector('.cv-block-image-row-mode');
          const imagesText = textarea ? textarea.value : '';
          const mode = modeSelect ? modeSelect.value : 'static';
          block.value = composeImageRowValue(mode, imagesText);
        } else {
          const textarea = row.querySelector('.cv-block-value');
          block.value = textarea ? (textarea.value || '') : '';
        }
      });
      hiddenField.value = JSON.stringify(blocks);
      renderPreview();
    };

    editor.addEventListener('input', (event) => {
      const target = event.target;
      if (target && target.classList && target.classList.contains('cv-block-value')) {
        serialize();
      }
    });

    editor.addEventListener('click', (event) => {
      const target = event.target;
      if (!target || !target.getAttribute) return;
      const action = target.getAttribute('data-act');
      const idx = Number(target.getAttribute('data-idx') || '-1');
      if (!action || idx < 0 || !blocks[idx]) return;

      if (action === 'remove') {
        blocks.splice(idx, 1);
      } else if (action === 'up' && idx > 0) {
        const temp = blocks[idx - 1];
        blocks[idx - 1] = blocks[idx];
        blocks[idx] = temp;
      } else if (action === 'down' && idx < blocks.length - 1) {
        const temp = blocks[idx + 1];
        blocks[idx + 1] = blocks[idx];
        blocks[idx] = temp;
      }
      render();
    });

    document.querySelectorAll('[data-add-block]').forEach((button) => {
      button.addEventListener('click', () => {
        const type = String(button.getAttribute('data-add-block') || '').toLowerCase();
        if (!['heading', 'text', 'list', 'image', 'image_row', 'quote', 'separator', 'html'].includes(type)) return;
        blocks.push({ type, value: '' });
        render();
      });
    });

    const form = editor.closest('form');
    if (form) {
      form.addEventListener('submit', () => {
        serialize();
      });
    }

    render();
  })();
</script>
<style>
  .cv-block-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 10px;
  }
  .cv-block-editor {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .cv-block-row {
    border: 1px solid #d7e2ec;
    border-radius: 8px;
    padding: 10px;
    background: #f9fbfd;
  }
  .cv-block-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
  }
  .cv-block-type {
    color: #325b84;
    letter-spacing: 0.04em;
    font-size: 11px;
  }
  .cv-block-actions {
    display: inline-flex;
    gap: 4px;
  }
  .cv-block-separator-preview {
    padding: 8px 10px;
    border: 1px dashed #b8cbdd;
    color: #607a96;
    border-radius: 6px;
    background: #fff;
    font-size: 12px;
  }
  .cv-block-preview-wrap {
    margin-top: 12px;
    border-top: 1px solid #dbe7f1;
    padding-top: 10px;
  }
  .cv-block-preview-title {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #5e7995;
    margin-bottom: 6px;
    font-weight: 700;
  }
  .cv-block-preview {
    border: 1px solid #d8e5f0;
    border-radius: 8px;
    background: #fff;
    padding: 10px;
    max-height: 280px;
    overflow: auto;
  }
  .cv-block-preview h4 {
    margin: 0 0 8px 0;
    font-size: 16px;
    color: #214f78;
  }
  .cv-block-preview p {
    margin: 0 0 8px 0;
    color: #2f4866;
  }
  .cv-block-preview ul {
    margin: 0 0 8px 18px;
    color: #2f4866;
  }
  .cv-block-preview blockquote {
    margin: 0 0 8px 0;
    padding: 6px 10px;
    border-left: 3px solid #8eb2d4;
    background: #f5f9fd;
    color: #335679;
  }
  .cv-block-preview-image,
  .cv-block-preview-image-row,
  .cv-block-preview-html {
    margin: 0 0 8px 0;
    border: 1px dashed #bfd2e5;
    background: #f8fbfe;
    border-radius: 6px;
    padding: 7px 9px;
    color: #557292;
    font-size: 12px;
  }
  .cv-block-image-row-controls {
    margin-bottom: 8px;
  }
</style>
<?php
cvAccessoRenderPageEnd();
