<?php
declare(strict_types=1);

if (!function_exists('cvBlogEnsureTable')) {
    function cvBlogEnsureTable(mysqli $connection): bool
    {
        static $initialized = null;
        if (is_bool($initialized)) {
            return $initialized;
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_blog_posts (
  id_blog_post BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL,
  excerpt TEXT DEFAULT NULL,
  content_html MEDIUMTEXT DEFAULT NULL,
  content_blocks_json LONGTEXT DEFAULT NULL,
  hero_image_path VARCHAR(255) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  sort_order INT UNSIGNED NOT NULL DEFAULT 100,
  published_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_blog_post),
  UNIQUE KEY uq_cv_blog_posts_slug (slug),
  KEY idx_cv_blog_posts_status (status, published_at),
  KEY idx_cv_blog_posts_order (sort_order, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $initialized = $connection->query($sql) === true;
        if (!$initialized) {
            error_log('cvBlogEnsureTable error: ' . $connection->error);
            return false;
        }

        $columnCheck = $connection->query("SHOW COLUMNS FROM cv_blog_posts LIKE 'content_blocks_json'");
        if ($columnCheck instanceof mysqli_result) {
            $hasColumn = $columnCheck->num_rows > 0;
            $columnCheck->free();
            if (!$hasColumn) {
                if (!$connection->query("ALTER TABLE cv_blog_posts ADD COLUMN content_blocks_json LONGTEXT DEFAULT NULL AFTER content_html")) {
                    error_log('cvBlogEnsureTable alter error: ' . $connection->error);
                }
            }
        }
        return $initialized;
    }
}

if (!function_exists('cvBlogNormalizeStatus')) {
    function cvBlogNormalizeStatus(string $status): string
    {
        $value = strtolower(trim($status));
        if (!in_array($value, ['draft', 'published', 'archived'], true)) {
            return 'draft';
        }
        return $value;
    }
}

if (!function_exists('cvBlogSlugify')) {
    function cvBlogSlugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        return $value !== '' ? $value : 'articolo';
    }
}

if (!function_exists('cvBlogUniqueSlug')) {
    function cvBlogUniqueSlug(mysqli $connection, string $baseSlug, int $excludeId = 0): string
    {
        $slug = cvBlogSlugify($baseSlug);
        if ($slug === '') {
            $slug = 'articolo';
        }
        $candidate = $slug;
        $index = 2;

        while (true) {
            if ($excludeId > 0) {
                $statement = $connection->prepare('SELECT id_blog_post FROM cv_blog_posts WHERE slug = ? AND id_blog_post <> ? LIMIT 1');
                if (!$statement instanceof mysqli_stmt) {
                    return $candidate;
                }
                $statement->bind_param('si', $candidate, $excludeId);
            } else {
                $statement = $connection->prepare('SELECT id_blog_post FROM cv_blog_posts WHERE slug = ? LIMIT 1');
                if (!$statement instanceof mysqli_stmt) {
                    return $candidate;
                }
                $statement->bind_param('s', $candidate);
            }

            if (!$statement->execute()) {
                $statement->close();
                return $candidate;
            }

            $result = $statement->get_result();
            $exists = $result instanceof mysqli_result && $result->num_rows > 0;
            $statement->close();
            if (!$exists) {
                return $candidate;
            }

            $candidate = $slug . '-' . $index;
            $index++;
        }
    }
}

if (!function_exists('cvBlogFetchAdminPosts')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvBlogFetchAdminPosts(mysqli $connection): array
    {
        if (!cvBlogEnsureTable($connection)) {
            return [];
        }

        $result = $connection->query(
            'SELECT id_blog_post, slug, title, excerpt, content_blocks_json, hero_image_path, status, sort_order, published_at, created_at, updated_at
             FROM cv_blog_posts
             ORDER BY sort_order ASC, updated_at DESC'
        );
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $heroPath = trim((string) ($row['hero_image_path'] ?? ''));
            $row['hero_image_url'] = $heroPath !== '' ? cvRouteSeoResolveImageUrl($heroPath) : '';
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }
}

if (!function_exists('cvBlogFetchPostById')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvBlogFetchPostById(mysqli $connection, int $id): ?array
    {
        if ($id <= 0 || !cvBlogEnsureTable($connection)) {
            return null;
        }
        $statement = $connection->prepare(
            'SELECT id_blog_post, slug, title, excerpt, content_html, content_blocks_json, hero_image_path, status, sort_order, published_at, created_at, updated_at
             FROM cv_blog_posts WHERE id_blog_post = ? LIMIT 1'
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
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return null;
        }
        $row = $result->fetch_assoc();
        $statement->close();
        if (!is_array($row)) {
            return null;
        }
        $heroPath = trim((string) ($row['hero_image_path'] ?? ''));
        $row['hero_image_url'] = $heroPath !== '' ? cvRouteSeoResolveImageUrl($heroPath) : '';
        return $row;
    }
}

if (!function_exists('cvBlogSavePost')) {
    function cvBlogSavePost(mysqli $connection, int $id, array $payload): int
    {
        if (!cvBlogEnsureTable($connection)) {
            return 0;
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Titolo articolo obbligatorio.');
        }

        $status = cvBlogNormalizeStatus((string) ($payload['status'] ?? 'draft'));
        $sortOrder = max(1, (int) ($payload['sort_order'] ?? 100));
        $excerpt = trim((string) ($payload['excerpt'] ?? ''));
        $content = trim((string) ($payload['content_html'] ?? ''));
        $contentBlocksJson = trim((string) ($payload['content_blocks_json'] ?? ''));
        $heroImagePath = trim((string) ($payload['hero_image_path'] ?? ''));
        $slugBase = trim((string) ($payload['slug'] ?? ''));
        if ($slugBase === '') {
            $slugBase = $title;
        }
        $slug = cvBlogUniqueSlug($connection, $slugBase, $id);

        $publishedAt = null;
        if ($status === 'published') {
            $publishedAt = trim((string) ($payload['published_at'] ?? ''));
            if ($publishedAt === '') {
                $publishedAt = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
            }
        }

        if ($id > 0) {
            $statement = $connection->prepare(
                'UPDATE cv_blog_posts
                 SET slug = ?, title = ?, excerpt = ?, content_html = ?, content_blocks_json = ?, hero_image_path = ?, status = ?, sort_order = ?,
                     published_at = ?
                 WHERE id_blog_post = ?'
            );
            if (!$statement instanceof mysqli_stmt) {
                return 0;
            }
            $excerptValue = $excerpt !== '' ? $excerpt : null;
            $contentValue = $content !== '' ? $content : null;
            $blocksValue = $contentBlocksJson !== '' ? $contentBlocksJson : null;
            $heroValue = $heroImagePath !== '' ? $heroImagePath : null;
            $publishedValue = $publishedAt !== null ? $publishedAt : null;
            $statement->bind_param(
                'sssssssisi',
                $slug,
                $title,
                $excerptValue,
                $contentValue,
                $blocksValue,
                $heroValue,
                $status,
                $sortOrder,
                $publishedValue,
                $id
            );
            if (!$statement->execute()) {
                $statement->close();
                return 0;
            }
            $statement->close();
            return $id;
        }

        $statement = $connection->prepare(
            'INSERT INTO cv_blog_posts (slug, title, excerpt, content_html, content_blocks_json, hero_image_path, status, sort_order, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$statement instanceof mysqli_stmt) {
            return 0;
        }
        $excerptValue = $excerpt !== '' ? $excerpt : null;
        $contentValue = $content !== '' ? $content : null;
        $blocksValue = $contentBlocksJson !== '' ? $contentBlocksJson : null;
        $heroValue = $heroImagePath !== '' ? $heroImagePath : null;
        $publishedValue = $publishedAt !== null ? $publishedAt : null;
        $statement->bind_param('sssssssis', $slug, $title, $excerptValue, $contentValue, $blocksValue, $heroValue, $status, $sortOrder, $publishedValue);
        if (!$statement->execute()) {
            $statement->close();
            return 0;
        }
        $insertId = (int) $statement->insert_id;
        $statement->close();
        return $insertId;
    }
}

if (!function_exists('cvBlogDeletePost')) {
    function cvBlogDeletePost(mysqli $connection, int $id): bool
    {
        if ($id <= 0 || !cvBlogEnsureTable($connection)) {
            return false;
        }
        $statement = $connection->prepare('DELETE FROM cv_blog_posts WHERE id_blog_post = ?');
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }
        $statement->bind_param('i', $id);
        $ok = $statement->execute();
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvBlogDuplicatePost')) {
    function cvBlogDuplicatePost(mysqli $connection, int $id): int
    {
        if ($id <= 0 || !cvBlogEnsureTable($connection)) {
            return 0;
        }

        $source = cvBlogFetchPostById($connection, $id);
        if (!is_array($source)) {
            return 0;
        }

        $sourceTitle = trim((string) ($source['title'] ?? ''));
        $newTitle = $sourceTitle !== '' ? ($sourceTitle . ' (copia)') : 'Articolo (copia)';
        $newSortOrder = max(1, (int) ($source['sort_order'] ?? 100) + 1);

        return cvBlogSavePost($connection, 0, [
            'slug' => (string) ($source['slug'] ?? ''),
            'title' => $newTitle,
            'excerpt' => (string) ($source['excerpt'] ?? ''),
            'content_html' => (string) ($source['content_html'] ?? ''),
            'content_blocks_json' => (string) ($source['content_blocks_json'] ?? ''),
            'hero_image_path' => (string) ($source['hero_image_path'] ?? ''),
            'status' => 'draft',
            'sort_order' => $newSortOrder,
        ]);
    }
}

if (!function_exists('cvBlogUpdateOrder')) {
    /**
     * @param array<int,int> $ids
     */
    function cvBlogUpdateOrder(mysqli $connection, array $ids): int
    {
        if (!cvBlogEnsureTable($connection)) {
            return 0;
        }
        $updated = 0;
        $order = 1;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $statement = $connection->prepare('UPDATE cv_blog_posts SET sort_order = ? WHERE id_blog_post = ?');
            if (!$statement instanceof mysqli_stmt) {
                continue;
            }
            $statement->bind_param('ii', $order, $id);
            if ($statement->execute()) {
                $updated++;
            }
            $statement->close();
            $order++;
        }
        return $updated;
    }
}

