<?php

declare(strict_types=1);

/**
 * Reseller Accounts: one reseller is one tenant on the shared PBX.
 *
 * v1 is the foundation — reseller records, host-to-tenant resolution, and the
 * helpers the core tenant plumbing (ResellerBridge) calls. What v1 does NOT do:
 * per-tenant trunk routing, dialplan islands, DID purchases, Stripe payment
 * flows, platform-fee accrual, custom-domain TLS, white-label rendering.
 *
 * Tenant identity, two ways:
 *   - a signed-in user belongs to the reseller in sys_users.reseller_id
 *     (NULL = the main admin's own users);
 *   - a request belongs to the reseller whose domain it arrived on
 *     (resolveTenantByHost): a custom domain, or <subdomain>.<stock base>.
 * An unknown host resolves to NULL — never to a wrong tenant.
 *
 * Secrets: a reseller's own Stripe keys are encrypted with the same secret box
 * the billing plugin's stripe_secret_key uses (SecretBox) before they are
 * stored, and decrypted on read. They are never logged and never rendered.
 */
final class Reseller
{
    public const SLUG = 'reseller';

    public const STATUSES = ['active', 'suspended'];

    public const STRIPE_MODES = ['own', 'platform'];

    public const FEE_TYPES = ['percent', 'monthly'];

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

