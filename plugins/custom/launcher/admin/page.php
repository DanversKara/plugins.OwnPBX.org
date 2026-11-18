<?php
/**
 * /plugins/launcher below the settings: the address and token a launcher needs, and what one
 * answer looks like. Available: $plugin, $settings, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/LauncherFeed.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$host = function_exists('portalEnv') ? trim((string) portalEnv('PORTAL_DOMAIN', '')) : '';
$base = 'https://' . ($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'your-pbx')) . '/api/plugins/launcher/events';
$token = LauncherFeed::token();
$watched = LauncherFeed::watched($settings);
$sample = json_encode([
    'ok' => true,
    'now' => time(),
    'latest_id' => 123456,
    'events' => [[
        'id' => 123456,
        'type' => 'voicemail',
        'title' => 'Voicemail',
        'who' => 'Kara Danvers',
        'number' => '15555550123',
        'to' => '611 Kara Danvers',
        'line' => 'pstn',
        'text' => 'Calling about the delivery on Friday, please ring back…',
        'at' => time(),
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-tv"></i> The address a launcher polls</div></div>
    <div class="u-p-16">
        <p>
            <?php if ($watched === []): ?>
                Everything on the PBX is reported.
            <?php else: ?>
                Only these extensions are reported: <strong><?php echo $h(implode(', ', $watched)); ?></strong>
            <?php endif; ?>
        </p>
        <div class="form-group">
            <label class="form-label">Address (header <code>X-Launcher-Token</code>, or <code>?token=</code> when a header is awkward)</label>
            <input type="text" class="form-control" readonly value="<?php echo $h($base . '?since=0&limit=20'); ?>" onclick="this.select()">
        </div>
        <div class="form-group">
            <label class="form-label">Token</label>
            <input type="text" class="form-control" readonly value="<?php echo $h($token); ?>" onclick="this.select()">
        </div>
        <p class="u-mt-10"><strong>How a launcher uses it:</strong> ask every few seconds, keep <code>latest_id</code> from the answer, and send it back as <code>since</code> next time. Only newer events come back, so nothing is shown twice. Read-only: nothing on the PBX can be changed through this address.</p>
        <pre class="u-p-16 u-overflow-x-auto u-bg-subtle"><?php echo $h($sample); ?></pre>
        <p class="u-hint">Test: <code>curl -H "X-Launcher-Token: …" "<?php echo $h($base . '?limit=5'); ?>"</code> answers <code>{"ok":true,...}</code>; a wrong token answers <code>bad token</code>. A call is reported while it rings, so a launcher asking every few seconds catches it before it is answered.</p>
        <form method="post" onsubmit="return confirm('Make a new token? Every launcher stops getting events until it has the new one.');">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="launcher_action" value="new_token">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-key"></i> New token</button>
        </form>
    </div>
</div>
