<?php
/**
 * /plugins/sms below the provider settings: the webhook address of every provider, and the numbers
 * with who reads them. Available: $plugin, $settings, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$providers = Sms::providers();
// The form starts on a provider that is set up (the first one listed would quietly move a number
// to a provider with no settings when the number is added again to change its users).
$defaultProvider = (string) array_key_first($providers);
foreach ($providers as $pid => $pclass) {
    if ((new $pclass($settings))->configured()) {
        $defaultProvider = (string) $pid;
        break;
    }
}
$numbers = Sms::numbers();
$users = getDB()->query("SELECT id, full_name, username, extension FROM sys_users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$userName = [];
foreach ($users as $u) {
    $userName[(int) $u['id']] = trim($u['full_name'] . ($u['extension'] !== '' ? ' (' . $u['extension'] . ')' : '')) ?: $u['username'];
}
?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-link"></i> Webhook addresses</div></div>
    <div class="u-p-16">
        <p class="u-hint">Give your provider the address of its name as the address for incoming messages. The last part is a secret: whoever knows it can post messages here.</p>
        <?php echo uiTableOpen(['Provider', 'Ready', 'Address for incoming messages', 'Where it goes'], ['search' => false]); ?>
        <?php foreach ($providers as $id => $class):
            $p = new $class($settings);
            $url = Sms::webhookUrl($id) . ($id === 'voipms' ? SmsVoipms::CALLBACK_QUERY : '');
            $where = [
                'bandwidth' => 'Messaging application → Callback URL (POST)',
                'bulkvs' => 'Messaging → Messaging Webhooks (HTTP POST)',
                'plivo' => 'Application → Message URL, method POST',
                'sinch' => 'Service plan → Callback URL',
                'telnyx' => 'Messaging profile → Inbound webhook URL',
                'twilio' => 'Phone number → Messaging → A message comes in (HTTP POST)',
                'vonage' => 'Application → Messages → Inbound URL (POST)',
                'voipms' => 'DID → SMS/MMS → SMS URL Callback, with the part after "?"',
            ][$id] ?? '';
        ?>
            <tr>
                <td><?php echo $h($class::label()); ?></td>
                <td><?php echo $p->configured() ? '<span class="badge badge-success">yes</span>' : '<span class="badge badge-secondary">no settings</span>'; ?></td>
                <td><code><?php echo $h($url); ?></code></td>
                <td class="u-hint"><?php echo $h($where); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
        <form method="post" class="u-mt-10" onsubmit="return confirm('The addresses above stop working until you give the new ones to your providers. Continue?');">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="sms_action" value="new_key">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-key"></i> Make a new secret key</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-hashtag"></i> Numbers</div></div>
    <div class="u-p-16">
        <p class="u-hint">A number takes messages only when it is listed here. Its users find them under Messages; administrators see every number.</p>
        <?php echo uiTableOpen(['Number', 'Provider', 'Name', 'Users', ''], ['search' => false]); ?>
        <?php if ($numbers === []): echo uiTableEmptyRow(5, 'No numbers yet.'); endif; ?>
        <?php foreach ($numbers as $n): ?>
            <tr>
                <td><?php echo $h($n['number']); ?></td>
                <td><?php echo $h(isset($providers[$n['provider']]) ? $providers[$n['provider']]::label() : $n['provider']); ?></td>
                <td><?php echo $h($n['label']); ?></td>
                <td><?php echo $h(implode(', ', array_map(static fn(int $id): string => $userName[$id] ?? '#' . $id, $n['user_ids']))); ?></td>
                <td class="u-nowrap">
                    <button type="button" class="btn btn-secondary btn-sm sms-edit-number" title="Edit"
                        data-number="<?php echo $h($n['number']); ?>" data-provider="<?php echo $h($n['provider']); ?>"
                        data-label="<?php echo $h($n['label']); ?>" data-users="<?php echo $h(implode(',', $n['user_ids'])); ?>"><i class="fas fa-pen"></i></button>
                    <form method="post" class="u-inline" onsubmit="return confirm('Delete this number and all its messages?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="sms_action" value="delete_number">
                        <input type="hidden" name="number_id" value="<?php echo (int) $n['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash-alt"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>

        <form method="post" class="u-mt-10" id="smsNumberForm">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="sms_action" value="save_number">
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label">Number (international form)</label>
                    <input type="text" name="number" class="form-control" placeholder="+15551234567" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Provider</label>
                    <select name="provider" class="form-control">
                        <?php foreach ($providers as $id => $class): ?><option value="<?php echo $h($id); ?>"<?php echo (string) $id === $defaultProvider ? ' selected' : ''; ?>><?php echo $h($class::label()); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Name (optional)</label>
                    <input type="text" name="label" class="form-control" maxlength="60" placeholder="Sales">
                </div>
                <div class="form-group">
                    <label class="form-label">Users who read and answer</label>
                    <select name="user_ids[]" class="form-control" multiple size="5">
                        <?php foreach ($userName as $id => $name): ?><option value="<?php echo $id; ?>"><?php echo $h($name); ?></option><?php endforeach; ?>
                    </select>
                    <small class="u-hint">To change a number, use its <i class="fas fa-pen"></i> button: the form fills in with its current provider, name and users.</small>
                </div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary" id="smsNumberSubmit"><i class="fas fa-plus"></i> Add number</button></div>
        </form>
        <script>
        // Edit: fill the form with the number's current values, so saving changes only what you change.
        document.querySelectorAll('.sms-edit-number').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const f = document.getElementById('smsNumberForm');
                f.elements['number'].value = btn.dataset.number;
                f.elements['provider'].value = btn.dataset.provider;
                f.elements['label'].value = btn.dataset.label;
                const users = btn.dataset.users ? btn.dataset.users.split(',') : [];
                Array.from(f.elements['user_ids[]'].options).forEach(function (o) { o.selected = users.indexOf(o.value) !== -1; });
                document.getElementById('smsNumberSubmit').innerHTML = '<i class="fas fa-save"></i> Save number';
                f.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });
        </script>
    </div>
</div>
