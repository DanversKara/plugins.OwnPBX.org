<?php
/** Administrator form on /plugins/ha-texts: a new token (the old one stops working at once). */
require_once dirname(__DIR__) . '/lib/HaTexts.php';

return static function (string $slug, array $settings): array {
    if (($_POST['ha_action'] ?? '') !== 'new_token' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf')];
    }
    HaTexts::token(true);
    return ['message' => 'New token made. Put it in Home Assistant; the old one no longer works.'];
};
