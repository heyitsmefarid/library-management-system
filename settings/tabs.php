<?php
/** Tab navigation shared by the settings pages. Expects $settingsTab. */
if (!defined('APP_ROOT')) {
    http_response_code(404);
    exit;
}
$settingsTabs = [
    'general' => ['Library settings', 'settings/index.php'],
    'account' => ['My account',       'settings/account.php'],
    'admins'  => ['Administrators',   'settings/admins.php'],
];
?>
<nav class="tabs">
    <?php foreach ($settingsTabs as $key => [$label, $href]): ?>
        <a class="tab<?= $settingsTab === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
