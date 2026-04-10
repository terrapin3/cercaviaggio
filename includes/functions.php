<?php
declare(strict_types=1);

require_once __DIR__ . '/place_tools.php';
require_once __DIR__ . '/runtime_settings.php';
if (is_file(dirname(__DIR__) . '/auth/config.php')) {
    require_once dirname(__DIR__) . '/auth/config.php';
}

if (!function_exists('cvProjectRootPath')) {
    function cvProjectRootPath(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('cvBasePath')) {
    function cvBasePath(): string
    {
        $projectRoot = realpath(cvProjectRootPath());
        if (!is_string($projectRoot) || $projectRoot === '') {
            return '';
        }

        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        if (is_string($documentRoot) && $documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
            $relative = trim(str_replace('\\', '/', substr($projectRoot, strlen($documentRoot))), '/');
            return $relative === '' ? '' : '/' . $relative;
        }

        $scriptFilename = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? trim(str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']), '/') : '';
        if (is_string($scriptFilename) && $scriptFilename !== '' && $scriptName !== '') {
            $scriptDirFs = realpath(dirname($scriptFilename));
            if (is_string($scriptDirFs) && $scriptDirFs !== '' && str_starts_with($scriptDirFs, $projectRoot)) {
                $relativeFs = trim(substr($scriptDirFs, strlen($projectRoot)), DIRECTORY_SEPARATOR);
                $upLevels = $relativeFs === '' ? 0 : count(array_filter(explode(DIRECTORY_SEPARATOR, $relativeFs), 'strlen'));
                $urlPath = $scriptName;
                for ($i = 0; $i < $upLevels; $i++) {
                    $urlPath = trim(str_replace('\\', '/', dirname('/' . $urlPath)), '/');
                }

                return $urlPath === '' ? '' : '/' . $urlPath;
            }
        }

        $scriptDir = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($scriptDir === '' || $scriptDir === '.') {
            return '';
        }

        return '/' . $scriptDir;
    }
}

if (!function_exists('cvBaseUrl')) {
    function cvIsHttpsRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return true;
        }

        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        if ($forwardedSsl === 'on' || $forwardedSsl === '1') {
            return true;
        }

        return false;
    }

    function cvBaseUrl(): string
    {
        $scheme = cvIsHttpsRequest() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
        return $scheme . '://' . $host . cvBasePath();
    }
}

if (!function_exists('cvRenderFaviconTags')) {
    function cvRenderFaviconTags(): string
    {
        $faviconSvg = htmlspecialchars(cvAssetRelativeUrl('images/favicon.svg'), ENT_QUOTES, 'UTF-8');
        return '<link rel="icon" href="' . $faviconSvg . '" type="image/svg+xml">' . "\n";
    }
}

if (!function_exists('cvGoogleClientIdPublic')) {
    function cvGoogleClientIdPublic(?mysqli $connection = null): string
    {
        static $cached = null;
        if (is_string($cached)) {
            return $cached;
        }

        $clientId = '';

        if (function_exists('cvRuntimeSettings') && function_exists('cvDbConnection')) {
            try {
                $connection = $connection instanceof mysqli ? $connection : cvDbConnection();
                $settings = cvRuntimeSettings($connection);
                $runtimeClientId = trim((string) ($settings['auth_google_client_id'] ?? ''));
                if ($runtimeClientId !== '') {
                    $clientId = $runtimeClientId;
                }
            } catch (Throwable $exception) {
                // ignore runtime fallback errors
            }
        }

        if ($clientId === '' && defined('CV_GOOGLE_CLIENT_ID')) {
            $clientId = trim((string) CV_GOOGLE_CLIENT_ID);
        }
        if ($clientId === '') {
            $clientId = trim((string) (getenv('CV_GOOGLE_CLIENT_ID') ?: ''));
        }

        $cached = $clientId;
        return $cached;
    }
}

if (!function_exists('cvSeoDiscourageIndexing')) {
    function cvSeoDiscourageIndexing(?mysqli $connection = null): bool
    {
        if (!function_exists('cvRuntimeSettings') || !function_exists('cvDbConnection')) {
            return false;
        }

        try {
            $connection = $connection instanceof mysqli ? $connection : cvDbConnection();
            $settings = cvRuntimeSettings($connection);
            return (int) ($settings['seo_discourage_indexing'] ?? 0) === 1;
        } catch (Throwable $exception) {
            return false;
        }
    }
}

if (!function_exists('cvRenderRobotsMetaTag')) {
    function cvRenderRobotsMetaTag(?mysqli $connection = null): string
    {
        if (!cvSeoDiscourageIndexing($connection)) {
            return '';
        }

        return "<meta name=\"robots\" content=\"noindex,nofollow\">\n";
    }
}

if (!function_exists('cvStaticSeoMetaFromSettings')) {
    /**
     * @param array<string,mixed> $runtimeSettings
     * @param array<string,string> $defaults
     * @return array<string,string>
     */
    function cvStaticSeoMetaFromSettings(array $runtimeSettings, string $pageKey, array $defaults): array
    {
        $raw = (string) ($runtimeSettings['seo_static_page_meta_json'] ?? '');
        if (trim($raw) === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $entry = $decoded[$pageKey] ?? null;
        if (!is_array($entry)) {
            return $defaults;
        }

        $title = trim((string) ($entry['title'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        $ogImage = trim((string) ($entry['og_image'] ?? ''));

        return [
            'title' => $title !== '' ? $title : ($defaults['title'] ?? ''),
            'description' => $description !== '' ? $description : ($defaults['description'] ?? ''),
            'og_image' => $ogImage !== '' ? $ogImage : ($defaults['og_image'] ?? ''),
        ];
    }
}

if (!function_exists('cvStaticSeoMeta')) {
    /**
     * @param array<string,string> $defaults
     * @return array<string,string>
     */
    function cvStaticSeoMeta(string $pageKey, array $defaults, ?mysqli $connection = null): array
    {
        if (!function_exists('cvRuntimeSettings') || !function_exists('cvDbConnection')) {
            return $defaults;
        }

        try {
            $connection = $connection instanceof mysqli ? $connection : cvDbConnection();
            $settings = cvRuntimeSettings($connection);
            return cvStaticSeoMetaFromSettings($settings, $pageKey, $defaults);
        } catch (Throwable $exception) {
            return $defaults;
        }
    }
}

if (!function_exists('cvSeoResolveImageUrl')) {
    function cvSeoResolveImageUrl(string $pathOrUrl): string
    {
        $value = trim($pathOrUrl);
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://') || str_starts_with($value, '//')) {
            return $value;
        }

        $relative = ltrim($value, '/');
        if (str_starts_with($relative, 'assets/')) {
            $relative = substr($relative, strlen('assets/'));
        }
        if (!str_starts_with($relative, 'images/')) {
            $relative = 'images/' . $relative;
        }

        return cvAsset($relative);
    }
}

if (!function_exists('cvRenderOpenGraphMetaTags')) {
    function cvRenderOpenGraphMetaTags(string $title, string $description, string $ogImage = ''): string
    {
        $title = trim($title);
        $description = trim($description);
        $ogImageUrl = cvSeoResolveImageUrl($ogImage);

        $canonical = rtrim(cvBaseUrl(), '/') . (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $canonicalEsc = htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8');

        $html = '<link rel="canonical" href="' . $canonicalEsc . "\">\n";
        if ($title !== '') {
            $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $html .= "<meta property=\"og:title\" content=\"{$titleEsc}\">\n";
            $html .= "<meta name=\"twitter:title\" content=\"{$titleEsc}\">\n";
        }
        if ($description !== '') {
            $descEsc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
            $html .= "<meta property=\"og:description\" content=\"{$descEsc}\">\n";
            $html .= "<meta name=\"twitter:description\" content=\"{$descEsc}\">\n";
        }
        $html .= "<meta property=\"og:type\" content=\"website\">\n";
        $html .= "<meta property=\"og:url\" content=\"{$canonicalEsc}\">\n";
        if ($ogImageUrl !== '') {
            $imgEsc = htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8');
            $html .= "<meta property=\"og:image\" content=\"{$imgEsc}\">\n";
            $html .= "<meta name=\"twitter:image\" content=\"{$imgEsc}\">\n";
            $html .= "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        } else {
            $html .= "<meta name=\"twitter:card\" content=\"summary\">\n";
        }

        return $html;
    }
}

if (!function_exists('cvAsset')) {
    function cvAsset(string $path): string
    {
        return cvBaseUrl() . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('cvAssetRelativeUrl')) {
    function cvAssetRelativeUrl(string $path): string
    {
        return (cvBasePath() === '' ? '' : cvBasePath()) . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('cvAssetFilesystemPath')) {
    function cvAssetFilesystemPath(string $path): string
    {
        return cvProjectRootPath() . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('cvAssetCacheDirectoryPath')) {
    function cvAssetCacheDirectoryPath(): string
    {
        return cvAssetFilesystemPath('cache');
    }
}

if (!function_exists('cvAssetBundleDefinitions')) {
    /**
     * @return array<string,array<string,mixed>>
     */
    function cvAssetBundleDefinitions(): array
    {
        static $definitions = null;
        if (is_array($definitions)) {
            return $definitions;
        }

        $definitions = [
            'public-base-css' => [
                'type' => 'css',
                'label' => 'Frontend base CSS',
                'files' => [
                    'vendor/bootstrap/css/bootstrap.min.css',
                    'vendor/bootstrap-icons/font/bootstrap-icons.css',
                ],
            ],
            'public-app-css' => [
                'type' => 'css',
                'label' => 'Frontend custom CSS',
                'files' => [
                    'css/custom.css',
                ],
            ],
            'public-date-css' => [
                'type' => 'css',
                'label' => 'Frontend calendario CSS',
                'files' => [
                    'vendor/flatpickr/flatpickr.min.css',
                ],
            ],
            'public-core-js' => [
                'type' => 'js',
                'label' => 'Frontend base JS',
                'files' => [
                    'vendor/bootstrap/js/bootstrap.bundle.min.js',
                ],
            ],
            'public-date-js' => [
                'type' => 'js',
                'label' => 'Frontend calendario JS',
                'files' => [
                    'vendor/flatpickr/flatpickr.min.js',
                    'vendor/flatpickr/l10n/it.js',
                ],
            ],
            'public-app-js' => [
                'type' => 'js',
                'label' => 'Frontend app JS',
                'files' => [
                    'js/app.js',
                ],
            ],
            'public-soluzioni-js' => [
                'type' => 'js',
                'label' => 'Frontend soluzioni JS',
                'files' => [
                    'js/soluzioni.js',
                ],
            ],
            'public-checkout-js' => [
                'type' => 'js',
                'label' => 'Frontend checkout JS',
                'files' => [
                    'js/checkout.js',
                ],
            ],
        ];

        return $definitions;
    }
}

if (!function_exists('cvAssetBundleSanitizeName')) {
    function cvAssetBundleSanitizeName(string $bundleName): string
    {
        $sanitized = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $bundleName) ?? 'bundle', '-'));
        return $sanitized !== '' ? $sanitized : 'bundle';
    }
}

if (!function_exists('cvAssetBundleMinifyCss')) {
    function cvAssetBundleMinifyCss(string $content): string
    {
        $content = preg_replace('!/\*.*?\*/!s', '', $content) ?? $content;
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;
        $content = preg_replace('/\s*([{};:,>])\s*/', '$1', $content) ?? $content;
        $content = str_replace(';}', '}', $content);
        return trim($content);
    }
}

if (!function_exists('cvAssetBundleMinifyJs')) {
    function cvAssetBundleMinifyJs(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $normalized = [];
        foreach ($lines as $line) {
            $trimmed = rtrim($line);
            if (trim($trimmed) === '') {
                continue;
            }
            $normalized[] = $trimmed;
        }

        return trim(implode("\n", $normalized));
    }
}

if (!function_exists('cvAssetBundleResolveRelativePath')) {
    function cvAssetBundleResolveRelativePath(string $baseDir, string $relativePath): string
    {
        $suffix = '';
        $splitPos = strcspn($relativePath, '?#');
        if ($splitPos < strlen($relativePath)) {
            $suffix = substr($relativePath, $splitPos);
            $relativePath = substr($relativePath, 0, $splitPos);
        }

        $segments = $baseDir === '' ? [] : array_values(array_filter(explode('/', trim($baseDir, '/')), 'strlen'));
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments) . $suffix;
    }
}

if (!function_exists('cvAssetBundleRewriteCssUrls')) {
    function cvAssetBundleRewriteCssUrls(string $content, string $assetPath): string
    {
        $assetDir = trim(str_replace('\\', '/', dirname($assetPath)), './');
        $rewritten = preg_replace_callback(
            '/url\(([^)]+)\)/i',
            static function (array $matches) use ($assetDir): string {
                $raw = trim((string) ($matches[1] ?? ''));
                if ($raw === '') {
                    return (string) ($matches[0] ?? '');
                }

                $quote = '';
                $value = $raw;
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, '\'') && str_ends_with($value, '\''))
                ) {
                    $quote = $value[0];
                    $value = substr($value, 1, -1);
                }

                $lowerValue = strtolower($value);
                if (
                    $value === '' ||
                    str_starts_with($lowerValue, 'data:') ||
                    str_starts_with($lowerValue, 'http://') ||
                    str_starts_with($lowerValue, 'https://') ||
                    str_starts_with($value, '//') ||
                    str_starts_with($value, '/') ||
                    str_starts_with($value, '#')
                ) {
                    return (string) ($matches[0] ?? '');
                }

                $resolved = cvAssetBundleResolveRelativePath($assetDir, $value);
                $assetUrl = cvAssetRelativeUrl($resolved);
                $finalQuote = $quote !== '' ? $quote : '"';
                return 'url(' . $finalQuote . $assetUrl . $finalQuote . ')';
            },
            $content
        );

        return is_string($rewritten) ? $rewritten : $content;
    }
}

