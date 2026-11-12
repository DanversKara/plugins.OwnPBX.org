<?php

declare(strict_types=1);

/**
 * Texting for Home Assistant and the ESP32-S3-Box panel (ha_pbx_messages.yaml). The same two
 * calls the old PBX's "brain" answered on port 8099, now under /api/plugins/ha-texts/messages/:
 *
 *   GET  messages/inbox?ext=8803&limit=20   {"ok":true,"latest_id":N,"messages":[...oldest first]}
 *   POST messages/send  {"to","from","body"}  {"ok":true} | {"ok":false,"error":"..."}
 *
 * Both need the header X-Msg-Token (the plugin's token) and an extension listed in the settings.
 * The inbox of an extension: its direct Chat messages, and the SMS of the numbers its user may read.
 * Sending: to an extension through Chat, to a phone number through SMS (the user's first number).
 *
 * Message ids: Home Assistant keeps the last one seen in an input_number (max 999999999) and counts
 * newer ones as unread, so an id must grow with time across Chat and SMS together and stay under
 * 1e9: seconds since 2026-01-01 times four, plus the source and the id's last bit (~7 years).
 */
final class HaTexts
{
    public const SLUG = 'ha-texts';
    private const EPOCH = 1767225600; // 2026-01-01T00:00:00Z

    /** The token Home Assistant sends in X-Msg-Token; made once, renewed from the plugin page. */
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

    /** Extensions allowed in the settings (empty list: none). */
    public static function allowed(array $settings): array
    {
        return array_values(array_filter(array_map(
            static fn(string $e): string => preg_replace('/\D+/', '', $e) ?? '',
            explode(',', (string) ($settings['extensions'] ?? ''))
        ), static fn(string $e): bool => $e !== ''));
    }

    public static function on(array $settings, string $key): bool
    {
        return (string) ($settings[$key] ?? '1') === '1';
    }

