<?php

declare(strict_types=1);

/**
 * What just happened on the PBX, for a screen that is not the phone: a TV launcher, a tablet
 * on the wall, a dashboard. One read-only call:
 *
 *   GET /api/plugins/launcher/events?since=<id>&limit=<n>   header X-Launcher-Token
 *   {"ok":true,"now":<unix>,"latest_id":N,"events":[ ... oldest first ... ]}
 *
 * An event carries what a glance needs and nothing else:
 *   type      call | sms | chat | voicemail
 *   title     "Incoming call", "SMS", "Chat", "Voicemail"
 *   who       the caller or sender, named when the PBX knows the name
 *   number    their number (empty for an inside extension)
 *   to        the extension it is for, with that person's name when known
 *   line      pstn | internal  - an outside number or another extension
 *   text      the first lines of the message, or of the voicemail transcript
 *   at        unix time
 *
 * Ids grow with time so a client keeps the last one it showed and asks for newer ones only.
 * They are not database ids: four sources are woven together here (see eventId).
 */
final class LauncherFeed
{
    public const SLUG = 'launcher';
    private const EPOCH = 1767225600; // 2026-01-01T00:00:00Z
    private const SOURCES = ['call' => 0, 'sms' => 1, 'chat' => 2, 'voicemail' => 3];

    /** The token the launcher sends in X-Launcher-Token; made once, renewed from the plugin page. */
    public static function token(bool $renew = false): string
    {
        $row = $renew ? null : PluginManager::kvGet(self::SLUG, 'state', 'token');
        $t = (string) ($row['value'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $t)) {
            $t = bin2hex(random_bytes(20));
            PluginManager::kvSet(self::SLUG, 'state', 'token', $t);
        }
        return $t;
    }

    public static function tokenValid(string $given): bool
    {
        return $given !== '' && hash_equals(self::token(), $given);
    }

    public static function on(array $settings, string $key): bool
    {
        return (string) ($settings[$key] ?? '1') === '1';
    }

    /** Extensions named in the settings; an empty list means every extension. */
    public static function watched(array $settings): array
    {
        return array_values(array_filter(array_map(
            static fn(string $e): string => preg_replace('/\D+/', '', trim($e)) ?? '',
            explode(',', (string) ($settings['extensions'] ?? ''))
        ), static fn(string $e): bool => $e !== ''));
    }

    private static function watches(array $watched, string $ext): bool
    {
        return $watched === [] || in_array($ext, $watched, true);
    }

    /**
     * Ids must grow with time across all four sources and stay small enough for a client that
     * keeps them in an integer: seconds since 2026-01-01, times four for the source, plus one
     * bit of the row id so two events in the same second never collide into one.
     */
    private static function eventId(int $at, string $type, int $rowId): int
    {
        $src = self::SOURCES[$type] ?? 0;
        return max(0, $at - self::EPOCH) * 8 + $src;
    }

