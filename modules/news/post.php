<?php
$pageTitle  = 'Мэдээ';
$activePage = 'news';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$pdo   = getDB();
$me    = (int)$_SESSION['user_id'];
$myOrg = isset($_SESSION['user_org_unit_id']) ? (int)$_SESSION['user_org_unit_id'] : null;

$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, e.last_name, e.first_name, e.position, e.photo FROM news_posts p JOIN employees e ON e.id = p.author_id WHERE p.id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post || !newsCanView($pdo, $id, $me, $myOrg)) { http_response_code(404); include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php'; echo '<div class="p-16 text-center text-slate-400">Мэдээ олдсонгүй эсвэл харах эрхгүй байна.</div>'; include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; exit; }
$pageTitle = $post['title'];

$live = $post['status'] === 'published' && strtotime($post['published_at']) <= time();
$canEdit = newsCanEdit($post, $me);
if ($live) {
    $pdo->prepare("INSERT IGNORE INTO news_reads (post_id, employee_id) VALUES (?,?)")->execute([$id, $me]);
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE employee_id = ? AND read_at IS NULL AND (link = ? OR link LIKE ?)")
        ->execute([$me, "/modules/news/post.php?id=$id", "/modules/news/post.php?id=$id#%"]);
}

$likes = $pdo->prepare("SELECT COUNT(*) AS n, SUM(employee_id = ?) AS mine FROM news_likes WHERE post_id = ?");
$likes->execute([$me, $id]);
$likeRow = $likes->fetch();

// Уншсан статистик — зохиогч / superadmin
$readStats = null;
if ($canEdit && $live) {
    [$audSql, $audParams] = newsAudienceSql($pdo, $post);
    $aud = $pdo->prepare("SELECT COUNT(*) FROM ($audSql) a");
    $aud->execute($audParams);
    $rc = $pdo->prepare("SELECT COUNT(*) FROM news_reads WHERE post_id = ?");
    $rc->execute([$id]);
    $readStats = ['read' => (int)$rc->fetchColumn(), 'total' => (int)$aud->fetchColumn()];
}

$audienceUnits = [];
if ($post['audience'] === 'units') {
    $u = $pdo->prepare("SELECT ou.name FROM news_post_units pu JOIN org_units ou ON ou.id = pu.org_unit_id WHERE pu.post_id = ? ORDER BY ou.name");
    $u->execute([$id]);
    $audienceUnits = $u->fetchAll(PDO::FETCH_COLUMN);
}

$cs = $pdo->prepare("SELECT c.*, e.last_name, e.first_name, e.position, e.photo FROM news_comments c JOIN employees e ON e.id = c.employee_id WHERE c.post_id = ? ORDER BY c.created_at, c.id");
$cs->execute([$id]);
$top = []; $replies = [];
foreach ($cs->fetchAll() as $c) { if ($c['parent_id']) $replies[(int)$c['parent_id']][] = $c; else $top[] = $c; }
$commentCount = 0;
foreach ($top as $c) { if (!$c['deleted_at']) $commentCount++; foreach ($replies[(int)$c['id']] ?? [] as $r) if (!$r['deleted_at']) $commentCount++; }

$cat = NEWS_CATEGORIES[$post['category']] ?? NEWS_CATEGORIES['general'];
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';

function newsAvatar(array $p, string $size = 'w-9 h-9'): string {
    if ($p['photo'] && file_exists(UPLOAD_PATH . $p['photo'])) return '<img src="' . UPLOAD_URL . htmlspecialchars($p['photo']) . '" class="' . $size . ' rounded-full object-cover flex-shrink-0" alt="">';
    return '<div class="' . $size . ' rounded-full bg-[#f1592a] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">' . htmlspecialchars(mb_substr($p['last_name'], 0, 1)) . '</div>';
}
function newsRenderComment(array $c, int $me, bool $isPostAuthor, bool $allowReply, bool $isReply): void {
    $own = (int)$c['employee_id'] === $me;
    $canDelete = $own || $isPostAuthor || isSuperAdmin(); ?>
    <div id="c<?= (int)$c['id'] ?>" class="flex gap-3 <?= $isReply ? 'mt-3' : '' ?>">
        <?= newsAvatar($c, $isReply ? 'w-8 h-8' : 'w-9 h-9') ?>
        <div class="flex-1 min-w-0">
            <?php if ($c['deleted_at']): ?>
                <p class="text-sm italic text-slate-400 dark:text-[#5a6172] py-2">Сэтгэгдэл устгагдсан</p>
            <?php else: ?>
            <div class="bg-slate-50 dark:bg-[#20242e] rounded-xl px-4 py-2.5">
                <div class="flex items-baseline gap-2 flex-wrap">
                    <span class="text-sm font-semibold"><?= htmlspecialchars(employeeName($c['last_name'], $c['first_name'])) ?></span>
                    <span class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($c['position'] ?? '') ?></span>
                </div>
                <p class="comment-body text-sm text-slate-700 dark:text-[#e5e7eb] mt-1 whitespace-pre-line break-words"><?= htmlspecialchars($c['body']) ?></p>
            </div>
            <div class="flex items-center gap-3 mt-1 ml-2 text-xs text-slate-400 dark:text-[#8b93a1]">
                <span><?= timeAgoMn($c['created_at']) ?><?= $c['updated_at'] ? ' · засварласан' : '' ?></span>
                <?php if ($allowReply): ?><button type="button" class="font-semibold hover:text-[#f1592a]" onclick="replyTo(<?= (int)$c['id'] ?>,<?= htmlspecialchars(json_encode(employeeName($c['last_name'], $c['first_name']), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Хариулах</button><?php endif; ?>
                <?php if ($own): ?><button type="button" class="font-semibold hover:text-[#f1592a]" onclick="editComment(<?= (int)$c['id'] ?>)">Засах</button><?php endif; ?>
                <?php if ($canDelete): ?><button type="button" class="font-semibold hover:text-red-500" onclick="deleteComment(<?= (int)$c['id'] ?>)">Устгах</button><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php }
?>
<style>
.news-body { font-size: 16px; line-height: 1.75; color: inherit; }
.news-body p { margin: 0 0 1em; }
.news-body h2 { font-size: 1.4em; font-weight: 700; margin: 1.4em 0 .6em; }
.news-body h3 { font-size: 1.15em; font-weight: 700; margin: 1.2em 0 .5em; }
.news-body ul, .news-body ol { margin: 0 0 1em; padding-left: 1.5em; }
.news-body ul { list-style: disc; } .news-body ol { list-style: decimal; }
.news-body li { margin: .25em 0; }
.news-body blockquote { border-left: 4px solid #f1592a; padding: .25em 0 .25em 1em; margin: 0 0 1em; color: #64748b; }
.news-body a { color: #f1592a; text-decoration: underline; }
</style>

<div class="max-w-3xl w-full mx-auto space-y-6">
    <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1]">
        <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a><span class="mx-2">/</span>
        <a href="<?= BASE_URL ?>/modules/news/index.php" class="hover:text-[#f1592a]">Мэдээ мэдээлэл</a>
    </nav>

    <?php if (!$live): ?>
    <div class="px-4 py-3 rounded-xl text-sm bg-blue-50 dark:bg-[#1c2a3d] text-blue-700 dark:text-[#6ea8fe] flex items-center gap-2">
        <span class="material-icons-outlined" style="font-size:18px"><?= $post['status'] === 'draft' ? 'edit_note' : 'schedule' ?></span>
        <?= $post['status'] === 'draft' ? 'Ноорог — зөвхөн танд харагдаж байна.' : 'Хуваарьт нийтлэл — ' . date('Y.m.d H:i', strtotime($post['published_at'])) . '-д нийтлэгдэнэ.' ?>
    </div>
    <?php endif; ?>

    <article class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
        <?php if ($post['cover_image']): ?>
            <img src="<?= NEWS_UPLOAD_URL . htmlspecialchars($post['cover_image']) ?>" alt="" class="w-full max-h-96 object-cover">
        <?php endif; ?>
        <div class="p-6 md:p-8">
            <div class="flex items-center gap-2 flex-wrap mb-3">
                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium <?= $cat['cls'] ?>"><?= $cat['label'] ?></span>
                <?php if ($post['is_pinned']): ?><span class="text-xs text-[#c9481f] flex items-center gap-1"><span class="material-icons-outlined" style="font-size:14px">push_pin</span>Бэхэлсэн</span><?php endif; ?>
                <?php if ($audienceUnits): ?><span class="text-xs text-slate-400 dark:text-[#8b93a1]" title="<?= htmlspecialchars(implode(', ', $audienceUnits)) ?>">· <?= htmlspecialchars(implode(', ', array_slice($audienceUnits, 0, 2))) ?><?= count($audienceUnits) > 2 ? ' +' . (count($audienceUnits) - 2) : '' ?></span><?php endif; ?>
            </div>
            <h1 class="text-2xl md:text-3xl font-bold leading-tight mb-5"><?= htmlspecialchars($post['title']) ?></h1>
            <div class="flex items-center justify-between gap-3 flex-wrap pb-5 mb-6 border-b border-slate-100 dark:border-[#2a2f3b]">
                <div class="flex items-center gap-3">
                    <?= newsAvatar($post, 'w-10 h-10') ?>
                    <div>
                        <p class="text-sm font-semibold"><?= htmlspecialchars(employeeName($post['last_name'], $post['first_name'])) ?></p>
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($post['position'] ?? '') ?> · <?= $post['published_at'] ? date('Y.m.d H:i', strtotime($post['published_at'])) : 'Ноорог' ?></p>
                    </div>
                </div>
                <?php if ($canEdit): ?>
                <div class="flex items-center gap-1">
                    <?php if ($readStats): ?><span class="text-xs text-slate-500 dark:text-[#8b93a1] mr-2 flex items-center gap-1"><span class="material-icons-outlined" style="font-size:15px">visibility</span><?= $readStats['read'] ?> / <?= $readStats['total'] ?> уншсан</span><?php endif; ?>
                    <a href="<?= BASE_URL ?>/modules/news/editor.php?id=<?= $id ?>" class="p-2 text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38] rounded-lg" title="Засах"><span class="material-icons-outlined" style="font-size:18px">edit</span></a>
                    <button type="button" onclick="deletePost()" class="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg" title="Устгах"><span class="material-icons-outlined" style="font-size:18px">delete</span></button>
                </div>
                <?php endif; ?>
            </div>
            <div class="news-body"><?= $post['body'] /* newsSanitizeHtml-ээр хадгалахдаа цэвэрлэсэн */ ?></div>

            <div class="flex items-center gap-4 pt-5 mt-6 border-t border-slate-100 dark:border-[#2a2f3b]">
                <button type="button" id="likeBtn" onclick="toggleLike()" <?= $live ? '' : 'disabled' ?>
                        class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $likeRow['mine'] ? 'bg-orange-50 dark:bg-[#2a1810] text-[#f1592a]' : 'hover:bg-slate-100 dark:hover:bg-[#272c38] text-slate-600 dark:text-[#c9cdd6]' ?>">
                    <span class="material-icons-outlined" style="font-size:18px">thumb_up</span>
                    <span id="likeCount"><?= (int)$likeRow['n'] ?></span>
                </button>
                <span class="text-sm text-slate-500 dark:text-[#8b93a1] flex items-center gap-1.5"><span class="material-icons-outlined" style="font-size:18px">chat_bubble_outline</span><?= $commentCount ?> сэтгэгдэл</span>
            </div>
        </div>
    </article>

    <section class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-6 md:p-8" id="comments">
        <h2 class="font-bold text-lg mb-5">Сэтгэгдэл</h2>
        <?php if (!$post['allow_comments']): ?>
            <p class="text-sm text-slate-400 dark:text-[#5a6172]">Энэ нийтлэлд сэтгэгдэл бичих боломжгүй.</p>
        <?php endif; ?>

        <div class="space-y-5">
            <?php foreach ($top as $c):
                $kids = $replies[(int)$c['id']] ?? [];
                $liveKids = array_filter($kids, function ($r) { return !$r['deleted_at']; });
                if ($c['deleted_at'] && !$liveKids) continue; ?>
                <div>
                    <?php newsRenderComment($c, $me, (int)$post['author_id'] === $me, (bool)$post['allow_comments'] && $live, false); ?>
                    <?php if ($liveKids): ?>
                    <div class="ml-12">
                        <?php foreach ($liveKids as $r) newsRenderComment($r, $me, (int)$post['author_id'] === $me, (bool)$post['allow_comments'] && $live, true); ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($post['allow_comments'] && $live): ?>
        <form id="commentForm" class="mt-6 flex gap-3">
            <?= newsAvatar(['photo' => $empPhoto ?? null, 'last_name' => $user['name']]) ?>
            <div class="flex-1">
                <div id="replyBanner" class="hidden text-xs text-slate-500 dark:text-[#8b93a1] mb-1.5">
                    <span id="replyName"></span>-д хариулж байна · <button type="button" class="text-[#f1592a] font-semibold" onclick="cancelReply()">Болих</button>
                </div>
                <textarea id="commentBody" rows="2" maxlength="2000" placeholder="Сэтгэгдэл бичих..." class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-xl px-4 py-2.5 text-sm resize-y focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"></textarea>
                <div class="flex justify-end mt-2">
                    <button type="submit" class="bg-[#f1592a] text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors">Илгээх</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </section>
</div>

<script>
var AJAX = '<?= BASE_URL ?>/modules/news/ajax.php', POST_ID = <?= $id ?>, replyParent = null;
function toggleLike() {
    $.post(AJAX, { action: 'toggle_like', post_id: POST_ID }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        $('#likeCount').text(r.count);
        $('#likeBtn').toggleClass('bg-orange-50 dark:bg-[#2a1810] text-[#f1592a]', r.liked).toggleClass('hover:bg-slate-100 dark:hover:bg-[#272c38] text-slate-600 dark:text-[#c9cdd6]', !r.liked);
    }, 'json');
}
function replyTo(parentId, name) {
    replyParent = parentId;
    $('#replyName').text(name); $('#replyBanner').removeClass('hidden');
    $('#commentBody').focus();
    document.getElementById('commentForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function cancelReply() { replyParent = null; $('#replyBanner').addClass('hidden'); }
$('#commentForm').on('submit', function (e) {
    e.preventDefault();
    var body = $('#commentBody').val().trim();
    if (!body) return;
    $.post(AJAX, { action: 'add_comment', post_id: POST_ID, parent_id: replyParent || '', body: body }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        location.href = location.pathname + '?id=' + POST_ID + '&t=' + Date.now() + '#c' + r.id;
    }, 'json');
});
function editComment(id) {
    var $p = $('#c' + id + ' .comment-body');
    var body = prompt('Сэтгэгдэл засах:', $p.text());
    if (body === null || !body.trim()) return;
    $.post(AJAX, { action: 'edit_comment', id: id, body: body.trim() }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        $p.text(body.trim());
    }, 'json');
}
function deleteComment(id) {
    if (!confirm('Энэ сэтгэгдлийг устгах уу?')) return;
    $.post(AJAX, { action: 'delete_comment', id: id }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        location.reload();
    }, 'json');
}
function deletePost() {
    if (!confirm('Энэ нийтлэлийг бүрмөсөн устгах уу? Сэтгэгдэл, like-ууд хамт устна.')) return;
    $.post(AJAX, { action: 'delete_post', id: POST_ID }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        location.href = '<?= BASE_URL ?>/modules/news/index.php';
    }, 'json');
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
