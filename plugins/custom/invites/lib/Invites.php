<?php

declare(strict_types=1);

/**
 * Invites: an administrator makes a link (copied, or e-mailed by the PBX); whoever opens it chooses
 * a user name and password and gets an account with the next free extension of the plugin's range,
 * as a normal user or as a guest with the rules the invite carries. The account works at once: the
 * parts of the configuration a new extension touches are applied right after it is created.
 *
 * A link is for one person (max_uses 1) or reusable (max_uses > 1), runs out at expires_at, and can
 * be withdrawn. Taking a use is one atomic UPDATE, so a reusable link never goes over its limit.
 */
final class Invites
{
    public const SLUG = 'invites';
    public const GUEST_RULES = ['guest_outbound', 'guest_inbound', 'guest_sms', 'guest_fax'];
    public const PHONE_MODES = ['web', 'mobil', 'sip', 'video'];
    private const JOIN_PER_IP_HOUR = 10;

    private static array $settings = [];

    public static function setSettings(array $s): void
    {
        self::$settings = $s;
    }

    public static function settings(): array
    {
        return self::$settings !== [] ? self::$settings : PluginManager::getAllSettings(self::SLUG, false);
    }

    public static function coreDir(): string
    {
        return dirname((string) (new \ReflectionFunction('getDB'))->getFileName()) . '/src';
    }