    public static function events(array $settings, int $since, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $chars = max(0, min(1000, (int) ($settings['preview_chars'] ?? 160)));
        $minutes = max(1, min(1440, (int) ($settings['window_minutes'] ?? 10)));
        $notBefore = time() - $minutes * 60;
        $watched = self::watched($settings);

        $out = [];
        if (self::on($settings, 'include_calls')) {
            $out = array_merge($out, self::calls($watched));
        }
        if (self::on($settings, 'include_sms')) {
            $out = array_merge($out, self::sms($watched, $notBefore, $chars, $limit));
        }
        if (self::on($settings, 'include_chat')) {
            $out = array_merge($out, self::chat($watched, $notBefore, $chars, $limit));
        }
        if (self::on($settings, 'include_voicemail')) {
            $out = array_merge($out, self::voicemail($watched, $notBefore, $chars));
        }

        $out = array_values(array_filter($out, static fn(array $e): bool => $e['at'] >= $notBefore && $e['id'] > $since));
        usort($out, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        $out = array_slice($out, -$limit);

        return [
            'ok' => true,
            'now' => time(),
            'latest_id' => $out === [] ? $since : (int) end($out)['id'],
            'events' => $out,
        ];
    }

    // ---------------------------------------------------------------- sources

    /**
     * Calls happening right now, from Asterisk itself (nothing is written down while a phone
     * rings). "core show channels concise" gives one line per channel, fields separated by "!":
     * name!context!exten!priority!state!application!data!callerid!accountcode!...!uniqueid
     */
    private static function calls(array $watched): array
    {
        $raw = (string) (AsteriskHelper::execCLI('core show channels concise')['output'] ?? '');
        $out = [];
        $seen = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $f = explode('!', $line);
            if (count($f) < 8 || !str_starts_with($f[0], 'PJSIP/')) {
                continue;
            }
            [$name, , $exten, , $state, , , $callerid] = $f;
            // The ringing leg is the one worth announcing; a call already up was announced before.
            if (stripos($state, 'ring') === false) {
                continue;
            }
            $ext = preg_replace('/\D+/', '', $exten) ?? '';
            $peer = (string) preg_replace('/^PJSIP\/([^-]+).*/', '$1', $name);
            // The extension being called: PJSIP/<ext>-sip, PJSIP/<ext>-webrtc, ...
            $toExt = preg_replace('/\D+/', '', explode('-', $peer)[0]) ?? '';
            if ($toExt === '' || !self::watches($watched, $toExt)) {
                continue;
            }
            $from = (string) preg_replace('/[^0-9+]/', '', $callerid);
            $key = $toExt . '|' . $from;
            if ($from === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $at = time();
            $out[] = [
                'id' => self::eventId($at, 'call', 0),
                'type' => 'call',
                'title' => 'Incoming call',
                'who' => self::callerName($from) ?: $from,
                'number' => $from,
                'to' => self::extLabel($toExt),
                'line' => strlen($from) > 6 ? 'pstn' : 'internal',
                'text' => '',
                'at' => $at,
            ];
            unset($ext);
        }
        return $out;
    }

    private static function sms(array $watched, int $notBefore, int $chars, int $limit): array
    {
        if (!self::tableExists('plg_sms_messages')) {
            return [];
        }
        try {
            $q = getDB()->prepare("SELECT m.id, m.body, m.created_at, m.peer, m.number,
                    (SELECT c.name FROM plg_sms_contacts c WHERE c.number = m.peer LIMIT 1) AS contact,
                    (SELECT u.extension FROM plg_sms_access a JOIN sys_users u ON u.id = a.user_id
                       WHERE a.number_id = m.number_id LIMIT 1) AS ext
                FROM plg_sms_messages m
                WHERE m.direction = 'in' AND m.created_at >= FROM_UNIXTIME(?)
                ORDER BY m.id DESC LIMIT " . (int) $limit);
            $q->execute([$notBefore]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('launcher sms: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $m) {
            $ext = preg_replace('/\D+/', '', (string) ($m['ext'] ?? '')) ?? '';
            if ($ext !== '' && !self::watches($watched, $ext)) {
                continue;
            }
            $at = strtotime((string) $m['created_at']) ?: 0;
            $peer = (string) $m['peer'];
            $out[] = [
                'id' => self::eventId($at, 'sms', (int) $m['id']),
                'type' => 'sms',
                'title' => 'SMS',
                'who' => (string) ($m['contact'] ?? '') !== '' ? (string) $m['contact'] : $peer,
                'number' => $peer,
                'to' => $ext !== '' ? self::extLabel($ext) : (string) $m['number'],
                'line' => 'pstn',
                'text' => self::preview((string) $m['body'] !== '' ? (string) $m['body'] : '[picture]', $chars),
                'at' => $at,
            ];
        }
        return $out;
    }

    private static function chat(array $watched, int $notBefore, int $chars, int $limit): array
    {
        if (!self::tableExists('chat_messages')) {
            return [];
        }
        try {
            $q = getDB()->prepare("SELECT m.id, m.sender_ext, m.msg_type, m.message, m.file_name, m.created_at,
                    su.full_name AS sender_name,
                    (SELECT GROUP_CONCAT(p.extension) FROM chat_participants p
                       WHERE p.conversation_id = m.conversation_id AND p.extension <> m.sender_ext AND p.left_at IS NULL) AS to_exts
                FROM chat_messages m
                LEFT JOIN sys_users su ON su.extension = m.sender_ext COLLATE utf8mb4_unicode_ci
                WHERE m.is_deleted = 0 AND m.msg_type <> 'system' AND m.created_at >= FROM_UNIXTIME(?)
                ORDER BY m.id DESC LIMIT " . (int) $limit);
            $q->execute([$notBefore]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('launcher chat: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $m) {
            $recipients = array_values(array_filter(array_map(
                static fn(string $e): string => preg_replace('/\D+/', '', trim($e)) ?? '',
                explode(',', (string) ($m['to_exts'] ?? ''))
            ), static fn(string $e): bool => $e !== ''));
            $forUs = array_values(array_filter($recipients, static fn(string $e): bool => self::watches($watched, $e)));
            if ($forUs === []) {
                continue;
            }
            $body = (string) $m['message'];
            if ($body === '' || $m['msg_type'] !== 'text') {
                $body = ['image' => '[picture]', 'audio' => '[voice message]', 'file' => '[file] ' . ($m['file_name'] ?: '')][$m['msg_type']] ?? $body;
            }
            $at = strtotime((string) $m['created_at']) ?: 0;
            $sender = (string) $m['sender_ext'];
            $out[] = [
                'id' => self::eventId($at, 'chat', (int) $m['id']),
                'type' => 'chat',
                'title' => 'Chat',
                'who' => (string) ($m['sender_name'] ?? '') !== '' ? (string) $m['sender_name'] : $sender,
                'number' => $sender,
                'to' => self::extLabel($forUs[0]),
                'line' => 'internal',
                'text' => self::preview($body, $chars),
                'at' => $at,
            ];
        }
        return $out;
    }

    /**
     * New voicemail: Asterisk writes each message to the spool as msgNNNN.txt beside its audio,
     * and the AI transcript (when AI voicemail is on) lands in voicemail_ai keyed by origtime.
     */
    private static function voicemail(array $watched, int $notBefore, int $chars): array
    {
        $boxes = $watched;
        if ($boxes === []) {
            try {
                $boxes = array_map('strval', getDB()->query(
                    "SELECT extension FROM sys_users WHERE is_active = 1 AND extension <> ''"
                )->fetchAll(PDO::FETCH_COLUMN));
            } catch (\Throwable $e) {
                return [];
            }
        }
        $out = [];
        foreach ($boxes as $ext) {
            $ext = preg_replace('/\D+/', '', (string) $ext) ?? '';
            if ($ext === '') {
                continue;
            }
            foreach (glob("/var/spool/asterisk/voicemail/default/{$ext}/INBOX/msg*.txt") ?: [] as $txt) {
                $info = @parse_ini_file($txt) ?: [];
                $at = (int) ($info['origtime'] ?? 0);
                if ($at < $notBefore) {
                    continue;
                }
                $cid = (string) ($info['callerid'] ?? '');
                $number = (string) preg_replace('/[^0-9+]/', '', $cid);
                $name = trim((string) preg_replace('/["<].*$/', '', $cid));
                $out[] = [
                    'id' => self::eventId($at, 'voicemail', 0),
                    'type' => 'voicemail',
                    'title' => 'Voicemail',
                    'who' => $name !== '' && !is_numeric($name) ? $name : (self::callerName($number) ?: $number),
                    'number' => $number,
                    'to' => self::extLabel($ext),
                    'line' => strlen($number) > 6 ? 'pstn' : 'internal',
                    'text' => self::preview(self::transcript($ext, $at), $chars),
                    'at' => $at,
                ];
            }
        }
        return $out;
    }

    /** The AI transcript of one message, when AI voicemail transcribed it; its summary if shorter. */
    private static function transcript(string $mailbox, int $origtime): string
    {
        if (!self::tableExists('voicemail_ai')) {
            return '';
        }
        try {
            $q = getDB()->prepare('SELECT transcript, summary FROM voicemail_ai WHERE mailbox = ? AND origtime = ? LIMIT 1');
            $q->execute([$mailbox, $origtime]);
            $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return '';
        }
        $summary = trim((string) ($row['summary'] ?? ''));
        $full = trim((string) ($row['transcript'] ?? ''));
        return $summary !== '' ? $summary : $full;
    }

    // ----------------------------------------------------------------- naming

    /** "611 Kara Danvers", or just the extension when nobody is on it. */
    private static function extLabel(string $ext): string
    {
        static $cache = [];
        if (!isset($cache[$ext])) {
            try {
                $q = getDB()->prepare("SELECT full_name FROM sys_users WHERE extension = ? LIMIT 1");
                $q->execute([$ext]);
                $name = trim((string) ($q->fetchColumn() ?: ''));
            } catch (\Throwable $e) {
                $name = '';
            }
            $cache[$ext] = $name !== '' ? $ext . ' ' . $name : $ext;
        }
        return $cache[$ext];
    }

    /** A name for an outside number: an SMS contact, or an extension if it is one of ours. */
    private static function callerName(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 6) {
            $label = self::extLabel($digits);
            return $label === $digits ? '' : trim(substr($label, strlen($digits)));
        }
        if (!self::tableExists('plg_sms_contacts')) {
            return '';
        }
        try {
            $q = getDB()->prepare("SELECT name FROM plg_sms_contacts WHERE RIGHT(number, 10) = RIGHT(?, 10) LIMIT 1");
            $q->execute([$digits]);
            return trim((string) ($q->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The first part of a message: enough to read at a glance, never a wall of text. */
    private static function preview(string $text, int $chars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($chars <= 0 || $text === '') {
            return '';
        }
        if (mb_strlen($text) <= $chars) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $chars)) . '…';
    }

    private static function tableExists(string $table): bool
    {
        static $known = [];
        if (!isset($known[$table])) {
            try {
                $q = getDB()->prepare('SHOW TABLES LIKE ?');
                $q->execute([$table]);
                $known[$table] = $q->fetch() !== false;
            } catch (\Throwable $e) {
                $known[$table] = false;
            }
        }
        return $known[$table];
    }
}
