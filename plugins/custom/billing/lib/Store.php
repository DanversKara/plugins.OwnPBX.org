<?php

declare(strict_types=1);

require_once __DIR__ . '/Billing.php';

/**
 * Store: a public sign-up page. New customers pick a plan (and, optionally, a phone number you
 * offer), then enter their card on Stripe's own Checkout page. Checkout runs in *setup* mode: it
 * only saves the card. NOBODY IS CHARGED AND NOTHING IS CREATED UNTIL THE ADMIN APPROVES:
 *   1. a free extension (Store range) and the number are checked,
 *   2. the subscription starts on the saved card (charged now; a declined card stops here),
 *   3. the user is created (activation e-mail with a link to set the password) and the number
 *      routed to their extension,
 *   4. the subscription is linked, so the plan's limits apply.
 * Reject only closes the order: the card was never charged.
 * Ported from the old OwnPBX store_ui.py.
 */
final class BillingStore
{
    public const STATUS = [
        'started' => ['Card not entered', 'secondary'],
        'awaiting' => ['Awaiting approval', 'warning'],
        'approved' => ['Approved', 'success'],
        'rejected' => ['Rejected', 'danger'],
        'failed' => ['Payment failed', 'danger'],
    ];
    private const TOKEN_TTL = 3600;
    private const MAX_ORDERS_PER_IP_HOUR = 5;

    public static function open(): bool
    {
        return Billing::enabled() && (Billing::settings()['store_enabled'] ?? '0') === '1';
    }

    public static function url(): string
    {
        return Billing::baseUrl() . '/api/plugins/billing/store';
    }

    /** @return list<array<string, mixed>> plans offered in the Store (published in the current mode) */
    public static function plans(): array
    {
        $mode = Billing::mode();
        return array_values(array_filter(Billing::plans(true), static fn(array $p): bool => !empty($p['in_store'])
            && $p['stripe_price_id'] !== '' && $p['stripe_mode'] === $mode));
    }

