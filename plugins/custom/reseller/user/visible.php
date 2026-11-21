<?php
/** The Reseller user page shows for a signed-in user who belongs to a reseller. Admins use the plugin page. */
require_once dirname(__DIR__) . '/lib/Reseller.php';

return static function (array $user, array $settings): bool {
    Reseller::setSettings($settings);
    return Reseller::resellerIdForUser((int) ($user['id'] ?? 0)) !== null;
};
