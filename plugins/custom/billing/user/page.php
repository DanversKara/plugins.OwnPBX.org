<?php
/**
 * /apps/billing: the user's plan, what they used this month, and the plans they can pick.
 * Available: $slug, $settings, $user, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/Billing.php';
Billing::setSettings($settings);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$uid = (int) ($user['id'] ?? 0);
if (!empty($_GET['done'])) {
    try {
        Billing::syncUser($uid); // the webhook may still be on its way
    } catch (\Throwable $e) {
        error_log('billing: sync after checkout: ' . $e->getMessage());
    }
}
$cust = Billing::customer($uid) ?? [];
$current = Billing::activePlanOf($uid);
$plans = array_values(array_filter(Billing::plans(true), static fn(array $p): bool => $p['stripe_price_id'] !== '' && $p['stripe_mode'] === Billing::mode()));
$blocked = Billing::guestUpgradeBlocked($uid);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$used = static function (string $f) use ($uid): string {
    $q = Billing::quota($uid, $f);
    $u = Billing::usage($uid, $f);
    return number_format($u) . ' of ' . ($q === Billing::UNLIMITED ? 'unlimited' : number_format(max(0, $q)));
};

echo uiPageHeader('fas fa-credit-card', 'Billing');
?>
<?php if (!empty($_GET['done'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Thank you! If your new plan does not show yet, refresh in a moment.</div>
<?php endif; ?>
<div class="u-grid-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-id-card"></i> Your plan</div></div>
        <div class="u-p-16">
            <?php if ($current !== null): ?>
                <h3 class="u-mt-0"><?php echo $h($current['name']); ?> <span class="u-hint"><?php echo $h(Billing::money((int) $current['price_cents'], (string) $current['currency'])); ?> / month</span></h3>
                <p><?php echo !empty($cust['cancel_at_period_end'])
                    ? 'Ends on ' . $h(date('Y-m-d', (int) $cust['current_period_end'])) . '.'
                    : 'Renews on ' . $h(date('Y-m-d', (int) $cust['current_period_end'])) . '.'; ?>
                    <?php echo ($cust['status'] ?? '') === 'past_due' ? '<span class="badge badge-danger">Payment failed — update your card</span>' : ''; ?></p>
                <div class="u-flex-gap">
                    <form method="post"><?php echo $csrf; ?><input type="hidden" name="billing_action" value="portal"><button class="btn btn-secondary" type="submit"><i class="fas fa-credit-card"></i> Card &amp; invoices</button></form>
                    <form method="post"><?php echo $csrf; ?>
                        <input type="hidden" name="billing_action" value="<?php echo !empty($cust['cancel_at_period_end']) ? 'resume' : 'cancel'; ?>">
                        <button class="btn btn-secondary" type="submit"><?php echo !empty($cust['cancel_at_period_end']) ? 'Keep my plan' : 'Cancel at end of month'; ?></button></form>
                </div>
            <?php else: ?>
                <p>You have no plan.</p>
            <?php endif; ?>
            <h4 class="u-mt-20">This month</h4>
            <ul>
                <li>Outside call minutes: <?php echo $h($used('call_minutes')); ?></li>
                <li>Texts: <?php echo $h($used('messages')); ?></li>
                <li>Fax: <?php echo Billing::quota($uid, 'fax') === 1 ? $h($used('faxes')) . ' sent' : 'not included'; ?></li>
            </ul>
            <?php if (!Billing::enforcing()): ?><p class="u-hint">Limits are not enforced on this system yet.</p><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-layer-group"></i> Plans</div></div>
        <div class="u-p-16">
            <?php if ($blocked): ?>
                <p class="alert alert-warning">Your administrator doesn't allow this account to change plans without contacting support first.</p>
            <?php endif; ?>
            <?php if ($plans === []): ?><p class="u-hint">No plans are offered right now.</p><?php endif; ?>
            <?php foreach ($plans as $p): $isCurrent = $current !== null && (int) $current['id'] === (int) $p['id']; ?>
                <div class="u-mb-20">
                    <strong><?php echo $h($p['name']); ?></strong> — <?php echo $h(Billing::money((int) $p['price_cents'], (string) $p['currency'])); ?> / month
                    <?php if ($p['description'] !== ''): ?><div class="u-hint"><?php echo $h($p['description']); ?></div><?php endif; ?>
                    <ul><?php foreach (Billing::describe($p) as $f): ?><li><?php echo $h($f); ?></li><?php endforeach; ?></ul>
                    <?php if ($isCurrent): ?>
                        <span class="badge badge-success">Your plan</span>
                    <?php elseif (!$blocked): ?>
                        <form method="post"><?php echo $csrf; ?>
                            <input type="hidden" name="billing_action" value="<?php echo $current !== null ? 'switch' : 'subscribe'; ?>">
                            <input type="hidden" name="plan_id" value="<?php echo (int) $p['id']; ?>">
                            <button class="btn btn-primary btn-sm" type="submit"><?php echo $current !== null ? 'Switch to this plan' : 'Subscribe'; ?></button></form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
