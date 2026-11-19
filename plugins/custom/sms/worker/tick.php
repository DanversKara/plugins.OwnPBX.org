<?php

declare(strict_types=1);

/**
 * Sends the answers the emergency lockdown channel left waiting.
 *
 * A command arrives on the provider's webhook. Answering from inside that request means the
 * provider waits while we call its own send API, which can take longer than its patience: it
 * reports a timeout for a message that was delivered, and may redeliver the incoming text. So
 * the webhook only queues the answer, and this - running every couple of seconds in the webhook
 * worker - is what actually sends it.
 */

require_once dirname(__DIR__) . '/lib/Sms.php';
require_once dirname(__DIR__, 3) . '/web/src/services/SmsGuardService.php';

return static function (string $slug, array $settings): void {
    $pending = SmsGuardService::takeQueuedReplies();
    if ($pending === []) {
        return;
    }

    $number = null;
    try {
        $number = getDB()
            ->query('SELECT * FROM plg_sms_numbers WHERE is_active = 1 ORDER BY id LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('sms guard worker: ' . $e->getMessage());
    }
    if ($number === null) {
        return;
    }

    foreach ($pending as $reply) {
        try {
            Sms::send($number, (string) $reply['to'], (string) $reply['text'], [], 0, $settings);
        } catch (\Throwable $e) {
            // One answer that cannot be sent must not hold up the next, and must never stop the
            // worker: the lockdown it was confirming has already happened either way.
            error_log('sms guard reply: ' . $e->getMessage());
        }
    }
};
