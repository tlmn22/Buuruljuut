<?php
$pageTitle  = 'Мэдээ мэдээлэл';
$activePage = 'news';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$pdo   = getDB();
$me    = (int)$_SESSION['user_id'];
$myOrg = isset($_SESSION['user_org_unit_id']) ? (int)$_SESSION['user_org_unit_id'] : null;
$canPublish = newsCanPublish();

$cat    = isset(NEWS_CATEGORIES[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$q      = trim($_GET['q'] ?? '');
$page   = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;

[$where, $params] = newsVisibilityWhere($pdo, $me, $myOrg);
if ($cat !== '') { $where .= " AND p.category = ?"; $params[] = $cat; }
if ($q !== '')   { $where .= " AND (p.title LIKE ? OR p.body LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }

$total = $pdo->prepare("SELECT COUNT(*) FROM news_posts p WHERE $where");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT p.*, e.last_name, e.first_name, e.photo,
           (SELECT COUNT(*) FROM news_likes l WHERE l.post_id = p.id) AS likes,
           (SELECT COUNT(*) FROM news_comments c WHERE c.post_id = p.id AND c.deleted_at IS NULL) AS comments,
           EXISTS (SELECT 1 FROM news_reads r WHERE r.post_id = p.id AND r.employee_id = ?) AS is_read
    FROM news_posts p JOIN employees e ON e.id = p.author_id
    WHERE $where
    ORDER BY (p.status = 'published' AND p.published_at <= NOW()) ASC, p.is_pinned DESC, COALESCE(p.published_at, p.created_at) DESC
    LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute(array_merge([$me], $params));
$posts = $stmt->fetchAll();
$pages = max(1, (int)ceil($totalCount / $perPage));

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$qs = function (array $over) use ($cat, $q) {
    return '?' . http_build_query(array_filter(array_merge(['cat' => $cat, 'q' => $q], $over), 'strlen'));
};
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Мэдээ мэдээлэл</span>
        </nav>
        <h1 class="text-3xl font-bold">Мэдээ мэдээлэл</h1>
    </div>
    <div class="flex items-center gap-2">
        <?php if (isSuperAdmin()): ?>
        <a href="<?= BASE_URL ?>/modules/news/admin.php" class="px-4 py-2.5 rounded-lg text-sm font-medium border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38] flex items-center gap-1.5">
            <span class="material-icons-outlined" style="font-size:18px">manage_accounts</span> Нийтлэгчид
        </a>
        <?php endif; ?>
        <?php if ($canPublish): ?>
        <a href="<?= BASE_URL ?>/modules/news/editor.php" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12] transition-colors">
            <span class="material-icons-outlined" style="font-size:18px">edit</span> Нийтлэл бичих
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-3 flex flex-wrap items-center gap-2">
    <a href="<?= $qs(['cat' => '', 'page' => '']) ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $cat === '' ? 'bg-[#f1592a] text-white' : 'text-slate-600 dark:text-[#c9cdd6] hover:bg-slate-100 dark:hover:bg-[#272c38]' ?>">Бүгд</a>
    <?php foreach (NEWS_CATEGORIES as $key => $c): ?>
        <a href="<?= $qs(['cat' => $key, 'page' => '']) ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $cat === $key ? 'bg-[#f1592a] text-white' : 'text-slate-600 dark:text-[#c9cdd6] hover:bg-slate-100 dark:hover:bg-[#272c38]' ?>"><?= $c['label'] ?></a>
    <?php endforeach; ?>
    <form method="GET" class="ml-auto relative">
        <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= htmlspecialchars($cat) ?>"><?php endif; ?>
        <span class="material-icons-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" style="font-size:18px">search</span>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Мэдээ хайх..." class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg pl-9 pr-3 py-1.5 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
    </form>
</div>

<?php if (!$posts): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">feed</span>
        <?= $q !== '' || $cat !== '' ? 'Хайлтад тохирох мэдээ олдсонгүй.' : 'Одоогоор мэдээ нийтлэгдээгүй байна.' ?>
    </div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($posts as $p):
        $c = NEWS_CATEGORIES[$p['category']] ?? NEWS_CATEGORIES['general'];
        $live = $p['status'] === 'published' && strtotime($p['published_at']) <= time();
    ?>
    <a href="<?= BASE_URL ?>/modules/news/post.php?id=<?= (int)$p['id'] ?>" class="group bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden flex flex-col hover:shadow-md hover:border-[#f1592a]/40 transition-all">
        <div class="relative h-40 bg-gradient-to-br from-[#f1592a] to-[#ff8a5c] flex-shrink-0">
            <?php if ($p['cover_image']): ?>
                <img src="<?= NEWS_UPLOAD_URL . htmlspecialchars($p['cover_image']) ?>" alt="" class="w-full h-full object-cover" loading="lazy">
            <?php else: ?>
                <span class="material-icons-outlined absolute inset-0 m-auto w-fit h-fit text-white/40" style="font-size:56px">campaign</span>
            <?php endif; ?>
            <div class="absolute top-3 left-3 flex gap-1.5">
                <?php if ($p['is_pinned']): ?><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-white/90 text-[#c9481f] flex items-center gap-1"><span class="material-icons-outlined" style="font-size:13px">push_pin</span>Бэхэлсэн</span><?php endif; ?>
                <?php if ($p['status'] === 'draft'): ?><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-800/80 text-white">Ноорог</span>
                <?php elseif (!$live): ?><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-600/90 text-white">Хуваарьт · <?= date('m.d H:i', strtotime($p['published_at'])) ?></span><?php endif; ?>
            </div>
            <?php if ($live && !$p['is_read']): ?><span class="absolute top-3 right-3 w-3 h-3 rounded-full bg-[#f1592a] ring-2 ring-white" title="Уншаагүй"></span><?php endif; ?>
        </div>
        <div class="p-5 flex flex-col gap-2 flex-1">
            <span class="self-start px-2 py-0.5 rounded-full text-xs font-medium <?= $c['cls'] ?>"><?= $c['label'] ?></span>
            <h3 class="font-bold text-lg leading-snug group-hover:text-[#f1592a] transition-colors"><?= htmlspecialchars($p['title']) ?></h3>
            <p class="text-sm text-slate-500 dark:text-[#8b93a1] line-clamp-3"><?= htmlspecialchars(newsExcerpt($p['body'], 180)) ?></p>
            <div class="mt-auto pt-3 flex items-center justify-between text-xs text-slate-400 dark:text-[#8b93a1]">
                <span class="truncate"><?= htmlspecialchars(employeeName($p['last_name'], $p['first_name'])) ?> · <?= timeAgoMn($p['published_at'] ?? $p['created_at']) ?></span>
                <span class="flex items-center gap-3 flex-shrink-0">
                    <span class="flex items-center gap-1"><span class="material-icons-outlined" style="font-size:15px">thumb_up</span><?= (int)$p['likes'] ?></span>
                    <span class="flex items-center gap-1"><span class="material-icons-outlined" style="font-size:15px">chat_bubble_outline</span><?= (int)$p['comments'] ?></span>
                </span>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<div class="flex justify-center gap-1">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="<?= $qs(['page' => $i > 1 ? $i : '']) ?>" class="min-w-9 h-9 px-3 flex items-center justify-center rounded-lg text-sm font-medium <?= $i === $page ? 'bg-[#f1592a] text-white' : 'bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38]' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
