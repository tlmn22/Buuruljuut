<?php
$pageTitle  = 'Дүрэм журам';
$activePage = 'regulations';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

$categories = $pdo->query("SELECT * FROM regulation_categories ORDER BY sort_order, name")->fetchAll();

// Бүх ажилтан бүх идэвхтэй журмыг харна
$stmt = $pdo->prepare("
    SELECT r.*, c.name AS category_name,
           (SELECT MAX(rr.read_at) FROM regulation_reads rr WHERE rr.regulation_id = r.id AND rr.employee_id = ?) AS my_read_at
    FROM regulations r
    LEFT JOIN regulation_categories c ON c.id = r.category_id
    WHERE r.is_active = 1
    ORDER BY c.sort_order IS NULL, c.sort_order, c.name, r.approved_date DESC, r.title
");
$stmt->execute([$myEmployeeId]);
$regs = $stmt->fetchAll();

// Ангиллаар бүлэглэх
$groups = [];
foreach ($regs as $r) $groups[$r['category_name'] ?? 'Ангилалгүй'][] = $r;

$required = array_filter($regs, function ($r) { return $r['require_ack']; });
$requiredRead = count(array_filter($required, function ($r) { return $r['my_read_at']; }));
$requiredLeft = count($required) - $requiredRead;
$pct = $required ? round($requiredRead / count($required) * 100) : 100;

$canManage = hasPermission('regulations.manage');
$card = 'bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm';
$pill = 'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors';
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Дүрэм журам</span>
        </nav>
        <h1 class="text-3xl font-bold">Дүрэм журам</h1>
    </div>
    <?php if ($canManage): ?>
    <a href="<?= BASE_URL ?>/modules/regulations/admin.php"
       class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12] transition-colors">
        <span class="material-icons-outlined text-sm">admin_panel_settings</span> Удирдах
    </a>
    <?php endif; ?>
</div>

<!-- Танилцах явц -->
<?php if ($required): ?>
<div class="<?= $card ?> p-5 flex flex-col sm:flex-row sm:items-center gap-4">
    <span class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 <?= $requiredLeft ? 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400' : 'bg-green-50 dark:bg-green-950/30 text-green-600 dark:text-green-400' ?>">
        <span class="material-icons-outlined"><?= $requiredLeft ? 'assignment_late' : 'task_alt' ?></span>
    </span>
    <div class="flex-1 min-w-0">
        <p class="font-semibold" id="ackTitle">
            <?= $requiredLeft ? $requiredLeft . ' журамтай танилцах шаардлагатай байна' : 'Заавал танилцах бүх журамтай танилцсан байна' ?>
        </p>
        <div class="flex items-center gap-3 mt-2">
            <div class="flex-1 h-2 bg-slate-100 dark:bg-[#272c38] rounded-full overflow-hidden">
                <div id="ackBar" class="h-full rounded-full <?= $requiredLeft ? 'bg-[#f1592a]' : 'bg-green-500' ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <span class="text-sm text-slate-500 dark:text-[#8b93a1] whitespace-nowrap"><b id="ackRead" class="text-[#1c2430] dark:text-[#f2f3f6]"><?= $requiredRead ?></b> / <?= count($required) ?> үзсэн</span>
        </div>
    </div>
    <?php if ($requiredLeft): ?>
    <button type="button" onclick="setStatus('todo')" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38] whitespace-nowrap">Үзээгүйг харах</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Шүүлтүүр -->
<div class="<?= $card ?> p-3 flex flex-wrap items-center gap-2">
    <div class="flex flex-wrap gap-1" id="catPills">
        <button type="button" data-cat="" class="cat-pill <?= $pill ?> bg-[#f1592a] text-white">Бүгд</button>
        <?php foreach ($categories as $c): ?>
            <button type="button" data-cat="<?= htmlspecialchars(mb_strtolower($c['name'])) ?>" class="cat-pill <?= $pill ?> text-slate-600 dark:text-[#c9cdd6] hover:bg-slate-100 dark:hover:bg-[#272c38]"><?= htmlspecialchars($c['name']) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="flex items-center gap-2 ml-auto flex-wrap">
        <select id="filterStatus" onchange="applyFilters()" class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
            <option value="">Бүх төлөв</option>
            <option value="todo">Үзээгүй</option>
            <option value="required">Заавал танилцах</option>
            <option value="read">Үзсэн</option>
        </select>
        <div class="relative">
            <span class="material-icons-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" style="font-size:18px">search</span>
            <input type="text" id="filterSearch" oninput="applyFilters()" placeholder="Журам хайх..."
                   class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg pl-9 pr-3 py-1.5 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
        </div>
    </div>
</div>

<?php if (!$regs): ?>
    <div class="<?= $card ?> p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">menu_book</span>
        Одоогоор дүрэм журам байршуулаагүй байна.
    </div>
