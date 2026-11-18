<?php
/** Administrator form on /plugins/launcher: a new token (the old one stops working at once). */
require_once dirname(__DIR__) . '/lib/LauncherFeed.php';

return static function (string $slug, array $settings): array {
    if (($_POST['launcher_action'] ?? '') !== 'new_token' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf')];
    }
    LauncherFeed::token(true);
    return ['message' => 'New token made. Put it in the launcher; the old one no longer works.'];
};
