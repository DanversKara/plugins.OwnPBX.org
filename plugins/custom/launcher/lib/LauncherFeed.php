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
            // Never below what the client already has: a ringing call keeps one id for its whole
            // life, so the newest id in a batch can be older than the cursor the client holds.
            'latest_id' => $out === [] ? $since : max($since, (int) end($out)['id']),
            // Where "now" is, for a client starting up: it asks once, keeps this, and is then
            // told only about what happens from here on instead of everything in the window.
            'now_id' => self::eventId(time(), 'call', 0) + 7,
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
        $rows = self::channels();
        $byUniqueId = [];
        foreach ($rows as $r) {
            if ($r['uniqueid'] !== '') {
                $byUniqueId[$r['uniqueid']] = $r;
            }
        }
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            // The ringing leg is the one worth announcing; a call already up was announced before.
            if (stripos($r['state'], 'ring') === false) {
                continue;
            }
            $toExt = self::extensionOf($r['name']);
            if ($toExt === '' || !self::watches($watched, $toExt)) {
                continue;
            }
            [$from, $callKey] = self::callerOf($r, $byUniqueId, $toExt);
            if (isset($seen[$callKey])) {
                continue;
            }
            $seen[$callKey] = true;
            $at = time();
            // A phone is ringing either way. Saying so without a name beats saying nothing,
            // which is what dropping the event did whenever the caller could not be worked out.
            $out[] = [
                'id' => self::callId($callKey, $at),
                'type' => 'call',
                'title' => 'Incoming call',
                'who' => $from === '' ? '' : (self::callerName($from) ?: $from),
                'number' => $from,
                'to' => self::extLabel($toExt),
                'line' => $from === '' ? '' : (strlen($from) > 6 ? 'pstn' : 'internal'),
                'text' => '',
                'at' => $at,
            ];
        }
        return $out;
    }

    /**
     * The extension a channel belongs to, or "" when it is not an extension's leg.
     *
     * A phone's channel is PJSIP/<ext>-sip, PJSIP/<ext>-webrtc and so on, so the part before the
     * first dash is the extension and nothing else. A trunk such as PJSIP/236248_openpbx has
     * digits in its name too, and stripping the letters out of it would invent an extension.
     */
    private static function extensionOf(string $channelName): string
    {
        $peer = (string) preg_replace('/^PJSIP\/([^-]+).*/', '$1', $channelName);
        return ctype_digit($peer) ? $peer : '';
    }

    /** One row per live channel, from "core show channels concise". */
    private static function channels(): array
    {
        $raw = (string) (AsteriskHelper::execCLI('core show channels concise')['output'] ?? '');
        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $f = explode('!', $line);
            if (count($f) < 8 || !str_starts_with($f[0], 'PJSIP/')) {
                continue;
            }
            $rows[] = [
                'name' => $f[0],
                'exten' => $f[2],
                'state' => $f[4],
                'cid' => (string) preg_replace('/[^0-9+]/', '', $f[7]),
                // The unique id is the last field, and counting from the end survives Asterisk
                // adding one: at a fixed index 13 it silently came back empty, which broke every
                // lookup that pairs a ringing leg with the call it belongs to.
                'uniqueid' => trim((string) end($f)),
            ];
        }
        return $rows;
    }

    /**
     * Who is really calling, and one key that stays the same for the whole call.
     *
     * The caller ID on the leg that rings a phone is not the caller: Asterisk fills it from the
     * endpoint it is dialling, so an outside call to extension 611 shows up as 611 calling 611.
     * The call's first channel - the one from the trunk - carries the real number, and every leg
     * of the call points at it through LinkedID, so that is what is read here.
     *
     * @param array<string, array> $byUniqueId every live channel, keyed by its unique id
     * @return array{0: string, 1: string} the caller's number (may be empty) and the call's key
     */
    private static function callerOf(array $leg, array $byUniqueId, string $toExt): array
    {
        $linked = '';
        $own = '';
        // The channel name comes from Asterisk, but it is going into a shell command: only the
        // characters a PJSIP channel name can hold are passed on.
        $name = (string) preg_replace('/[^A-Za-z0-9\/_.-]/', '', $leg['name']);
        if ($name !== '') {
            $info = (string) (AsteriskHelper::execCLI('core show channel ' . $name)['output'] ?? '');
            if (preg_match('/^\s*LinkedID:\s*(\S+)/mi', $info, $m)) {
                $linked = trim($m[1]);
            }
            if (preg_match('/^\s*Caller ID:\s*(.*)$/mi', $info, $m)) {
                $own = (string) preg_replace('/[^0-9+]/', '', $m[1]);
            }
        }

        $candidates = [];
        // Best: the channel the call started on, which for an inbound call is the trunk leg.
        if ($linked !== '' && $linked !== $leg['uniqueid'] && isset($byUniqueId[$linked])) {
            $candidates[] = $byUniqueId[$linked]['cid'];
        }
        $candidates[] = $own;
        // Failing that: the only channel on the PBX that is not an extension's own leg is the
        // trunk the call came in on. With two such calls at once there is no telling them apart,
        // so this is used only when there is exactly one.
        $outside = [];
        foreach ($byUniqueId as $r) {
            if ($r['cid'] !== '' && self::extensionOf($r['name']) === '') {
                $outside[$r['cid']] = $r['cid'];
            }
        }
        if (count($outside) === 1) {
            $candidates[] = reset($outside);
        }
        $candidates[] = $leg['cid'];

        $from = '';
        foreach ($candidates as $c) {
            $digits = preg_replace('/\D+/', '', (string) $c) ?? '';
            // A leg that claims to be called by the extension it is ringing is telling us nothing.
            if ($digits !== '' && $digits !== $toExt) {
                $from = (string) $c;
                break;
            }
        }
        $key = $linked !== '' ? 'L' . $linked : $toExt . '|' . $from;
        return [$from, $key];
    }

    /**
     * The id for a ringing call, assigned once and then remembered.
     *
     * A ringing call is not a row in a table: it is whatever Asterisk happens to be doing when the
     * feed is asked. Giving it a fresh id on every poll made it a new event every few seconds, so
     * the launcher put the same banner back on screen for as long as the phone rang. The id is now
     * kept against the call's key until the call is long gone.
     */
    private static function callId(string $key, int $at): int
    {
        $row = PluginManager::kvGet(self::SLUG, 'state', 'call_ids');
        $map = is_array($row['value'] ?? null) ? $row['value'] : [];
        $cutoff = $at - 3600;
        $changed = false;
        foreach ($map as $k => $v) {
            if ((int) ($v['at'] ?? 0) < $cutoff) {
                unset($map[$k]);
                $changed = true;
            }
        }
        if (isset($map[$key]['id'])) {
            $id = (int) $map[$key]['id'];
            if ($changed) {
                PluginManager::kvSet(self::SLUG, 'state', 'call_ids', $map);
            }
            return $id;
        }
        // Never reuse or go below an id already handed out: two calls in the same second would
        // otherwise share one id, and the second would look like something already seen.
        $highest = 0;
        foreach ($map as $v) {
            $highest = max($highest, (int) ($v['id'] ?? 0));
        }
        $id = max(self::eventId($at, 'call', 0), $highest + 8);
        $map[$key] = ['id' => $id, 'at' => $at];
        PluginManager::kvSet(self::SLUG, 'state', 'call_ids', $map);
        return $id;
    }

    private static function sms(array $watched, int $notBefore, int $chars, int $limit): array
    {
        if (!self::tableExists('plg_sms_messages')) {
            return [];
        }
        try {
            // The number the text came in on lives in plg_sms_numbers, and a contact's number is
            // its "phone" column, scoped to that same number: plg_sms_messages has neither.
            // UNIX_TIMESTAMP, not the datetime string: the database and PHP do not have to agree
            // about the time zone, and reading "16:46" as if it were UTC put every message hours
            // outside the window, so nothing was ever reported.
            $q = getDB()->prepare("SELECT m.id, m.body, UNIX_TIMESTAMP(m.created_at) AS at_unix, m.peer, n.number,
                    (SELECT c.name FROM plg_sms_contacts c
                       WHERE c.number_id = m.number_id AND c.phone = m.peer LIMIT 1) AS contact,
                    (SELECT u.extension FROM plg_sms_access a JOIN sys_users u ON u.id = a.user_id
                       WHERE a.number_id = m.number_id LIMIT 1) AS ext
                FROM plg_sms_messages m
                LEFT JOIN plg_sms_numbers n ON n.id = m.number_id
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
            $at = (int) ($m['at_unix'] ?? 0);
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
            $q = getDB()->prepare("SELECT m.id, m.sender_ext, m.msg_type, m.message, m.file_name,
                    UNIX_TIMESTAMP(m.created_at) AS at_unix,
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
            $at = (int) ($m['at_unix'] ?? 0);
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
            $q = getDB()->prepare("SELECT name FROM plg_sms_contacts WHERE RIGHT(phone, 10) = RIGHT(?, 10) LIMIT 1");
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

    /**
     * What each source can see right now, and what it did with it.
     *
     * When nothing reaches the screen the question is always the same: did the PBX have nothing,
     * or did it have something and drop it? This answers that on the plugin page - the live
     * channels, the last few messages, which extension each is for, and whether the watch list
     * let it through - so a blank screen can be read instead of guessed at.
     *
     * @return array<string, array{note: string, lines: list<string>}>
     */
    public static function diagnose(array $settings): array
    {
        $watched = self::watched($settings);
        $minutes = max(1, min(1440, (int) ($settings['window_minutes'] ?? 10)));
        $notBefore = time() - $minutes * 60;
        $watchNote = $watched === [] ? 'every extension' : implode(', ', $watched);
        $mark = static fn(bool $ok): string => $ok ? '✓ shown' : '✗ not watched';
        $out = [];

        $rows = self::channels();
        // The same map the feed itself builds: without it no caller can be resolved here, and the
        // panel would report "no caller" for calls the feed reports perfectly well.
        $byUniqueId = [];
        foreach ($rows as $r) {
            if ($r['uniqueid'] !== '') {
                $byUniqueId[$r['uniqueid']] = $r;
            }
        }
        $lines = [];
        foreach ($rows as $r) {
            $toExt = self::extensionOf($r['name']);
            $ringing = stripos($r['state'], 'ring') !== false;
            $from = '';
            if ($ringing && $toExt !== '') {
                [$from] = self::callerOf($r, $byUniqueId, $toExt);
            }
            $lines[] = sprintf(
                '%s — %s%s%s',
                $r['name'],
                $r['state'],
                $from !== '' ? ', from ' . $from : ($r['cid'] !== '' ? ', leg caller id ' . $r['cid'] : ''),
                !$ringing ? ' (not ringing)' : ($toExt === '' ? ' (a trunk, not an extension)' : ' ' . $mark(self::watches($watched, $toExt)))
            );
        }
        $out['calls'] = [
            'note' => $rows === [] ? 'No live channels.' : count($rows) . ' live channel(s). Watching: ' . $watchNote . '.',
            'lines' => $lines,
        ];

        $out['sms'] = self::diagnoseRows(
            'plg_sms_messages',
            "SELECT m.id, m.peer, m.created_at,
                    (SELECT u.extension FROM plg_sms_access a JOIN sys_users u ON u.id = a.user_id
                       WHERE a.number_id = m.number_id LIMIT 1) AS ext
             FROM plg_sms_messages m
             WHERE m.direction = 'in' AND m.created_at >= FROM_UNIXTIME(?)
             ORDER BY m.id DESC LIMIT 5",
            $notBefore,
            static function (array $m) use ($watched, $mark): string {
                $ext = preg_replace('/\D+/', '', (string) ($m['ext'] ?? '')) ?? '';
                return sprintf(
                    '%s from %s → %s  %s',
                    (string) $m['created_at'],
                    (string) $m['peer'],
                    $ext !== '' ? 'ext ' . $ext : 'no extension owns this number',
                    $ext === '' ? '✓ shown' : $mark(self::watches($watched, $ext))
                );
            },
            $minutes
        );

        $out['chat'] = self::diagnoseRows(
            'chat_messages',
            "SELECT m.id, m.sender_ext, m.created_at,
                    (SELECT GROUP_CONCAT(p.extension) FROM chat_participants p
                       WHERE p.conversation_id = m.conversation_id AND p.extension <> m.sender_ext
                         AND p.left_at IS NULL) AS to_exts
             FROM chat_messages m
             WHERE m.is_deleted = 0 AND m.msg_type <> 'system' AND m.created_at >= FROM_UNIXTIME(?)
             ORDER BY m.id DESC LIMIT 5",
            $notBefore,
            static function (array $m) use ($watched, $mark): string {
                $to = trim((string) ($m['to_exts'] ?? ''));
                $any = false;
                foreach (explode(',', $to) as $e) {
                    $e = preg_replace('/\D+/', '', trim($e)) ?? '';
                    if ($e !== '' && self::watches($watched, $e)) {
                        $any = true;
                    }
                }
                return sprintf(
                    '%s %s → %s  %s',
                    (string) $m['created_at'],
                    'ext ' . (string) $m['sender_ext'],
                    $to !== '' ? 'ext ' . $to : 'nobody else in the conversation',
                    $mark($any)
                );
            },
            $minutes
        );

        return $out;
    }

    /**
     * One source's last few rows, turned into readable lines - or the reason there are none.
     *
     * @param callable(array): string $line
     * @return array{note: string, lines: list<string>}
     */
    private static function diagnoseRows(string $table, string $sql, int $notBefore, callable $line, int $minutes): array
    {
        if (!self::tableExists($table)) {
            return ['note' => 'Not installed on this PBX.', 'lines' => []];
        }
        try {
            $q = getDB()->prepare($sql);
            $q->execute([$notBefore]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['note' => 'The query failed: ' . $e->getMessage(), 'lines' => []];
        }
        if ($rows === []) {
            return ['note' => "Nothing in the last {$minutes} minutes.", 'lines' => []];
        }
        return [
            'note' => count($rows) . ' in the last ' . $minutes . ' minutes, newest first.',
            'lines' => array_map($line, $rows),
        ];
    }

    private static function tableExists(string $table): bool
    {
        static $known = [];
        if (!isset($known[$table])) {
            try {
                // Not "SHOW TABLES LIKE ?": SHOW takes no placeholder, so with real prepared
                // statements MariaDB rejects it outright. The error was caught and read as "no
                // such table", which quietly switched texts, chat and transcripts off for good.
                $q = getDB()->prepare(
                    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
                );
                $q->execute([$table]);
                $known[$table] = $q->fetchColumn() !== false;
            } catch (\Throwable $e) {
                $known[$table] = false;
            }
        }
        return $known[$table];
    }
}