    public static function portalUrl(): string
    {
        $host = function_exists('portalEnv') ? trim((string) portalEnv('PORTAL_DOMAIN', '')) : '';
        return 'https://' . ($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    public static function url(string $token): string
    {
        return self::portalUrl() . '/api/plugins/invites/join/' . $token;
    }

    // --- Administrator side ---

    /**
     * New invite from the admin form. Returns the row (with token and url).
     * @param array<string, mixed> $in label, email, kind (one|many), max_uses, days, is_guest,
     *                                 guest_* switches, phone_modes[]
     */
    public static function create(array $in, ?int $adminId): array
    {
        $label = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($in['label'] ?? '')) ?? ''), 0, 80);
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 120)) {
            throw new \InvalidArgumentException('That e-mail address does not look right.');
        }
        $many = ($in['kind'] ?? 'one') === 'many';
        if ($many && $email !== '') {
            throw new \InvalidArgumentException('A reusable link is shared, not e-mailed to one person: leave the e-mail empty, or make it a one-person invite.');
        }
        $maxUses = $many ? max(2, min(500, (int) ($in['max_uses'] ?? 10))) : 1;
        $days = max(1, min(90, (int) ($in['days'] ?? (self::settings()['default_days'] ?? 7))));
        $isGuest = !empty($in['is_guest']) ? 1 : 0;
        $rules = [];
        if ($isGuest) {
            foreach (self::GUEST_RULES as $r) {
                $rules[$r] = !empty($in[$r]) ? 1 : 0;
            }
            $modes = array_values(array_intersect(self::PHONE_MODES, (array) ($in['phone_modes'] ?? [])));
            $rules['phone_modes'] = $modes;
        }
        $token = bin2hex(random_bytes(16));
        getDB()->prepare('INSERT INTO plg_invites (token, label, email, max_uses, expires_at, is_guest, guest_rules, created_by)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?, ?)')
            ->execute([$token, $label, $email, $maxUses, $days, $isGuest, $isGuest ? (string) json_encode($rules) : '', $adminId]);
        $row = self::byToken($token);
        self::audit("Invite made: " . self::describe((array) $row));
        if ($email !== '') {
            self::sendInvite((array) $row);
        }
        return (array) $row;
    }

    /** All invites, newest first, with their status and who joined. */
    public static function all(): array
    {
        $rows = getDB()->query('SELECT * FROM plg_invites ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
        $joined = [];
        foreach (getDB()->query('SELECT u.invite_id, s.full_name, s.extension, s.username FROM plg_invite_uses u
            LEFT JOIN sys_users s ON s.id = u.user_id ORDER BY u.id')->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $joined[(int) $j['invite_id']][] = trim(($j['full_name'] ?: $j['username'] ?: '?') . ($j['extension'] ? ' (' . $j['extension'] . ')' : ''));
        }
        foreach ($rows as &$r) {
            $r['status'] = self::status($r);
            $r['joined'] = $joined[(int) $r['id']] ?? [];
            $r['url'] = self::url((string) $r['token']);
        }
        return $rows;
    }

    public static function revoke(int $id): void
    {
        getDB()->prepare('UPDATE plg_invites SET revoked = 1 WHERE id = ?')->execute([$id]);
        self::audit("Invite #{$id} withdrawn");
    }

    public static function delete(int $id): void
    {
        getDB()->prepare('DELETE FROM plg_invites WHERE id = ?')->execute([$id]);
        getDB()->prepare('DELETE FROM plg_invite_uses WHERE invite_id = ?')->execute([$id]);
        self::audit("Invite #{$id} deleted");
    }

    public static function resend(int $id): void
    {
        $q = getDB()->prepare('SELECT * FROM plg_invites WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['email'] === '' || self::status($row) !== 'open') {
            throw new \InvalidArgumentException('Only an open one-person invite with an e-mail can be sent again.');
        }
        self::sendInvite($row);
    }

    /** open | used | expired | withdrawn */
    public static function status(array $r): string
    {
        if (!empty($r['revoked'])) {
            return 'withdrawn';
        }
        if ((int) $r['uses'] >= (int) $r['max_uses']) {
            return 'used';
        }
        if (strtotime((string) $r['expires_at']) <= time()) {
            return 'expired';
        }
        return 'open';
    }

    /** "guest (outside calls, texts; web, mobile)" or "user", for lists and the audit log. */
    public static function describe(array $r): string
    {
        $who = ($r['label'] ?? '') !== '' ? $r['label'] : (($r['email'] ?? '') !== '' ? $r['email'] : 'link');
        $kind = (int) ($r['max_uses'] ?? 1) > 1 ? 'up to ' . (int) $r['max_uses'] . ' people' : 'one person';
        return "{$who}, {$kind}, " . self::accountText($r);
    }

    public static function accountText(array $r): string
    {
        if (empty($r['is_guest'])) {
            return 'normal user';
        }
        $rules = json_decode((string) ($r['guest_rules'] ?? ''), true) ?: [];
        $names = ['guest_outbound' => 'outside calls', 'guest_inbound' => 'outside callers', 'guest_sms' => 'texts', 'guest_fax' => 'fax'];
        $on = array_values(array_map(static fn(string $k): string => $names[$k], array_filter(self::GUEST_RULES, static fn(string $k): bool => !empty($rules[$k]))));
        $modeNames = ['web' => 'web phone', 'mobil' => 'mobile app', 'sip' => 'desk phone', 'video' => 'video'];
        $modes = array_map(static fn(string $m): string => $modeNames[$m] ?? $m, (array) ($rules['phone_modes'] ?? []));
        return 'guest (' . ($on === [] ? 'no outside access' : implode(', ', $on)) . '; ' . ($modes === [] ? 'no phones' : implode(', ', $modes)) . ')';
    }

    // --- The invited person ---

    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $q = getDB()->prepare('SELECT * FROM plg_invites WHERE token = ?');
        $q->execute([$token]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Signed time stamp the join form carries: stops replayed and scripted posts (2 s – 2 h). */
    public static function formToken(?int $now = null): string
    {
        $ts = (string) ($now ?? time());
        return $ts . '.' . hash_hmac('sha256', 'invite-form|' . $ts, self::secret());
    }

    public static function formTokenValid(string $token, ?int $now = null): bool
    {
        [$ts, $sig] = array_pad(explode('.', $token, 2), 2, '');
        $age = ($now ?? time()) - (int) $ts;
        return ctype_digit($ts) && $age >= 2 && $age <= 7200
            && hash_equals(hash_hmac('sha256', 'invite-form|' . $ts, self::secret()), $sig);
    }

    /**
     * Accepts an invite: checks the form, takes one use, creates the account, applies it.
     * @return array{extension: string, username: string, full_name: string}
     */
    public static function accept(string $token, array $in, string $ip): array
    {
        $inv = self::byToken($token);
        if ($inv === null || self::status($inv) !== 'open') {
            throw new \InvalidArgumentException('This invite is no longer valid.');
        }
        if (trim((string) ($in['website'] ?? '')) !== '' || !self::formTokenValid((string) ($in['form'] ?? ''))) {
            throw new \InvalidArgumentException('The form expired. Reload the page and try again.');
        }
        self::rateLimit($ip);

        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($in['full_name'] ?? '')) ?? '');
        $username = trim((string) ($in['username'] ?? ''));
        $email = $inv['email'] !== '' ? (string) $inv['email'] : strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        if ($name === '' || mb_strlen($name) > 80) {
            throw new \InvalidArgumentException('Enter your name.');
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,31}$/', $username)) {
            throw new \InvalidArgumentException('Choose a user name of 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter.');
        }
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 120)) {
            throw new \InvalidArgumentException('That e-mail address does not look right.');
        }
        if (mb_strlen($password) < 10) {
            throw new \InvalidArgumentException('Choose a password of at least 10 characters.');
        }
        if ($password !== (string) ($in['password2'] ?? '')) {
            throw new \InvalidArgumentException('The two passwords are not the same.');
        }
        $db = getDB();
        $taken = $db->prepare('SELECT 1 FROM sys_users WHERE username = ? OR extension = ? LIMIT 1');
        $taken->execute([$username, $username]);
        if ($taken->fetchColumn()) {
            throw new \InvalidArgumentException('That user name is taken. Choose another one.');
        }
        if ($email !== '') {
            $q = $db->prepare('SELECT 1 FROM sys_users WHERE email = ? LIMIT 1');
            $q->execute([$email]);
            if ($q->fetchColumn()) {
                throw new \InvalidArgumentException('An account with this e-mail already exists. Sign in instead, or ask for a password reset.');
            }
        }

        // Take one use before anything is created; given back if the account fails.
        $claim = $db->prepare('UPDATE plg_invites SET uses = uses + 1 WHERE id = ? AND revoked = 0 AND uses < max_uses AND expires_at > NOW()');
        $claim->execute([(int) $inv['id']]);
        if ($claim->rowCount() !== 1) {
            throw new \InvalidArgumentException('This invite is no longer valid.');
        }
        try {
            $ext = self::createAccount($inv, $name, $username, $email, $password);
        } catch (\Throwable $e) {
            $db->prepare('UPDATE plg_invites SET uses = GREATEST(uses - 1, 0) WHERE id = ?')->execute([(int) $inv['id']]);
            throw $e instanceof \InvalidArgumentException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
        $uid = (int) $db->query('SELECT id FROM sys_users WHERE username = ' . $db->quote($username))->fetchColumn();
        $db->prepare('INSERT INTO plg_invite_uses (invite_id, user_id, ip) VALUES (?, ?, ?)')->execute([(int) $inv['id'], $uid, mb_substr($ip, 0, 45)]);
        $applied = self::applyNewExtension(!empty($inv['is_guest']));
        self::audit("Joined by invite #{$inv['id']}: {$name} ({$username}), extension {$ext}" . ($applied ? '' : ' — apply pending changes'));
        self::notifyAdmin($inv, $name, $username, $ext, $email, $applied);
        return ['extension' => $ext, 'username' => $username, 'full_name' => $name];
    }

    /** Creates the account like the Users page does; tries the next extension if one was taken meanwhile. */
    private static function createAccount(array $inv, string $name, string $username, string $email, string $password): string
    {
        require_once self::coreDir() . '/services/UserService.php';
        $rules = json_decode((string) $inv['guest_rules'], true) ?: [];
        $last = '';
        for ($try = 0; $try < 3; $try++) {
            $ext = self::nextFreeExtension();
            $data = [
                'csrf_token' => getCSRFToken(),
                'username' => $username,
                'full_name' => $name,
                'email' => $email,
                'password' => $password,
                'skip_invitation' => 1,      // they chose their password: no set-password mail
                'role' => 'user',
                'extension' => $ext,
                'is_active' => 1,
            ];
            if (!empty($inv['is_guest'])) {
                $data['is_guest'] = 1;
                foreach (self::GUEST_RULES as $r) {
                    $data[$r] = !empty($rules[$r]) ? 1 : 0;
                }
                $data['allowed_phone_modes'] = array_values(array_intersect(self::PHONE_MODES, (array) ($rules['phone_modes'] ?? [])));
            } else {
                $data['allowed_phone_mode'] = 'both';
            }
            $res = UserService::saveUser($data);
            if (!empty($res['success'])) {
                return $ext;
            }
            $last = (string) ($res['error'] ?? 'unknown error');
            // Somebody took the extension a moment ago: try the next one; anything else is final.
            if (stripos($last, 'extension') === false) {
                break;
            }
        }
        throw new \RuntimeException('The account could not be created: ' . $last);
    }

    public static function nextFreeExtension(): string
    {
        $s = self::settings();
        $from = max(100, (int) ($s['ext_from'] ?? 3000));
        $to = max($from, (int) ($s['ext_to'] ?? 3999));
        require_once self::coreDir() . '/internal_numbers.php';
        for ($e = $from; $e <= $to; $e++) {
            if (internalNumberOwner((string) $e) === null) {
                return (string) $e;
            }
        }
        throw new \RuntimeException("No free extension left between {$from} and {$to} (Invites settings).");
    }

    /**
     * The new extension works at once: only the parts of the configuration a new user touches are
     * rebuilt (extensions, the general dialplan, and for a guest the outbound gate), the same way
     * Apply does it for those parts. Other pending changes stay pending. False when that failed:
     * the admin then applies by hand.
     */
    private static function applyNewExtension(bool $guest): bool
    {
        try {
            require_once self::coreDir() . '/asterisk_sync.php';
            $domains = ['extensions', 'general_dialplan'];
            if ($guest) {
                $domains[] = 'outbound_dialplan';
            }
            $ok = true;
            foreach ($domains as $d) {
                $fn = PENDING_SYNC_DOMAIN_MAP[$d] ?? null;
                if (!$fn || !function_exists($fn)) {
                    $ok = false;
                    continue;
                }
                try {
                    $fn();
                    if ($d === 'general_dialplan') {
                        PluginManager::syncAllEnabledPlugins();
                    }
                    getDB()->prepare('DELETE FROM sys_pending_sync WHERE domain = ?')->execute([$d]);
                } catch (\Throwable $e) {
                    error_log("invites: apply {$d}: " . $e->getMessage());
                    $ok = false;
                }
            }
            return $ok;
        } catch (\Throwable $e) {
            error_log('invites: apply: ' . $e->getMessage());
            return false;
        }
    }

    private static function rateLimit(string $ip): void
    {
        $key = substr(hash('sha256', $ip), 0, 24) . '-' . date('YmdH');
        $row = PluginManager::kvGet(self::SLUG, 'rate', $key);
        $n = (int) ($row['value'] ?? 0);
        if ($n >= self::JOIN_PER_IP_HOUR) {
            throw new \InvalidArgumentException('Too many tries from your network. Try again in an hour.');
        }
        PluginManager::kvSet(self::SLUG, 'rate', $key, $n + 1);
    }

    private static function secret(): string
    {
        $row = PluginManager::kvGet(self::SLUG, 'state', 'secret');
        $k = (string) ($row['value'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $k)) {
            $k = bin2hex(random_bytes(32));
            PluginManager::kvSet(self::SLUG, 'state', 'secret', $k);
        }
        return $k;
    }

    // --- Mail and audit ---

    private static function siteName(): string
    {
        return function_exists('getSystemSetting') ? (string) getSystemSetting('brand_title', 'OwnPBX') : 'OwnPBX';
    }

    public static function sendInvite(array $inv): void
    {
        $site = self::siteName();
        $hello = $inv['label'] !== '' ? "Hello {$inv['label']},\n\n" : "Hello,\n\n";
        self::mail((string) $inv['email'], "You are invited to {$site}", $hello
            . "you are invited to an account on {$site}. Open this link to choose your user name and password; "
            . "you get your own extension right away:\n\n" . self::url((string) $inv['token']) . "\n\n"
            . 'The link works until ' . date('F j, Y', (int) strtotime((string) $inv['expires_at'])) . '.');
    }

    private static function notifyAdmin(array $inv, string $name, string $username, string $ext, string $email, bool $applied): void
    {
        $to = trim((string) (self::settings()['notify_email'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        self::mail($to, "{$name} joined through an invite", "{$name} ({$username}" . ($email !== '' ? ", {$email}" : '') . ") accepted an invite "
            . '(' . self::describe($inv) . ") and has extension {$ext}."
            . ($applied ? '' : "\n\nThe extension could not be applied automatically: open Apply pending changes in the portal."));
    }

    /** A plain e-mail in the portal's mail look; a failure is only logged. */
    private static function mail(string $to, string $subject, string $text): void
    {
        try {
            if (!class_exists('MailTemplateService')) {
                require_once self::coreDir() . '/services/MailTemplateService.php';
            }
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
            $mail = MailTemplateService::render('invites', 'en', [], [], ['subject' => $subject, 'body' => $html]);
            [$from, $fromName] = MailTemplateService::sender('portal');
            [$headers, $body] = MailTemplateService::buildMime($mail, $from, $fromName);
            if (!@mail($to, mb_encode_mimeheader($mail['subject'], 'UTF-8'), $body, $headers, '-f ' . $from)) {
                error_log("invites: mail to {$to} failed");
            }
        } catch (\Throwable $e) {
            error_log('invites: mail: ' . $e->getMessage());
        }
    }

    private static function audit(string $what): void
    {
        if (function_exists('writeAuditLog')) {
            try {
                writeAuditLog(null, 'invites', 'invite', mb_substr($what, 0, 250), 'update', $_SESSION['user_id'] ?? null);
            } catch (\Throwable $e) {
                error_log('invites audit: ' . $e->getMessage());
            }
        }
    }
}
