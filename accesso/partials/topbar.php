<?php
declare(strict_types=1);
?>
<nav id="app-navbar" class="app-navbar p-l-lg p-r-md in primary">
    <div id="navbar-header" class="pull-left">
        <button id="aside-fold" class="hamburger visible-lg-inline-block hamburger--arrowalt is-active js-hamburger" type="button">
            <span class="hamburger-box">
                <span class="hamburger-inner"></span>
            </span>
        </button>
        <button id="aside-toggle" class="hamburger hidden-lg hamburger--spin js-hamburger" type="button">
            <span class="hamburger-box">
                <span class="hamburger-inner"></span>
            </span>
        </button>
        <h5 id="page-title" class="visible-md-inline-block visible-lg-inline-block m-l-md"><?= cvAccessoH($pageTitle) ?></h5>
    </div>

    <div>
        <ul id="top-nav" class="pull-right">
            <li class="nav-item">
                <span class="cv-top-scope"><?= cvAccessoH(cvAccessoScopeLabel($state)) ?></span>
            </li>
            <li class="nav-item">
                <form method="post" action="<?= cvAccessoH(cvAccessoUrl(basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php')))) ?>" class="cv-logout-form">
                    <input type="hidden" name="action" value="logout">
                    <?= cvAccessoCsrfField() ?>
                    <button type="submit" class="btn btn-primary btn-sm cv-logout-btn">
                        <i class="fa fa-power-off"></i> Logout
                    </button>
                </form>
            </li>
        </ul>
    </div>
</nav>

<main id="app-main" class="app-main in">
    <div class="wrap">
        <section class="app-content">
            <?php cvAccessoRenderMessages($state); ?>
