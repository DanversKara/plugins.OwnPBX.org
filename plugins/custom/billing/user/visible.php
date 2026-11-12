<?php
/** Billing shows for every non-admin user once at least one plan is offered (admins use the plugin page). */
require_once dirname(__DIR__) . '/lib/Billing.php';

return static function (array $user, array $settings): bool {
    if (($user['role'] ?? '') === 'admin') {
        return false;
    }
    Billing::setSettings($settings);
    return Billing::plans(true) !== [];
};
