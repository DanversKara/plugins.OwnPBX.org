<?php

declare(strict_types=1);

require_once __DIR__ . '/Stripe.php';

/**
 * Billing: what each user may use, what they used, and their Stripe subscription.
 * Ported from the old OwnPBX (billing.py + entitlements.py).
 *
 * Features (-1 = unlimited, 0 = none for the monthly ones):
 *   call_minutes  outside-call minutes per calendar month (calls a user places or forwards out)
 *   messages      texts sent per calendar month
 *   fax           send faxes (1/0)
 *   faxes         faxes sent per calendar month, when fax is on
 *
 * A user's value: admin → unlimited; else an admin override; else the active plan; else the
 * "users without a plan" default. With "Enforce limits" off nobody is stopped (usage is still
 * counted and shown). Internal calls are never counted. Guest rules (GuestService) are separate
 * caps on top: a plan never grants a guest what its guest rules leave off.
 */
final class Billing
{
    public const SLUG = 'billing';
    public const UNLIMITED = -1;
    public const ACTIVE = ['active', 'trialing', 'past_due'];

    /** feature => [label, kind] (kind: monthly | bool) */
    public const FEATURES = [
        'call_minutes' => ['Outside call minutes', 'monthly'],
        'messages' => ['Text messages', 'monthly'],
        'fax' => ['Fax', 'bool'],
        'faxes' => ['Faxes sent', 'monthly'],
    ];

    /** @var array<string, string>|null */
    private static ?array $settings = null;

    /** @return array<string, string> */
    public static function settings(): array
    {
        if (self::$settings === null) {
            self::$settings = class_exists('PluginManager') ? PluginManager::getAllSettings(self::SLUG, false) : [];
        }
        return self::$settings;
    }

    /** For tests and after saving settings. */
    public static function setSettings(?array $settings): void
    {
        self::$settings = $settings;
    }

    public static function enabled(): bool
    {
        return class_exists('PluginManager') && PluginManager::isEnabled(self::SLUG);
    }

    public static function enforcing(): bool
    {
        return (self::settings()['enforce'] ?? '0') === '1';
    }

    public static function stripe(): BillingStripe
    {
        return new BillingStripe(trim((string) (self::settings()['stripe_secret_key'] ?? '')));
    }

    public static function mode(): string
    {
        return BillingStripe::modeOf(trim((string) (self::settings()['stripe_secret_key'] ?? '')));
    }

    public static function currency(): string
    {
        $c = strtolower(trim((string) (self::settings()['currency'] ?? 'usd')));
        return preg_match('/^[a-z]{3}$/', $c) ? $c : 'usd';
    }