    /** The active user of an extension, or null. */
    public static function userOf(string $ext): ?array
    {
        $q = getDB()->prepare("SELECT id, username, full_name, extension, role FROM sys_users WHERE extension = ? AND is_active = 1 LIMIT 1");
        $q->execute([$ext]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        return $u ?: null;
    }

    public static function haId(string $createdAt, int $source, int $id): int
    {
        $t = strtotime($createdAt . ' UTC');
        $secs = max(0, ($t === false ? time() : $t) - self::EPOCH);
        return $secs * 4 + $source * 2 + ($id & 1);
    }

    /** The newest messages of an extension, oldest first, as Home Assistant reads them. */
    public static function inbox(string $ext, int $limit, array $settings): array
    {
        $limit = max(1, min(100, $limit));
        $out = [];
        if (self::on($settings, 'include_chat')) {
            foreach (self::chatMessages($ext, $limit) as $m) {
                $mine = $m['sender_ext'] === $ext;
                $body = (string) $m['message'];
                if ($body === '' || $m['msg_type'] !== 'text') {
                    $body = ['image' => '📷 Picture', 'audio' => '🎤 Voice message', 'file' => '📎 ' . ($m['file_name'] ?: 'File')][$m['msg_type']] ?? $body;
                }
                $out[] = [
                    'id' => self::haId((string) $m['created_at'], 0, (int) $m['id']),
                    'dir' => $mine ? 'out' : 'in',
                    'from' => $mine ? $ext : self::label((string) $m['sender_ext'], (string) ($m['sender_name'] ?? '')),
                    'to' => $mine ? self::label((string) $m['other_ext'], (string) ($m['other_name'] ?? '')) : $ext,
                    'body' => $body,
                    'ts' => (string) $m['created_at'],
                    'via' => 'chat',
                ];
            }
        }
        $user = self::userOf($ext);
        if ($user !== null && self::on($settings, 'include_sms') && self::smsLoaded()) {
            foreach (self::smsMessages((int) $user['id'], $limit) as $m) {
                $in = $m['direction'] === 'in';
                $peer = (string) $m['peer'];
                $peerLabel = $m['contact'] !== null && $m['contact'] !== '' ? $m['contact'] : $peer;
                $out[] = [
                    'id' => self::haId((string) $m['created_at'], 1, (int) $m['id']),
                    'dir' => $in ? 'in' : 'out',
                    'from' => $in ? $peerLabel : (string) $m['number'],
                    'to' => $in ? (string) $m['number'] : $peerLabel,
                    'body' => (string) $m['body'] !== '' ? (string) $m['body'] : '📷 Picture',
                    'ts' => (string) $m['created_at'],
                    'via' => 'sms',
                ];
            }
        }
        usort($out, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        $out = array_slice($out, -$limit);
        return ['ok' => true, 'latest_id' => $out === [] ? 0 : (int) end($out)['id'], 'messages' => $out];
    }

    /**
     * Sends a text from $from: to an extension through Chat, to a phone number through SMS.
     * Throws InvalidArgumentException with the reason Home Assistant shows.
     */
    public static function send(string $from, string $to, string $body, array $settings): void
    {
        $body = trim($body);
        if (trim($to) === '') {
            throw new \InvalidArgumentException('no recipient: put an extension or a phone number in To');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('empty message');
        }
        $user = self::userOf($from);
        if ($user === null) {
            throw new \InvalidArgumentException("extension {$from} has no active user");
        }
        $toDigits = preg_replace('/\D+/', '', $to) ?? '';
        $isExtension = $toDigits !== '' && !str_starts_with(trim($to), '+') && strlen($toDigits) <= 6 && self::userOf($toDigits) !== null;
        if ($isExtension) {
            if (!self::on($settings, 'include_chat')) {
                throw new \InvalidArgumentException('texting extensions (Chat) is off in the plugin settings');
            }
            if ($toDigits === $from) {
                throw new \InvalidArgumentException('cannot text yourself');
            }
            self::sendChat($user, $toDigits, $body);
            return;
        }
        if (!self::on($settings, 'include_sms') || !self::smsLoaded()) {
            throw new \InvalidArgumentException("{$to} is not an extension, and SMS is off");
        }
        $numbers = Sms::numbersFor($user);
        if ($numbers === []) {
            throw new \InvalidArgumentException("extension {$from} has no SMS number");
        }
        Sms::send($numbers[0], $to, $body, [], (int) $user['id'], PluginManager::getAllSettings('sms', false));
    }

    // --- Chat ---

    private static function chatMessages(string $ext, int $limit): array
    {
        try {
            $q = getDB()->prepare("SELECT m.id, m.sender_ext, m.msg_type, m.message, m.file_name, m.created_at,
                    o.extension AS other_ext, ou.full_name AS other_name, su.full_name AS sender_name
                FROM chat_participants p
                JOIN chat_conversations c ON c.id = p.conversation_id AND c.type = 'direct' AND c.is_deleted = 0
                JOIN chat_participants o ON o.conversation_id = c.id AND o.extension <> p.extension
                JOIN chat_messages m ON m.conversation_id = c.id AND m.is_deleted = 0 AND m.msg_type <> 'system'
                LEFT JOIN sys_users ou ON ou.extension = o.extension COLLATE utf8mb4_unicode_ci
                LEFT JOIN sys_users su ON su.extension = m.sender_ext COLLATE utf8mb4_unicode_ci
                WHERE p.extension = ? AND p.left_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM chat_conversation_deletions d WHERE d.conversation_id = c.id AND d.user_ext = p.extension)
                ORDER BY m.id DESC LIMIT " . (int) $limit);
            $q->execute([$ext]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Without the per-user deletions table (older install) the same query without it.
            if (!str_contains($e->getMessage(), 'chat_conversation_deletions')) {
                error_log('ha-texts chat inbox: ' . $e->getMessage());
                return [];
            }
            $q = getDB()->prepare("SELECT m.id, m.sender_ext, m.msg_type, m.message, m.file_name, m.created_at,
                    o.extension AS other_ext, ou.full_name AS other_name, su.full_name AS sender_name
                FROM chat_participants p
                JOIN chat_conversations c ON c.id = p.conversation_id AND c.type = 'direct' AND c.is_deleted = 0
                JOIN chat_participants o ON o.conversation_id = c.id AND o.extension <> p.extension
                JOIN chat_messages m ON m.conversation_id = c.id AND m.is_deleted = 0 AND m.msg_type <> 'system'
                LEFT JOIN sys_users ou ON ou.extension = o.extension COLLATE utf8mb4_unicode_ci
                LEFT JOIN sys_users su ON su.extension = m.sender_ext COLLATE utf8mb4_unicode_ci
                WHERE p.extension = ? AND p.left_at IS NULL
                ORDER BY m.id DESC LIMIT " . (int) $limit);
            $q->execute([$ext]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /** "Kara Danvers (611)", or the bare extension when it has no name. */
    private static function label(string $ext, string $name): string
    {
        $name = trim($name);
        return $name !== '' ? "{$name} ({$ext})" : $ext;
    }

    /** Through the chat service itself, so the message reaches open chats and phones at once. */
    private static function sendChat(array $user, string $toExt, string $body): void
    {
        require_once self::webDir() . '/api/mobile/auth_helper.php';
        $token = generateMobileToken($user, 120);
        $conv = self::chatCall('POST', '/api/conversations/direct', $token, ['target_extension' => $toExt]);
        $convId = (int) ($conv['conversation']['id'] ?? 0);
        if ($convId <= 0) {
            throw new \InvalidArgumentException('chat: ' . (string) ($conv['error'] ?? 'could not open the conversation'));
        }
        $sent = self::chatCall('POST', '/api/messages', $token, ['conversation_id' => $convId, 'msg_type' => 'text', 'message' => $body]);
        if (empty($sent['success'])) {
            throw new \InvalidArgumentException('chat: ' . (string) ($sent['error'] ?? 'not sent'));
        }
    }

    private static function chatCall(string $method, string $path, string $token, array $body): array
    {
        $port = (string) (function_exists('portalEnv') ? portalEnv('CHAT_PORT', '8086') : '8086');
        if (!ctype_digit($port)) {
            $port = '8086';
        }
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => (string) json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROXY => '',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!is_string($resp) || $resp === '') {
            throw new \InvalidArgumentException('chat service not reachable');
        }
        $json = json_decode($resp, true);
        return is_array($json) ? $json : [];
    }

    private static function webDir(): string
    {
        return dirname((string) (new \ReflectionFunction('getDB'))->getFileName());
    }

    // --- SMS ---

    private static function smsLoaded(): bool
    {
        if (!class_exists('PluginManager') || !PluginManager::isEnabled('sms')) {
            return false;
        }
        $lib = dirname(__DIR__, 2) . '/sms/lib/Sms.php';
        if (!class_exists('Sms') && is_file($lib)) {
            require_once $lib;
        }
        return class_exists('Sms');
    }

    private static function smsMessages(int $userId, int $limit): array
    {
        $q = getDB()->prepare("SELECT m.id, m.peer, m.direction, m.body, m.created_at, n.number, c.name AS contact
            FROM plg_sms_messages m
            JOIN plg_sms_numbers n ON n.id = m.number_id
            JOIN plg_sms_access a ON a.number_id = m.number_id AND a.user_id = ?
            LEFT JOIN plg_sms_contacts c ON c.number_id = m.number_id AND c.phone = m.peer
            ORDER BY m.id DESC LIMIT " . (int) $limit);
        $q->execute([$userId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
