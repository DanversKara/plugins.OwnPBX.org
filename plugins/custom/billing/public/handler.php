<?php
/**
 * Public endpoints, no sign-in:
 *   POST /api/plugins/billing/webhook           Stripe events (signature checked)
 *   GET  /api/plugins/billing/gate/<key>?ext=   dialplan: seconds of outside calling left (-1 = unlimited)
 *   GET  /api/plugins/billing/record/<key>?...  dialplan: an outside call ended (seconds used)
 *   GET  /api/plugins/billing/store             the Store (sign-up) page; POST places an order
 *   GET  /api/plugins/billing/store/thanks      back from Stripe Checkout
 * gate/record answer only the PBX itself (127.0.0.1) and need the plugin's internal key.
 * Nothing here throws: the core turns five errors in a row into Safe Mode, and a stranger must not
 * be able to switch the plugin off with bad requests.
 */
require_once dirname(__DIR__) . '/lib/Store.php';

// Defined before the return below: code after a file's return never runs.
if (!function_exists('billingStoreForm')) {
function billingStoreForm(array $settings, string $error): string
{
    $h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $plans = BillingStore::plans();
    if ($plans === []) {
        return '<p>No plans are on offer right now.</p>';
    }
    $out = '<h1>Choose your plan</h1>';
    if ($error !== '') {
        $out .= '<p class="err">' . $h($error) . '</p>';
    }
    $out .= '<form method="post">';
    $out .= '<input type="hidden" name="token" value="' . $h(BillingStore::formToken()) . '">';
    $out .= '<div class="trap" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
    $out .= '<div class="plans">';
    foreach ($plans as $i => $p) {
        $out .= '<label class="plan"><input type="radio" name="plan_id" value="' . (int) $p['id'] . '"' . ($i === 0 ? ' checked' : '') . '>'
            . '<strong>' . $h($p['name']) . '</strong> <span class="price">' . $h(Billing::money((int) $p['price_cents'], (string) $p['currency'])) . ' / month</span>'
            . ($p['description'] !== '' ? '<div>' . $h($p['description']) . '</div>' : '')
            . '<ul>' . implode('', array_map(static fn(string $f): string => '<li>' . $h($f) . '</li>', Billing::describe($p))) . '</ul></label>';
    }
    $out .= '</div>';
    $numbers = BillingStore::numbers();
    if ($numbers !== []) {
        $out .= '<label>Phone number <select name="number"><option value="">No number, thanks</option>';
        foreach ($numbers as $n) {
            $out .= '<option value="' . $h($n['number']) . '">' . $h($n['number'] . ($n['label'] !== '' ? ' — ' . $n['label'] : '')) . '</option>';
        }
        $out .= '</select></label>';
    }
    $out .= '<label>Your name <input type="text" name="full_name" maxlength="80" required></label>';
    $out .= '<label>E-mail <input type="email" name="email" maxlength="120" required></label>';
    $terms = trim((string) ($settings['store_terms_url'] ?? ''));
    if ($terms !== '') {
        $out .= '<label class="check"><input type="checkbox" name="terms" value="1" required> I accept the <a href="' . $h($terms) . '" target="_blank" rel="noopener">terms of service</a></label>';
    }
    $out .= '<p class="note">Next, Stripe saves your card. <strong>You are not charged until we approve your order.</strong></p>';
    $out .= '<button type="submit">Continue to card details</button></form>';
    return $out;
}

function billingStorePage(string $title, string $body): string
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . "<title>{$t}</title><style>"
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f6fa;color:#1f2430;margin:0;padding:24px}'
        . 'main{max-width:640px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.06)}'
        . 'h1{font-size:24px;margin-top:0}label{display:block;margin:14px 0 4px;font-weight:600}'
        . 'input[type=text],input[type=email],select{width:100%;padding:10px;border:1px solid #cfd3dc;border-radius:8px;font-size:15px;box-sizing:border-box;margin-top:4px}'
        . '.plans{display:grid;gap:10px}.plan{border:1px solid #cfd3dc;border-radius:10px;padding:12px;font-weight:400;cursor:pointer}'
        . '.plan:has(input:checked){border-color:#635bff;box-shadow:0 0 0 2px #635bff33}.price{float:right;font-weight:700}'
        . '.plan ul{margin:6px 0 0;padding-left:20px;color:#555}.check{font-weight:400}.note{color:#555;font-size:14px}'
        . 'button{background:#635bff;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-size:16px;cursor:pointer;margin-top:8px}'
        . '.err{background:#fde8e8;color:#9b1c1c;padding:10px;border-radius:8px}.trap{position:absolute;left:-10000px}'
        . "</style></head><body><main>{$body}</main></body></html>";
}
}


