<?php
declare(strict_types=1);

$currentUser = is_array($state['current_user'] ?? null) ? $state['current_user'] : [];
$menuItems = is_array($navItems ?? null) ? $navItems : [];
$currentUserLogoPath = trim((string) ($currentUser['logo_path'] ?? ''));
$currentUserLogoUrl = $currentUserLogoPath !== '' ? cvAccessoAccountLogoUrl($currentUserLogoPath) : '';

if (function_exists('cvAccessoIsAdmin') && cvAccessoIsAdmin($state)) {
    $hasSettings = false;
    foreach ($menuItems as $item) {
        if (is_array($item) && (string) ($item['slug'] ?? '') === 'settings') {
            $hasSettings = true;
            break;
        }
    }

    if (!$hasSettings) {
        $menuItems[] = [
            'slug' => 'settings',
            'label' => 'Settings',
            'href' => '#',
            'icon' => 'fa-sliders',
            'children' => [
                [
                    'slug' => 'settings-search',
                    'label' => 'Ricerca',
                    'href' => cvAccessoUrl('settings.php'),
                ],
                [
                    'slug' => 'settings-places',
                    'label' => 'Macroaree',
                    'href' => cvAccessoUrl('places.php'),
                ],
                [
                    'slug' => 'settings-payments',
                    'label' => 'Pagamenti',
                    'href' => cvAccessoUrl('pagamenti.php'),
                ],
                [
                    'slug' => 'settings-mail',
                    'label' => 'Mail',
                    'href' => cvAccessoUrl('mail-settings.php'),
                ],
            ],
        ];
    }
}
?>
<aside id="app-aside" class="app-aside left in light">
    <header class="aside-header">
        <div class="animated">
            <a href="<?= cvAccessoH(cvAccessoUrl('index.php')) ?>" id="app-brand" class="app-brand">
                <span id="brand-icon" class="brand-icon"><i class="fa fa-bus"></i></span>
                <span id="brand-name" class="brand-icon foldable"><?= cvAccessoH((string) ($state['config']['brand_name'] ?? 'Cercaviaggio')) ?></span>
            </a>
        </div>
    </header>

    <div class="aside-user">
        <div class="media">
            <div class="media-left">
                <div class="avatar avatar-md avatar-circle cv-avatar-badge">
                    <?php if ($currentUserLogoUrl !== ''): ?>
                        <img src="<?= cvAccessoH($currentUserLogoUrl) ?>" alt="Logo account" class="cv-user-logo">
                    <?php else: ?>
                        <span><i class="fa fa-user"></i></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="media-body">
                <div class="foldable">
                    <h5><a href="<?= cvAccessoH(cvAccessoUrl('index.php')) ?>" class="username"><?= cvAccessoH((string) ($currentUser['name'] ?? 'Utente')) ?></a></h5>
                    <small class="cv-user-email"><?= cvAccessoH((string) ($currentUser['email'] ?? '')) ?></small>
                    <div class="cv-role-tag"><?= cvAccessoH(cvAccessoRoleLabel($state)) ?></div>
                    <div class="cv-provider-scope"><?= cvAccessoH(cvAccessoScopeLabel($state)) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="aside-scroll">
        <div class="aside-scroll-inner" id="aside-scroll-inner">
            <ul class="aside-menu aside-left-menu">
                <?php foreach ($menuItems as $item): ?>
                    <?php
                    $children = isset($item['children']) && is_array($item['children']) ? $item['children'] : [];
                    $hasChildren = count($children) > 0;
                    $isParentActive = (string) ($item['slug'] ?? '') === $activeSlug;
                    $isChildActive = false;
                    foreach ($children as $child) {
                        if ((string) ($child['slug'] ?? '') === $activeSlug) {
                            $isChildActive = true;
                            break;
                        }
                    }
                    $liClass = 'menu-item';
                    if ($hasChildren) {
                        $liClass .= ' has-submenu';
                    }
                    if ($isParentActive || $isChildActive) {
                        $liClass .= ' active';
                    }
                    if ($hasChildren && $isChildActive) {
                        $liClass .= ' open';
                    }
                    ?>
                    <li class="<?= cvAccessoH($liClass) ?>">
                        <a
                            href="<?= cvAccessoH((string) ($item['href'] ?? '#')) ?>"
                            class="<?= $hasChildren ? 'menu-link submenu-toggle' : 'menu-link' ?>"
                        >
                            <span class="menu-icon"><i class="fa <?= cvAccessoH((string) ($item['icon'] ?? 'fa-circle')) ?>"></i></span>
                            <span class="menu-text foldable"><?= cvAccessoH((string) ($item['label'] ?? '')) ?></span>
                            <?php if ($hasChildren): ?>
                                <span class="menu-caret fa fa-angle-right"></span>
                            <?php endif; ?>
                        </a>
                        <?php if ($hasChildren): ?>
                            <ul class="submenu">
                                <?php foreach ($children as $child): ?>
                                    <li class="<?= (string) ($child['slug'] ?? '') === $activeSlug ? 'active' : '' ?>">
                                        <a href="<?= cvAccessoH((string) ($child['href'] ?? '#')) ?>">
                                            <span class="menu-label"><?= cvAccessoH((string) ($child['label'] ?? '')) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</aside>
