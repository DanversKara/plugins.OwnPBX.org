<?php
/**
 * Public page for an invited person (no sign-in; the link is the proof):
 *   GET  /api/plugins/invites/join/<token>   the form (or why the link no longer works)
 *   POST /api/plugins/invites/join/<token>   create the account, then the "ready" page
 * Nothing here throws: the core turns repeated errors into Safe Mode, and a stranger must not be
 * able to switch the plugin off with bad requests.
 */
require_once dirname(__DIR__) . '/lib/Invites.php';

// Defined before the return below: code after a file's return never runs.
if (!function_exists('invitesPage')) {
function invitesPage(string $title, string $body): string
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $t . '</title><style>'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f6fa;color:#1f2430;margin:0;padding:24px}'
        . 'main{max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.06)}'
        . 'h1{font-size:24px;margin-top:0}label{display:block;margin:14px 0 4px;font-weight:600}'
        . 'input{width:100%;padding:10px;border:1px solid #cfd3dc;border-radius:8px;font-size:15px;box-sizing:border-box}'
        . 'input:disabled{background:#f0f1f5;color:#555}.note{color:#555;font-size:14px;font-weight:400}'
        . 'button,.btn{display:inline-block;background:#0ea5e9;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-size:16px;cursor:pointer;margin-top:18px;text-decoration:none}'
        . '.err{background:#fde8e8;color:#9b1c1c;padding:10px;border-radius:8px}.trap{position:absolute;left:-10000px}'
        . '.box{background:#f0f9ff;border-radius:8px;padding:12px;line-height:1.8}'
        . '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}main{background:#1e293b}input{background:#0f172a;color:#e2e8f0;border-color:#334155}input:disabled{background:#1e293b;color:#94a3b8}.note{color:#94a3b8}.box{background:#0c4a6e}}'
        . '</style></head><body><main>' . $body . '</main></body></html>';
}
}


return static function (string $subPath, array $settings): void {
    Invites::setSettings($settings);
    $h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $send = static function (int $code, string $title, string $body): void {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
        header('Referrer-Policy: no-referrer');
        echo invitesPage($title, $body);
    };
    try {
        $parts = explode('/', trim($subPath, '/'));
        if (count($parts) !== 2 || $parts[0] !== 'join') {
            $send(404, 'Not found', '<h1>Not found</h1>');
            return;
        }
        $token = $parts[1];
        $inv = Invites::byToken($token);
        $site = function_exists('getSystemSetting') ? (string) getSystemSetting('brand_title', 'OwnPBX') : 'OwnPBX';
        if ($inv === null || Invites::status($inv) !== 'open') {
            $why = $inv === null ? 'This invite link is not valid.' : [
                'used' => 'This invite has already been used.',
                'expired' => 'This invite has expired.',
                'withdrawn' => 'This invite was withdrawn.',
            ][Invites::status($inv)] ?? 'This invite is no longer valid.';
            $send(410, 'Invite', '<h1>' . $h($site) . '</h1><p>' . $h($why) . ' Ask the person who invited you for a new one.</p>');
            return;
        }

        $error = '';
        $old = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $old = $_POST;
            try {
                $acc = Invites::accept($token, $_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                $portal = Invites::portalUrl();
                $send(200, 'Welcome', '<h1>Welcome, ' . $h($acc['full_name']) . '!</h1>'
                    . '<p>Your account on ' . $h($site) . ' is ready.</p>'
                    . '<div class="box"><div>User name: <strong>' . $h($acc['username']) . '</strong></div>'
                    . '<div>Your extension: <strong>' . $h($acc['extension']) . '</strong></div></div>'
                    . '<p><a class="btn" href="' . $h($portal . '/login') . '">Sign in</a></p>'
                    . '<p class="note">After signing in, <strong>My Phone</strong> has a QR code that signs the phone app in, and the settings for a desk phone.</p>');
                return;
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('invites join: ' . $e->getMessage());
                $error = 'Your account could not be created right now. Please try again later, or contact the person who invited you.';
            }
        }

        $val = static fn(string $k): string => $h($old[$k] ?? '');
        $emailField = $inv['email'] !== ''
            ? '<label>E-mail</label><input type="email" value="' . $h($inv['email']) . '" disabled>'
            : '<label for="email">E-mail <span class="note">(optional, for password resets)</span></label><input id="email" type="email" name="email" maxlength="120" value="' . $val('email') . '" autocomplete="email">';
        $guest = !empty($inv['is_guest']) ? '<p class="note">This is a guest account: ' . $h(substr(Invites::accountText($inv), 7, -1)) . '.</p>' : '';
        $send(200, 'Join ' . $site, '<h1>Join ' . $h($site) . '</h1>'
            . '<p>You are invited to an account with your own extension.</p>' . $guest
            . ($error !== '' ? '<p class="err">' . $h($error) . '</p>' : '')
            . '<form method="post" autocomplete="on">'
            . '<input type="hidden" name="form" value="' . $h(Invites::formToken()) . '">'
            . '<div class="trap" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<label for="full_name">Your name</label><input id="full_name" type="text" name="full_name" maxlength="80" required value="' . $h($old['full_name'] ?? $inv['label']) . '" autocomplete="name">'
            . $emailField
            . '<label for="username">User name <span class="note">(you sign in with this)</span></label><input id="username" type="text" name="username" maxlength="32" required pattern="[A-Za-z][A-Za-z0-9._\-]{2,31}" value="' . $val('username') . '" autocomplete="username">'
            . '<label for="password">Password <span class="note">(at least 10 characters)</span></label><input id="password" type="password" name="password" minlength="10" required autocomplete="new-password">'
            . '<label for="password2">Password again</label><input id="password2" type="password" name="password2" minlength="10" required autocomplete="new-password">'
            . '<button type="submit">Create my account</button></form>'
            . '<p class="note">The link works until ' . $h(date('F j, Y', (int) strtotime((string) $inv['expires_at']))) . '.</p>');
    } catch (\Throwable $e) {
        error_log('invites: ' . $e->getMessage());
        $send(500, 'Error', '<h1>Something went wrong</h1><p>Please try again later.</p>');
    }
};