return static function (string $subPath, array $settings): void {
    Billing::setSettings($settings);
    $parts = explode('/', trim($subPath, '/'));
    $answer = static function (int $code, string $type, string $body): void {
        http_response_code($code);
        header('Content-Type: ' . $type);
        echo $body;
    };
    $local = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    try {
        // ---- Stripe webhook
        if ($parts[0] === 'webhook' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $payload = (string) file_get_contents('php://input');
            try {
                $event = BillingStripe::verifyWebhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), trim((string) ($settings['stripe_webhook_secret'] ?? '')));
            } catch (BillingStripeError $e) {
                $answer(400, 'application/json', json_encode(['error' => $e->getMessage()]));
                return;
            }
            $ok = Billing::handleEvent($event);
            $answer($ok ? 200 : 502, 'application/json', json_encode(['ok' => $ok]));
            return;
        }

        // ---- dialplan (PBX only)
        if (($parts[0] === 'gate' || $parts[0] === 'record') && count($parts) === 2) {
            if (!$local || !hash_equals(Billing::internalKey(), $parts[1])) {
                $answer(404, 'text/plain', 'not found');
                return;
            }
            $ext = (string) preg_replace('/\D/', '', (string) ($_GET['ext'] ?? ''));
            if ($parts[0] === 'gate') {
                $answer(200, 'text/plain', (string) Billing::callAllowance($ext));
                return;
            }
            $sec = max(0, (int) ($_GET['sec'] ?? 0));
            Billing::record(Billing::userIdForExtension($ext), 'call_seconds', $sec, 'to ' . preg_replace('/[^0-9+]/', '', (string) ($_GET['to'] ?? '')));
            $answer(200, 'text/plain', 'ok');
            return;
        }

        // ---- Store
        if ($parts[0] === 'store') {
            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            if (!BillingStore::open()) {
                $answer(404, 'text/html; charset=utf-8', billingStorePage('Store', '<p>The store is not open.</p>'));
                return;
            }
            if (($parts[1] ?? '') === 'thanks') {
                $answer(200, 'text/html; charset=utf-8', billingStorePage('Thank you', '<h1>Thank you!</h1><p>Your card is saved with Stripe. <strong>Nothing has been charged yet.</strong> '
                    . 'We review every order; when it is approved the first month is charged and you get an e-mail with your new phone account.</p>'));
                return;
            }
            $error = '';
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                try {
                    $url = BillingStore::placeOrder($_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                    http_response_code(303);
                    header('Location: ' . $url);
                    return;
                } catch (\Throwable $e) {
                    $error = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The order could not be started. Please try again later.';
                    if (!$e instanceof \InvalidArgumentException) {
                        error_log('billing store: ' . $e->getMessage());
                    }
                }
            }
            $answer(200, 'text/html; charset=utf-8', billingStorePage('Sign up', billingStoreForm($settings, $error)));
            return;
        }
        $answer(404, 'text/plain', 'not found');
    } catch (\Throwable $e) {
        error_log('billing public: ' . $e->getMessage());
        $answer(500, 'text/plain', 'error');
    }
};
