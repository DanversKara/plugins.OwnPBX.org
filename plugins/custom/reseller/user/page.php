<?php
/**
 * /apps/reseller: the signed-in user's own reseller record and status.
 * Available: $plugin, $settings, $user, $csrf_token.
 * v1 is read-only — reseller user management (adding logins under the tenant) comes later.
 */
require_once dirname(__DIR__) . '/lib/Reseller.php';
Reseller::setSettings($settings);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$reseller = Reseller::resellerForUser((int) ($user['id'] ?? 0));
$sh = $reseller !== null ? Reseller::stockHost((string) ($reseller['stock_subdomain'] ?? '')) : '';
?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-sitemap"></i> Your reseller</div></div>
    <div class="u-p-16">
        <?php if ($reseller === null): ?>
            <p>You do not belong to a reseller.</p>
        <?php else: ?>
            <p><strong><?php echo $h($reseller['display_name'] !== '' ? $reseller['display_name'] : $reseller['name']); ?></strong>
                <?php echo $reseller['status'] === 'active' ? '<span class="badge badge-success">active</span>' : '<span class="badge badge-warning">suspended</span>'; ?></p>
            <p><strong>Stock address:</strong> <?php echo $sh !== '' ? '<code>' . $h($sh) . '</code>' : '—'; ?></p>
            <p><strong>Custom domain:</strong> <?php echo $reseller['custom_domain'] !== '' && $reseller['custom_domain'] !== null ? '<code>' . $h($reseller['custom_domain']) . '</code>' : '—'; ?></p>
            <p><strong>Stripe:</strong> <?php echo $h($reseller['stripe_mode']); ?></p>
            <p><strong>Extension range:</strong> <?php echo $reseller['ext_from'] !== null && $reseller['ext_to'] !== null ? $h($reseller['ext_from'] . '–' . $reseller['ext_to']) : 'not set'; ?></p>
            <p class="u-hint">Reseller user management — adding logins under this reseller — comes in a
                later phase. Your calls, chat, SMS and fax work as normal; only your reseller's
                tenant can see your account.</p>
        <?php endif; ?>
    </div>
</div>
