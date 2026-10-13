<?php
/**
 * Page header + layout opening tags.
 * Set $pageTitle and $activeNav before including this file.
 */
$pageTitle   = $pageTitle ?? 'Dashboard';
$activeNav   = $activeNav ?? '';
$libraryName = setting('library_name', 'School Library');

sync_notifications();
$unreadCount = unread_notification_count();
$admin       = current_admin();
$initials    = strtoupper(mb_substr($admin['full_name'] ?? 'A', 0, 1));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e($libraryName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <div class="main">
        <header class="topbar no-print">
            <button type="button" class="icon-btn menu-toggle" data-sidebar-toggle aria-label="Open navigation">
                <?= icon('menu', 20) ?>
            </button>
            <div class="topbar-title">
                <span class="topbar-library"><?= e($libraryName) ?></span>
                <span class="topbar-date"><?= e(date('l, F j, Y')) ?></span>
            </div>
            <div class="topbar-actions">
                <span class="kbd-hint" data-kbd-hint hidden>Press <kbd>/</kbd> to search</span>
                <a class="icon-btn bell" href="<?= e(url('notifications/index.php')) ?>" title="Notifications">
                    <?= icon('bell', 20) ?>
                    <?php if ($unreadCount > 0): ?>
                        <span class="bell-count"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a class="admin-chip" href="<?= e(url('settings/account.php')) ?>" title="My account">
                    <span class="avatar"><?= e($initials) ?></span>
                    <span class="admin-name"><?= e($admin['full_name'] ?? '') ?></span>
                </a>
            </div>
        </header>

        <main class="content">
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> no-print" role="status" data-flash="<?= e($f['type']) ?>">
                    <span class="alert-icon"><?= icon(['success' => 'check', 'info' => 'bell'][$f['type']] ?? 'alert', 18) ?></span>
                    <span><?= e($f['message']) ?></span>
                    <button type="button" class="alert-close" data-dismiss aria-label="Dismiss"><?= icon('x', 16) ?></button>
                </div>
            <?php endforeach; ?>
