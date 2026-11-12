<?php
/**
 * /plugins/ha-texts below the settings: the token, the two addresses, and the Home Assistant
 * lines to change in ha_pbx_messages.yaml. Available: $plugin, $settings, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/HaTexts.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$host = function_exists('portalEnv') ? trim((string) portalEnv('PORTAL_DOMAIN', '')) : '';
$base = 'https://' . ($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'your-pbx')) . '/api/plugins/ha-texts/messages';
$token = HaTexts::token();
$exts = HaTexts::allowed($settings);
$ext = $exts[0] ?? '<box-ext>';
$yaml = "rest_command:\n  pbx_send_text:\n    url: \"{$base}/send\"\n    headers:\n      X-Msg-Token: \"{$token}\"\n\n"
    . "sensor:\n  - platform: rest\n    resource: \"{$base}/inbox?ext={$ext}&limit=20\"\n    headers:\n      X-Msg-Token: \"{$token}\"";
?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-house-signal"></i> Home Assistant</div></div>
    <div class="u-p-16">
        <?php if ($exts === []): ?>
            <div class="alert alert-warning">No extension is allowed yet: enter the panel's extension (e.g. 8803) under <strong>Extensions that may use it</strong> above and save.</div>
        <?php else: ?>
            <p>Allowed: <strong><?php echo $h(implode(', ', $exts)); ?></strong></p>
        <?php endif; ?>
        <div class="form-group">
            <label class="form-label">Token (header <code>X-Msg-Token</code>)</label>
            <input type="text" class="form-control" readonly value="<?php echo $h($token); ?>" onclick="this.select()">
        </div>
        <div class="form-group">
            <label class="form-label">Inbox</label>
            <input type="text" class="form-control" readonly value="<?php echo $h($base . '/inbox?ext=' . $ext . '&limit=20'); ?>" onclick="this.select()">
        </div>
        <div class="form-group">
            <label class="form-label">Send (POST, JSON <code>{"to", "from", "body"}</code>)</label>
            <input type="text" class="form-control" readonly value="<?php echo $h($base . '/send'); ?>" onclick="this.select()">
        </div>
        <p class="u-mt-10"><strong>In Home Assistant</strong> (<code>/config/packages/ha_pbx_messages.yaml</code>), change only these lines, keep the rest, and restart Home Assistant. <code>verify_ssl: false</code> can stay.</p>
        <pre class="u-p-16" style="background: var(--bg-subtle); border-radius: var(--radius-sm); overflow-x: auto;"><?php echo $h($yaml); ?></pre>
        <p class="u-hint">The box's inbox shows its Chat messages with other extensions and the SMS of the numbers its user has (SMS → Users who read and answer). Texting an extension goes through Chat; texting a phone number goes out as SMS from the user's first number. Test: <code>curl -H "X-Msg-Token: …" "<?php echo $h($base . '/inbox?ext=' . $ext . '&limit=5'); ?>"</code> answers <code>{"ok":true,...}</code>; a wrong token answers <code>bad token</code>.</p>
        <form method="post" onsubmit="return confirm('Make a new token? Home Assistant stops getting texts until it has the new one.');">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="ha_action" value="new_token">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-key"></i> New token</button>
        </form>
    </div>
</div>
