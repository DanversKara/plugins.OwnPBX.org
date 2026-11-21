<?php
/**
 * /plugins/reseller: reseller accounts (tenants). Admin only — PluginsController::detail
 * already gates this page with requireRole('admin').
 * Available: $plugin, $settings, $csrf_token.
 * v1: reseller records + domains + Stripe mode/keys. No trunk routing, no DID purchases,
 * no fee accrual, no white-label rendering yet — those come in later phases.
 */
require_once dirname(__DIR__) . '/lib/Reseller.php';
Reseller::setSettings($settings);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$resellers = Reseller::all();
$feeLabel = static function (array $r): string {
    if ($r['fee_type'] === 'monthly') {
        return number_format((float) $r['fee_rate'], 2) . ' / month';
    }
    return rtrim(rtrim(number_format((float) $r['fee_rate'], 2), '0'), '.') . '% / transaction';
};
?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-sitemap"></i> Resellers</div></div>
    <div class="u-p-16">
        <p class="u-hint">v1 is the foundation: reseller records, tenant identity, and host-to-tenant
            resolution. A reseller's users belong to them through the reseller id on their user row;
            the Users and Extensions lists already show a reseller only their own tenant.
            Trunk routing, dialplan islands, DID purchases, Stripe payments and white-label
            domains are later phases — nothing here charges anyone yet.</p>
        <?php echo uiTableOpen(['Reseller', 'Status', 'Stock address', 'Custom domain', 'Stripe', 'Platform fee', 'Extensions', 'Users', ''], ['search' => true]); ?>
        <?php if ($resellers === []): echo uiTableEmptyRow(9, 'No resellers yet.'); endif; ?>
        <?php foreach ($resellers as $r): $rid = (int) $r['id']; ?>
            <tr>
                <td><strong><?php echo $h($r['display_name'] !== '' ? $r['display_name'] : $r['name']); ?></strong>
                    <?php if ($r['display_name'] !== '' && $r['display_name'] !== $r['name']): ?><div class="u-hint"><?php echo $h($r['name']); ?></div><?php endif; ?></td>
                <td><?php echo $r['status'] === 'active' ? '<span class="badge badge-success">active</span>' : '<span class="badge badge-warning">suspended</span>'; ?></td>
                <td><?php $sh = Reseller::stockHostForRow($r); echo $sh !== '' ? '<code>' . $h($sh) . '</code>' : '<span class="u-hint">—</span>'; ?></td>
                <td><?php echo $r['custom_domain'] !== '' && $r['custom_domain'] !== null ? '<code>' . $h($r['custom_domain']) . '</code>' : '<span class="u-hint">—</span>'; ?></td>
                <td><?php echo $h($r['stripe_mode']); ?></td>
                <td><?php echo $h($feeLabel($r)); ?></td>
                <td><?php echo $r['ext_from'] !== null && $r['ext_to'] !== null ? $h($r['ext_from'] . '–' . $r['ext_to']) : '<span class="u-hint">not set</span>'; ?></td>
                <td><?php echo (int) Reseller::userCount($rid); ?></td>
                <td class="u-nowrap">
                    <form method="post" class="u-d-inline"><?php echo $csrf; ?><input type="hidden" name="reseller_action" value="set_status"><input type="hidden" name="reseller_id" value="<?php echo $rid; ?>">
                        <input type="hidden" name="status" value="<?php echo $r['status'] === 'active' ? 'suspended' : 'active'; ?>">
                        <button class="btn btn-secondary btn-sm" type="submit"><?php echo $r['status'] === 'active' ? 'Suspend' : 'Activate'; ?></button></form>
                    <form method="post" class="u-d-inline" onsubmit="return confirm('Delete this reseller? Their Stripe keys, domains and fee rows go with it. Users must be moved or deleted first.');"><?php echo $csrf; ?><input type="hidden" name="reseller_action" value="delete_reseller"><input type="hidden" name="reseller_id" value="<?php echo $rid; ?>">
                        <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-trash"></i> Delete</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>

        <h4 class="u-mt-20">Add or change a reseller</h4>
        <form method="post" class="u-mt-10">
            <?php echo $csrf; ?><input type="hidden" name="reseller_action" value="save_reseller">
            <div class="u-grid-2">
                <div class="form-group"><label class="form-label">Reseller to change</label>
                    <select name="reseller_id" id="reseller_to_change" class="form-control"><option value="0">New reseller</option>
                        <?php foreach ($resellers as $r): ?><option value="<?php echo (int) $r['id']; ?>" data-base="<?php echo $h(Reseller::stockBaseForRow($r)); ?>"><?php echo $h($r['name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Name</label><input type="text" name="name" class="form-control" maxlength="80" required></div>
                <div class="form-group"><label class="form-label">Display name (brand, optional)</label><input type="text" name="display_name" class="form-control" maxlength="80"></div>
                <div class="form-group"><label class="form-label">Status</label>
                    <select name="status" class="form-control"><option value="active">active</option><option value="suspended">suspended</option></select></div>
                <div class="form-group"><label class="form-label">Stock subdomain (e.g. acme — login at acme.&lt;base domain&gt;)</label><input type="text" name="stock_subdomain" class="form-control" maxlength="63"></div>
                <div class="form-group"><label class="form-label">Stock base domain (login at &lt;subdomain&gt;.&lt;base&gt;)</label>
                    <select name="stock_base_domain" id="reseller_stock_base" class="form-control">
                        <?php foreach (Reseller::stockBaseDomains() as $bd): ?><option value="<?php echo $h($bd); ?>"><?php echo $h($bd); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label class="form-label">Custom domain (their own domain, optional — paid extra)</label><input type="text" name="custom_domain" class="form-control" maxlength="253"></div>
                <div class="form-group"><label class="form-label">Brand colour (#rrggbb, optional)</label><input type="text" name="primary_color" class="form-control" maxlength="7" placeholder="#0ea5e9"></div>
                <div class="form-group"><label class="form-label">Logo path (optional, stored for white-label later)</label><input type="text" name="logo_path" class="form-control" maxlength="255"></div>
                <div class="form-group"><label class="form-label">Stripe mode</label>
                    <select name="stripe_mode" class="form-control"><option value="platform">PBX Stripe (we take a cut)</option><option value="own">Reseller's own Stripe keys</option></select></div>
                <div class="form-group"><label class="form-label">Platform fee type</label>
                    <select name="fee_type" class="form-control"><option value="percent">% of each transaction</option><option value="monthly">Flat monthly fee</option></select></div>
                <div class="form-group"><label class="form-label">Platform fee rate (% 0–100, or monthly amount)</label><input type="number" name="fee_rate" class="form-control" min="0" step="0.01" value="0"></div>
                <div class="form-group"><label class="form-label">Extension range from</label><input type="number" name="ext_from" class="form-control" min="0"></div>
                <div class="form-group"><label class="form-label">Extension range to</label><input type="number" name="ext_to" class="form-control" min="0"></div>
            </div>
            <p class="u-hint">Changing a reseller: pick it, then fill in every field (they replace the old values).
                The stock base follows the reseller you pick; a base that left the offered list still
                shows as that reseller's current value. Extension ranges must not overlap another
                reseller's range. A suspended reseller's domains stop resolving to them.</p>
            <script>
                // The stock base follows the reseller being changed, so editing one
                // shows its stored base selected — even when that base is no longer offered.
                (function () {
                    var pick = document.getElementById('reseller_to_change');
                    var base = document.getElementById('reseller_stock_base');
                    if (!pick || !base) return;
                    function syncBase() {
                        var current = base.querySelectorAll('option[data-current]');
                        for (var k = 0; k < current.length; k++) current[k].remove();
                        var opt = pick.options[pick.selectedIndex];
                        var b = opt ? opt.getAttribute('data-base') : '';
                        if (!b) return;
                        for (var i = 0; i < base.options.length; i++) {
                            if (base.options[i].value === b) { base.selectedIndex = i; return; }
                        }
                        var o = document.createElement('option');
                        o.value = b; o.textContent = b + ' (current)';
                        o.setAttribute('data-current', '1'); o.selected = true;
                        base.appendChild(o);
                    }
                    pick.addEventListener('change', syncBase);
                    syncBase();
                })();
            </script>
            <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save reseller</button></div>
        </form>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-credit-card"></i> Reseller Stripe keys</div></div>
    <div class="u-p-16">
        <p class="u-hint">Only for resellers on <strong>own</strong> Stripe mode. Keys are encrypted
            before they are stored and are never shown back. An empty field keeps the stored key.
            Resellers on platform mode use the PBX Stripe and pay the platform fee above.</p>
        <form method="post" class="u-mt-10">
            <?php echo $csrf; ?><input type="hidden" name="reseller_action" value="save_stripe_keys">
            <div class="u-grid-2">
                <div class="form-group"><label class="form-label">Reseller</label>
                    <select name="reseller_id" class="form-control" required>
                        <option value="" disabled selected>Pick a reseller</option>
                        <?php foreach ($resellers as $r): ?><option value="<?php echo (int) $r['id']; ?>"><?php echo $h($r['name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Stripe mode</label>
                    <select name="stripe_mode" class="form-control"><option value="own">Reseller's own Stripe keys</option><option value="platform">PBX Stripe</option></select></div>
                <div class="form-group"><label class="form-label">Stripe secret key (sk_test_… / sk_live_…)</label><input type="password" name="stripe_secret_key" class="form-control" autocomplete="off"></div>
                <div class="form-group"><label class="form-label">Stripe webhook signing secret (whsec_…)</label><input type="password" name="stripe_webhook_secret" class="form-control" autocomplete="off"></div>
            </div>
            <p class="u-hint">Start with a Stripe <em>test</em> key (sk_test_…) until the payment flow
                is built and tried in a later phase — v1 stores the keys only; nothing charges anyone yet.</p>
            <div class="form-actions"><button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save Stripe keys</button></div>
        </form>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-globe"></i> Domains</div></div>
    <div class="u-p-16">
        <p class="u-hint">Domains that resolve to a reseller. A custom domain is added when it is
            saved on the reseller above; its certificate state is <em>pending</em> until the
            certificate step lands in a later phase — a domain that never validates must fail
            closed and never take logins.</p>
        <?php $domains = getDB()->query('SELECT d.domain, d.cert_status, r.name FROM plg_reseller_domains d JOIN plg_resellers r ON r.id = d.reseller_id ORDER BY d.domain')->fetchAll(PDO::FETCH_ASSOC); ?>
        <?php echo uiTableOpen(['Domain', 'Reseller', 'Certificate'], ['search' => false]); ?>
        <?php if ($domains === []): echo uiTableEmptyRow(3, 'No custom domains yet.'); endif; ?>
        <?php foreach ($domains as $d): ?>
            <tr>
                <td><code><?php echo $h($d['domain']); ?></code></td>
                <td><?php echo $h($d['name']); ?></td>
                <td><?php echo $h($d['cert_status']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>
