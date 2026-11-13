<?php
/**
 * header.php — shared page header
 *
 * Variables expected before include:
 *   $pageTitle   string   Browser tab title
 *   $bodyClass   string   Optional class on <body>
 */
$pageTitle = $pageTitle ?? 'TERMINAL | AI Game Master';
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=VT323&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/public/css/terminal.css">
    <link rel="stylesheet" href="/public/css/components.css">
    <link rel="stylesheet" href="/public/css/screens.css">
    <link rel="stylesheet" href="/public/css/animations.css">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