<?php else: ?>
<div id="regGroups" class="space-y-6">
    <?php foreach ($groups as $catName => $items): ?>
    <section class="reg-group">
        <h2 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-[#8b93a1] mb-3">
            <?= htmlspecialchars($catName) ?> <span class="group-count px-2 py-0.5 rounded-full bg-slate-100 dark:bg-[#272c38] text-xs normal-case tracking-normal"><?= count($items) ?></span>
        </h2>
        <div class="<?= $card ?> divide-y divide-slate-100 dark:divide-[#2a2f3b] overflow-hidden">
            <?php foreach ($items as $r):
                $isRead = (bool)$r['my_read_at'];
                $status = $isRead ? 'read' : 'todo'; ?>
            <div class="reg-item flex flex-col sm:flex-row sm:items-center gap-4 p-5 hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors <?= !$isRead && $r['require_ack'] ? 'border-l-4 border-l-[#f1592a]' : '' ?>"
                 data-id="<?= (int)$r['id'] ?>"
                 data-name="<?= htmlspecialchars(mb_strtolower($r['title'] . ' ' . ($r['description'] ?? '') . ' ' . ($r['category_name'] ?? ''))) ?>"
                 data-category="<?= htmlspecialchars(mb_strtolower($r['category_name'] ?? '')) ?>"
                 data-status="<?= $status ?>" data-required="<?= $r['require_ack'] ? 1 : 0 ?>">
                <span class="w-11 h-11 rounded-lg bg-red-50 dark:bg-red-950/30 text-red-500 dark:text-red-400 flex items-center justify-center flex-shrink-0">
                    <span class="material-icons-outlined">picture_as_pdf</span>
                </span>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold leading-snug"><?= htmlspecialchars($r['title']) ?></p>
                    <?php if (!empty($r['description'])): ?>
                        <p class="text-sm text-slate-500 dark:text-[#8b93a1] mt-1 line-clamp-2"><?= htmlspecialchars($r['description']) ?></p>
                    <?php endif; ?>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-xs text-slate-400 dark:text-[#8b93a1]">
                        <?php if ($r['approved_date']): ?><span class="flex items-center gap-1"><span class="material-icons-outlined" style="font-size:14px">event</span>Батлагдсан <?= date('Y.m.d', strtotime($r['approved_date'])) ?></span><?php endif; ?>
                        <?php if ($r['require_ack']): ?><span class="flex items-center gap-1 text-amber-600 dark:text-amber-400 font-medium"><span class="material-icons-outlined" style="font-size:14px">priority_high</span>Заавал танилцах</span><?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-3 sm:flex-shrink-0">
                    <span class="status-badge inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium whitespace-nowrap
                        <?= $isRead ? 'bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400' : ($r['require_ack'] ? 'bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400' : 'bg-slate-100 dark:bg-[#272c38] text-slate-500 dark:text-[#8b93a1]') ?>"
                        title="<?= $isRead ? 'Үзсэн: ' . htmlspecialchars(date('Y.m.d H:i', strtotime($r['my_read_at']))) : '' ?>">
                        <span class="material-icons-outlined" style="font-size:14px"><?= $isRead ? 'check_circle' : 'radio_button_unchecked' ?></span><?= $isRead ? 'Үзсэн' : 'Үзээгүй' ?>
                    </span>
                    <a href="<?= REGULATIONS_UPLOAD_URL . htmlspecialchars($r['file_path']) ?>" target="_blank" rel="noopener"
                       onclick="markRead(<?= (int)$r['id'] ?>)"
                       class="inline-flex items-center gap-1.5 px-4 py-2 bg-[#f1592a] text-white rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors">
                        <span class="material-icons-outlined" style="font-size:16px">open_in_new</span> Үзэх
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
    <div id="emptyFilter" class="hidden <?= $card ?> p-12 text-center text-sm text-slate-400 dark:text-[#5a6172]">Шүүлтүүрт тохирох журам олдсонгүй.</div>
</div>
<?php endif; ?>

<script>
var activeCat = '';
var REQUIRED_TOTAL = <?= count($required) ?>;

// Үзэх дарахад "Үзсэн" болгож бүртгэнэ (хуудас дахин ачаалахгүй)
function markRead(id) {
    $.post('<?= BASE_URL ?>/modules/regulations/ajax.php', { action: 'mark_read', id: id });
    var $item = $('.reg-item[data-id="' + id + '"]');
    if ($item.attr('data-status') === 'read') return;
    $item.attr('data-status', 'read').removeClass('border-l-4 border-l-[#f1592a]');
    $item.find('.status-badge').attr('class', 'status-badge inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium whitespace-nowrap bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400')
        .html('<span class="material-icons-outlined" style="font-size:14px">check_circle</span>Үзсэн');
    if ($item.attr('data-required') === '1' && REQUIRED_TOTAL) {
        var read = $('.reg-item[data-required="1"][data-status="read"]').length, left = REQUIRED_TOTAL - read;
        $('#ackRead').text(read);
        $('#ackBar').css('width', Math.round(read / REQUIRED_TOTAL * 100) + '%').toggleClass('bg-green-500', !left).toggleClass('bg-[#f1592a]', !!left);
        $('#ackTitle').text(left ? left + ' журамтай танилцах шаардлагатай байна' : 'Заавал танилцах бүх журамтай танилцсан байна');
    }
}

function setStatus(v) { $('#filterStatus').val(v); applyFilters(); }

$('#catPills').on('click', '.cat-pill', function () {
    activeCat = this.dataset.cat;
    $('.cat-pill').removeClass('bg-[#f1592a] text-white').addClass('text-slate-600 dark:text-[#c9cdd6] hover:bg-slate-100 dark:hover:bg-[#272c38]');
    $(this).addClass('bg-[#f1592a] text-white').removeClass('text-slate-600 dark:text-[#c9cdd6] hover:bg-slate-100 dark:hover:bg-[#272c38]');
    applyFilters();
});

function applyFilters() {
    var q = $('#filterSearch').val().toLowerCase().trim(), status = $('#filterStatus').val(), shown = 0;
    $('.reg-group').each(function () {
        var n = 0;
        $(this).find('.reg-item').each(function () {
            var d = this.dataset;
            var ok = (!q || d.name.indexOf(q) !== -1)
                && (!activeCat || d.category === activeCat)
                && (!status || (status === 'required' ? d.required === '1' : d.status === status));
            $(this).toggleClass('hidden', !ok);
            if (ok) n++;
        });
        $(this).toggleClass('hidden', !n).find('.group-count').text(n);
        shown += n;
    });
    $('#emptyFilter').toggleClass('hidden', shown > 0);
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
