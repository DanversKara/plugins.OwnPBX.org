<?php
/**
 * The count next to Messages in the menu: unread texts on every number this user reads.
 * PluginManager::userBadge() calls it on each page and when the menu refreshes (every 30 s).
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

return static function (string $slug, array $settings, array $user): int {
    $ids = array_map(static fn(array $n): int => (int) $n['id'], Sms::numbersFor($user));
    return array_sum(Sms::unreadByNumber($ids));
};
