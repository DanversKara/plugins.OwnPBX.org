<?php
/**
 * The read-only address a TV launcher (or any screen) polls:
 *   GET /api/plugins/launcher/events?since=<id>&limit=<n>   header X-Launcher-Token
 * Answers JSON with "ok". Nothing here throws: the core turns repeated errors into Safe Mode,
 * and a stranger must not be able to switch the plugin off.
 */
require_once dirname(__DIR__) . '/lib/LauncherFeed.php';

return static function (string $subPath, array $settings): void {
    $reply = static function (int $code, array $body): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    try {
        if (trim($subPath, '/') !== 'events') {
            $reply(404, ['ok' => false, 'error' => 'not found']);
            return;
        }
        // A screen on the wall polls; it never changes anything.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $reply(405, ['ok' => false, 'error' => 'GET only']);
            return;
        }
        $token = (string) ($_SERVER['HTTP_X_LAUNCHER_TOKEN'] ?? '');
        if ($token === '') {
            // A TV remote is a poor way to type a header; a query token is allowed too.
            $token = (string) ($_GET['token'] ?? '');
        }
        if (!LauncherFeed::tokenValid($token)) {
            $reply(401, ['ok' => false, 'error' => 'bad token']);
            return;
        }
        $reply(200, LauncherFeed::events(
            $settings,
            max(0, (int) ($_GET['since'] ?? 0)),
            (int) ($_GET['limit'] ?? 20)
        ));
    } catch (\Throwable $e) {
        error_log('launcher endpoint: ' . $e->getMessage());
        $reply(500, ['ok' => false, 'error' => 'server error']);
    }
};
