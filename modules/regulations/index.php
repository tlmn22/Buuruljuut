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

$unitsById = regulationsOrgUnitsById($pdo);

$categories = $pdo->query("SELECT * FROM regulation_categories ORDER BY sort_order, name")->fetchAll();

// Бүх ажилтан бүх идэвхтэй журмыг харна — хамаарах нэгжийн сонголт нь ЗӨВХӨН аль хэлтэст
// хамаарахыг илэрхийлэх шошго, харах эрхийг хязгаарлахгүй.
$stmt = $pdo->prepare("
    SELECT r.*, c.name AS category_name,
           (SELECT COUNT(*) FROM regulation_reads rr WHERE rr.regulation_id = r.id AND rr.employee_id = ?) AS my_read
    FROM regulations r
    LEFT JOIN regulation_categories c ON c.id = r.category_id
    WHERE r.is_active = 1
    ORDER BY c.sort_order, c.name, r.approved_date DESC, r.title
");
$stmt->execute([$myEmployeeId]);
$regs = $stmt->fetchAll();

$orgUnitMap = regulationsOrgUnitMap($pdo);

$canManage = hasPermission('regulations.manage');
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
       class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12] transition-colors shadow-lg shadow-orange-100 dark:shadow-none">
        <span class="material-icons-outlined text-sm">admin_panel_settings</span> Удирдах
    </a>
    <?php endif; ?>
</div>

<div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-4 flex flex-wrap items-center gap-3">
    <span class="material-icons-outlined text-slate-400 dark:text-[#8b93a1]">search</span>
    <input type="text" id="filterSearch" onkeyup="filterTable()" placeholder="Гарчиг, ангиллаар хайх..."
           class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm w-72 focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"/>
    <select id="filterCategory" onchange="filterTable()"
            class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
        <option value="">Бүх ангилал</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= htmlspecialchars(mb_strtolower($c['name'])) ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="text-sm text-slate-400 dark:text-[#8b93a1] ml-auto">Нийт: <strong id="rowCount"><?= count($regs) ?></strong> баримт</span>
</div>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b]">
                    <th class="text-center px-4 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">№</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Гарчиг</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Ангилал</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Хамаарах нэгж</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Батлагдсан огноо</th>
                    <th class="text-center px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Таны төлөв</th>
                    <th class="text-right px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Үйлдэл</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($regs)): ?>
                <tr><td colspan="7" class="text-center py-16 text-slate-400 dark:text-[#5a6172]">
                    <span class="material-icons-outlined block mb-3" style="font-size:48px">menu_book</span>Танд харагдах дүрэм журам одоогоор байхгүй байна
                </td></tr>
            <?php else: foreach ($regs as $i => $r): $isRead = (int)$r['my_read'] > 0; ?>
                <tr class="border-b border-slate-100 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors table-row"
                    data-name="<?= mb_strtolower(htmlspecialchars($r['title'] . ' ' . ($r['category_name'] ?? ''))) ?>"
                    data-category="<?= mb_strtolower(htmlspecialchars($r['category_name'] ?? '')) ?>">
                    <td class="px-4 py-4 text-center text-slate-400 dark:text-[#5a6172] row-num"><?= $i + 1 ?></td>
                    <td class="px-6 py-4">
                        <p class="font-medium"><?= htmlspecialchars($r['title']) ?></p>
                        <?php if ($r['require_ack']): ?>
                            <span class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400 rounded-full text-xs font-medium">
                                <span class="material-icons-outlined" style="font-size:12px">priority_high</span> Заавал танилцах
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($r['category_name']): ?>
                            <span class="inline-flex items-center px-2.5 py-1 bg-purple-50 dark:bg-[#272c38] text-purple-600 dark:text-[#c9a8ff] rounded-full text-xs font-medium"><?= htmlspecialchars($r['category_name']) ?></span>
                        <?php else: ?><span class="text-slate-400 dark:text-[#5a6172]">—</span><?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php $regUnitIds = $orgUnitMap[(int)$r['id']] ?? []; ?>
                        <?php if ($regUnitIds): ?>
                            <div class="flex flex-wrap gap-1 max-w-xs">
                                <?php foreach ($regUnitIds as $uid): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 bg-blue-50 dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0] rounded-full text-xs font-medium"><?= htmlspecialchars($unitsById[$uid]['name'] ?? '') ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?><span class="text-slate-400 dark:text-[#5a6172] text-xs">Бүх компани</span><?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-slate-600 dark:text-[#c9cdd6]"><?= $r['approved_date'] ? htmlspecialchars($r['approved_date']) : '—' ?></td>
                    <td class="px-6 py-4 text-center">
                        <?php if ($isRead): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400 rounded-full text-xs font-medium"><span class="material-icons-outlined" style="font-size:14px">check_circle</span> Үзсэн</span>
                        <?php elseif ($r['require_ack']): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400 rounded-full text-xs font-medium"><span class="material-icons-outlined" style="font-size:14px">error_outline</span> Үзээгүй</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-slate-100 dark:bg-[#272c38] text-slate-500 dark:text-[#8b93a1] rounded-full text-xs font-medium">Үзээгүй</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <a href="<?= REGULATIONS_UPLOAD_URL . htmlspecialchars($r['file_path']) ?>" target="_blank"
                           onclick="markRead(<?= $r['id'] ?>)"
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-[#f1592a] text-white rounded-lg text-xs font-medium hover:bg-[#c33e12] transition-colors">
                            <span class="material-icons-outlined" style="font-size:14px">picture_as_pdf</span> Үзэх
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function markRead(id) {
    $.post('<?= BASE_URL ?>/modules/regulations/ajax.php', { action: 'mark_read', id });
}

function filterTable() {
    const s = $('#filterSearch').val().toLowerCase();
    const cat = $('#filterCategory').val();
    let n = 0;
    $('.table-row').each(function () {
        const nameMatch = !s || $(this).data('name').includes(s);
        const catMatch = !cat || $(this).data('category') === cat;
        const match = nameMatch && catMatch;
        $(this).toggle(match);
        if (match) { n++; $(this).find('.row-num').text(n); }
    });
    $('#rowCount').text(n);
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
