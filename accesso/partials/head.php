<?php
declare(strict_types=1);

$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'Accesso';
$bodyClass = isset($bodyClass) ? (string) $bodyClass : 'sb-left pace-done theme-primary';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="format-detection" content="telephone=no">
    <meta name="msapplication-tap-highlight" content="no">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1">
    <title><?= cvAccessoH($pageTitle) ?> · <?= cvAccessoH((string) ($state['config']['brand_name'] ?? 'Cercaviaggio')) ?></title>
    <link rel="stylesheet" href="<?= cvAccessoH(cvAccessoAssetUrl('css/font-awesome.css')) ?>">
    <link rel="stylesheet" href="<?= cvAccessoH(cvAccessoAssetUrl('css/material-design-iconic-font.css')) ?>">
    <link rel="stylesheet" href="<?= cvAccessoH(cvAccessoAssetUrl('css/animate.min.css')) ?>">
    <link rel="stylesheet" href="<?= cvAccessoH(cvAccessoAssetUrl('css/perfect-scrollbar.css')) ?>">
    <link rel="stylesheet" href="<?= cvAccessoH(cvAccessoAssetUrl('css/bootstrap.css')) ?>">
    <link rel="stylesheet" href="../assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="<?= cvAccessoH(cvAccessoAssetUrl('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= cvAccessoH(cvAccessoAssetUrl('css/custom.css')) ?>">
</head>
<body class="<?= cvAccessoH($bodyClass) ?>">
