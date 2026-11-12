<?php
/**
 * Messages page forms (the core has checked CSRF): send a message (optionally as a reply and/or
 * with a GIF from the search), delete a message or a whole conversation, search GIFs, and "poll"
 * for the page's script to see whether something new arrived.
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

return static function (string $slug, array $settings, array $user): array {
    $numbers = [];
    foreach (Sms::numbersFor($user) as $n) {
        $numbers[(int) $n['id']] = $n;
    }
    $action = (string) ($_POST['sms_action'] ?? '');
    if ($action === 'poll') {
        return ['json' => ['last' => Sms::lastId(array_keys($numbers))]];
    }
    if ($action === 'gif_search') {
        try {
            return ['json' => ['success' => true, 'gifs' => Sms::gifSearch($settings, (string) ($_POST['q'] ?? ''))]];
        } catch (\Throwable $e) {
            return ['json' => ['success' => false, 'error' => $e->getMessage()]];
        }
    }
    $number = $numbers[(int) ($_POST['number_id'] ?? 0)] ?? null;
    if (!in_array($action, ['send', 'contact', 'delete', 'delete_thread'], true)) {
        return [];
    }
    if ($number === null) {
        return ['error' => 'This number is not yours.'];
    }
    if ($action === 'contact') {
        try {
            Sms::saveContact((int) $number['id'], (string) ($_POST['peer'] ?? ''), (string) ($_POST['name'] ?? ''));
            return ['message' => 'Name saved.'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
    if ($action === 'delete') {
        return Sms::deleteMessage((int) $number['id'], (int) ($_POST['message_id'] ?? 0))
            ? ['message' => 'Message deleted.']
            : ['error' => 'That message was not found.'];
    }
    if ($action === 'delete_thread') {
        $count = Sms::deleteThread((int) $number['id'], SmsProvider::e164((string) ($_POST['peer'] ?? '')));
        return ['message' => $count . ' message(s) deleted.'];
    }
    $files = [];
    $up = $_FILES['media'] ?? null;
    foreach (is_array($up['name'] ?? null) ? array_keys($up['name']) : [] as $i) {
        if ((int) $up['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ((int) $up['error'][$i] !== UPLOAD_ERR_OK) {
            return ['error' => 'A picture could not be uploaded (larger than the server allows?).'];
        }
        $files[] = ['name' => $up['name'][$i], 'tmp_name' => $up['tmp_name'][$i], 'size' => $up['size'][$i]];
    }
    try {
        $extra = [];
        $gifUrl = trim((string) ($_POST['gif_url'] ?? ''));
        if ($gifUrl !== '') {
            $extra[] = Sms::fetchGif($gifUrl);
        }
        Sms::send($number, (string) ($_POST['peer'] ?? ''), (string) ($_POST['text'] ?? ''), $files, (int) $user['id'], $settings,
            (int) ($_POST['reply_to'] ?? 0), $extra);
        return ['message' => 'Sent.'];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
};