    // ------------------------------------------------------------------ rows

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return getDB()->query('SELECT * FROM plg_resellers ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = getDB()->prepare('SELECT * FROM plg_resellers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public static function getBySubdomain(string $subdomain): ?array
    {
        $subdomain = strtolower(trim($subdomain));
        if ($subdomain === '') {
            return null;
        }
        $stmt = getDB()->prepare('SELECT * FROM plg_resellers WHERE stock_subdomain = ?');
        $stmt->execute([$subdomain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public static function getByDomain(string $domain): ?array
    {
        $domain = self::normalizeHost($domain);
        if ($domain === '') {
            return null;
        }
        $stmt = getDB()->prepare('SELECT * FROM plg_resellers WHERE custom_domain = ?');
        $stmt->execute([$domain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** How many portal users belong to this reseller (0 when the core migration has not run yet). */
    public static function userCount(int $resellerId): int
    {
        try {
            $stmt = getDB()->prepare('SELECT COUNT(*) FROM sys_users WHERE reseller_id = ?');
            $stmt->execute([$resellerId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // sys_users.reseller_id arrives with the core migration (1.75.58);
            // before that, no user can belong to a reseller.
            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function fees(int $resellerId): array
    {
        $stmt = getDB()->prepare('SELECT * FROM plg_reseller_fees WHERE reseller_id = ? ORDER BY period DESC, id DESC');
        $stmt->execute([$resellerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------- validation

    public static function isValidSubdomain(string $subdomain): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain);
    }

    public static function isValidDomain(string $domain): bool
    {
        $domain = self::normalizeHost($domain);
        if ($domain === '' || strlen($domain) > 253 || str_contains($domain, '..')) {
            return false;
        }
        foreach (explode('.', $domain) as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        return str_contains($domain, '.');
    }

    public static function isValidColor(string $color): bool
    {
        return $color === '' || (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    }

    /** Lowercase, no port, no trailing dot. */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return rtrim($host, '.');
    }

    /**
     * Validated field values for create()/save(), from raw form input.
     * Throws InvalidArgumentException on the first problem.
     *
     * @return array<string, mixed>
     */
    public static function validated(array $in, int $excludeId = 0): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new \InvalidArgumentException('A reseller name (up to 80 characters) is required.');
        }
        $status = (string) ($in['status'] ?? 'active');
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown reseller status.');
        }
        $subdomain = strtolower(trim((string) ($in['stock_subdomain'] ?? '')));
        if ($subdomain !== '' && !self::isValidSubdomain($subdomain)) {
            throw new \InvalidArgumentException('Stock subdomain must be lowercase letters, digits and hyphens (e.g. acme).');
        }
        if ($subdomain !== '') {
            $other = self::getBySubdomain($subdomain);
            if ($other !== null && (int) $other['id'] !== $excludeId) {
                throw new \InvalidArgumentException('That stock subdomain is already taken by another reseller.');
            }
        }
        $domain = self::normalizeHost((string) ($in['custom_domain'] ?? ''));
        if ($domain !== '' && !self::isValidDomain($domain)) {
            throw new \InvalidArgumentException('Custom domain is not a valid domain name.');
        }
        if ($domain !== '') {
            $other = self::getByDomain($domain);
            if ($other !== null && (int) $other['id'] !== $excludeId) {
                throw new \InvalidArgumentException('That custom domain is already taken by another reseller.');
            }
        }
        $stripeMode = (string) ($in['stripe_mode'] ?? 'platform');
        if (!in_array($stripeMode, self::STRIPE_MODES, true)) {
            throw new \InvalidArgumentException('Unknown Stripe mode.');
        }
        $feeType = (string) ($in['fee_type'] ?? 'percent');
        if (!in_array($feeType, self::FEE_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown fee type.');
        }
        $feeRate = (float) ($in['fee_rate'] ?? 0);
        if ($feeRate < 0 || ($feeType === 'percent' && $feeRate > 100)) {
            throw new \InvalidArgumentException('Fee rate must be 0-100 for a percent fee, or 0 and up for a monthly fee.');
        }
        $extFrom = trim((string) ($in['ext_from'] ?? ''));
        $extTo = trim((string) ($in['ext_to'] ?? ''));
        $extFrom = $extFrom === '' ? null : (int) $extFrom;
        $extTo = $extTo === '' ? null : (int) $extTo;
        if (($extFrom !== null && $extFrom < 0) || ($extTo !== null && $extTo < 0)) {
            throw new \InvalidArgumentException('Extension range must be positive numbers.');
        }
        if ($extFrom !== null && $extTo !== null && $extFrom > $extTo) {
            throw new \InvalidArgumentException('Extension range start must not be after its end.');
        }
        if ($extFrom !== null && $extTo !== null && self::rangesOverlap($extFrom, $extTo, $excludeId)) {
            throw new \InvalidArgumentException('That extension range overlaps another reseller\'s range.');
        }
        $color = trim((string) ($in['primary_color'] ?? ''));
        if (!self::isValidColor($color)) {
            throw new \InvalidArgumentException('Brand colour must be empty or a #rrggbb hex colour.');
        }
        return [
            'name' => $name,
            'status' => $status,
            'stock_subdomain' => $subdomain === '' ? null : $subdomain,
            'custom_domain' => $domain === '' ? null : $domain,
            'display_name' => mb_substr(trim((string) ($in['display_name'] ?? '')), 0, 80),
            'logo_path' => mb_substr(trim((string) ($in['logo_path'] ?? '')), 0, 255),
            'primary_color' => $color,
            'stripe_mode' => $stripeMode,
            'fee_type' => $feeType,
            'fee_rate' => $feeRate,
            'ext_from' => $extFrom,
            'ext_to' => $extTo,
        ];
    }

    /** Does [from, to] intersect any other reseller's assigned range? */
    public static function rangesOverlap(int $from, int $to, int $excludeId = 0): bool
    {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM plg_resellers
             WHERE id <> ? AND ext_from IS NOT NULL AND ext_to IS NOT NULL
             AND ext_from <= ? AND ext_to >= ?'
        );
        $stmt->execute([$excludeId, $to, $from]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------- write

    public static function create(array $in): int
    {
        $v = self::validated($in);
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO plg_resellers
                (name, status, stock_subdomain, custom_domain, display_name, logo_path, primary_color,
                 stripe_mode, fee_type, fee_rate, ext_from, ext_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $v['name'], $v['status'], $v['stock_subdomain'], $v['custom_domain'], $v['display_name'],
            $v['logo_path'], $v['primary_color'], $v['stripe_mode'], $v['fee_type'], $v['fee_rate'],
            $v['ext_from'], $v['ext_to'],
        ]);
        $id = (int) $db->lastInsertId();
        self::mirrorDomain($id, $v['custom_domain']);
        self::audit($id, 'Reseller created: ' . $v['name']);
        return $id;
    }

    public static function save(int $id, array $in): void
    {
        if (self::get($id) === null) {
            throw new \InvalidArgumentException('Unknown reseller.');
        }
        $v = self::validated($in, $id);
        $stmt = getDB()->prepare(
            'UPDATE plg_resellers SET
                name = ?, status = ?, stock_subdomain = ?, custom_domain = ?, display_name = ?,
                logo_path = ?, primary_color = ?, stripe_mode = ?, fee_type = ?, fee_rate = ?,
                ext_from = ?, ext_to = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $v['name'], $v['status'], $v['stock_subdomain'], $v['custom_domain'], $v['display_name'],
            $v['logo_path'], $v['primary_color'], $v['stripe_mode'], $v['fee_type'], $v['fee_rate'],
            $v['ext_from'], $v['ext_to'], $id,
        ]);
        self::mirrorDomain($id, $v['custom_domain']);
        self::audit($id, 'Reseller saved: ' . $v['name']);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown reseller status.');
        }
        $row = self::get($id);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown reseller.');
        }
        $stmt = getDB()->prepare('UPDATE plg_resellers SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        self::audit($id, 'Reseller ' . $status . ': ' . $row['name']);
    }

    /** A reseller with portal users cannot be deleted — move or delete the users first. */
    public static function delete(int $id): void
    {
        $row = self::get($id);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown reseller.');
        }
        if (self::userCount($id) > 0) {
            throw new \InvalidArgumentException('That reseller still has users — move or delete them first.');
        }
        $stmt = getDB()->prepare('DELETE FROM plg_resellers WHERE id = ?');
        $stmt->execute([$id]);
        self::audit($id, 'Reseller deleted: ' . $row['name']);
    }

    /**
     * Keep plg_reseller_domains in step with the reseller row's custom domain:
     * the resolution lookup reads the domains table only. A domain that is
     * removed or changed leaves no stale row behind.
     */
    private static function mirrorDomain(int $resellerId, ?string $domain): void
    {
        $db = getDB();
        $db->prepare('DELETE FROM plg_reseller_domains WHERE reseller_id = ?')->execute([$resellerId]);
        if ($domain !== null && $domain !== '') {
            $db->prepare(
                'INSERT INTO plg_reseller_domains (domain, reseller_id, cert_status) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE reseller_id = VALUES(reseller_id)'
            )->execute([$domain, $resellerId, 'pending']);
        }
    }

    // ------------------------------------------------------------- tenants

    /** Base domain for stock subdomains: the plugin setting, else the portal's own domain. */
    public static function stockBaseDomain(): string
    {
        $base = trim((string) (self::settings()['stock_base_domain'] ?? ''));
        if ($base === '' && function_exists('portalEnv')) {
            $base = trim((string) portalEnv('PORTAL_DOMAIN', ''));
        }
        if ($base === '') {
            $base = self::normalizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        }
        return self::normalizeHost($base);
    }

    /** The stock login host for a reseller, e.g. acme.pbx.ownpbx.org ('' when no base is known). */
    public static function stockHost(string $subdomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        $base = self::stockBaseDomain();
        if ($subdomain === '' || $base === '') {
            return '';
        }
        return $subdomain . '.' . $base;
    }

    /**
     * Which reseller (if any) owns this request host. Custom domains first,
     * then <subdomain>.<stock base>. Only active resellers resolve — a
     * suspended reseller's domains stop taking logins. Unknown host: NULL,
     * never a wrong tenant. Never throws.
     */
    public static function resolveTenantByHost(string $host): ?array
    {
        try {
            $host = self::normalizeHost($host);
            if ($host === '') {
                return null;
            }
            $db = getDB();
            $stmt = $db->prepare(
                'SELECT r.* FROM plg_reseller_domains d
                 JOIN plg_resellers r ON r.id = d.reseller_id
                 WHERE d.domain = ? AND r.status = ?'
            );
            $stmt->execute([$host, 'active']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
            $base = self::stockBaseDomain();
            if ($base !== '' && str_ends_with($host, '.' . $base)) {
                $sub = substr($host, 0, -strlen('.' . $base));
                if ($sub !== '' && !str_contains($sub, '.')) {
                    $row = self::getBySubdomain($sub);
                    if ($row !== null && $row['status'] === 'active') {
                        return $row;
                    }
                }
            }
            return null;
        } catch (\Throwable $e) {
            error_log('reseller resolveTenantByHost: ' . $e->getMessage());
            return null;
        }
    }

    /** The reseller a portal user belongs to (NULL = the main admin's own users). */
    public static function resellerForUser(int $userId): ?array
    {
        $id = self::resellerIdForUser($userId);
        return $id === null ? null : self::get($id);
    }

    /** @return int|null */
    public static function resellerIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $stmt = getDB()->prepare('SELECT reseller_id FROM sys_users WHERE id = ?');
            $stmt->execute([$userId]);
            $v = $stmt->fetchColumn();
            return ($v === false || $v === null || (int) $v <= 0) ? null : (int) $v;
        } catch (\Throwable $e) {
            // sys_users.reseller_id arrives with the core migration (1.75.58).
            return null;
        }
    }

    /** Is this extension inside the reseller's assigned range? (No range assigned: false.) */
    public static function extensionInRange(int $resellerId, string $extension): bool
    {
        $row = self::get($resellerId);
        if ($row === null || $row['ext_from'] === null || $row['ext_to'] === null) {
            return false;
        }
        if (!ctype_digit($extension)) {
            return false;
        }
        $ext = (int) $extension;
        return $ext >= (int) $row['ext_from'] && $ext <= (int) $row['ext_to'];
    }

    // ------------------------------------------------------------- Stripe keys

    /**
     * A reseller's own Stripe keys, decrypted. Empty strings when the reseller
     * uses platform Stripe or has no keys yet. Never throws.
     *
     * @return array{secret: string, webhook: string}
     */
    public static function stripeKeys(int $resellerId): array
    {
        try {
            $stmt = getDB()->prepare('SELECT secret_key, webhook_secret FROM plg_reseller_stripe WHERE reseller_id = ?');
            $stmt->execute([$resellerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return ['secret' => '', 'webhook' => ''];
            }
            return [
                'secret' => self::decryptSecret((string) $row['secret_key']),
                'webhook' => self::decryptSecret((string) $row['webhook_secret']),
            ];
        } catch (\Throwable $e) {
            error_log('reseller stripeKeys: ' . $e->getMessage());
            return ['secret' => '', 'webhook' => ''];
        }
    }

    /**
     * Save a reseller's own Stripe keys. An empty secret keeps the stored one
     * (same rule as the billing plugin's secret settings: an empty field on
     * submit preserves the existing secret). $mode is kept in step on the
     * reseller row too. Throws when the secret box is unavailable — keys are
     * never stored in plaintext.
     */
    public static function saveStripeKeys(int $resellerId, string $secret, string $webhook, string $mode): void
    {
        $row = self::get($resellerId);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown reseller.');
        }
        if (!in_array($mode, self::STRIPE_MODES, true)) {
            throw new \InvalidArgumentException('Unknown Stripe mode.');
        }
        $keys = self::stripeKeys($resellerId);
        $secret = trim($secret);
        $webhook = trim($webhook);
        if ($secret === '' && $keys['secret'] !== '') {
            $secret = $keys['secret'];
        }
        if ($webhook === '' && $keys['webhook'] !== '') {
            $webhook = $keys['webhook'];
        }
        $db = getDB();
        $db->prepare('UPDATE plg_resellers SET stripe_mode = ? WHERE id = ?')->execute([$mode, $resellerId]);
        $db->prepare(
            'INSERT INTO plg_reseller_stripe (reseller_id, mode, secret_key, webhook_secret)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), secret_key = VALUES(secret_key), webhook_secret = VALUES(webhook_secret)'
        )->execute([$resellerId, $mode, self::encryptSecret($secret), self::encryptSecret($webhook)]);
        self::audit($resellerId, 'Reseller Stripe keys saved (' . $mode . ' mode): ' . $row['name']);
    }

    private static function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (!class_exists('SecretBox')) {
            throw new \RuntimeException('SecretBox is unavailable — refusing to store a Stripe key in plaintext.');
        }
        return SecretBox::encrypt($plain);
    }

    private static function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (!class_exists('SecretBox')) {
            return '';
        }
        try {
            return SecretBox::decrypt($stored);
        } catch (\Throwable $e) {
            error_log('reseller decryptSecret: ' . $e->getMessage());
            return '';
        }
    }

    // ------------------------------------------------------------- audit

    public static function audit(int $resellerId, string $what): void
    {
        if (function_exists('writeAuditLog')) {
            try {
                writeAuditLog(null, 'reseller', $resellerId, $what, 'update', $_SESSION['user_id'] ?? null);
            } catch (\Throwable $e) {
                error_log('reseller audit: ' . $e->getMessage());
            }
        }
    }
}
