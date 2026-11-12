<?php
/**
 * /plugins/billing below the settings: Stripe status and webhook address, plans, users (plan,
 * usage this month, exceptions), and the Store (numbers for sale, orders to approve).
 * Available: $plugin, $settings, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/Store.php';
Billing::setSettings($settings);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$mode = Billing::mode();
$plans = Billing::plans();
$planName = array_column($plans, 'name', 'id');
$limit = static function (int $v, string $unit = ''): string {
    return $v === Billing::UNLIMITED ? 'Unlimited' : ($v === 0 ? 'None' : number_format($v) . $unit);
};
$users = getDB()->query("SELECT u.id, u.username, u.full_name, u.extension, u.role, COALESCE(u.is_guest, 0) AS is_guest,
        c.status, c.plan_id, c.current_period_end, c.cancel_at_period_end, c.stripe_customer_id, COALESCE(c.guest_may_upgrade, 0) AS guest_may_upgrade
    FROM sys_users u LEFT JOIN plg_billing_customers c ON c.user_id = u.id
    WHERE COALESCE(u.extension_type, 'sip') <> 'fax' ORDER BY u.full_name, u.username")->fetchAll(PDO::FETCH_ASSOC);
$overrides = [];
foreach (getDB()->query('SELECT user_id, feature, value FROM plg_billing_overrides')->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $overrides[(int) $o['user_id']][$o['feature']] = (int) $o['value'];
}
?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-plug"></i> Stripe</div></div>
    <div class="u-p-16">
        <p>
            Mode: <?php echo $mode === 'live' ? '<span class="badge badge-danger">LIVE — real payments</span>' : ($mode === 'test' ? '<span class="badge badge-warning">Test</span>' : '<span class="badge badge-secondary">No secret key yet</span>'); ?>
            &nbsp; Limits: <?php echo Billing::enforcing() ? '<span class="badge badge-success">enforced</span>' : '<span class="badge badge-secondary">counted only (not enforced)</span>'; ?>
        </p>
        <p class="u-hint">Billing is <strong>not tested with real payments</strong>: start with a Stripe <em>test</em> key (sk_test_…) and test cards until you have tried every step yourself.</p>
        <ol class="u-hint">
            <li>In Stripe → Developers → Webhooks, add the endpoint <code><?php echo $h(Billing::baseUrl() . '/api/plugins/billing/webhook'); ?></code> with the events
                <code>checkout.session.completed</code>, <code>customer.subscription.created</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code>,
                <code>invoice.paid</code>, <code>invoice.payment_failed</code>. Paste its signing secret (whsec_…) above.</li>
            <li>In Stripe → Settings → Billing → Customer portal, turn the portal on (users manage their card there).</li>
            <li>Create plans below and publish them. Users subscribe under <strong>Billing</strong> in their menu.</li>
            <li>Watch usage below, then turn on <strong>Enforce limits</strong> above. After enabling or disabling the plugin or changing that setting, apply the pending changes.</li>
        </ol>
        <?php if (BillingStore::open()): ?>
            <p>Store page: <a href="<?php echo $h(BillingStore::url()); ?>" target="_blank" rel="noopener"><code><?php echo $h(BillingStore::url()); ?></code></a></p>
        <?php endif; ?>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-layer-group"></i> Plans</div></div>
    <div class="u-p-16">
        <p class="u-hint">-1 = unlimited, 0 = none. Changing a price makes a new Stripe price when you publish; current subscribers keep the old price until they switch. Limits apply to subscribers at once.</p>
        <?php echo uiTableOpen(['Plan', 'Price / month', 'Minutes', 'Texts', 'Fax', 'Store', 'Stripe', ''], ['search' => false]); ?>
        <?php if ($plans === []): echo uiTableEmptyRow(8, 'No plans yet.'); endif; ?>
        <?php foreach ($plans as $p): ?>
            <tr>
                <td><strong><?php echo $h($p['name']); ?></strong><?php echo empty($p['is_active']) ? ' <span class="badge badge-secondary">hidden</span>' : ''; ?><div class="u-hint"><?php echo $h($p['description']); ?></div></td>
                <td><?php echo $h(Billing::money((int) $p['price_cents'], (string) $p['currency'])); ?></td>
                <td><?php echo $h($limit((int) $p['call_minutes'])); ?></td>
                <td><?php echo $h($limit((int) $p['messages'])); ?></td>
                <td><?php echo empty($p['fax']) ? 'No' : $h($limit((int) $p['faxes'], ' / mo')); ?></td>
                <td><?php echo empty($p['in_store']) ? 'No' : 'Yes'; ?></td>
                <td><?php echo $p['stripe_price_id'] !== '' && $p['stripe_mode'] === $mode ? '<span class="badge badge-success">published</span>' : '<span class="badge badge-secondary">not published</span>'; ?></td>
                <td class="u-nowrap">
                    <?php if ($mode !== ''): ?>
                    <form method="post" class="u-d-inline"><?php echo $csrf; ?><input type="hidden" name="billing_action" value="publish_plan"><input type="hidden" name="plan_id" value="<?php echo (int) $p['id']; ?>">
                        <button class="btn btn-secondary btn-sm" type="submit"><i class="fas fa-cloud-upload-alt"></i> Publish</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>

        <h4 class="u-mt-20">Add or change a plan</h4>
        <form method="post" class="u-mt-10">
            <?php echo $csrf; ?><input type="hidden" name="billing_action" value="save_plan">
            <div class="u-grid-2">
                <div class="form-group"><label class="form-label">Plan to change</label>
                    <select name="plan_id" class="form-control"><option value="0">New plan</option>
                        <?php foreach ($plans as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo $h($p['name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Name</label><input type="text" name="name" class="form-control" maxlength="80" required></div>
                <div class="form-group"><label class="form-label">Description</label><input type="text" name="description" class="form-control" maxlength="255"></div>
                <div class="form-group"><label class="form-label">Price per month (<?php echo $h(strtoupper(Billing::currency())); ?>)</label><input type="number" name="price" class="form-control" min="0" step="0.01" value="0"></div>
                <div class="form-group"><label class="form-label">Outside call minutes / month</label><input type="number" name="call_minutes" class="form-control" min="-1" value="0"></div>
                <div class="form-group"><label class="form-label">Texts / month</label><input type="number" name="messages" class="form-control" min="-1" value="0"></div>
                <div class="form-group"><label class="form-label">Faxes sent / month</label><input type="number" name="faxes" class="form-control" min="-1" value="0"></div>
                <div class="form-group"><label class="form-label">Order</label><input type="number" name="sort_order" class="form-control" value="0"></div>
            </div>
            <label class="u-d-flex u-ai-center u-gap-8"><input type="checkbox" name="fax" value="1"> Fax included</label>
            <label class="u-d-flex u-ai-center u-gap-8"><input type="checkbox" name="is_active" value="1" checked> Offered to users</label>
            <label class="u-d-flex u-ai-center u-gap-8"><input type="checkbox" name="in_store" value="1" checked> Offered in the Store</label>
            <?php if ($mode !== ''): ?><label class="u-d-flex u-ai-center u-gap-8"><input type="checkbox" name="publish" value="1" checked> Publish to Stripe now</label><?php endif; ?>
            <p class="u-hint">Changing a plan: pick it, then fill in every field (they replace the old values).</p>
            <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save plan</button></div>
        </form>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-users"></i> Users this month</div></div>
    <div class="u-p-16">
        <p class="u-hint">Used / allowed this calendar month. An exception (set below) wins over the plan and the defaults; admins are never limited.</p>
        <?php echo uiTableOpen(['User', 'Plan', 'Minutes', 'Texts', 'Faxes', ''], ['search' => true]); ?>
        <?php foreach ($users as $u):
            $uid = (int) $u['id'];
            $active = in_array((string) $u['status'], Billing::ACTIVE, true);
            $cell = static function (string $f) use ($uid, $limit, $overrides): string {
                $used = Billing::usage($uid, $f);
                $q = Billing::quota($uid, $f);
                return number_format($used) . ' / ' . $limit($q) . (isset($overrides[$uid][$f]) ? ' <span class="badge badge-info" title="exception">exc.</span>' : '');
            };
        ?>
            <tr>
                <td><strong><?php echo $h($u['full_name'] ?: $u['username']); ?></strong> <?php echo $h($u['extension']); ?>
                    <?php echo $u['role'] === 'admin' ? ' <span class="badge badge-primary">admin</span>' : ''; ?>
                    <?php echo !empty($u['is_guest']) && $u['role'] !== 'admin' ? ' <span class="badge badge-warning">guest</span>' : ''; ?></td>
                <td><?php echo $active ? $h($planName[(int) $u['plan_id']] ?? '?') . ' <span class="u-hint">' . $h($u['status']) . ($u['cancel_at_period_end'] ? ', ends ' . date('Y-m-d', (int) $u['current_period_end']) : '') . '</span>' : '<span class="u-hint">no plan</span>'; ?></td>
                <td><?php echo $cell('call_minutes'); ?></td>
                <td><?php echo $cell('messages'); ?></td>
                <td><?php echo Billing::quota($uid, 'fax') === 1 ? $cell('faxes') : 'No fax'; ?></td>
                <td class="u-nowrap">
                    <?php if (!empty($u['is_guest']) && $u['role'] !== 'admin'): ?>
                        <form method="post" class="u-d-inline"><?php echo $csrf; ?><input type="hidden" name="billing_action" value="guest_upgrade"><input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                            <input type="hidden" name="may" value="<?php echo $u['guest_may_upgrade'] ? '0' : '1'; ?>">
                            <button class="btn btn-secondary btn-sm" type="submit" title="When allowed, buying a paid plan turns the guest into a normal account"><?php echo $u['guest_may_upgrade'] ? 'Guest may upgrade ✓' : 'Allow guest to upgrade'; ?></button></form>
                    <?php endif; ?>
                    <?php if ($u['stripe_customer_id'] !== null && $u['stripe_customer_id'] !== ''): ?>
                        <form method="post" class="u-d-inline"><?php echo $csrf; ?><input type="hidden" name="billing_action" value="sync_user"><input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                            <button class="btn btn-secondary btn-sm" type="submit"><i class="fas fa-sync"></i> Sync</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>

        <h4 class="u-mt-20">Exception for one user</h4>
        <form method="post" class="u-flex-gap u-mt-10">
            <?php echo $csrf; ?><input type="hidden" name="billing_action" value="override">
            <select name="user_id" class="form-control" required>
                <option value="" disabled selected>Pick a user (admins are never limited)</option>
                <?php foreach ($users as $u): if ($u['role'] === 'admin') { continue; } ?>
                    <option value="<?php echo (int) $u['id']; ?>"><?php echo $h(($u['full_name'] ?: $u['username']) . ' ' . $u['extension']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="feature" class="form-control">
                <?php foreach (Billing::FEATURES as $f => [$label, $kind]): ?><option value="<?php echo $h($f); ?>"><?php echo $h($label . ($kind === 'bool' ? ' (1 = on, 0 = off)' : '')); ?></option><?php endforeach; ?>
            </select>
            <input type="number" name="value" class="form-control" min="-1" placeholder="-1 unlimited · empty = remove">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-user-cog"></i> Save</button>
        </form>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-store"></i> Store</div></div>
    <div class="u-p-16">
        <p class="u-hint">Orders save the customer's card with Stripe. <strong>Nobody is charged and nothing is created until you approve.</strong> Approve charges the first month, creates the user on the next free extension of the Store range (activation e-mail), and routes the chosen number to it. Reject just closes the order.</p>
        <form method="post" class="u-mb-20">
            <?php echo $csrf; ?><input type="hidden" name="billing_action" value="store_numbers">
            <label class="form-label">Numbers offered (one per line: <code>+15551234567, Fresno</code>). A number is offered only while nothing else answers it.</label>
            <textarea name="numbers" class="form-control" rows="3" placeholder="+15551234567, Fresno&#10;+15557654321, Austin"><?php
                foreach (getDB()->query('SELECT number, label FROM plg_billing_store_numbers ORDER BY number')->fetchAll(PDO::FETCH_ASSOC) as $n) {
                    echo $h($n['number'] . ($n['label'] !== '' ? ', ' . $n['label'] : '')) . "\n";
                } ?></textarea>
            <button type="submit" class="btn btn-secondary btn-sm u-mt-10"><i class="fas fa-save"></i> Save numbers</button>
        </form>
        <?php $orders = BillingStore::orders(); ?>
        <?php echo uiTableOpen(['#', 'Customer', 'Plan', 'Number', 'Status', 'When', ''], ['search' => false]); ?>
        <?php if ($orders === []): echo uiTableEmptyRow(7, 'No orders yet.'); endif; ?>
        <?php foreach ($orders as $o): [$label, $cls] = BillingStore::STATUS[$o['status']] ?? [$o['status'], 'secondary']; ?>
            <tr>
                <td><?php echo (int) $o['id']; ?></td>
                <td><strong><?php echo $h($o['full_name']); ?></strong><div class="u-hint"><?php echo $h($o['email']); ?></div></td>
                <td><?php echo $h(($o['plan_name'] ?? '?') . ' · ' . Billing::money((int) ($o['price_cents'] ?? 0), (string) ($o['currency'] ?? ''))); ?></td>
                <td><?php echo $h($o['number'] ?: '—'); ?></td>
                <td><span class="badge badge-<?php echo $h($cls); ?>"><?php echo $h($label); ?></span><div class="u-hint"><?php echo $h($o['note']); ?></div></td>
                <td class="u-nowrap u-hint"><?php echo $h($o['created_at']); ?></td>
                <td class="u-nowrap">
                    <?php if ($o['status'] === 'awaiting'): ?>
                        <form method="post" class="u-d-inline" onsubmit="return confirm('Approve: charge the first month and create the account?');"><?php echo $csrf; ?>
                            <input type="hidden" name="billing_action" value="approve_order"><input type="hidden" name="order_id" value="<?php echo (int) $o['id']; ?>">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-check"></i> Approve</button></form>
                    <?php endif; ?>
                    <?php if (in_array($o['status'], ['awaiting', 'started'], true)): ?>
                        <form method="post" class="u-d-inline"><?php echo $csrf; ?>
                            <input type="hidden" name="billing_action" value="reject_order"><input type="hidden" name="order_id" value="<?php echo (int) $o['id']; ?>">
                            <input type="text" name="reason" class="form-control u-d-inline" placeholder="Reason (e-mailed, optional)" maxlength="255">
                            <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-times"></i> Reject</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>