if (!function_exists('cvAssetBundleBuild')) {
    /**
     * @param array<int,string> $assetPaths
     * @return array<string,string>|null
     */
    function cvAssetBundleBuild(array $assetPaths, string $type, string $bundleName): ?array
    {
        $type = strtolower($type) === 'js' ? 'js' : 'css';
        $bundleName = cvAssetBundleSanitizeName($bundleName);
        $hashParts = ['cv-bundle-v1', $type, $bundleName];
        $resolvedFiles = [];

        foreach ($assetPaths as $assetPath) {
            $normalizedAssetPath = ltrim($assetPath, '/');
            $fullPath = cvAssetFilesystemPath($normalizedAssetPath);
            if (!is_file($fullPath)) {
                return null;
            }

            $hashParts[] = $normalizedAssetPath . '|' . (string) (@filesize($fullPath) ?: 0) . '|' . (string) (@filemtime($fullPath) ?: 0);
            $resolvedFiles[] = [
                'relative' => $normalizedAssetPath,
                'full' => $fullPath,
            ];
        }

        $hash = substr(sha1(implode(';', $hashParts)), 0, 16);
        $cacheDir = cvAssetCacheDirectoryPath();
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        $filename = $bundleName . '.' . $hash . '.' . $type;
        $filePath = $cacheDir . '/' . $filename;
        if (!is_file($filePath)) {
            $chunks = [];
            foreach ($resolvedFiles as $fileInfo) {
                $content = @file_get_contents((string) $fileInfo['full']);
                if (!is_string($content)) {
                    return null;
                }

                if ($type === 'css') {
                    $content = cvAssetBundleRewriteCssUrls($content, (string) $fileInfo['relative']);
                    $content = cvAssetBundleMinifyCss($content);
                } else {
                    $content = cvAssetBundleMinifyJs($content);
                }

                $chunks[] = $content;
            }

            $bundleContent = $type === 'css'
                ? implode("\n", $chunks)
                : implode("\n;\n", $chunks) . "\n";

            if (@file_put_contents($filePath, $bundleContent, LOCK_EX) === false) {
                return null;
            }

            $staleFiles = glob($cacheDir . '/' . $bundleName . '.*.' . $type);
            if (is_array($staleFiles)) {
                foreach ($staleFiles as $staleFile) {
                    if ($staleFile === $filePath || !is_file($staleFile)) {
                        continue;
                    }
                    @unlink($staleFile);
                }
            }
        }

        return [
            'url' => cvAsset('cache/' . $filename),
            'path' => $filePath,
            'filename' => $filename,
            'type' => $type,
        ];
    }
}

if (!function_exists('cvRenderAssetBundleTags')) {
    /**
     * @param array<int,string> $assetPaths
     */
    function cvRenderAssetBundleTags(array $assetPaths, string $type, string $bundleName): string
    {
        $bundle = cvAssetBundleBuild($assetPaths, $type, $bundleName);
        if (is_array($bundle) && isset($bundle['url'])) {
            $url = htmlspecialchars((string) $bundle['url'], ENT_QUOTES, 'UTF-8');
            if (($bundle['type'] ?? $type) === 'css') {
                return '<link rel="stylesheet" href="' . $url . '">' . "\n";
            }

            return '<script src="' . $url . '"></script>' . "\n";
        }

        $html = '';
        foreach ($assetPaths as $assetPath) {
            $url = htmlspecialchars(cvAsset($assetPath), ENT_QUOTES, 'UTF-8');
            if (strtolower($type) === 'css') {
                $html .= '<link rel="stylesheet" href="' . $url . '">' . "\n";
                continue;
            }

            $html .= '<script src="' . $url . '"></script>' . "\n";
        }

        return $html;
    }
}

if (!function_exists('cvRenderNamedAssetBundle')) {
    function cvRenderNamedAssetBundle(string $bundleKey): string
    {
        $definitions = cvAssetBundleDefinitions();
        if (!isset($definitions[$bundleKey]) || !is_array($definitions[$bundleKey])) {
            return '';
        }

        $definition = $definitions[$bundleKey];
        $files = isset($definition['files']) && is_array($definition['files']) ? $definition['files'] : [];
        $type = (string) ($definition['type'] ?? 'css');
        return cvRenderAssetBundleTags($files, $type, $bundleKey);
    }
}

if (!function_exists('cvAssetBundleBuildAll')) {
    /**
     * @return array<string,bool>
     */
    function cvAssetBundleBuildAll(): array
    {
        $results = [];
        foreach (cvAssetBundleDefinitions() as $bundleKey => $definition) {
            $files = isset($definition['files']) && is_array($definition['files']) ? $definition['files'] : [];
            $type = (string) ($definition['type'] ?? 'css');
            $results[$bundleKey] = cvAssetBundleBuild($files, $type, $bundleKey) !== null;
        }

        return $results;
    }
}