    /** @return list<array{number: string, label: string}> numbers offered that nothing else answers */
    public static function numbers(): array
    {
        $rows = getDB()->query('SELECT number, label FROM plg_billing_store_numbers ORDER BY number')->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static fn(array $r): bool => self::numberFree((string) $r['number'])));
    }

    public static function numberFree(string $number): bool
    {
        $digits = (string) preg_replace('/\D/', '', $number);
        if ($digits === '') {
            return false;
        }
        $q = getDB()->prepare("SELECT COUNT(*) FROM pbx_dids WHERE REPLACE(did_number, '+', '') IN (?, ?)");
        $q->execute([$digits, ltrim($digits, '1')]);
        if ((int) $q->fetchColumn() > 0) {
            return false;
        }
        // Not promised to an order that waits for approval, or one still at Stripe (an hour).
        $o = getDB()->prepare("SELECT COUNT(*) FROM plg_billing_orders WHERE number = ? AND (status = 'awaiting'
            OR (status = 'started' AND created_at > NOW() - INTERVAL 1 HOUR))");
        $o->execute([$number]);
        return (int) $o->fetchColumn() === 0;
    }

    public static function setNumbers(string $text): void
    {
        $db = getDB();
        $db->exec('DELETE FROM plg_billing_store_numbers');
        $add = $db->prepare('INSERT IGNORE INTO plg_billing_store_numbers (number, label) VALUES (?, ?)');
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            [$num, $label] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
            $num = (str_starts_with($num, '+') ? '+' : '') . preg_replace('/\D/', '', $num);
            if (strlen(ltrim($num, '+')) >= 7) {
                $add->execute([$num, mb_substr($label, 0, 60)]);
            }
        }
    }

    // ------------------------------------------------------------------ public form

    /** A signed, time-limited form token (the public page has no session, so no CSRF token). */
    public static function formToken(?int $now = null): string
    {
        $ts = (string) ($now ?? time());
        return $ts . '.' . hash_hmac('sha256', 'store-form|' . $ts, Billing::internalKey());
    }

    public static function tokenValid(string $token, ?int $now = null): bool
    {
        [$ts, $sig] = array_pad(explode('.', $token, 2), 2, '');
        $age = ($now ?? time()) - (int) $ts;
        return ctype_digit($ts) && $age >= 2 && $age <= self::TOKEN_TTL
            && hash_equals(hash_hmac('sha256', 'store-form|' . $ts, Billing::internalKey()), $sig);
    }

    /**
     * Validates an order and sends the customer to Stripe Checkout (setup mode).
     * @return string the Stripe URL
     */
    public static function placeOrder(array $in, string $ip): string
    {
        if (!self::open()) {
            throw new \InvalidArgumentException('The Store is closed.');
        }
        if (trim((string) ($in['website'] ?? '')) !== '' || !self::tokenValid((string) ($in['token'] ?? ''))) {
            throw new \InvalidArgumentException('The form expired. Reload the page and try again.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($in['full_name'] ?? '')) ?? '');
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new \InvalidArgumentException('Enter your name.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 120) {
            throw new \InvalidArgumentException('Enter a valid e-mail address.');
        }
        if (empty($in['terms']) && trim((string) (Billing::settings()['store_terms_url'] ?? '')) !== '') {
            throw new \InvalidArgumentException('Please accept the terms of service.');
        }
        $plan = null;
        foreach (self::plans() as $p) {
            if ((int) $p['id'] === (int) ($in['plan_id'] ?? 0)) {
                $plan = $p;
            }
        }
        if ($plan === null) {
            throw new \InvalidArgumentException('Choose a plan.');
        }
        $number = trim((string) ($in['number'] ?? ''));
        if ($number !== '' && !in_array($number, array_column(self::numbers(), 'number'), true)) {
            throw new \InvalidArgumentException('That number was just taken. Pick another one.');
        }
        $db = getDB();
        $recent = $db->prepare('SELECT COUNT(*) FROM plg_billing_orders WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $recent->execute([$ip]);
        if ((int) $recent->fetchColumn() >= self::MAX_ORDERS_PER_IP_HOUR) {
            throw new \InvalidArgumentException('Too many orders from your address. Try again later.');
        }
        $waiting = $db->prepare("SELECT COUNT(*) FROM plg_billing_orders WHERE email = ? AND status = 'awaiting'");
        $waiting->execute([$email]);
        if ((int) $waiting->fetchColumn() > 0) {
            throw new \InvalidArgumentException('An order for this e-mail address is already waiting for approval.');
        }
        $exists = $db->prepare('SELECT COUNT(*) FROM sys_users WHERE LOWER(TRIM(email)) = ?');
        $exists->execute([$email]);
        if ((int) $exists->fetchColumn() > 0) {
            throw new \InvalidArgumentException('This e-mail address already has an account. Sign in and change your plan under Billing.');
        }

        $db->prepare('INSERT INTO plg_billing_orders (status, full_name, email, plan_id, number, stripe_mode, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['started', $name, $email, (int) $plan['id'], $number, Billing::mode(), mb_substr($ip, 0, 45)]);
        $orderId = (int) $db->lastInsertId();
        $st = Billing::stripe();
        $cus = $st->post('/v1/customers', ['name' => $name, 'email' => $email, 'metadata' => ['pbx_store_order' => $orderId]]);
        $session = $st->post('/v1/checkout/sessions', [
            'mode' => 'setup',
            'customer' => (string) $cus['id'],
            'currency' => (string) $plan['currency'],
            'payment_method_types' => ['card'],
            'metadata' => ['pbx_store_order' => $orderId],
            'setup_intent_data' => ['metadata' => ['pbx_store_order' => $orderId]],
            'success_url' => self::url() . '/thanks?order=' . $orderId,
            'cancel_url' => self::url(),
        ]);
        $db->prepare('UPDATE plg_billing_orders SET stripe_customer_id = ?, stripe_session_id = ?, updated_at = NOW() WHERE id = ?')
            ->execute([(string) $cus['id'], (string) $session['id'], $orderId]);
        return (string) $session['url'];
    }

    /** Webhook: Checkout saved the card — the order now waits for the admin. */
    public static function onCheckoutCompleted(array $session): void
    {
        $orderId = (int) ($session['metadata']['pbx_store_order'] ?? 0);
        $order = self::order($orderId);
        if ($order === null || $order['status'] !== 'started' || $order['stripe_session_id'] !== ($session['id'] ?? '')) {
            return;
        }
        $pm = '';
        if (!empty($session['setup_intent'])) {
            $si = Billing::stripe()->get('/v1/setup_intents/' . $session['setup_intent']);
            $pm = (string) ($si['payment_method'] ?? '');
        }
        getDB()->prepare("UPDATE plg_billing_orders SET status = 'awaiting', payment_method = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$pm, $orderId]);
        self::notifyAdmin($orderId);
    }

    public static function order(int $id): ?array
    {
        $q = getDB()->prepare('SELECT * FROM plg_billing_orders WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function orders(int $limit = 100): array
    {
        return getDB()->query('SELECT o.*, p.name AS plan_name, p.price_cents, p.currency FROM plg_billing_orders o
            LEFT JOIN plg_billing_plans p ON p.id = o.plan_id ORDER BY o.id DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function nextFreeExtension(): string
    {
        $s = Billing::settings();
        $from = max(100, (int) ($s['store_ext_from'] ?? 2000));
        $to = max($from, (int) ($s['store_ext_to'] ?? 2999));
        require_once Billing::coreDir() . '/internal_numbers.php';
        for ($e = $from; $e <= $to; $e++) {
            if (internalNumberOwner((string) $e) === null) {
                return (string) $e;
            }
        }
        throw new \RuntimeException("No free extension left between {$from} and {$to} (Store range in the plugin settings).");
    }

    /**
     * Approve: charge the first month on the saved card, then create the account and route the
     * number. A declined card stops before anything is created.
     * @return string what happened, for the admin
     */
    public static function approve(int $orderId): string
    {
        $o = self::order($orderId);
        if ($o === null || $o['status'] !== 'awaiting') {
            throw new \InvalidArgumentException('That order is not waiting for approval.');
        }
        if ($o['stripe_mode'] !== Billing::mode()) {
            throw new \InvalidArgumentException('The order was made with the other Stripe mode (test/live).');
        }
        $plan = Billing::plan((int) $o['plan_id']);
        if ($plan === null || $plan['stripe_price_id'] === '') {
            throw new \InvalidArgumentException('The ordered plan no longer exists or is not published.');
        }
        $ext = self::nextFreeExtension();
        if ($o['number'] !== '' && !self::numberFreeIgnoring((string) $o['number'], $orderId)) {
            throw new \InvalidArgumentException("The number {$o['number']} is now answered by something else. Reject the order or free the number.");
        }
        $st = Billing::stripe();
        if ($o['payment_method'] !== '') {
            $st->post("/v1/customers/{$o['stripe_customer_id']}", ['invoice_settings' => ['default_payment_method' => $o['payment_method']]]);
        }
        try {
            $sub = $st->post('/v1/subscriptions', [
                'customer' => $o['stripe_customer_id'],
                'items' => [['price' => $plan['stripe_price_id']]],
                'payment_behavior' => 'error_if_incomplete',
                'metadata' => ['pbx_store_order' => $orderId],
            ], "pbx-store-order-{$orderId}-subscription");
        } catch (BillingStripeError $e) {
            getDB()->prepare("UPDATE plg_billing_orders SET status = 'failed', note = ?, updated_at = NOW() WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 255), $orderId]);
            return 'The card was declined: ' . $e->getMessage() . ' Nothing was created.';
        }

        // Create the account like the Users page does (activation e-mail with a set-password link).
        require_once Billing::coreDir() . '/services/UserService.php';
        $res = UserService::saveUser([
            'csrf_token' => getCSRFToken(),
            'username' => $ext,
            'full_name' => $o['full_name'],
            'email' => $o['email'],
            'role' => 'user',
            'extension' => $ext,
            'allowed_phone_mode' => 'both',
            'is_active' => 1,
        ]);
        if (empty($res['success'])) {
            // The card was charged: cancel the subscription right away and refund the invoice.
            self::undoCharge($sub);
            getDB()->prepare("UPDATE plg_billing_orders SET status = 'failed', note = ?, updated_at = NOW() WHERE id = ?")
                ->execute([mb_substr('Account not created: ' . ($res['error'] ?? ''), 0, 255), $orderId]);
            return 'The account could not be created (' . ($res['error'] ?? '?') . '); the charge was refunded.';
        }
        $uid = Billing::userIdForExtension($ext);
        getDB()->prepare('INSERT INTO plg_billing_customers (user_id, stripe_mode, stripe_customer_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE stripe_mode = VALUES(stripe_mode), stripe_customer_id = VALUES(stripe_customer_id)')
            ->execute([$uid, Billing::mode(), $o['stripe_customer_id']]);
        Billing::applySubscription($uid, $sub);
        $st->post("/v1/customers/{$o['stripe_customer_id']}", ['metadata' => ['pbx_user_id' => $uid]]);

        $routed = '';
        if ($o['number'] !== '') {
            require_once Billing::coreDir() . '/services/RouteService.php';
            $r = RouteService::saveDIDRoute([
                'csrf_token' => getCSRFToken(),
                'did_number' => $o['number'],
                'title' => 'Store: ' . $o['full_name'],
                'dest_type' => 'extension',
                'dest_id' => $ext,
                'is_active' => 1,
            ]);
            $routed = !empty($r['success']) ? " Number {$o['number']} routed to it." : ' The number could not be routed: ' . ($r['error'] ?? '?');
        }
        getDB()->prepare("UPDATE plg_billing_orders SET status = 'approved', user_id = ?, note = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$uid, "Extension {$ext}", $orderId]);
        Billing::audit($uid, "store order {$orderId} approved: extension {$ext}, plan {$plan['name']}");
        return "Approved: extension {$ext} created for {$o['full_name']} on {$plan['name']} (first month charged, activation e-mail sent to {$o['email']}).{$routed} Apply the pending changes.";
    }

    public static function reject(int $orderId, string $reason): void
    {
        $o = self::order($orderId);
        if ($o === null || !in_array($o['status'], ['awaiting', 'started'], true)) {
            throw new \InvalidArgumentException('That order cannot be rejected.');
        }
        getDB()->prepare("UPDATE plg_billing_orders SET status = 'rejected', note = ?, updated_at = NOW() WHERE id = ?")
            ->execute([mb_substr(trim($reason), 0, 255), $orderId]);
        if ($o['email'] !== '') {
            Billing::mail($o['email'], 'Your order', "Hello {$o['full_name']},\n\nYour order could not be approved."
                . (trim($reason) !== '' ? "\nReason: " . trim($reason) : '') . "\nYour card was not charged.");
        }
    }

    private static function numberFreeIgnoring(string $number, int $orderId): bool
    {
        $digits = (string) preg_replace('/\D/', '', $number);
        $q = getDB()->prepare("SELECT COUNT(*) FROM pbx_dids WHERE REPLACE(did_number, '+', '') IN (?, ?)");
        $q->execute([$digits, ltrim($digits, '1')]);
        return (int) $q->fetchColumn() === 0;
    }

    private static function undoCharge(array $sub): void
    {
        try {
            $st = Billing::stripe();
            $st->request('DELETE', '/v1/subscriptions/' . $sub['id']);
            $inv = (string) ($sub['latest_invoice'] ?? '');
            if ($inv !== '') {
                $invoice = $st->get('/v1/invoices/' . $inv);
                if (!empty($invoice['charge'])) {
                    $st->post('/v1/refunds', ['charge' => $invoice['charge']]);
                }
            }
        } catch (\Throwable $e) {
            error_log('billing store: undo charge failed: ' . $e->getMessage());
        }
    }

    private static function notifyAdmin(int $orderId): void
    {
        $to = trim((string) (Billing::settings()['store_notify_email'] ?? ''));
        $o = self::order($orderId);
        if ($to === '' || $o === null) {
            return;
        }
        $plan = Billing::plan((int) $o['plan_id']);
        Billing::mail($to, "New Store order #{$orderId}", "{$o['full_name']} <{$o['email']}> ordered "
            . ($plan['name'] ?? '?') . ($o['number'] !== '' ? " with number {$o['number']}" : '')
            . ".\nThe card is saved, nothing is charged yet. Approve or reject it on the Billing plugin page:\n"
            . Billing::baseUrl() . "/plugins/billing\n");
    }
}
