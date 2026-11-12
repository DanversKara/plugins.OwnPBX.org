<?php
/**
 * Public endpoints for Home Assistant (no sign-in; the token and an allowed extension are the proof):
 *   GET  /api/plugins/ha-texts/messages/inbox?ext=<ext>&limit=<n>
 *   POST /api/plugins/ha-texts/messages/send   JSON {"to": "...", "from": "<ext>", "body": "..."}
 * Header X-Msg-Token: the token on the plugin page. Answers JSON with "ok". Nothing here throws:
 * the core turns repeated errors into Safe Mode, and a stranger must not switch the plugin off.
 */
require_once dirname(__DIR__) . '/lib/HaTexts.php';

return static function (string $subPath, array $settings): void {
    $reply = static function (int $code, array $body): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    try {
        $path = trim($subPath, '/');
        if ($path !== 'messages/inbox' && $path !== 'messages/send') {
            $reply(404, ['ok' => false, 'error' => 'not found']);
            return;
        }
        $token = (string) ($_SERVER['HTTP_X_MSG_TOKEN'] ?? '');
        if (!HaTexts::tokenValid($token)) {
            // Same answer as the old PBX, so Home Assistant's notification reads the same.
            $reply(401, ['ok' => false, 'error' => 'bad token']);
            return;
        }
        $allowed = HaTexts::allowed($settings);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($path === 'messages/inbox') {
            $ext = preg_replace('/\D+/', '', (string) ($_GET['ext'] ?? '')) ?? '';
            if ($ext === '' || !in_array($ext, $allowed, true)) {
                $reply(403, ['ok' => false, 'error' => 'extension not allowed (plugin settings)']);
                return;
            }
            $reply(200, HaTexts::inbox($ext, (int) ($_GET['limit'] ?? 20), $settings));
            return;
        }

        if ($method !== 'POST') {
            $reply(405, ['ok' => false, 'error' => 'POST only']);
            return;
        }
        $in = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
        if (!is_array($in)) {
            $in = $_POST;
        }
        $from = preg_replace('/\D+/', '', (string) ($in['from'] ?? '')) ?? '';
        if ($from === '' || !in_array($from, $allowed, true)) {
            $reply(403, ['ok' => false, 'error' => 'extension not allowed (plugin settings)']);
            return;
        }
        try {
            HaTexts::send($from, trim((string) ($in['to'] ?? '')), (string) ($in['body'] ?? ''), $settings);
            $reply(200, ['ok' => true]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $reply(200, ['ok' => false, 'error' => $e->getMessage()]);
        }
    } catch (\Throwable $e) {
        error_log('ha-texts: ' . $e->getMessage());
        $reply(500, ['ok' => false, 'error' => 'server error']);
    }
};