if (!function_exists('cvAssetBundleClearAll')) {
    function cvAssetBundleClearAll(): int
    {
        $cacheDir = cvAssetCacheDirectoryPath();
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $deleted = 0;
        $files = glob($cacheDir . '/*.{css,js}', GLOB_BRACE);
        if (!is_array($files)) {
            return 0;
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}

if (!function_exists('cvAssetBundleStatus')) {
    /**
     * @return array<string,mixed>
     */
    function cvAssetBundleStatus(): array
    {
        $cacheDir = cvAssetCacheDirectoryPath();
        $entries = [];
        $files = is_dir($cacheDir) ? (glob($cacheDir . '/*.{css,js}', GLOB_BRACE) ?: []) : [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $entries[] = [
                'name' => basename($file),
                'size' => (int) (@filesize($file) ?: 0),
                'modified_at' => (int) (@filemtime($file) ?: 0),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            return ($right['modified_at'] ?? 0) <=> ($left['modified_at'] ?? 0);
        });

        return [
            'dir' => $cacheDir,
            'count' => count($entries),
            'files' => $entries,
        ];
    }
}

if (!function_exists('cvSitemapDefinitions')) {
    /**
     * @return array<int,array<string,string>>
     */
    function cvSitemapDefinitions(): array
    {
        $entries = [
            ['path' => '', 'file' => 'index.php', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['path' => 'chi-siamo.php', 'file' => 'chi-siamo.php', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['path' => 'partner.php', 'file' => 'partner.php', 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['path' => 'mappa-fermate.php', 'file' => 'mappa-fermate.php', 'changefreq' => 'weekly', 'priority' => '0.7'],
            ['path' => 'tratte-autobus', 'file' => 'tratte-autobus.php', 'changefreq' => 'daily', 'priority' => '0.8'],
            ['path' => 'faq.php', 'file' => 'faq.php', 'changefreq' => 'weekly', 'priority' => '0.7'],
            ['path' => 'documentazione-endpoint.php', 'file' => 'documentazione-endpoint.php', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['path' => 'privacy.php', 'file' => 'privacy.php', 'changefreq' => 'yearly', 'priority' => '0.4'],
            ['path' => 'cookie.php', 'file' => 'cookie.php', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ];

        if (function_exists('cvRouteSeoSitemapEntries') && function_exists('cvDbConnection')) {
            try {
                $connection = cvDbConnection();
                $entries = array_merge($entries, cvRouteSeoSitemapEntries($connection));
            } catch (Throwable $exception) {
                error_log('cvSitemapDefinitions route pages warning: ' . $exception->getMessage());
            }
        }

        if (function_exists('cvBlogSitemapEntries') && function_exists('cvDbConnection')) {
            try {
                $connection = cvDbConnection();
                $entries = array_merge($entries, cvBlogSitemapEntries($connection));
            } catch (Throwable $exception) {
                error_log('cvSitemapDefinitions blog pages warning: ' . $exception->getMessage());
            }
        }

        return $entries;
    }
}

if (!function_exists('cvSitemapXmlContent')) {
    function cvSitemapXmlContent(): string
    {
        $baseUrl = cvBaseUrl();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach (cvSitemapDefinitions() as $entry) {
            $path = trim((string) ($entry['path'] ?? ''), '/');
            $file = trim((string) ($entry['file'] ?? ''), '/');
            if ($file === '') {
                continue;
            }

            $fullPath = cvProjectRootPath() . '/' . $file;
            if (!is_file($fullPath)) {
                continue;
            }

            $loc = $path === '' ? rtrim($baseUrl, '/') . '/' : rtrim($baseUrl, '/') . '/' . $path;
            $lastmod = trim((string) ($entry['lastmod'] ?? ''));
            if ($lastmod === '') {
                $lastmod = date('c', (int) (@filemtime($fullPath) ?: time()));
            }
            $changefreq = (string) ($entry['changefreq'] ?? 'monthly');
            $priority = (string) ($entry['priority'] ?? '0.5');

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>';
            $lines[] = '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</lastmod>';
            $lines[] = '    <changefreq>' . htmlspecialchars($changefreq, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</changefreq>';
            $lines[] = '    <priority>' . htmlspecialchars($priority, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('cvSitemapWriteFile')) {
    function cvSitemapWriteFile(): bool
    {
        $xml = cvSitemapXmlContent();
        return @file_put_contents(cvProjectRootPath() . '/sitemap.xml', $xml, LOCK_EX) !== false;
    }
}

if (!function_exists('cvSitemapStatus')) {
    /**
     * @return array<string,mixed>
     */
    function cvSitemapStatus(): array
    {
        $path = cvProjectRootPath() . '/sitemap.xml';
        $exists = is_file($path);
        return [
            'path' => $path,
            'url' => rtrim(cvBaseUrl(), '/') . '/sitemap.xml',
            'exists' => $exists,
            'size' => $exists ? (int) (@filesize($path) ?: 0) : 0,
            'modified_at' => $exists ? (int) (@filemtime($path) ?: 0) : 0,
            'pages' => cvSitemapDefinitions(),
        ];
    }
}

if (!function_exists('cvRobotsTxtContent')) {
    function cvRobotsTxtContent(): string
    {
        $baseUrl = rtrim(cvBaseUrl(), '/');
        $discourage = cvSeoDiscourageIndexing();
        $lines = [
            'User-agent: *',
            $discourage ? 'Disallow: /' : 'Allow: /',
            'Sitemap: ' . $baseUrl . '/sitemap.xml',
        ];

        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('cvRobotsWriteFile')) {
    function cvRobotsWriteFile(): bool
    {
        $content = cvRobotsTxtContent();
        return @file_put_contents(cvProjectRootPath() . '/robots.txt', $content, LOCK_EX) !== false;
    }
}

if (!function_exists('cvRobotsStatus')) {
    /**
     * @return array<string,mixed>
     */
    function cvRobotsStatus(): array
    {
        $path = cvProjectRootPath() . '/robots.txt';
        $exists = is_file($path);
        return [
            'path' => $path,
            'url' => rtrim(cvBaseUrl(), '/') . '/robots.txt',
            'exists' => $exists,
            'size' => $exists ? (int) (@filesize($path) ?: 0) : 0,
            'modified_at' => $exists ? (int) (@filemtime($path) ?: 0) : 0,
            'discourage_indexing' => cvSeoDiscourageIndexing(),
        ];
    }
}

if (!function_exists('cvTodayIsoDate')) {
    function cvTodayIsoDate(): string
    {
        return (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
    }
}

if (!function_exists('cvIsoToItDate')) {
    function cvIsoToItDate(string $isoDate): string
    {
        $dt = DateTime::createFromFormat('Y-m-d', $isoDate, new DateTimeZone('Europe/Rome'));
        if (!$dt instanceof DateTime) {
            return '';
        }

        return $dt->format('d/m/Y');
    }
}

if (!function_exists('cvFormatEuro')) {
    function cvFormatEuro(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }
}

if (!function_exists('cvFetchActiveStops')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function cvFetchActiveStops(mysqli $connection, ?string $providerCode = null): array
    {
        $sql = <<<SQL
SELECT
  p.code AS provider_code,
  p.name AS provider_name,
  s.external_id,
  s.name,
  s.lat,
  s.lon
FROM cv_provider_stops s
INNER JOIN cv_providers p
  ON p.id_provider = s.id_provider
WHERE s.is_active = 1
  AND p.is_active = 1
SQL;

        $bindProvider = null;
        if ($providerCode !== null && $providerCode !== '') {
            $sql .= "\n  AND p.code = ?";
            $bindProvider = $providerCode;
        }

        $sql .= "\nORDER BY s.name ASC";

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        if ($bindProvider !== null) {
            $statement->bind_param('s', $bindProvider);
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

            $rows[] = [
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
                'id' => (string) ($row['provider_code'] ?? '') . '|' . (string) ($row['external_id'] ?? ''),
                'external_id' => (string) ($row['external_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'lat' => isset($row['lat']) ? (float) $row['lat'] : null,
                'lon' => isset($row['lon']) ? (float) $row['lon'] : null,
            ];
        }

        $statement->close();
        return $rows;
    }
}

if (!function_exists('cvSearchEntriesBindDynamicParams')) {
    /**
     * @param array<int,mixed> $params
     */
    function cvSearchEntriesBindDynamicParams(mysqli_stmt $statement, string $types, array $params): bool
    {
        if ($types === '') {
            return true;
        }

        $bindParams = [$types];
        foreach ($params as $index => $value) {
            $bindParams[] = &$params[$index];
        }

        return (bool) call_user_func_array([$statement, 'bind_param'], $bindParams);
    }
}

if (!function_exists('cvSearchEntryRankMap')) {
    /**
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,array{search_count:int,last_requested_at:string}>
     */
    function cvSearchEntryRankMap(mysqli $connection, array $entries): array
    {
        if (count($entries) === 0 || !cvSearchRouteStatsEnsureTable($connection)) {
            return [];
        }

        $refs = [];
        foreach ($entries as $entry) {
            $ref = trim((string) ($entry['id'] ?? ''));
            if ($ref !== '') {
                $refs[$ref] = $ref;
            }
        }

        if (count($refs) === 0) {
            return [];
        }

        $params = array_values($refs);
        $placeholders = implode(',', array_fill(0, count($params), '?'));
        $sql = "SELECT ref, SUM(search_count) AS total_search_count, MAX(last_requested_at) AS last_requested_at
                FROM (
                    SELECT from_ref AS ref, search_count, last_requested_at
                    FROM cv_search_route_stats
                    WHERE from_ref IN ({$placeholders})
                    UNION ALL
                    SELECT to_ref AS ref, search_count, last_requested_at
                    FROM cv_search_route_stats
                    WHERE to_ref IN ({$placeholders})
                ) ranked_refs
                GROUP BY ref";

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        $bindParams = array_merge($params, $params);
        $types = str_repeat('s', count($bindParams));
        if (!cvSearchEntriesBindDynamicParams($statement, $types, $bindParams) || !$statement->execute()) {
            $statement->close();
            return [];
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            return [];
        }

        $rankMap = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $ref = trim((string) ($row['ref'] ?? ''));
            if ($ref === '') {
                continue;
            }

            $rankMap[$ref] = [
                'search_count' => max(0, (int) ($row['total_search_count'] ?? 0)),
                'last_requested_at' => trim((string) ($row['last_requested_at'] ?? '')),
            ];
        }

        $result->free();
        $statement->close();
        return $rankMap;
    }
}

if (!function_exists('cvFetchSearchEntries')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function cvFetchSearchEntries(mysqli $connection): array
    {
        $places = cvPlacesFetchSearchEntries($connection);
        if (count($places) > 0) {
            $places = array_values(array_filter(
                $places,
                static function (array $place): bool {
                    $type = strtolower(trim((string) ($place['place_type'] ?? '')));
                    $normalized = preg_replace('/[^a-z0-9]+/', '', $type);
                    return $normalized !== 'province';
                }
            ));

            $rankMap = cvSearchEntryRankMap($connection, $places);
            foreach ($places as $index => &$place) {
                $ref = trim((string) ($place['id'] ?? ''));
                $rank = $rankMap[$ref] ?? ['search_count' => 0, 'last_requested_at' => ''];
                $place['_original_index'] = $index;
                $place['_search_count'] = (int) ($rank['search_count'] ?? 0);
                $place['_last_requested_at'] = (string) ($rank['last_requested_at'] ?? '');
            }
            unset($place);

            usort(
                $places,
                static function (array $left, array $right): int {
                    $leftCount = (int) ($left['_search_count'] ?? 0);
                    $rightCount = (int) ($right['_search_count'] ?? 0);
                    if ($leftCount !== $rightCount) {
                        return $rightCount <=> $leftCount;
                    }

                    $leftLast = (string) ($left['_last_requested_at'] ?? '');
                    $rightLast = (string) ($right['_last_requested_at'] ?? '');
                    if ($leftLast !== $rightLast) {
                        return strcmp($rightLast, $leftLast);
                    }

                    return ((int) ($left['_original_index'] ?? 0)) <=> ((int) ($right['_original_index'] ?? 0));
                }
            );

            foreach ($places as &$place) {
                unset($place['_original_index'], $place['_search_count'], $place['_last_requested_at']);
            }
            unset($place);

            return $places;
        }

        return cvFetchActiveStops($connection, null);
    }
}

if (!function_exists('cvSearchRouteStatsEnsureTable')) {
    function cvSearchRouteStatsEnsureTable(mysqli $connection): bool
    {
        static $initialized = null;
        if (is_bool($initialized)) {
            return $initialized;
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_search_route_stats (
  id_route_stat BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_ref VARCHAR(191) NOT NULL,
  to_ref VARCHAR(191) NOT NULL,
  from_provider_code VARCHAR(64) DEFAULT NULL,
  from_stop_external_id VARCHAR(120) DEFAULT NULL,
  from_name VARCHAR(255) NOT NULL,
  to_provider_code VARCHAR(64) DEFAULT NULL,
  to_stop_external_id VARCHAR(120) DEFAULT NULL,
  to_name VARCHAR(255) NOT NULL,
  search_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
  first_requested_at DATETIME NOT NULL,
  last_requested_at DATETIME NOT NULL,
  last_travel_date_it VARCHAR(10) DEFAULT NULL,
  last_mode VARCHAR(20) DEFAULT NULL,
  last_adults INT UNSIGNED NOT NULL DEFAULT 1,
  last_children INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id_route_stat),
  UNIQUE KEY uq_cv_search_route_pair (from_ref, to_ref),
  KEY idx_cv_search_route_rank (search_count, last_requested_at),
  KEY idx_cv_search_route_last (last_requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $initialized = $connection->query($sql) === true;
        if (!$initialized) {
            error_log('cvSearchRouteStatsEnsureTable error: ' . $connection->error);
        }

        return $initialized;
    }
}

if (!function_exists('cvSearchRouteParseRef')) {
    /**
     * @return array<string,string>
     */
    function cvSearchRouteParseRef(string $rawRef): array
    {
        $trimmed = trim($rawRef);
        if ($trimmed === '') {
            return [
                'ref' => '',
                'provider_code' => '',
                'stop_external_id' => '',
            ];
        }

        $providerCode = '';
        $stopExternalId = $trimmed;
        $separatorPos = strpos($trimmed, '|');
        if ($separatorPos !== false) {
            $providerCode = strtolower(trim(substr($trimmed, 0, $separatorPos)));
            $stopExternalId = trim(substr($trimmed, $separatorPos + 1));
        }

        if ($stopExternalId === '') {
            $stopExternalId = $trimmed;
            $providerCode = '';
        }

        $normalizedRef = $providerCode !== ''
            ? ($providerCode . '|' . $stopExternalId)
            : $stopExternalId;

        return [
            'ref' => $normalizedRef,
            'provider_code' => $providerCode,
            'stop_external_id' => $stopExternalId,
        ];
    }
}

if (!function_exists('cvSearchRouteResolveStopName')) {
    function cvSearchRouteResolveStopName(
        mysqli $connection,
        string $providerCode,
        string $stopExternalId,
        string $fallback = ''
    ): string {
        $providerCode = strtolower(trim($providerCode));
        $stopExternalId = trim($stopExternalId);
        $fallback = trim($fallback);

        if ($stopExternalId === '') {
            return $fallback;
        }

        if ($providerCode !== '') {
            $statement = $connection->prepare(
                "SELECT s.name
                 FROM cv_provider_stops s
                 INNER JOIN cv_providers p ON p.id_provider = s.id_provider
                 WHERE p.code = ? AND s.external_id = ?
                 ORDER BY s.is_active DESC, s.id ASC
                 LIMIT 1"
            );
            if ($statement instanceof mysqli_stmt) {
                $statement->bind_param('ss', $providerCode, $stopExternalId);
                if ($statement->execute()) {
                    $result = $statement->get_result();
                    if ($result instanceof mysqli_result) {
                        $row = $result->fetch_assoc();
                        $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
                        $statement->close();
                        if ($name !== '') {
                            return $name;
                        }
                    } else {
                        $statement->close();
                    }
                } else {
                    $statement->close();
                }
            }
        }

        $statement = $connection->prepare(
            "SELECT name
             FROM cv_provider_stops
             WHERE external_id = ?
             ORDER BY is_active DESC, id ASC
             LIMIT 1"
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('s', $stopExternalId);
            if ($statement->execute()) {
                $result = $statement->get_result();
                if ($result instanceof mysqli_result) {
                    $row = $result->fetch_assoc();
                    $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
                    $statement->close();
                    if ($name !== '') {
                        return $name;
                    }
                } else {
                    $statement->close();
                }
            } else {
                $statement->close();
            }
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return $stopExternalId;
    }
}

if (!function_exists('cvSearchRouteResolvePlaceName')) {
    function cvSearchRouteResolvePlaceName(
        mysqli $connection,
        string $placeExternalId,
        string $fallback = ''
    ): string {
        $placeExternalId = trim($placeExternalId);
        $fallback = trim($fallback);

        if ($placeExternalId === '') {
            return $fallback;
        }

        $placeId = (int) $placeExternalId;
        if ($placeId <= 0 || !cvPlacesTablesExist($connection)) {
            return $fallback !== '' ? $fallback : $placeExternalId;
        }

        $statement = $connection->prepare(
            "SELECT name, place_type
             FROM cv_places
             WHERE id_place = ?
             ORDER BY is_active DESC, id_place ASC
             LIMIT 1"
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('i', $placeId);
            if ($statement->execute()) {
                $result = $statement->get_result();
                if ($result instanceof mysqli_result) {
                    $row = $result->fetch_assoc();
                    $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
                    $placeType = is_array($row) ? trim((string) ($row['place_type'] ?? '')) : '';
                    $statement->close();
                    if ($name !== '') {
                        if (function_exists('cvPlaceSuggestionDisplayName') && $placeType !== '') {
                            $displayName = cvPlaceSuggestionDisplayName($name, $placeType);
                            if (trim($displayName) !== '') {
                                return $displayName;
                            }
                        }
                        return $name;
                    }
                } else {
                    $statement->close();
                }
            } else {
                $statement->close();
            }
        }

        return $fallback !== '' ? $fallback : $placeExternalId;
    }
}

if (!function_exists('cvSearchRouteResolveRefName')) {
    function cvSearchRouteResolveRefName(
        mysqli $connection,
        string $rawRef,
        string $fallback = ''
    ): string {
        $parsed = cvSearchRouteParseRef($rawRef);
        $fallback = trim($fallback);

        if ($parsed['provider_code'] === 'place') {
            return cvSearchRouteResolvePlaceName(
                $connection,
                $parsed['stop_external_id'],
                $fallback !== '' ? $fallback : $parsed['stop_external_id']
            );
        }

        return cvSearchRouteResolveStopName(
            $connection,
            $parsed['provider_code'],
            $parsed['stop_external_id'],
            $fallback !== '' ? $fallback : $parsed['stop_external_id']
        );
    }
}

if (!function_exists('cvTrackRouteSearchRequest')) {
    function cvTrackRouteSearchRequest(
        mysqli $connection,
        string $fromRefRaw,
        string $toRefRaw,
        string $travelDateIt = '',
        int $adults = 1,
        int $children = 0,
        string $mode = 'oneway'
    ): bool {
        if (!cvSearchRouteStatsEnsureTable($connection)) {
            return false;
        }

        $from = cvSearchRouteParseRef($fromRefRaw);
        $to = cvSearchRouteParseRef($toRefRaw);
        if ($from['ref'] === '' || $to['ref'] === '') {
            return false;
        }

        $fromName = cvSearchRouteResolveRefName(
            $connection,
            $from['ref'],
            $from['stop_external_id']
        );
        $toName = cvSearchRouteResolveRefName(
            $connection,
            $to['ref'],
            $to['stop_external_id']
        );

        $now = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');
        $safeDateIt = trim($travelDateIt);
        $safeMode = trim($mode);
        if ($safeMode !== 'roundtrip') {
            $safeMode = 'oneway';
        }
        $safeAdults = max(1, $adults);
        $safeChildren = max(0, $children);

        $sql = <<<SQL
INSERT INTO cv_search_route_stats (
  from_ref,
  to_ref,
  from_provider_code,
  from_stop_external_id,
  from_name,
  to_provider_code,
  to_stop_external_id,
  to_name,
  search_count,
  first_requested_at,
  last_requested_at,
  last_travel_date_it,
  last_mode,
  last_adults,
  last_children
) VALUES (
  ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?
)
ON DUPLICATE KEY UPDATE
  search_count = search_count + 1,
  last_requested_at = VALUES(last_requested_at),
  last_travel_date_it = VALUES(last_travel_date_it),
  last_mode = VALUES(last_mode),
  last_adults = VALUES(last_adults),
  last_children = VALUES(last_children),
  from_name = IF(VALUES(from_name) <> '', VALUES(from_name), from_name),
  to_name = IF(VALUES(to_name) <> '', VALUES(to_name), to_name),
  from_provider_code = IF(VALUES(from_provider_code) <> '', VALUES(from_provider_code), from_provider_code),
  to_provider_code = IF(VALUES(to_provider_code) <> '', VALUES(to_provider_code), to_provider_code),
  from_stop_external_id = IF(VALUES(from_stop_external_id) <> '', VALUES(from_stop_external_id), from_stop_external_id),
  to_stop_external_id = IF(VALUES(to_stop_external_id) <> '', VALUES(to_stop_external_id), to_stop_external_id)
SQL;

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $statement->bind_param(
            'ssssssssssssii',
            $from['ref'],
            $to['ref'],
            $from['provider_code'],
            $from['stop_external_id'],
            $fromName,
            $to['provider_code'],
            $to['stop_external_id'],
            $toName,
            $now,
            $now,
            $safeDateIt,
            $safeMode,
            $safeAdults,
            $safeChildren
        );

        $ok = $statement->execute();
        if (!$ok) {
            error_log('cvTrackRouteSearchRequest error: ' . $statement->error);
        }
        $statement->close();
        return $ok;
    }
}

if (!function_exists('cvSearchRouteProviderName')) {
    function cvSearchRouteProviderName(mysqli $connection, string $providerCode): string
    {
        $providerCode = strtolower(trim($providerCode));
        if ($providerCode === '') {
            return '';
        }

        static $nameCache = [];
        if (isset($nameCache[$providerCode])) {
            return $nameCache[$providerCode];
        }

        $statement = $connection->prepare(
            "SELECT name FROM cv_providers WHERE code = ? LIMIT 1"
        );
        if (!$statement instanceof mysqli_stmt) {
            $nameCache[$providerCode] = '';
            return '';
        }

        $statement->bind_param('s', $providerCode);
        if (!$statement->execute()) {
            $statement->close();
            $nameCache[$providerCode] = '';
            return '';
        }

        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            $statement->close();
            $nameCache[$providerCode] = '';
            return '';
        }

        $row = $result->fetch_assoc();
        $statement->close();
        $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
        $nameCache[$providerCode] = $name;
        return $name;
    }
}

if (!function_exists('cvSearchRouteBestDirectFare')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvSearchRouteBestDirectFare(
        mysqli $connection,
        string $fromProviderCode,
        string $fromStopExternalId,
        string $toProviderCode,
        string $toStopExternalId
    ): ?array {
        $fromStopExternalId = trim($fromStopExternalId);
        $toStopExternalId = trim($toStopExternalId);
        if ($fromStopExternalId === '' || $toStopExternalId === '') {
            return null;
        }

        $fromProviderCode = strtolower(trim($fromProviderCode));
        $toProviderCode = strtolower(trim($toProviderCode));
        $providerFilter = '';
        if ($fromProviderCode !== '' && $toProviderCode !== '' && $fromProviderCode === $toProviderCode) {
            $providerFilter = $fromProviderCode;
        } elseif ($fromProviderCode !== '' && $toProviderCode === '') {
            $providerFilter = $fromProviderCode;
        } elseif ($toProviderCode !== '' && $fromProviderCode === '') {
            $providerFilter = $toProviderCode;
        }

        $sql = <<<SQL
SELECT
  p.code AS provider_code,
  p.name AS provider_name,
  MIN(f.amount) AS min_amount,
  MAX(f.currency) AS currency,
  COUNT(*) AS fare_count
FROM cv_provider_fares f
INNER JOIN cv_providers p
  ON p.id_provider = f.id_provider
WHERE f.is_active = 1
  AND p.is_active = 1
  AND f.from_stop_external_id = ?
  AND f.to_stop_external_id = ?
SQL;

        if ($providerFilter !== '') {
            $sql .= "\n  AND p.code = ?";
        }

        $sql .= "\nGROUP BY p.code, p.name\nORDER BY min_amount ASC, fare_count DESC\nLIMIT 1";

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return null;
        }

        if ($providerFilter !== '') {
            $statement->bind_param('sss', $fromStopExternalId, $toStopExternalId, $providerFilter);
        } else {
            $statement->bind_param('ss', $fromStopExternalId, $toStopExternalId);
        }

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

        return [
            'provider_code' => strtolower(trim((string) ($row['provider_code'] ?? ''))),
            'provider_name' => trim((string) ($row['provider_name'] ?? '')),
            'min_amount' => isset($row['min_amount']) ? (float) $row['min_amount'] : 0.0,
            'currency' => trim((string) ($row['currency'] ?? 'EUR')),
            'fare_count' => isset($row['fare_count']) ? (int) $row['fare_count'] : 0,
        ];
    }
}

if (!function_exists('cvFetchMostRequestedRoutes')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function cvFetchMostRequestedRoutes(
        mysqli $connection,
        int $limit = 10,
        ?array $providerPriceModes = null
    ): array {
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
  last_requested_at
FROM cv_search_route_stats
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

            $fromProviderCode = strtolower(trim((string) ($row['from_provider_code'] ?? '')));
            $toProviderCode = strtolower(trim((string) ($row['to_provider_code'] ?? '')));
            $fromStopExternalId = trim((string) ($row['from_stop_external_id'] ?? ''));
            $toStopExternalId = trim((string) ($row['to_stop_external_id'] ?? ''));
            $fromName = trim((string) ($row['from_name'] ?? ''));
            $toName = trim((string) ($row['to_name'] ?? ''));

            if ($fromStopExternalId === '' || $toStopExternalId === '') {
                $fromParsed = cvSearchRouteParseRef((string) ($row['from_ref'] ?? ''));
                $toParsed = cvSearchRouteParseRef((string) ($row['to_ref'] ?? ''));
                if ($fromStopExternalId === '') {
                    $fromStopExternalId = $fromParsed['stop_external_id'];
                }
                if ($toStopExternalId === '') {
                    $toStopExternalId = $toParsed['stop_external_id'];
                }
                if ($fromProviderCode === '') {
                    $fromProviderCode = $fromParsed['provider_code'];
                }
                if ($toProviderCode === '') {
                    $toProviderCode = $toParsed['provider_code'];
                }
            }

            if ($fromStopExternalId === '' || $toStopExternalId === '') {
                continue;
            }

            $fromName = cvSearchRouteResolveRefName(
                $connection,
                (string) ($row['from_ref'] ?? ''),
                $fromName !== '' ? $fromName : $fromStopExternalId
            );
            $toName = cvSearchRouteResolveRefName(
                $connection,
                (string) ($row['to_ref'] ?? ''),
                $toName !== '' ? $toName : $toStopExternalId
            );

            $fareInfo = cvSearchRouteBestDirectFare(
                $connection,
                $fromProviderCode,
                $fromStopExternalId,
                $toProviderCode,
                $toStopExternalId
            );

            $providerCode = '';
            $providerName = '';
            $minAmount = 0.0;
            $currency = 'EUR';
            $fareCount = 0;
            if (is_array($fareInfo)) {
                $providerCode = (string) ($fareInfo['provider_code'] ?? '');
                $providerName = (string) ($fareInfo['provider_name'] ?? '');
                $minAmount = isset($fareInfo['min_amount']) ? (float) $fareInfo['min_amount'] : 0.0;
                $currency = (string) ($fareInfo['currency'] ?? 'EUR');
                $fareCount = isset($fareInfo['fare_count']) ? (int) $fareInfo['fare_count'] : 0;
            }

            if ($providerCode === '' && $fromProviderCode !== '' && $fromProviderCode === $toProviderCode) {
                $providerCode = $fromProviderCode;
                $providerName = cvSearchRouteProviderName($connection, $providerCode);
            }

            $rows[] = [
                'provider_code' => $providerCode,
                'provider_name' => $providerName,
                'from_ref' => (string) ($row['from_ref'] ?? ''),
                'to_ref' => (string) ($row['to_ref'] ?? ''),
                'from_id' => $fromStopExternalId,
                'from_name' => $fromName,
                'to_id' => $toStopExternalId,
                'to_name' => $toName,
                'min_amount' => $minAmount,
                'currency' => $currency,
                'fare_count' => $fareCount,
                'search_count' => isset($row['search_count']) ? (int) $row['search_count'] : 0,
                'last_requested_at' => (string) ($row['last_requested_at'] ?? ''),
            ];
        }

        $result->free();
        return cvPopularRoutesApplyDisplayAmounts($connection, $rows, $providerPriceModes);
    }
}

if (!function_exists('cvPopularRouteLiveCacheIndex')) {
    /**
     * I prezzi home usano il fare base del sync come fallback. Se esistono cache live
     * recenti per la stessa tratta/provider, il valore mostrato segue il price mode
     * del provider (scontato/intero) usando i dati gia' ottenuti dalle API.
     *
     * @return array<string,array<string,mixed>>
     */
    function cvPopularRouteLiveCacheIndex(): array
    {
        static $index = null;
        if (is_array($index)) {
            return $index;
        }

        $index = [];
        $maxFilesPerBucket = 250;
        $maxAgeSeconds = 43200;

        $providerSearchDir = dirname(__DIR__) . '/files/cache/provider_search';
        foreach (cvPopularRouteRecentCacheFiles($providerSearchDir, $maxFilesPerBucket, $maxAgeSeconds) as $path) {
            $payload = cvPopularRouteReadCachePayload($path);
            if (!is_array($payload) || (string) ($payload['status'] ?? '') !== 'ok') {
                continue;
            }

            $meta = isset($payload['request_meta']) && is_array($payload['request_meta']) ? $payload['request_meta'] : [];
            $providerCode = strtolower(trim((string) ($payload['provider_code'] ?? ($meta['provider_code'] ?? ''))));
            $fromId = trim((string) ($meta['from_stop_id'] ?? ''));
            $toId = trim((string) ($meta['to_stop_id'] ?? ''));
            $adults = isset($meta['adults']) ? (int) $meta['adults'] : 0;
            $children = isset($meta['children']) ? (int) $meta['children'] : 0;

            if ($providerCode === '' || $fromId === '' || $toId === '' || $adults !== 1 || $children !== 0) {
                continue;
            }

            $discounted = null;
            $full = null;
            $solutions = isset($payload['solutions']) && is_array($payload['solutions']) ? $payload['solutions'] : [];
            foreach ($solutions as $solution) {
                if (!is_array($solution)) {
                    continue;
                }

                $segments = isset($solution['segments']) && is_array($solution['segments']) ? $solution['segments'] : [];
                if (count($segments) === 0) {
                    continue;
                }

                $firstSegment = $segments[0] ?? null;
                $lastSegment = $segments[count($segments) - 1] ?? null;
                if (!is_array($firstSegment) || !is_array($lastSegment)) {
                    continue;
                }

                if (
                    trim((string) ($firstSegment['from_id'] ?? '')) !== $fromId ||
                    trim((string) ($lastSegment['to_id'] ?? '')) !== $toId
                ) {
                    continue;
                }

                $fares = isset($solution['fares']) && is_array($solution['fares']) ? $solution['fares'] : [];
                foreach ($fares as $fare) {
                    if (!is_array($fare)) {
                        continue;
                    }

                    $discountedAmount = isset($fare['amount']) ? (float) $fare['amount'] : 0.0;
                    $originalAmount = isset($fare['original_amount']) ? (float) $fare['original_amount'] : 0.0;
                    $fullAmount = $originalAmount > 0.0 ? $originalAmount : $discountedAmount;

                    if ($discountedAmount > 0.0 && ($discounted === null || $discountedAmount < $discounted)) {
                        $discounted = $discountedAmount;
                    }

                    if ($fullAmount > 0.0 && ($full === null || $fullAmount < $full)) {
                        $full = $fullAmount;
                    }
                }
            }

            cvPopularRouteStoreCacheAmounts($index, $providerCode, $fromId, $toId, $discounted, $full, 'provider_search_cache');
        }

        $providerQuoteDir = dirname(__DIR__) . '/files/cache/provider_quote';
        foreach (cvPopularRouteRecentCacheFiles($providerQuoteDir, $maxFilesPerBucket, $maxAgeSeconds) as $path) {
            $payload = cvPopularRouteReadCachePayload($path);
            if (!is_array($payload) || !isset($payload['ok']) || (bool) $payload['ok'] !== true) {
                continue;
            }

            $meta = isset($payload['request_meta']) && is_array($payload['request_meta']) ? $payload['request_meta'] : [];
            $providerCode = strtolower(trim((string) ($payload['provider_code'] ?? ($meta['provider_code'] ?? ''))));
            $fromId = trim((string) ($meta['from_stop_id'] ?? ''));
            $toId = trim((string) ($meta['to_stop_id'] ?? ''));
            $adults = isset($meta['adults']) ? (int) $meta['adults'] : 0;
            $children = isset($meta['children']) ? (int) $meta['children'] : 0;

            if ($providerCode === '' || $fromId === '' || $toId === '' || $adults !== 1 || $children !== 0) {
                continue;
            }

            $discounted = isset($payload['amount']) ? (float) $payload['amount'] : 0.0;
            $originalAmount = isset($payload['original_amount']) ? (float) $payload['original_amount'] : 0.0;
            $full = $originalAmount > 0.0 ? $originalAmount : $discounted;

            cvPopularRouteStoreCacheAmounts(
                $index,
                $providerCode,
                $fromId,
                $toId,
                $discounted > 0.0 ? $discounted : null,
                $full > 0.0 ? $full : null,
                'provider_quote_cache'
            );
        }

        return $index;
    }
}

if (!function_exists('cvPopularRouteRecentCacheFiles')) {
    /**
     * @return array<int,string>
     */
    function cvPopularRouteRecentCacheFiles(string $dir, int $maxFiles, int $maxAgeSeconds): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, '/') . '/*.json');
        if (!is_array($files) || count($files) === 0) {
            return [];
        }

        usort($files, static function (string $left, string $right): int {
            $leftMtime = @filemtime($left);
            $rightMtime = @filemtime($right);
            $leftTs = is_int($leftMtime) ? $leftMtime : 0;
            $rightTs = is_int($rightMtime) ? $rightMtime : 0;
            return $rightTs <=> $leftTs;
        });

        $cutoff = time() - max(60, $maxAgeSeconds);
        $recent = [];
        foreach ($files as $path) {
            $mtime = @filemtime($path);
            if (!is_int($mtime) || $mtime < $cutoff) {
                continue;
            }

            $recent[] = $path;
            if (count($recent) >= $maxFiles) {
                break;
            }
        }

        return $recent;
    }
}

if (!function_exists('cvPopularRouteReadCachePayload')) {
    /**
     * @return array<string,mixed>|null
     */
    function cvPopularRouteReadCachePayload(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('cvPopularRouteCacheKey')) {
    function cvPopularRouteCacheKey(string $providerCode, string $fromId, string $toId): string
    {
        return strtolower(trim($providerCode)) . '|' . trim($fromId) . '|' . trim($toId);
    }
}

if (!function_exists('cvPopularRouteStoreCacheAmounts')) {
    /**
     * @param array<string,array<string,mixed>> $index
     */
    function cvPopularRouteStoreCacheAmounts(
        array &$index,
        string $providerCode,
        string $fromId,
        string $toId,
        ?float $discounted,
        ?float $full,
        string $source
    ): void {
        $key = cvPopularRouteCacheKey($providerCode, $fromId, $toId);
        if ($key === '||') {
            return;
        }

        if (!isset($index[$key])) {
            $index[$key] = [
                'discounted' => 0.0,
                'discounted_source' => '',
                'full' => 0.0,
                'full_source' => '',
            ];
        }

        if ($discounted !== null && $discounted > 0.0) {
            $currentDiscounted = isset($index[$key]['discounted']) ? (float) $index[$key]['discounted'] : 0.0;
            if ($currentDiscounted <= 0.0 || $discounted < $currentDiscounted) {
                $index[$key]['discounted'] = $discounted;
                $index[$key]['discounted_source'] = $source;
            }
        }

        if ($full !== null && $full > 0.0) {
            $currentFull = isset($index[$key]['full']) ? (float) $index[$key]['full'] : 0.0;
            if ($currentFull <= 0.0 || $full < $currentFull) {
                $index[$key]['full'] = $full;
                $index[$key]['full_source'] = $source;
            }
        }
    }
}

if (!function_exists('cvPopularRoutesApplyDisplayAmounts')) {
    /**
     * @param array<int,array<string,mixed>> $routes
     * @param array<string,string>|null $providerPriceModes
     * @return array<int,array<string,mixed>>
     */
    function cvPopularRoutesApplyDisplayAmounts(mysqli $connection, array $routes, ?array $providerPriceModes = null): array
    {
        $priceModes = is_array($providerPriceModes) ? $providerPriceModes : cvRuntimeProviderPriceModeMap($connection);
        $cacheIndex = cvPopularRouteLiveCacheIndex();

        foreach ($routes as $index => $route) {
            $providerCode = strtolower(trim((string) ($route['provider_code'] ?? '')));
            $fromId = trim((string) ($route['from_id'] ?? ''));
            $toId = trim((string) ($route['to_id'] ?? ''));
            $baseAmount = isset($route['min_amount']) ? (float) $route['min_amount'] : 0.0;
            $mode = isset($priceModes[$providerCode]) ? cvRuntimeNormalizeProviderPriceMode((string) $priceModes[$providerCode]) : 'discounted';

            $displayAmount = $baseAmount;
            $displaySource = 'base_fare';
            $cacheKey = cvPopularRouteCacheKey($providerCode, $fromId, $toId);
            $cached = isset($cacheIndex[$cacheKey]) && is_array($cacheIndex[$cacheKey]) ? $cacheIndex[$cacheKey] : null;

            if ($mode === 'full' && is_array($cached)) {
                $cachedFull = isset($cached['full']) ? (float) $cached['full'] : 0.0;
                if ($cachedFull > 0.0) {
                    $displayAmount = $cachedFull;
                    $displaySource = (string) ($cached['full_source'] ?? 'live_cache');
                }
            } elseif ($mode === 'discounted' && is_array($cached)) {
                $cachedDiscounted = isset($cached['discounted']) ? (float) $cached['discounted'] : 0.0;
                if ($cachedDiscounted > 0.0) {
                    $displayAmount = $cachedDiscounted;
                    $displaySource = (string) ($cached['discounted_source'] ?? 'live_cache');
                }
            }

            $routes[$index]['display_amount'] = $displayAmount;
            $routes[$index]['display_amount_source'] = $displaySource;
            $routes[$index]['display_price_mode'] = $mode;
        }

        return $routes;
    }
}

if (!function_exists('cvFetchPopularRoutes')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function cvFetchPopularRoutes(
        mysqli $connection,
        int $limit = 8,
        ?array $providerCodes = null,
        ?array $providerPriceModes = null
    ): array
    {
        $safeLimit = max(1, min(200, $limit));

        $sql = <<<SQL
SELECT
  p.code AS provider_code,
  p.name AS provider_name,
  fs.external_id AS from_id,
  fs.name AS from_name,
  ts.external_id AS to_id,
  ts.name AS to_name,
  MIN(f.amount) AS min_amount,
  MAX(f.currency) AS currency,
  COUNT(*) AS fare_count
FROM cv_provider_fares f
INNER JOIN cv_providers p
  ON p.id_provider = f.id_provider
INNER JOIN cv_provider_stops fs
  ON fs.id_provider = f.id_provider
 AND fs.external_id = f.from_stop_external_id
 AND fs.is_active = 1
INNER JOIN cv_provider_stops ts
  ON ts.id_provider = f.id_provider
 AND ts.external_id = f.to_stop_external_id
 AND ts.is_active = 1
WHERE f.is_active = 1
  AND p.is_active = 1
  AND f.from_stop_external_id <> f.to_stop_external_id
SQL;

        $normalizedProviderCodes = [];
        if (is_array($providerCodes)) {
            foreach ($providerCodes as $providerCode) {
                $providerCode = strtolower(trim((string) $providerCode));
                if ($providerCode === '') {
                    continue;
                }
                $normalizedProviderCodes[$providerCode] = $providerCode;
            }
        }

        $bindProviderCodes = array_values($normalizedProviderCodes);
        if (count($bindProviderCodes) > 0) {
            $placeholders = implode(', ', array_fill(0, count($bindProviderCodes), '?'));
            $sql .= "\n  AND p.code IN ({$placeholders})";
        }

        $sql .= <<<SQL

GROUP BY
  p.code,
  p.name,
  fs.external_id,
  fs.name,
  ts.external_id,
  ts.name
ORDER BY fare_count DESC, min_amount ASC, fs.name ASC
LIMIT {$safeLimit}
SQL;

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        if (count($bindProviderCodes) > 0) {
            $types = str_repeat('s', count($bindProviderCodes));
            $bindParams = array_merge([$types], $bindProviderCodes);
            $bindReferences = [];
            foreach ($bindParams as $index => $value) {
                $bindReferences[$index] = &$bindParams[$index];
            }

            call_user_func_array([$statement, 'bind_param'], $bindReferences);
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

            $rows[] = [
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
                'from_id' => (string) ($row['from_id'] ?? ''),
                'from_name' => (string) ($row['from_name'] ?? ''),
                'to_id' => (string) ($row['to_id'] ?? ''),
                'to_name' => (string) ($row['to_name'] ?? ''),
                'min_amount' => isset($row['min_amount']) ? (float) $row['min_amount'] : 0.0,
                'currency' => (string) ($row['currency'] ?? 'EUR'),
                'fare_count' => isset($row['fare_count']) ? (int) $row['fare_count'] : 0,
            ];
        }

        $statement->close();
        return cvPopularRoutesApplyDisplayAmounts($connection, $rows, $providerPriceModes);
    }
}

if (!function_exists('cvFetchPopularRoutesPerProvider')) {
    /**
     * @param array<int,string> $providerCodes
     * @return array<int, array<string, mixed>>
     */
    function cvFetchPopularRoutesPerProvider(
        mysqli $connection,
        array $providerCodes,
        int $perProvider,
        ?array $providerPriceModes = null
    ): array
    {
        $safePerProvider = max(1, min(200, $perProvider));
        $normalizedProviderCodes = [];
        foreach ($providerCodes as $providerCode) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }
            $normalizedProviderCodes[] = $providerCode;
        }

        if (count($normalizedProviderCodes) === 0) {
            return [];
        }

        $allRoutes = [];
        foreach ($normalizedProviderCodes as $providerCode) {
            $routes = cvFetchPopularRoutes($connection, $safePerProvider, [$providerCode], $providerPriceModes);
            foreach ($routes as $route) {
                $allRoutes[] = $route;
            }
        }

        return $allRoutes;
    }
}

if (!function_exists('cvFetchPopularRoutesByProviderLimits')) {
    /**
     * @param array<string,int> $providerLimits
     * @return array<int, array<string, mixed>>
     */
    function cvFetchPopularRoutesByProviderLimits(
        mysqli $connection,
        array $providerLimits,
        ?array $providerPriceModes = null
    ): array
    {
        $allRoutes = [];

        foreach ($providerLimits as $providerCode => $limit) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }

            $safeLimit = is_numeric($limit) ? (int) $limit : 0;
            if ($safeLimit <= 0) {
                continue;
            }

            $safeLimit = min(200, $safeLimit);
            $routes = cvFetchPopularRoutes($connection, $safeLimit, [$providerCode], $providerPriceModes);
            foreach ($routes as $route) {
                $allRoutes[] = $route;
            }
        }

        return $allRoutes;
    }
}

if (!function_exists('cvHomepageFeaturedRoutesEnsureTable')) {
    function cvHomepageFeaturedRoutesEnsureTable(mysqli $connection): bool
    {
        static $initialized = null;
        if (is_bool($initialized)) {
            return $initialized;
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_home_provider_featured_routes (
  id_featured_route BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_code VARCHAR(64) NOT NULL,
  from_stop_external_id VARCHAR(120) NOT NULL,
  to_stop_external_id VARCHAR(120) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_featured_route),
  UNIQUE KEY uq_cv_home_provider_featured_route (provider_code, from_stop_external_id, to_stop_external_id),
  KEY idx_cv_home_provider_featured_provider (provider_code, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL;

        $initialized = $connection->query($sql) === true;
        if (!$initialized) {
            error_log('cvHomepageFeaturedRoutesEnsureTable error: ' . $connection->error);
        }

        return $initialized;
    }
}

if (!function_exists('cvHomepageFeaturedRouteKey')) {
    function cvHomepageFeaturedRouteKey(string $fromId, string $toId): string
    {
        $fromId = trim($fromId);
        $toId = trim($toId);
        if ($fromId === '' || $toId === '') {
            return '';
        }

        return $fromId . '||' . $toId;
    }
}

if (!function_exists('cvHomepageFeaturedRouteParseKey')) {
    /**
     * @return array<string,string>
     */
    function cvHomepageFeaturedRouteParseKey(string $routeKey): array
    {
        $routeKey = trim($routeKey);
        if ($routeKey === '') {
            return [
                'key' => '',
                'from_id' => '',
                'to_id' => '',
            ];
        }

        $parts = explode('||', $routeKey, 2);
        if (count($parts) !== 2) {
            return [
                'key' => '',
                'from_id' => '',
                'to_id' => '',
            ];
        }

        $fromId = trim((string) ($parts[0] ?? ''));
        $toId = trim((string) ($parts[1] ?? ''));
        if ($fromId === '' || $toId === '') {
            return [
                'key' => '',
                'from_id' => '',
                'to_id' => '',
            ];
        }

        return [
            'key' => cvHomepageFeaturedRouteKey($fromId, $toId),
            'from_id' => $fromId,
            'to_id' => $toId,
        ];
    }
}

if (!function_exists('cvHomepageFeaturedRoutesFetchProviderCandidates')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function cvHomepageFeaturedRoutesFetchProviderCandidates(
        mysqli $connection,
        string $providerCode
    ): array {
        $providerCode = strtolower(trim($providerCode));
        if ($providerCode === '') {
            return [];
        }

        $sql = <<<SQL
SELECT
  p.code AS provider_code,
  p.name AS provider_name,
  fs.external_id AS from_id,
  fs.name AS from_name,
  ts.external_id AS to_id,
  ts.name AS to_name,
  MIN(f.amount) AS min_amount,
  MAX(f.currency) AS currency,
  COUNT(f.id) AS fare_count
FROM cv_provider_fares f
INNER JOIN cv_providers p
  ON p.id_provider = f.id_provider
INNER JOIN cv_provider_stops fs
  ON fs.id_provider = f.id_provider
 AND fs.external_id = f.from_stop_external_id
 AND fs.is_active = 1
INNER JOIN cv_provider_stops ts
  ON ts.id_provider = f.id_provider
 AND ts.external_id = f.to_stop_external_id
 AND ts.is_active = 1
WHERE f.is_active = 1
  AND p.is_active = 1
  AND p.code = ?
  AND f.from_stop_external_id <> f.to_stop_external_id
GROUP BY
  p.code,
  p.name,
  fs.external_id,
  fs.name,
  ts.external_id,
  ts.name
ORDER BY fs.name ASC, ts.name ASC, min_amount ASC
SQL;

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        $statement->bind_param('s', $providerCode);
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

            $fromId = trim((string) ($row['from_id'] ?? ''));
            $toId = trim((string) ($row['to_id'] ?? ''));
            $routeKey = cvHomepageFeaturedRouteKey($fromId, $toId);
            if ($routeKey === '') {
                continue;
            }

            $rows[] = [
                'route_key' => $routeKey,
                'provider_code' => (string) ($row['provider_code'] ?? ''),
                'provider_name' => (string) ($row['provider_name'] ?? ''),
                'from_id' => $fromId,
                'from_name' => (string) ($row['from_name'] ?? ''),
                'to_id' => $toId,
                'to_name' => (string) ($row['to_name'] ?? ''),
                'min_amount' => isset($row['min_amount']) ? (float) $row['min_amount'] : 0.0,
                'currency' => (string) ($row['currency'] ?? 'EUR'),
                'fare_count' => isset($row['fare_count']) ? (int) $row['fare_count'] : 0,
                'is_valid' => true,
            ];
        }

        $statement->close();
        return $rows;
    }
}

if (!function_exists('cvHomepageFeaturedRoutesFetchSelections')) {
    /**
     * @param array<int,string> $providerCodes
     * @return array<string,array<int,array<string,mixed>>>
     */
    function cvHomepageFeaturedRoutesFetchSelections(mysqli $connection, array $providerCodes = []): array
    {
        if (!cvHomepageFeaturedRoutesEnsureTable($connection)) {
            return [];
        }

        $normalizedProviderCodes = [];
        foreach ($providerCodes as $providerCode) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }
            $normalizedProviderCodes[$providerCode] = $providerCode;
        }

        if (count($normalizedProviderCodes) === 0) {
            $result = $connection->query("SELECT DISTINCT provider_code FROM cv_home_provider_featured_routes WHERE is_active = 1 ORDER BY provider_code ASC");
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $providerCode = strtolower(trim((string) ($row['provider_code'] ?? '')));
                    if ($providerCode !== '') {
                        $normalizedProviderCodes[$providerCode] = $providerCode;
                    }
                }
                $result->free();
            }
        }

        if (count($normalizedProviderCodes) === 0) {
            return [];
        }

        $grouped = [];
        foreach (array_values($normalizedProviderCodes) as $providerCode) {
            $sql = <<<SQL
SELECT
  r.provider_code,
  COALESCE(p.name, r.provider_code) AS provider_name,
  COALESCE(fs.external_id, fs_id.external_id, '') AS resolved_from_id,
  COALESCE(fs.external_id, fs_id.external_id, r.from_stop_external_id) AS from_id,
  COALESCE(fs.name, fs_id.name, r.from_stop_external_id) AS from_name,
  COALESCE(ts.external_id, ts_id.external_id, '') AS resolved_to_id,
  COALESCE(ts.external_id, ts_id.external_id, r.to_stop_external_id) AS to_id,
  COALESCE(ts.name, ts_id.name, r.to_stop_external_id) AS to_name,
  r.sort_order,
  COUNT(f.id) AS fare_count,
  MIN(f.amount) AS min_amount,
  MAX(f.currency) AS currency
FROM cv_home_provider_featured_routes r
LEFT JOIN cv_providers p
  ON BINARY p.code = BINARY r.provider_code
LEFT JOIN cv_provider_stops fs
  ON p.id_provider = fs.id_provider
 AND BINARY fs.external_id = BINARY r.from_stop_external_id
 AND fs.is_active = 1
LEFT JOIN cv_provider_stops fs_id
  ON p.id_provider = fs_id.id_provider
 AND fs_id.id = CAST(r.from_stop_external_id AS UNSIGNED)
 AND fs_id.is_active = 1
LEFT JOIN cv_provider_stops ts
  ON p.id_provider = ts.id_provider
 AND BINARY ts.external_id = BINARY r.to_stop_external_id
 AND ts.is_active = 1
LEFT JOIN cv_provider_stops ts_id
  ON p.id_provider = ts_id.id_provider
 AND ts_id.id = CAST(r.to_stop_external_id AS UNSIGNED)
 AND ts_id.is_active = 1
LEFT JOIN cv_provider_fares f
  ON p.id_provider = f.id_provider
 AND BINARY f.from_stop_external_id = BINARY COALESCE(fs.external_id, fs_id.external_id, r.from_stop_external_id)
 AND BINARY f.to_stop_external_id = BINARY COALESCE(ts.external_id, ts_id.external_id, r.to_stop_external_id)
 AND f.is_active = 1
WHERE BINARY r.provider_code = BINARY ?
  AND r.is_active = 1
GROUP BY
  r.provider_code,
  provider_name,
  r.from_stop_external_id,
  from_name,
  r.to_stop_external_id,
  to_name,
  r.sort_order
ORDER BY r.sort_order ASC, from_name ASC, to_name ASC
SQL;

            $statement = $connection->prepare($sql);
            if (!$statement instanceof mysqli_stmt) {
                continue;
            }

            $statement->bind_param('s', $providerCode);
            if (!$statement->execute()) {
                $statement->close();
                continue;
            }

            $result = $statement->get_result();
            if (!$result instanceof mysqli_result) {
                $statement->close();
                continue;
            }

            while ($row = $result->fetch_assoc()) {
                if (!is_array($row)) {
                    continue;
                }

                $fromId = trim((string) ($row['from_id'] ?? ''));
                $toId = trim((string) ($row['to_id'] ?? ''));
                $routeKey = cvHomepageFeaturedRouteKey($fromId, $toId);
                if ($routeKey === '') {
                    continue;
                }

                $resolvedFromId = trim((string) ($row['resolved_from_id'] ?? ''));
                $resolvedToId = trim((string) ($row['resolved_to_id'] ?? ''));
                $hasStops = ($resolvedFromId !== '' && $resolvedToId !== '');

                $grouped[$providerCode][] = [
                    'route_key' => $routeKey,
                    'provider_code' => $providerCode,
                    'provider_name' => (string) ($row['provider_name'] ?? $providerCode),
                    'from_id' => $fromId,
                    'from_name' => (string) ($row['from_name'] ?? ''),
                    'to_id' => $toId,
                    'to_name' => (string) ($row['to_name'] ?? ''),
                    'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                    'min_amount' => isset($row['min_amount']) ? (float) $row['min_amount'] : 0.0,
                    'currency' => (string) ($row['currency'] ?? 'EUR'),
                    'fare_count' => isset($row['fare_count']) ? (int) $row['fare_count'] : 0,
                    // Show featured routes as long as stops still exist, even if the fare table is empty.
                    // Price will be computed at search time.
                    'is_valid' => $hasStops,
                ];
            }

            $statement->close();
        }

        return $grouped;
    }
}

if (!function_exists('cvHomepageFeaturedRoutesReplaceProviderSelections')) {
    /**
     * @param array<int,string> $routeKeys
     */
    function cvHomepageFeaturedRoutesReplaceProviderSelections(
        mysqli $connection,
        string $providerCode,
        array $routeKeys
    ): bool {
        if (!cvHomepageFeaturedRoutesEnsureTable($connection)) {
            return false;
        }

        $providerCode = strtolower(trim($providerCode));
        if ($providerCode === '') {
            return false;
        }

        $normalizedRouteKeys = [];
        foreach ($routeKeys as $routeKey) {
            $parsed = cvHomepageFeaturedRouteParseKey((string) $routeKey);
            if ($parsed['key'] === '') {
                continue;
            }
            $normalizedRouteKeys[$parsed['key']] = $parsed;
        }

        $connection->begin_transaction();

        try {
            $deleteStatement = $connection->prepare('DELETE FROM cv_home_provider_featured_routes WHERE BINARY provider_code = BINARY ?');
            if (!$deleteStatement instanceof mysqli_stmt) {
                throw new RuntimeException('Prepare delete featured routes fallita.');
            }

            $deleteStatement->bind_param('s', $providerCode);
            if (!$deleteStatement->execute()) {
                $error = $deleteStatement->error;
                $deleteStatement->close();
                throw new RuntimeException('Delete featured routes fallita: ' . $error);
            }
            $deleteStatement->close();

            if (count($normalizedRouteKeys) > 0) {
                $insertStatement = $connection->prepare(
                    'INSERT INTO cv_home_provider_featured_routes (provider_code, from_stop_external_id, to_stop_external_id, sort_order, is_active) VALUES (?, ?, ?, ?, 1)'
                );
                if (!$insertStatement instanceof mysqli_stmt) {
                    throw new RuntimeException('Prepare insert featured routes fallita.');
                }

                $sortOrder = 1;
                foreach ($normalizedRouteKeys as $parsed) {
                    $fromId = (string) ($parsed['from_id'] ?? '');
                    $toId = (string) ($parsed['to_id'] ?? '');
                    $insertStatement->bind_param('sssi', $providerCode, $fromId, $toId, $sortOrder);
                    if (!$insertStatement->execute()) {
                        $error = $insertStatement->error;
                        $insertStatement->close();
                        throw new RuntimeException('Insert featured routes fallita: ' . $error);
                    }
                    $sortOrder++;
                }

                $insertStatement->close();
            }

            $connection->commit();
            return true;
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('cvFetchHomepageProviderFeaturedRoutes')) {
    /**
     * @param array<string,int> $providerLimits
     * @return array<int,array<string,mixed>>
     */
    function cvFetchHomepageProviderFeaturedRoutes(
        mysqli $connection,
        array $providerLimits,
        ?array $providerPriceModes = null
    ): array {
        $normalizedProviderLimits = [];
        foreach ($providerLimits as $providerCode => $limit) {
            $providerCode = strtolower(trim((string) $providerCode));
            if ($providerCode === '') {
                continue;
            }

            $safeLimit = is_numeric($limit) ? (int) $limit : 0;
            if ($safeLimit <= 0) {
                continue;
            }

            $normalizedProviderLimits[$providerCode] = min(200, $safeLimit);
        }

        if (count($normalizedProviderLimits) === 0) {
            return [];
        }

        $selectedByProvider = cvHomepageFeaturedRoutesFetchSelections($connection, array_keys($normalizedProviderLimits));
        $rows = [];

        foreach ($normalizedProviderLimits as $providerCode => $limit) {
            $providerRows = isset($selectedByProvider[$providerCode]) && is_array($selectedByProvider[$providerCode])
                ? $selectedByProvider[$providerCode]
                : [];
            if (count($providerRows) === 0) {
                continue;
            }

            $count = 0;
            foreach ($providerRows as $row) {
                if (!is_array($row) || empty($row['is_valid'])) {
                    continue;
                }

                $rows[] = [
                    'provider_code' => $providerCode,
                    'provider_name' => (string) ($row['provider_name'] ?? $providerCode),
                    'from_ref' => $providerCode . '|' . (string) ($row['from_id'] ?? ''),
                    'to_ref' => $providerCode . '|' . (string) ($row['to_id'] ?? ''),
                    'from_id' => (string) ($row['from_id'] ?? ''),
                    'from_name' => (string) ($row['from_name'] ?? ''),
                    'to_id' => (string) ($row['to_id'] ?? ''),
                    'to_name' => (string) ($row['to_name'] ?? ''),
                    'min_amount' => isset($row['min_amount']) ? (float) $row['min_amount'] : 0.0,
                    'currency' => (string) ($row['currency'] ?? 'EUR'),
                    'fare_count' => isset($row['fare_count']) ? (int) $row['fare_count'] : 0,
                    'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                ];

                $count++;
                if ($count >= $limit) {
                    break;
                }
            }
        }

        return cvPopularRoutesApplyDisplayAmounts($connection, $rows, $providerPriceModes);
    }
}

if (!function_exists('cvFetchReachabilityIndex')) {
    /**
     * Mappa gli archi diretti disponibili per suggerire solo destinazioni valide.
     * Formato:
     * [
     *   "provider|from_stop_id" => ["provider|to_stop_id", ...]
     * ]
     *
     * @return array<string, array<int, string>>
     */
    function cvFetchReachabilityIndex(mysqli $connection, ?string $providerCode = null): array
    {
        $sql = <<<SQL
SELECT
  p.code AS provider_code,
  f.from_stop_external_id AS from_id,
  f.to_stop_external_id AS to_id
FROM cv_provider_fares f
INNER JOIN cv_providers p
  ON p.id_provider = f.id_provider
WHERE f.is_active = 1
  AND p.is_active = 1
  AND f.from_stop_external_id <> f.to_stop_external_id
SQL;

        $bindProvider = null;
        if ($providerCode !== null && $providerCode !== '') {
            $sql .= "\n  AND p.code = ?";
            $bindProvider = $providerCode;
        }

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return [];
        }

        if ($bindProvider !== null) {
            $statement->bind_param('s', $bindProvider);
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

        /** @var array<string, array<string, bool>> $indexMap */
        $indexMap = [];

        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $provider = (string) ($row['provider_code'] ?? '');
            $fromId = (string) ($row['from_id'] ?? '');
            $toId = (string) ($row['to_id'] ?? '');
            if ($provider === '' || $fromId === '' || $toId === '') {
                continue;
            }

            $fromKey = $provider . '|' . $fromId;
            $toKey = $provider . '|' . $toId;

            if (!isset($indexMap[$fromKey])) {
                $indexMap[$fromKey] = [];
            }

            $indexMap[$fromKey][$toKey] = true;
        }

        $statement->close();

        $normalized = [];
        foreach ($indexMap as $fromKey => $destinations) {
            $normalized[$fromKey] = array_keys($destinations);
        }

        return $normalized;
    }
}

if (!function_exists('cvFetchProvinceOptions')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function cvFetchProvinceOptions(mysqli $connection): array
    {
        $tableCheck = $connection->query("SHOW TABLES LIKE 'provReg'");
        if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows === 0) {
            return [];
        }
        $tableCheck->free();

        $sql = 'SELECT id_prov, provincia, regione FROM provReg ORDER BY provincia ASC';
        $result = $connection->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'id_prov' => isset($row['id_prov']) ? (int) $row['id_prov'] : 0,
                'provincia' => (string) ($row['provincia'] ?? ''),
                'regione' => (string) ($row['regione'] ?? ''),
            ];
        }

        $result->free();
        return $rows;
    }
}

require_once __DIR__ . '/route_seo_tools.php';
require_once __DIR__ . '/blog_tools.php';
require_once __DIR__ . '/site_layout.php';
