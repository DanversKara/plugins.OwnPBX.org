<?php
/**
 * /plugins/invites below the settings: make an invite (link or e-mail; one person or reusable;
 * normal user or guest) and the list of invites with copy, send again, withdraw and delete.
 * Available: $plugin, $settings, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/Invites.php';
Invites::setSettings($settings);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$invites = Invites::all();
$newId = (int) ($_SESSION['invites_new'] ?? 0);
unset($_SESSION['invites_new']);
$days = max(1, min(90, (int) ($settings['default_days'] ?? 7)));
$badge = ['open' => 'badge-success', 'used' => 'badge-secondary', 'expired' => 'badge-warning', 'withdrawn' => 'badge-danger'];
?>
<div class="card u-mb-20">
    <div class="card-header"><div class="card-title"><i class="fas fa-user-plus"></i> New invite</div></div>
    <div class="u-p-16">
        <form method="post" id="inviteForm">
            <?php echo $csrf; ?>
            <input type="hidden" name="invite_action" value="create">
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label">Who is it for? <span class="u-muted">(name or note, optional)</span></label>
                    <input type="text" name="label" class="form-control" maxlength="80" placeholder="Mom, Front desk, Team">
                </div>
                <div class="form-group">
                    <label class="form-label">E-mail it to <span class="u-muted">(optional: empty = just a link to copy)</span></label>
                    <input type="email" name="email" id="inviteEmail" class="form-control" maxlength="120" placeholder="name@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Link for</label>
                    <select name="kind" id="inviteKind" class="form-control">
                        <option value="one">One person (works once)</option>
                        <option value="many">Several people (reusable)</option>
                    </select>
                </div>
                <div class="form-group" id="inviteMaxWrap" style="display:none">
                    <label class="form-label">Up to how many people</label>
                    <input type="number" name="max_uses" class="form-control" min="2" max="500" value="10">
                </div>
                <div class="form-group">
                    <label class="form-label">Valid for (days)</label>
                    <input type="number" name="days" class="form-control" min="1" max="90" value="<?php echo $days; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Account</label>
                    <select name="is_guest" id="inviteGuest" class="form-control">
                        <option value="0">Normal user</option>
                        <option value="1">Guest (locked down)</option>
                    </select>
                </div>
            </div>
            <div id="inviteGuestRules" style="display:none" class="u-mb-20">
                <p class="u-mb-0"><strong>The guest may:</strong> <span class="u-muted">(everything unticked stays blocked; emergency numbers are always blocked for guests)</span></p>
                <div class="u-flex-gap u-wrap u-mt-10">
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="guest_outbound" value="1"> Call outside numbers</label>
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="guest_inbound" value="1"> Take outside callers</label>
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="guest_sms" value="1"> Text (SMS)</label>
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="guest_fax" value="1"> Fax</label>
                </div>
                <p class="u-mb-0 u-mt-10"><strong>Phones the guest may use:</strong></p>
                <div class="u-flex-gap u-wrap u-mt-10">
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="phone_modes[]" value="web" checked> Web phone</label>
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="phone_modes[]" value="mobil" checked> Mobile app</label>
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="phone_modes[]" value="sip"> Desk phone (SIP)</label>
                    <label class="u-d-inline-flex u-ai-center u-gap-4"><input type="checkbox" name="phone_modes[]" value="video"> Video</label>
                </div>
            </div>
            <p class="u-hint">The person opens the link, chooses a user name and password, and gets the next free extension (<?php echo (int) ($settings['ext_from'] ?? 3000); ?>–<?php echo (int) ($settings['ext_to'] ?? 3999); ?>, change it above). It works right away.</p>
            <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Make invite</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-list"></i> Invites</div></div>
    <div class="u-p-16">
        <?php echo uiTableOpen(['For', 'Account', 'Used', 'Valid until', 'Status', 'Link', ''], ['search' => false]); ?>
        <?php if ($invites === []): echo uiTableEmptyRow(7, 'No invites yet.'); endif; ?>
        <?php foreach ($invites as $i): $open = $i['status'] === 'open'; ?>
            <tr<?php echo (int) $i['id'] === $newId ? ' style="background: var(--success-soft, #e7f8ef)"' : ''; ?>>
                <td><?php echo $h($i['label'] !== '' ? $i['label'] : '—'); ?><?php if ($i['email'] !== ''): ?><div class="u-muted"><?php echo $h($i['email']); ?></div><?php endif; ?></td>
                <td><?php echo $h(Invites::accountText($i)); ?></td>
                <td><?php echo (int) $i['uses']; ?> / <?php echo (int) $i['max_uses']; ?><?php if ($i['joined'] !== []): ?><div class="u-muted"><?php echo $h(implode(', ', $i['joined'])); ?></div><?php endif; ?></td>
                <td class="u-nowrap"><?php echo $h(date('M j, Y H:i', (int) strtotime((string) $i['expires_at']))); ?></td>
                <td><span class="badge <?php echo $badge[$i['status']] ?? 'badge-secondary'; ?>"><?php echo $h($i['status']); ?></span></td>
                <td>
                    <?php if ($open): ?>
                        <div class="u-d-flex u-gap-4">
                            <input type="text" class="form-control form-control-sm" readonly value="<?php echo $h($i['url']); ?>" onclick="this.select()" style="min-width: 220px">
                            <button type="button" class="btn btn-secondary btn-sm invite-copy" data-url="<?php echo $h($i['url']); ?>" title="Copy link"><i class="fas fa-copy"></i></button>
                        </div>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="u-nowrap">
                    <?php if ($open && $i['email'] !== ''): ?>
                        <form method="post" class="u-inline"><?php echo $csrf; ?><input type="hidden" name="invite_action" value="resend"><input type="hidden" name="invite_id" value="<?php echo (int) $i['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" title="E-mail again"><i class="fas fa-envelope"></i></button></form>
                    <?php endif; ?>
                    <?php if ($open): ?>
                        <form method="post" class="u-inline" onsubmit="return confirm('Withdraw this invite? The link stops working.');"><?php echo $csrf; ?><input type="hidden" name="invite_action" value="revoke"><input type="hidden" name="invite_id" value="<?php echo (int) $i['id']; ?>">
                            <button type="submit" class="btn btn-warning btn-sm" title="Withdraw"><i class="fas fa-ban"></i></button></form>
                    <?php endif; ?>
                    <form method="post" class="u-inline" onsubmit="return confirm('Delete this invite from the list? Accounts made with it stay.');"><?php echo $csrf; ?><input type="hidden" name="invite_action" value="delete"><input type="hidden" name="invite_id" value="<?php echo (int) $i['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash-alt"></i></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>
<script>
(function () {
    const kind = document.getElementById('inviteKind'), maxWrap = document.getElementById('inviteMaxWrap');
    const email = document.getElementById('inviteEmail');
    const guest = document.getElementById('inviteGuest'), rules = document.getElementById('inviteGuestRules');
    const sync = function () {
        const many = kind.value === 'many';
        maxWrap.style.display = many ? '' : 'none';
        // A reusable link is shared, not e-mailed to one person.
        email.disabled = many;
        if (many) { email.value = ''; }
        rules.style.display = guest.value === '1' ? '' : 'none';
    };
    kind.addEventListener('change', sync);
    guest.addEventListener('change', sync);
    sync();
    document.querySelectorAll('.invite-copy').forEach(function (b) {
        b.addEventListener('click', function () {
            const done = function () { b.innerHTML = '<i class="fas fa-check"></i>'; setTimeout(function () { b.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500); };
            if (navigator.clipboard) { navigator.clipboard.writeText(b.dataset.url).then(done, function () { window.prompt('Copy the link:', b.dataset.url); }); }
            else { window.prompt('Copy the link:', b.dataset.url); }
        });
    });
})();
</script>