if (!function_exists('cvBlogFetchPublishedPosts')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvBlogFetchPublishedPosts(mysqli $connection, int $limit = 100): array
    {
        if (!cvBlogEnsureTable($connection)) {
            return [];
        }
        $safeLimit = max(1, min(500, $limit));
        $result = $connection->query(
            "SELECT id_blog_post, slug, title, excerpt, content_html, content_blocks_json, hero_image_path, published_at, updated_at
             FROM cv_blog_posts
             WHERE status = 'published'
             ORDER BY sort_order ASC, COALESCE(published_at, updated_at) DESC
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
            $heroPath = trim((string) ($row['hero_image_path'] ?? ''));
            $row['hero_image_url'] = $heroPath !== '' ? cvRouteSeoResolveImageUrl($heroPath) : '';
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }
}

if (!function_exists('cvBlogFetchPublishedPostBySlug')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvBlogFetchPublishedPostBySlug(mysqli $connection, string $slug): ?array
    {
        if (!cvBlogEnsureTable($connection)) {
            return null;
        }
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $statement = $connection->prepare(
            "SELECT id_blog_post, slug, title, excerpt, content_html, content_blocks_json, hero_image_path, published_at, updated_at
             FROM cv_blog_posts
             WHERE slug = ? AND status = 'published'
             LIMIT 1"
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
        $heroPath = trim((string) ($row['hero_image_path'] ?? ''));
        $row['hero_image_url'] = $heroPath !== '' ? cvRouteSeoResolveImageUrl($heroPath) : '';
        return $row;
    }
}

if (!function_exists('cvBlogDecodeBlocks')) {
    /**
     * @return array<int,array<string,string>>
     */
    function cvBlogDecodeBlocks(string $json): array
    {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) {
            return [];
        }

        $blocks = [];
        foreach ($decoded as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = strtolower(trim((string) ($block['type'] ?? '')));
            if (!in_array($type, ['heading', 'text', 'list', 'image', 'image_row', 'quote', 'separator', 'html'], true)) {
                continue;
            }
            $value = (string) ($block['value'] ?? '');
            $blocks[] = ['type' => $type, 'value' => $value];
        }
        return $blocks;
    }
}

if (!function_exists('cvBlogRenderBlocksHtml')) {
    /**
     * @return array{mode:string,images:array<int,string>}
     */
    function cvBlogParseImageRowValue(string $value): array
    {
        $lines = preg_split('/\R+/u', trim($value)) ?: [];
        $mode = 'static';
        if (count($lines) > 0) {
            $first = strtolower(trim((string) $lines[0]));
            if ($first === '[mode:gallery]') {
                $mode = 'gallery';
                array_shift($lines);
            } elseif ($first === '[mode:static]') {
                $mode = 'static';
                array_shift($lines);
            }
        }

        $images = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $imgSrc = cvRouteSeoResolveImageUrl($line);
            if ($imgSrc === '') {
                continue;
            }
            $images[] = $imgSrc;
            if (count($images) >= 5) {
                break;
            }
        }

        return [
            'mode' => $mode,
            'images' => $images,
        ];
    }

    function cvBlogRenderBlocksHtml(array $blocks): string
    {
        $html = [];
        $galleryCounter = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = strtolower(trim((string) ($block['type'] ?? '')));
            $value = trim((string) ($block['value'] ?? ''));
            if ($type === 'separator') {
                $html[] = '<hr class="cv-blog-separator">';
                continue;
            }
            if ($value === '') {
                continue;
            }

            if ($type === 'heading') {
                $html[] = '<h2 class="cv-blog-block cv-blog-block-heading">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</h2>';
            } elseif ($type === 'text') {
                $html[] = '<div class="cv-blog-block cv-blog-block-text">' . cvRouteSeoTextToHtml($value) . '</div>';
            } elseif ($type === 'list') {
                $items = preg_split('/\R+/u', $value) ?: [];
                $listItems = [];
                foreach ($items as $item) {
                    $item = trim((string) $item);
                    if ($item === '') {
                        continue;
                    }
                    $listItems[] = '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
                }
                if (count($listItems) > 0) {
                    $html[] = '<ul class="cv-blog-block cv-blog-block-list">' . implode('', $listItems) . '</ul>';
                }
            } elseif ($type === 'quote') {
                $html[] = '<blockquote class="cv-blog-block cv-blog-block-quote">' . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) . '</blockquote>';
            } elseif ($type === 'html') {
                $html[] = '<div class="cv-blog-block cv-blog-block-html">' . $value . '</div>';
            } elseif ($type === 'image') {
                $imgSrc = cvRouteSeoResolveImageUrl($value);
                if ($imgSrc !== '') {
                    $html[] = '<figure class="cv-blog-block cv-blog-block-image"><img src="' . htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></figure>';
                }
            } elseif ($type === 'image_row') {
                $parsed = cvBlogParseImageRowValue($value);
                $images = $parsed['images'];
                if (count($images) > 0) {
                    $cols = count($images);
                    $mode = $parsed['mode'] === 'gallery' ? 'gallery' : 'static';
                    if ($mode === 'gallery') {
                        $galleryCounter++;
                        $galleryJson = htmlspecialchars((string) json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                        $itemsHtml = [];
                        foreach ($images as $idx => $imgSrc) {
                            $itemsHtml[] = '<button type="button" class="cv-blog-image-row-open" data-gallery-id="cv_blog_gallery_' . $galleryCounter . '" data-images="' . $galleryJson . '" data-index="' . $idx . '"><img src="' . htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></button>';
                        }
                        $html[] = '<div class="cv-blog-block cv-blog-block-image-row is-gallery" style="--cv-image-row-cols:' . $cols . ';">' . implode('', $itemsHtml) . '</div>';
                    } else {
                        $itemsHtml = [];
                        foreach ($images as $imgSrc) {
                            $itemsHtml[] = '<img src="' . htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">';
                        }
                        $html[] = '<div class="cv-blog-block cv-blog-block-image-row" style="--cv-image-row-cols:' . $cols . ';">' . implode('', $itemsHtml) . '</div>';
                    }
                }
            }
        }
        return implode("\n", $html);
    }
}

if (!function_exists('cvBlogSitemapEntries')) {
    /**
     * @return array<int,array<string,string>>
     */
    function cvBlogSitemapEntries(mysqli $connection): array
    {
        if (!cvBlogEnsureTable($connection)) {
            return [];
        }
        $entries = [];
        $entries[] = [
            'path' => 'blog',
            'file' => 'blog.php',
            'changefreq' => 'weekly',
            'priority' => '0.7',
            'lastmod' => date('c'),
        ];
        $result = $connection->query(
            "SELECT slug, updated_at
             FROM cv_blog_posts
             WHERE status = 'published'
             ORDER BY updated_at DESC"
        );
        if (!$result instanceof mysqli_result) {
            return $entries;
        }
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $entries[] = [
                'path' => 'blog/' . $slug,
                'file' => 'articolo.php',
                'changefreq' => 'weekly',
                'priority' => '0.65',
                'lastmod' => date('c', (int) (strtotime((string) ($row['updated_at'] ?? '')) ?: time())),
            ];
        }
        $result->free();
        return $entries;
    }
}