    /** The secret part of the addresses only the PBX itself calls (dialplan CURL). */
    public static function internalKey(): string
    {
        $row = PluginManager::kvGet(self::SLUG, 'state', 'internal_key');
        $key = (string) ($row['value'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            PluginManager::kvSet(self::SLUG, 'state', 'internal_key', $key);
        }
        return $key;
    }

    public static function baseUrl(): string
    {
        $host = function_exists('portalEnv') ? trim((string) portalEnv('PORTAL_DOMAIN', '')) : '';
        return 'https://' . ($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    public static function money(int $cents, string $currency = ''): string
    {
        $currency = strtoupper($currency !== '' ? $currency : self::currency());
        $symbol = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$', 'AUD' => 'A$'][$currency] ?? '';
        return $symbol !== '' ? $symbol . number_format($cents / 100, 2) : number_format($cents / 100, 2) . ' ' . $currency;
    }

    // ------------------------------------------------------------------ plans

    /** @return list<array<string, mixed>> */
    public static function plans(bool $activeOnly = false): array
    {
        return getDB()->query('SELECT * FROM plg_billing_plans' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, price_cents, id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function plan(int $id): ?array
    {
        $q = getDB()->prepare('SELECT * FROM plg_billing_plans WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function planByPrice(string $priceId): ?array
    {
        if ($priceId === '') {
            return null;
        }
        $q = getDB()->prepare('SELECT * FROM plg_billing_plans WHERE stripe_price_id = ?');
        $q->execute([$priceId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Human list of what a plan (or a feature map) gives. @return list<string> */
    public static function describe(array $values): array
    {
        $out = [];
        foreach (['call_minutes' => ['Unlimited outside calls', '%s outside call minutes / month'],
                  'messages' => ['Unlimited texts', '%s texts / month']] as $f => [$unl, $fmt]) {
            $v = (int) ($values[$f] ?? 0);
            if ($v === self::UNLIMITED) {
                $out[] = $unl;
            } elseif ($v > 0) {
                $out[] = sprintf($fmt, number_format($v));
            }
        }
        if (!empty($values['fax'])) {
            $n = (int) ($values['faxes'] ?? 0);
            $out[] = $n === self::UNLIMITED ? 'Unlimited faxes' : ($n > 0 ? sprintf('Fax (%s / month)', number_format($n)) : 'Fax (receive only)');
        }
        return $out;
    }

    /**
     * Saves a plan. A changed price makes a new Stripe price on the next publish (Stripe prices
     * cannot be edited); subscribers keep the old one until they switch.
     */
    public static function savePlan(array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Give the plan a name.');
        }
        $num = static fn(string $k): int => max(self::UNLIMITED, (int) ($in[$k] ?? 0));
        $row = [
            'name' => mb_substr($name, 0, 80),
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255),
            'price_cents' => max(0, (int) round(((float) ($in['price'] ?? 0)) * 100)),
            'currency' => self::currency(),
            'call_minutes' => $num('call_minutes'),
            'messages' => $num('messages'),
            'fax' => empty($in['fax']) ? 0 : 1,
            'faxes' => $num('faxes'),
            'is_active' => empty($in['is_active']) ? 0 : 1,
            'in_store' => empty($in['in_store']) ? 0 : 1,
            'sort_order' => (int) ($in['sort_order'] ?? 0),
        ];
        $db = getDB();
        $id = (int) ($in['plan_id'] ?? 0);
        if ($id > 0) {
            $old = self::plan($id);
            if ($old === null) {
                throw new \InvalidArgumentException('Plan not found.');
            }
            $sets = implode(', ', array_map(static fn(string $k): string => "{$k} = ?", array_keys($row)));
            $db->prepare("UPDATE plg_billing_plans SET {$sets} WHERE id = ?")->execute([...array_values($row), $id]);
            // Subscribers get the new limits now (the price changes on the next publish).
            foreach (self::subscribersOf($id) as $uid) {
                self::audit($uid, "plan {$id} limits updated");
            }
        } else {
            $cols = implode(', ', array_keys($row));
            $db->prepare("INSERT INTO plg_billing_plans ({$cols}) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')')->execute(array_values($row));
            $id = (int) $db->lastInsertId();
        }
        return $id;
    }

    /** @return list<int> */
    public static function subscribersOf(int $planId): array
    {
        $q = getDB()->prepare('SELECT user_id FROM plg_billing_customers WHERE plan_id = ? AND status IN (' . self::activeIn() . ')');
        $q->execute([$planId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Creates (or refreshes) the plan's Stripe product and monthly price in the current mode. */
    public static function publishPlan(int $id): void
    {
        $plan = self::plan($id);
        if ($plan === null) {
            throw new \InvalidArgumentException('Plan not found.');
        }
        $st = self::stripe();
        $mode = self::mode();
        $desc = $plan['description'] !== '' ? $plan['description'] : implode(', ', self::describe($plan));
        $product = $plan['stripe_mode'] === $mode ? (string) $plan['stripe_product_id'] : '';
        $productParams = ['name' => $plan['name'], 'description' => $desc !== '' ? $desc : null, 'metadata' => ['pbx_plan_id' => $id]];
        if ($product === '') {
            $product = (string) $st->post('/v1/products', $productParams, "pbx-plan-{$id}-product-{$mode}")['id'];
        } else {
            $st->post("/v1/products/{$product}", $productParams);
        }
        $price = $plan['stripe_mode'] === $mode ? (string) $plan['stripe_price_id'] : '';
        $needPrice = $price === '';
        if (!$needPrice) {
            $cur = $st->get("/v1/prices/{$price}");
            $needPrice = (int) ($cur['unit_amount'] ?? -1) !== (int) $plan['price_cents'] || ($cur['currency'] ?? '') !== $plan['currency'] || empty($cur['active']);
        }
        if ($needPrice) {
            $new = $st->post('/v1/prices', [
                'product' => $product, 'unit_amount' => (int) $plan['price_cents'], 'currency' => $plan['currency'],
                'recurring' => ['interval' => 'month'], 'metadata' => ['pbx_plan_id' => $id],
            ]);
            if ($price !== '') {
                $st->post("/v1/prices/{$price}", ['active' => false]);
            }
            $price = (string) $new['id'];
        }
        getDB()->prepare('UPDATE plg_billing_plans SET stripe_mode = ?, stripe_product_id = ?, stripe_price_id = ? WHERE id = ?')
            ->execute([$mode, $product, $price, $id]);
    }

    // ------------------------------------------------------------------ entitlements

    /** The value of a feature for a user (-1 unlimited, 0 none; fax 1/0). */
    public static function quota(int $userId, string $feature): int
    {
        if (!isset(self::FEATURES[$feature])) {
            return 0;
        }
        $db = getDB();
        $u = $db->prepare('SELECT role FROM sys_users WHERE id = ?');
        $u->execute([$userId]);
        if ((string) $u->fetchColumn() === 'admin') {
            return self::FEATURES[$feature][1] === 'bool' ? 1 : self::UNLIMITED;
        }
        $o = $db->prepare('SELECT value FROM plg_billing_overrides WHERE user_id = ? AND feature = ?');
        $o->execute([$userId, $feature]);
        $v = $o->fetchColumn();
        if ($v !== false) {
            return (int) $v;
        }
        $plan = self::activePlanOf($userId);
        if ($plan !== null) {
            return (int) $plan[$feature];
        }
        $default = (string) (self::settings()['default_' . $feature] ?? (self::FEATURES[$feature][1] === 'bool' ? '1' : '-1'));
        return (int) $default;
    }

    public static function activePlanOf(int $userId): ?array
    {
        $q = getDB()->prepare('SELECT p.* FROM plg_billing_customers c JOIN plg_billing_plans p ON p.id = c.plan_id
            WHERE c.user_id = ? AND c.status IN (' . self::activeIn() . ')');
        $q->execute([$userId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** This month's use: minutes (each call rounded up), texts, faxes. */
    public static function usage(int $userId, string $feature): int
    {
        $start = date('Y-m-01 00:00:00');
        if ($feature === 'call_minutes') {
            $q = getDB()->prepare("SELECT COALESCE(SUM(CEIL(amount / 60)), 0) FROM plg_billing_usage WHERE user_id = ? AND feature = 'call_seconds' AND created_at >= ?");
        } else {
            $q = getDB()->prepare('SELECT COALESCE(SUM(amount), 0) FROM plg_billing_usage WHERE user_id = ? AND feature = ? AND created_at >= ?');
        }
        $q->execute($feature === 'call_minutes' ? [$userId, $start] : [$userId, $feature, $start]);
        return (int) $q->fetchColumn();
    }

    /**
     * May the user use one more of a monthly feature (texts, faxes) / the feature at all (fax)?
     * Always true while limits are not enforced.
     */
    public static function allows(int $userId, string $feature): bool
    {
        if (!self::enabled() || !self::enforcing()) {
            return true;
        }
        if ($feature === 'faxes' && self::quota($userId, 'fax') !== 1) {
            return false;
        }
        $q = self::quota($userId, $feature);
        if (self::FEATURES[$feature][1] === 'bool') {
            return $q === 1;
        }
        return $q === self::UNLIMITED || self::usage($userId, $feature) < $q;
    }

    public static function record(int $userId, string $feature, int $amount = 1, string $detail = ''): void
    {
        if ($userId <= 0 || $amount <= 0) {
            return;
        }
        getDB()->prepare('INSERT INTO plg_billing_usage (user_id, feature, amount, detail) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $feature, $amount, mb_substr($detail, 0, 120)]);
    }

    /**
     * Seconds of outside calling the extension's user may still use: -1 unlimited, 0 none left.
     * Unknown extensions (and limits not enforced) are unlimited: billing never blocks a call
     * it cannot attribute.
     */
    public static function callAllowance(string $ext): int
    {
        $uid = self::userIdForExtension($ext);
        if ($uid === 0 || !self::enforcing()) {
            return self::UNLIMITED;
        }
        $q = self::quota($uid, 'call_minutes');
        if ($q === self::UNLIMITED) {
            return self::UNLIMITED;
        }
        return max(0, ($q - self::usage($uid, 'call_minutes')) * 60);
    }

    public static function userIdForExtension(string $ext): int
    {
        $ext = (string) preg_replace('/\D/', '', $ext);
        if ($ext === '') {
            return 0;
        }
        $q = getDB()->prepare('SELECT id FROM sys_users WHERE extension = ? LIMIT 1');
        $q->execute([$ext]);
        return (int) $q->fetchColumn();
    }

    public static function setOverride(int $userId, string $feature, ?int $value): void
    {
        if (!isset(self::FEATURES[$feature])) {
            throw new \InvalidArgumentException('Unknown feature.');
        }
        if ($value === null) {
            getDB()->prepare('DELETE FROM plg_billing_overrides WHERE user_id = ? AND feature = ?')->execute([$userId, $feature]);
        } else {
            getDB()->prepare('INSERT INTO plg_billing_overrides (user_id, feature, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute([$userId, $feature, max(self::UNLIMITED, $value)]);
        }
        self::audit($userId, "override {$feature} = " . ($value === null ? 'removed' : (string) $value));
    }

    // ------------------------------------------------------------------ customers & subscriptions

    public static function customer(int $userId): ?array
    {
        $q = getDB()->prepare('SELECT * FROM plg_billing_customers WHERE user_id = ?');
        $q->execute([$userId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function customerByStripe(string $customerId): ?array
    {
        if ($customerId === '') {
            return null;
        }
        $q = getDB()->prepare('SELECT * FROM plg_billing_customers WHERE stripe_customer_id = ?');
        $q->execute([$customerId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The user's Stripe customer id in the current mode, created on first use. */
    public static function stripeCustomerFor(int $userId): string
    {
        $row = self::customer($userId);
        $mode = self::mode();
        if ($row !== null && $row['stripe_customer_id'] !== '' && $row['stripe_mode'] === $mode) {
            return (string) $row['stripe_customer_id'];
        }
        $q = getDB()->prepare('SELECT username, full_name, email, extension FROM sys_users WHERE id = ?');
        $q->execute([$userId]);
        $u = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $cus = self::stripe()->post('/v1/customers', [
            'name' => (string) ($u['full_name'] ?? '') ?: (string) ($u['username'] ?? ''),
            'email' => ($u['email'] ?? '') !== '' ? $u['email'] : null,
            'metadata' => ['pbx_user_id' => $userId, 'extension' => (string) ($u['extension'] ?? '')],
        ], "pbx-user-{$userId}-customer-{$mode}");
        $id = (string) $cus['id'];
        getDB()->prepare('INSERT INTO plg_billing_customers (user_id, stripe_mode, stripe_customer_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE stripe_mode = VALUES(stripe_mode), stripe_customer_id = VALUES(stripe_customer_id),
                subscription_id = \'\', subscription_item_id = \'\', plan_id = NULL, status = \'\'')->execute([$userId, $mode, $id]);
        return $id;
    }

    /**
     * Stores a Stripe subscription for a user. An active one sets the plan (and, when the admin
     * allows it, turns a guest into a normal account on a paid plan); any other status leaves the
     * user on the defaults. A stale non-active subscription never replaces an active one.
     */
    public static function applySubscription(int $userId, array $sub): void
    {
        $item = $sub['items']['data'][0] ?? [];
        $price = (string) ($item['price']['id'] ?? '');
        $status = (string) ($sub['status'] ?? '');
        $periodEnd = (int) ($sub['current_period_end'] ?? ($item['current_period_end'] ?? 0));
        $plan = self::planByPrice($price);
        $row = self::customer($userId) ?? [];
        if (!in_array($status, self::ACTIVE, true) && ($row['subscription_id'] ?? '') !== ''
            && $row['subscription_id'] !== ($sub['id'] ?? '') && in_array($row['status'] ?? '', self::ACTIVE, true)) {
            return;
        }
        getDB()->prepare('INSERT INTO plg_billing_customers (user_id, stripe_mode, stripe_customer_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE user_id = user_id')
            ->execute([$userId, self::mode(), (string) ($sub['customer'] ?? '')]);
        getDB()->prepare('UPDATE plg_billing_customers SET subscription_id = ?, subscription_item_id = ?, plan_id = ?, status = ?,
                current_period_end = ?, cancel_at_period_end = ?, updated_at = NOW() WHERE user_id = ?')
            ->execute([(string) ($sub['id'] ?? ''), (string) ($item['id'] ?? ''), $plan['id'] ?? null, $status, $periodEnd,
                empty($sub['cancel_at_period_end']) ? 0 : 1, $userId]);
        self::audit($userId, "subscription {$status}" . ($plan ? " on plan {$plan['name']}" : ''));
        if ($plan !== null && in_array($status, self::ACTIVE, true)) {
            self::maybeUpgradeGuest($userId, $plan);
        }
    }

    /** Pulls the customer's subscriptions from Stripe and applies the best one. */
    public static function syncUser(int $userId): void
    {
        $row = self::customer($userId);
        if ($row === null || $row['stripe_customer_id'] === '' || $row['stripe_mode'] !== self::mode()) {
            return;
        }
        $subs = (array) (self::stripe()->get('/v1/subscriptions', ['customer' => $row['stripe_customer_id'], 'status' => 'all', 'limit' => 20])['data'] ?? []);
        if ($subs === []) {
            getDB()->prepare("UPDATE plg_billing_customers SET subscription_id = '', subscription_item_id = '', plan_id = NULL, status = '',
                current_period_end = 0, cancel_at_period_end = 0, updated_at = NOW() WHERE user_id = ?")->execute([$userId]);
            return;
        }
        usort($subs, static fn(array $a, array $b): int => [in_array($b['status'] ?? '', self::ACTIVE, true), (int) ($b['created'] ?? 0)]
            <=> [in_array($a['status'] ?? '', self::ACTIVE, true), (int) ($a['created'] ?? 0)]);
        self::applySubscription($userId, $subs[0]);
    }

    /** Checkout page for a new subscription. @return string the Stripe URL to send the user to */
    public static function checkoutUrl(int $userId, int $planId): string
    {
        $plan = self::plan($planId);
        if ($plan === null || empty($plan['is_active']) || $plan['stripe_price_id'] === '' || $plan['stripe_mode'] !== self::mode()) {
            throw new \InvalidArgumentException('That plan is not available.');
        }
        if (self::guestUpgradeBlocked($userId)) {
            throw new \InvalidArgumentException('Your account cannot change plans without contacting support first.');
        }
        $session = self::stripe()->post('/v1/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => self::stripeCustomerFor($userId),
            'line_items' => [['price' => $plan['stripe_price_id'], 'quantity' => 1]],
            'client_reference_id' => (string) $userId,
            'metadata' => ['pbx_user_id' => $userId],
            'subscription_data' => ['metadata' => ['pbx_user_id' => $userId]],
            'success_url' => self::baseUrl() . '/apps/billing?done=1',
            'cancel_url' => self::baseUrl() . '/apps/billing',
        ]);
        return (string) $session['url'];
    }

    /** Switches an active subscription to another plan (prorated). */
    public static function switchPlan(int $userId, int $planId): void
    {
        $row = self::customer($userId);
        $plan = self::plan($planId);
        if ($row === null || $row['subscription_id'] === '' || !in_array($row['status'], self::ACTIVE, true)) {
            throw new \InvalidArgumentException('You have no plan to switch.');
        }
        if ($plan === null || empty($plan['is_active']) || $plan['stripe_price_id'] === '' || $plan['stripe_mode'] !== self::mode()) {
            throw new \InvalidArgumentException('That plan is not available.');
        }
        $sub = self::stripe()->post('/v1/subscriptions/' . $row['subscription_id'], [
            'items' => [['id' => $row['subscription_item_id'], 'price' => $plan['stripe_price_id']]],
            'proration_behavior' => 'create_prorations',
            'cancel_at_period_end' => false,
        ]);
        self::applySubscription($userId, $sub);
    }

    /** Cancel at the end of the paid month, or undo that. */
    public static function setCancel(int $userId, bool $cancel): void
    {
        $row = self::customer($userId);
        if ($row === null || $row['subscription_id'] === '') {
            throw new \InvalidArgumentException('You have no plan.');
        }
        $sub = self::stripe()->post('/v1/subscriptions/' . $row['subscription_id'], ['cancel_at_period_end' => $cancel]);
        self::applySubscription($userId, $sub);
    }

    /** Stripe's own page for the card and invoices. */
    public static function portalUrl(int $userId): string
    {
        $session = self::stripe()->post('/v1/billing_portal/sessions', [
            'customer' => self::stripeCustomerFor($userId),
            'return_url' => self::baseUrl() . '/apps/billing',
        ]);
        return (string) $session['url'];
    }

    // ------------------------------------------------------------------ guests

    private static function isGuest(int $userId): bool
    {
        try {
            $q = getDB()->prepare("SELECT is_guest FROM sys_users WHERE id = ? AND role <> 'admin'");
            $q->execute([$userId]);
            return (bool) $q->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A guest may buy a plan only when the admin ticked "may upgrade" for them. */
    public static function guestUpgradeBlocked(int $userId): bool
    {
        return self::isGuest($userId) && empty(self::customer($userId)['guest_may_upgrade']);
    }

    public static function setGuestMayUpgrade(int $userId, bool $may): void
    {
        getDB()->prepare('INSERT INTO plg_billing_customers (user_id, guest_may_upgrade) VALUES (?, ?) ON DUPLICATE KEY UPDATE guest_may_upgrade = VALUES(guest_may_upgrade)')
            ->execute([$userId, $may ? 1 : 0]);
        self::audit($userId, 'guest may upgrade: ' . ($may ? 'yes' : 'no'));
    }

    /** A permitted guest on a paid plan becomes a normal account (guest rules stay stored, inert). */
    private static function maybeUpgradeGuest(int $userId, array $plan): void
    {
        if ((int) $plan['price_cents'] <= 0 || !self::isGuest($userId) || self::guestUpgradeBlocked($userId)) {
            return;
        }
        getDB()->prepare('UPDATE sys_users SET is_guest = 0 WHERE id = ?')->execute([$userId]);
        $ext = (string) getDB()->query('SELECT extension FROM sys_users WHERE id = ' . $userId)->fetchColumn();
        if ($ext !== '' && function_exists('markPendingSync')) {
            foreach (['extensions', 'general_dialplan', 'outbound_dialplan'] as $domain) {
                try {
                    markPendingSync($domain, 'guest', $ext, "Guest {$ext} upgraded by plan {$plan['name']}", 'update', null);
                } catch (\Throwable $e) {
                    error_log('billing: pending sync: ' . $e->getMessage());
                }
            }
        }
        self::audit($userId, "guest upgraded to a normal account by paid plan {$plan['name']}");
    }

    // ------------------------------------------------------------------ webhook

    /**
     * Handles a verified Stripe event once (events are retried). Returns false when Stripe
     * should retry (Stripe could not be reached to read the full subscription).
     */
    public static function handleEvent(array $event): bool
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $seen = getDB()->prepare('SELECT 1 FROM plg_billing_events WHERE id = ?');
        $seen->execute([$id]);
        if ($id === '' || $seen->fetchColumn()) {
            return true;
        }
        $obj = (array) ($event['data']['object'] ?? []);
        try {
            if ($type === 'checkout.session.completed' && !empty($obj['metadata']['pbx_store_order'])) {
                require_once __DIR__ . '/Store.php';
                BillingStore::onCheckoutCompleted($obj);
            } elseif ($type === 'checkout.session.completed') {
                $uid = (int) ($obj['client_reference_id'] ?? ($obj['metadata']['pbx_user_id'] ?? 0));
                if ($uid > 0 && !empty($obj['customer'])) {
                    getDB()->prepare('INSERT INTO plg_billing_customers (user_id, stripe_mode, stripe_customer_id) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE stripe_mode = VALUES(stripe_mode), stripe_customer_id = VALUES(stripe_customer_id)')
                        ->execute([$uid, self::mode(), (string) $obj['customer']]);
                    if (!empty($obj['subscription'])) {
                        self::applySubscription($uid, self::stripe()->get('/v1/subscriptions/' . $obj['subscription']));
                    }
                }
            } elseif (str_starts_with($type, 'customer.subscription.')) {
                $row = self::customerByStripe((string) ($obj['customer'] ?? ''));
                $uid = $row !== null ? (int) $row['user_id'] : (int) ($obj['metadata']['pbx_user_id'] ?? 0);
                if ($uid > 0) {
                    self::applySubscription($uid, $obj);
                }
            } elseif ($type === 'invoice.paid' || $type === 'invoice.payment_failed') {
                $row = self::customerByStripe((string) ($obj['customer'] ?? ''));
                if ($row !== null) {
                    self::syncUser((int) $row['user_id']);
                }
            }
        } catch (BillingStripeError $e) {
            error_log('billing webhook: ' . $e->getMessage());
            return false;
        }
        getDB()->prepare('INSERT IGNORE INTO plg_billing_events (id, type) VALUES (?, ?)')->execute([$id, mb_substr($type, 0, 80)]);
        return true;
    }

    // ------------------------------------------------------------------ dialplan

    /**
     * [sub-billing-gate] for the outbound dialplan: runs before every trunk Dial(), after the
     * guest gate. The caller's extension (AIPBX_USER_EXT, set on every phone and carried by
     * forwards and click-to-call) asks the plugin how many seconds are left: none → the call is
     * refused with the guest/blocked prompt; some → Dial() hangs up when they run out (L()).
     * Every billed call is counted when it ends. If the plugin cannot be reached the call goes
     * through: billing never takes the phones down.
     */
    public static function dialplan(string $sound): string
    {
        $key = self::internalKey();
        $base = "http://127.0.0.1/api/plugins/billing";
        $c = "; ---- Billing (plugin): outside-call minutes ----\n";
        $c .= "[sub-billing-gate]\n";
        $c .= "exten => s,1,Set(LOCAL(U)=\${FILTER(0-9,\${AIPBX_USER_EXT})})\n";
        $c .= " same => n,GotoIf(\$[\"\${U}\" = \"\" | \"\${AIPBX_BILL_SKIP}\" = \"1\"]?ok)\n";
        $c .= " same => n,ExecIf(\$[\"\${BILL_HH}\" = \"\"]?Set(CHANNEL(hangup_handler_push)=sub-billing-record,s,1(\${U},\${FILTER(0-9+,\${ARG1})})))\n";
        $c .= " same => n,Set(BILL_HH=1)\n";
        $c .= " same => n,Set(LOCAL(R)=\${CURL({$base}/gate/{$key}?ext=\${U})})\n";
        // Anything but a plain number (unlimited "-1", an error page, a redirect, no answer) lets the
        // call through: a billing hiccup must never break outside calling.
        $c .= " same => n,GotoIf(\$[\${REGEX(\"^[0-9]+\$\" \${R})}]?num:ok)\n";
        $c .= " same => n(num),GotoIf(\$[\${R} <= 0]?deny)\n";
        // Dial(..., L(limit ms : warn 60 s before)) — the trunk Dial lines add ${BILL_OPT}.
        $c .= " same => n,Set(BILL_OPT=L(\${MATH(\${R}*1000,i)}))\n";
        // A beep one minute before the end, when there is more than two minutes to warn about.
        $c .= " same => n,GotoIf(\$[\${R} <= 120]?ok)\n";
        $c .= " same => n,Set(BILL_OPT=L(\${MATH(\${R}*1000,i)}:60000))\n";
        $c .= " same => n(ok),Return()\n";
        $c .= " same => n(deny),Log(NOTICE,Billing: \${U} has no outside minutes left - call to \${ARG1} refused)\n";
        $c .= " same => n,Set(CDR(userfield)=NO_MINUTES_LEFT)\n";
        $c .= " same => n,Answer()\n";
        $c .= " same => n,Playback({$sound})\n";
        $c .= " same => n,Hangup(21)\n\n";
        $c .= "[sub-billing-record]\n";
        $c .= "exten => s,1,GotoIf(\$[0\${ANSWEREDTIME} <= 0]?done)\n";
        $c .= " same => n,Set(LOCAL(X)=\${CURL({$base}/record/{$key}?ext=\${ARG1}&sec=\${ANSWEREDTIME}&to=\${ARG2})})\n";
        $c .= " same => n(done),Return()\n\n";
        return $c;
    }

    // ------------------------------------------------------------------ helpers

    /** The portal's web/src directory (config.php, which defines getDB(), sits next to it). */
    public static function coreDir(): string
    {
        return dirname((string) (new \ReflectionFunction('getDB'))->getFileName()) . '/src';
    }

    /** A plain e-mail in the portal's mail look; a failure is only logged. */
    public static function mail(string $to, string $subject, string $text): void
    {
        try {
            if (!class_exists('MailTemplateService')) {
                require_once self::coreDir() . '/services/MailTemplateService.php';
            }
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
            $mail = MailTemplateService::render('billing', 'en', [], [], ['subject' => $subject, 'body' => $html]);
            [$from, $fromName] = MailTemplateService::sender('portal');
            [$headers, $body] = MailTemplateService::buildMime($mail, $from, $fromName);
            if (!@mail($to, mb_encode_mimeheader($mail['subject'], 'UTF-8'), $body, $headers, '-f ' . $from)) {
                error_log("billing: mail to {$to} failed");
            }
        } catch (\Throwable $e) {
            error_log('billing: mail: ' . $e->getMessage());
        }
    }

    private static function activeIn(): string
    {
        return "'" . implode("','", self::ACTIVE) . "'";
    }

    public static function audit(int $userId, string $what): void
    {
        if (function_exists('writeAuditLog')) {
            try {
                writeAuditLog(null, 'billing', $userId, $what, 'update', $_SESSION['user_id'] ?? null);
            } catch (\Throwable $e) {
                error_log('billing audit: ' . $e->getMessage());
            }
        }
    }
}
