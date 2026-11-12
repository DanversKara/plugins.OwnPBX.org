<?php
/**
 * /apps/sms: the conversations of the user's numbers, one opened, and a reply. ?n=<number id>&peer=<+number>
 * Available: $slug, $settings, $user, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$numbers = [];
foreach (Sms::numbersFor($user) as $n) {
    $numbers[(int) $n['id']] = $n;
}
$nid = (int) ($_GET['n'] ?? array_key_first($numbers));
$number = $numbers[$nid] ?? reset($numbers);
$nid = (int) $number['id'];
$peer = SmsProvider::e164((string) ($_GET['peer'] ?? ''));
$threads = Sms::threads([$nid]);
$messages = $peer !== '' ? Sms::thread($nid, $peer) : [];
$link = static fn(int $n, string $p = ''): string => '/apps/sms?' . http_build_query(array_filter(['n' => $n, 'peer' => $p]));
$numberName = static fn(array $n): string => $n['number'] . ($n['label'] !== '' ? ' · ' . $n['label'] : '');
$contacts = Sms::contacts($nid);
$unread = Sms::unreadByNumber(array_keys($numbers));

echo uiPageHeader('fas fa-comment-sms', 'Messages');
?>
<style>
/* The plugin's own few rules; colours come from the portal's theme, so dark mode follows. */
.sms-msgs { display: flex; flex-direction: column; gap: 8px; max-height: 60vh; overflow-y: auto; margin-bottom: 12px; }
.sms-msg { max-width: 80%; padding: 8px 12px; border-radius: var(--radius-md); background: var(--bg-subtle); overflow-wrap: anywhere; }
.sms-out { align-self: flex-end; background: var(--info-soft); }
.sms-meta { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.sms-img { display: block; max-width: 100%; max-height: 220px; margin-top: 6px; border-radius: var(--radius-sm); }
.sms-msg { position: relative; }
.sms-quote { border-left: 3px solid var(--primary, #0ea5e9); padding: 2px 8px; margin-bottom: 6px; font-size: 13px; color: var(--text-muted); background: rgba(127,127,127,.08); border-radius: 4px; }
.sms-tools { display: none; position: absolute; top: -12px; right: 8px; gap: 4px; }
.sms-msg:hover .sms-tools, .sms-msg:focus-within .sms-tools { display: flex; }
.sms-tools button { border: 1px solid var(--border-color, #ddd); background: var(--bg-card, #fff); color: var(--text-muted); border-radius: 999px; width: 28px; height: 28px; cursor: pointer; padding: 0; }
.sms-tools button:hover { color: var(--text-primary, inherit); }
.sms-tools form { margin: 0; }
.sms-replybar, .sms-gifchip { display: none; align-items: center; gap: 8px; padding: 6px 10px; margin-bottom: 8px; border-radius: var(--radius-sm); background: var(--bg-subtle); font-size: 13px; }
.sms-replybar.on, .sms-gifchip.on { display: flex; }
.sms-replybar .grow, .sms-gifchip .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sms-gifchip img { height: 48px; border-radius: 4px; }
.sms-toolbar { display: flex; gap: 6px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; }
.sms-panel { display: none; border: 1px solid var(--border-color, #ddd); border-radius: var(--radius-md); padding: 8px; margin-bottom: 8px; background: var(--bg-card, #fff); }
.sms-panel.on { display: block; }
.sms-emojis { display: grid; grid-template-columns: repeat(auto-fill, minmax(34px, 1fr)); gap: 2px; max-height: 180px; overflow-y: auto; }
.sms-emojis button { font-size: 20px; background: none; border: 0; cursor: pointer; border-radius: 6px; padding: 4px 0; }
.sms-emojis button:hover { background: var(--bg-subtle); }
.sms-gifs { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 6px; max-height: 260px; overflow-y: auto; margin-top: 8px; }
.sms-tab { position: relative; }
.sms-tab-count { position: absolute; top: -8px; right: -8px; min-width: 20px; padding: 1px 6px; border-radius: 10px; background: var(--danger, #dc2626); color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; }
.sms-gifs img { width: 100%; height: 90px; object-fit: cover; border-radius: 6px; cursor: pointer; }
</style>
<?php if (count($numbers) > 1): ?>
<div class="u-flex-gap u-mb-20" id="smsTabs">
    <?php foreach ($numbers as $n): ?>
        <a class="btn sms-tab <?php echo (int) $n['id'] === $nid ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo $h($link((int) $n['id'])); ?>"><?php echo $h($numberName($n)); ?><?php if (($unread[(int) $n['id']] ?? 0) > 0): ?><span class="sms-tab-count" title="Unread"><?php echo $unread[(int) $n['id']] > 99 ? '99+' : (int) $unread[(int) $n['id']]; ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="u-grid-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-comments"></i> <?php echo $h($numberName($number)); ?></div></div>
        <div class="u-p-16">
            <form method="get" action="/apps/sms" class="u-flex-gap u-mb-20">
                <input type="hidden" name="n" value="<?php echo $nid; ?>">
                <input type="text" name="peer" class="form-control" placeholder="New: +15551234567" list="smsContacts" required>
                <datalist id="smsContacts"><?php foreach ($contacts as $phone => $name): ?><option value="<?php echo $h($phone); ?>"><?php echo $h($name); ?></option><?php endforeach; ?></datalist>
                <button type="submit" class="btn btn-primary"><i class="fas fa-pen"></i> Write</button>
            </form>
            <div id="smsThreads">
            <?php echo uiTableOpen([], ['search' => false]); ?>
            <?php if ($threads === []): echo uiTableEmptyRow(1, 'No messages yet.'); endif; ?>
            <?php foreach ($threads as $t): ?>
                <tr<?php echo $t['peer'] === $peer ? ' class="active"' : ''; ?>>
                    <td>
                        <a href="<?php echo $h($link($nid, (string) $t['peer'])); ?>"><strong><?php echo $h($contacts[$t['peer']] ?? $t['peer']); ?></strong></a>
                        <?php if (isset($contacts[$t['peer']])): ?><span class="u-muted"><?php echo $h($t['peer']); ?></span><?php endif; ?>
                        <?php if ((int) $t['unread'] > 0): ?><span class="badge badge-success"><?php echo (int) $t['unread']; ?></span><?php endif; ?>
                        <div class="u-muted"><?php echo $h(($t['direction'] === 'out' ? '→ ' : '') . mb_strimwidth((string) $t['body'], 0, 70, '…')); ?></div>
                    </td>
                    <td class="u-nowrap u-muted"><?php echo $h($t['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header u-flex-between">
            <div class="card-title"><i class="fas fa-user"></i> <?php echo $h($peer === '' ? 'Choose a conversation' : (isset($contacts[$peer]) ? $contacts[$peer] . ' · ' . $peer : $peer)); ?></div>
            <?php if ($peer !== '' && $messages !== []): ?>
                <form method="post" action="<?php echo $h($link($nid)); ?>" onsubmit="return confirm('Delete this whole conversation from the PBX? This cannot be undone. (It does not unsend texts the other person already has.)');">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="sms_action" value="delete_thread">
                    <input type="hidden" name="number_id" value="<?php echo $nid; ?>">
                    <input type="hidden" name="peer" value="<?php echo $h($peer); ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete conversation</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="u-p-16">
            <?php if ($peer !== ''): ?>
                <form method="post" class="u-flex-gap u-mb-20">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="sms_action" value="contact">
                    <input type="hidden" name="number_id" value="<?php echo $nid; ?>">
                    <input type="hidden" name="peer" value="<?php echo $h($peer); ?>">
                    <input type="text" name="name" class="form-control" maxlength="80" value="<?php echo $h($contacts[$peer] ?? ''); ?>" placeholder="Name for this number">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-address-book"></i> Save name</button>
                </form>
                <div class="sms-msgs" id="smsMsgs">
                <?php foreach ($messages as $m): ?>
                    <?php $snippet = $m['body'] !== '' ? mb_strimwidth((string) $m['body'], 0, 120, '…') : ($m['media'] !== [] ? '📷 Picture' : ''); ?>
                    <div class="sms-msg<?php echo $m['direction'] === 'out' ? ' sms-out' : ''; ?>" tabindex="0" data-id="<?php echo (int) $m['id']; ?>" data-snippet="<?php echo $h($snippet); ?>" data-who="<?php echo $m['direction'] === 'out' ? 'You' : $h($contacts[$peer] ?? $peer); ?>">
                        <div class="sms-tools">
                            <button type="button" class="sms-reply-btn" title="Reply"><i class="fas fa-reply"></i></button>
                            <form method="post" onsubmit="return confirm('Delete this message from the PBX? (It does not unsend it from the other phone.)');">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                <input type="hidden" name="sms_action" value="delete">
                                <input type="hidden" name="number_id" value="<?php echo $nid; ?>">
                                <input type="hidden" name="message_id" value="<?php echo (int) $m['id']; ?>">
                                <button type="submit" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                        <?php if (!empty($m['reply_to'])): $q = $m['reply_to']; ?>
                            <div class="sms-quote"><?php echo $q['deleted'] ? '<em>Deleted message</em>' : ($q['direction'] === 'out' ? 'You: ' : '') . $h($q['body'] !== '' ? $q['body'] : ($q['media'] > 0 ? '📷 Picture' : '')); ?></div>
                        <?php endif; ?>
                        <?php if ($m['body'] !== ''): ?><div><?php echo nl2br($h($m['body'])); ?></div><?php endif; ?>
                        <?php foreach ($m['media'] as $media):
                            $url = Sms::mediaUrl($media);
                            if (str_starts_with((string) $media['content_type'], 'image/')): ?>
                                <a href="<?php echo $h($url); ?>" target="_blank" rel="noopener"><img class="sms-img" src="<?php echo $h($url); ?>" alt=""></a>
                            <?php else: ?>
                                <a href="<?php echo $h($url); ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?php echo $h($media['name'] ?: $media['content_type']); ?></a>
                            <?php endif;
                        endforeach; ?>
                        <div class="sms-meta">
                            <?php echo $h($m['created_at']); ?>
                            <?php if ($m['direction'] === 'out'): ?> · <?php echo $h($m['status']); ?><?php endif; ?>
                            <?php if ($m['error'] !== ''): ?><div class="text-danger"><?php echo $h($m['error']); ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <form method="post" enctype="multipart/form-data" class="u-mt-10" id="smsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="sms_action" value="send">
                    <input type="hidden" name="number_id" value="<?php echo $nid; ?>">
                    <input type="hidden" name="peer" value="<?php echo $h($peer); ?>">
                    <input type="hidden" name="reply_to" id="smsReplyTo" value="">
                    <input type="hidden" name="gif_url" id="smsGifUrl" value="">
                    <div class="sms-replybar" id="smsReplyBar">
                        <i class="fas fa-reply"></i>
                        <div class="grow"><strong id="smsReplyWho"></strong> <span id="smsReplyText"></span></div>
                        <button type="button" class="btn btn-secondary btn-sm" id="smsReplyCancel" title="Cancel reply"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="sms-gifchip" id="smsGifChip">
                        <img id="smsGifPreview" src="" alt="">
                        <div class="grow">GIF attached</div>
                        <button type="button" class="btn btn-secondary btn-sm" id="smsGifRemove" title="Remove GIF"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="form-group">
                        <textarea name="text" id="smsText" class="form-control" rows="3" maxlength="1600" placeholder="Message"></textarea>
                    </div>
                    <div class="sms-toolbar">
                        <button type="button" class="btn btn-secondary btn-sm" id="smsEmojiBtn" title="Emoji"><i class="far fa-smile"></i> Emoji</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="smsGifBtn" title="GIF"><strong>GIF</strong></button>
                        <label class="btn btn-secondary btn-sm u-mb-0" title="Picture"><i class="fas fa-image"></i> Picture
                            <input type="file" name="media[]" id="smsFiles" multiple accept="image/*,video/*,audio/*" hidden>
                        </label>
                        <span class="u-muted" id="smsFileNames"></span>
                    </div>
                    <div class="sms-panel" id="smsEmojiPanel"><div class="sms-emojis" id="smsEmojis"></div></div>
                    <div class="sms-panel" id="smsGifPanel">
                        <input type="search" class="form-control" id="smsGifQuery" placeholder="Search GIFs (GIPHY)">
                        <div class="sms-gifs" id="smsGifs"></div>
                        <small class="u-hint">Powered by GIPHY</small>
                    </div>
                    <small class="u-hint">Up to <?php echo Sms::MAX_MEDIA; ?> pictures or GIFs, 5 MB each; with pictures the message goes as MMS. Deleting removes a message from this PBX only.</small>
                    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button></div>
                </form>
            <?php else: ?>
                <p class="u-muted">Pick a conversation on the left, or write a new one.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// New messages appear by themselves: every 5 s the page asks for the newest id, and when it changed
// it fetches itself and swaps in the conversation list and the messages; a reply being written stays.
(function () {
    const box = document.getElementById('smsMsgs');
    if (box) { box.scrollTop = box.scrollHeight; }
    let last = <?php echo Sms::lastId(array_keys($numbers)); ?>;
    const swap = (doc, id) => {
        const now = document.getElementById(id), fresh = doc.getElementById(id);
        if (now && fresh) { now.innerHTML = fresh.innerHTML; }
    };
    const timer = setInterval(function () {
        // Left the page (the portal swaps pages without reloading): stop asking.
        if (!document.getElementById('smsThreads')) { clearInterval(timer); return; }
        if (document.hidden) { return; }
        const body = new URLSearchParams({ csrf_token: <?php echo json_encode($csrf_token); ?>, sms_action: 'poll' });
        fetch(location.href, { method: 'POST', body: body, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((d) => {
                if (!(d.last > last)) { return; }
                last = d.last;
                return fetch(location.href, { credentials: 'same-origin' }).then((r) => r.text()).then((html) => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const atEnd = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 40;
                    swap(doc, 'smsTabs');
                    swap(doc, 'smsThreads');
                    swap(doc, 'smsMsgs');
                    if (window.refreshNavCounts) { window.refreshNavCounts(); }
                    if (box && atEnd) { box.scrollTop = box.scrollHeight; }
                });
            })
            .catch(() => {});
    }, 5000);

    // --- Reply, emoji, GIF (event delegation: the message list is swapped by the refresh above) ---
    const $ = (id) => document.getElementById(id);
    const text = $('smsText');
    if (!text) { return; }
    const replyTo = $('smsReplyTo'), replyBar = $('smsReplyBar');
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.sms-reply-btn');
        if (!btn) { return; }
        const msg = btn.closest('.sms-msg');
        replyTo.value = msg.dataset.id;
        $('smsReplyWho').textContent = msg.dataset.who + ':';
        $('smsReplyText').textContent = msg.dataset.snippet;
        replyBar.classList.add('on');
        text.focus();
    });
    $('smsReplyCancel').addEventListener('click', function () { replyTo.value = ''; replyBar.classList.remove('on'); });

    const insertAtCursor = function (str) {
        const start = text.selectionStart ?? text.value.length, end = text.selectionEnd ?? text.value.length;
        text.value = text.value.slice(0, start) + str + text.value.slice(end);
        text.selectionStart = text.selectionEnd = start + str.length;
        text.focus();
    };
    const EMOJIS = '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😋 😜 🤪 😎 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 😣 😫 😩 🥺 😢 😭 😤 😠 😡 🤯 😳 😱 😨 😰 🤗 🤔 🤭 🤫 😶 😐 😑 😬 🙄 😯 😴 🤤 😷 🤒 🤕 🤢 🤮 🥴 😵 🤠 👍 👎 👌 ✌️ 🤞 🤟 🤘 👏 🙌 👐 🙏 💪 👋 👀 ❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 💯 🔥 ✨ ⭐ 🎉 🎊 🎁 🎂 ☕ 🍕 🍔 🍺 🍷 ⚽ 🏀 🚗 ✈️ 🏠 📞 📱 💻 📷 🎵 ✅ ❌ ⚠️ ❓ ❗ 💤 🌞 🌙 🌧️ ❄️ 🐶 🐱'.split(' ');
    const emojiBox = $('smsEmojis');
    EMOJIS.forEach(function (em) {
        const b = document.createElement('button');
        b.type = 'button'; b.textContent = em;
        b.addEventListener('click', function () { insertAtCursor(em); });
        emojiBox.appendChild(b);
    });
    const emojiPanel = $('smsEmojiPanel'), gifPanel = $('smsGifPanel');
    $('smsEmojiBtn').addEventListener('click', function () { gifPanel.classList.remove('on'); emojiPanel.classList.toggle('on'); });

    const files = $('smsFiles');
    const showFiles = function () { $('smsFileNames').textContent = Array.from(files.files).map((f) => f.name).join(', '); };
    files.addEventListener('change', showFiles);

    const gifSearch = <?php echo Sms::gifSearchEnabled($settings) ? 'true' : 'false'; ?>;
    const gifUrl = $('smsGifUrl'), gifChip = $('smsGifChip');
    const pickGif = function (preview, url) {
        gifUrl.value = url; $('smsGifPreview').src = preview;
        gifChip.classList.add('on'); gifPanel.classList.remove('on');
    };
    $('smsGifRemove').addEventListener('click', function () { gifUrl.value = ''; gifChip.classList.remove('on'); });
    let gifTimer = null;
    const loadGifs = function () {
        const body = new URLSearchParams({ csrf_token: <?php echo json_encode($csrf_token); ?>, sms_action: 'gif_search', q: $('smsGifQuery').value });
        fetch(location.href, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => {
                const box = $('smsGifs');
                box.innerHTML = '';
                if (!d.success) { box.textContent = d.error || 'GIF search failed.'; return; }
                (d.gifs || []).forEach(function (g) {
                    const img = document.createElement('img');
                    img.src = g.preview; img.alt = 'GIF'; img.loading = 'lazy';
                    img.addEventListener('click', function () { pickGif(g.preview, g.url); });
                    box.appendChild(img);
                });
            })
            .catch(() => {});
    };
    $('smsGifQuery').addEventListener('input', function () { clearTimeout(gifTimer); gifTimer = setTimeout(loadGifs, 350); });
    $('smsGifBtn').addEventListener('click', function () {
        if (!gifSearch) {
            // No GIPHY key: pick a GIF file from this computer instead.
            files.accept = 'image/gif';
            files.click();
            files.accept = 'image/*,video/*,audio/*';
            return;
        }
        emojiPanel.classList.remove('on');
        gifPanel.classList.toggle('on');
        if (gifPanel.classList.contains('on') && !$('smsGifs').childElementCount) { loadGifs(); }
    });
})();
</script>
