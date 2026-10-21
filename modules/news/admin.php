<?php
$pageTitle  = 'Нийтлэгчид';
$activePage = 'news-admin';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireRole('superadmin');
$pdo = getDB();

$publishers = $pdo->query("
    SELECT e.id, e.employee_code, e.last_name, e.first_name, e.position, ou.name AS unit_name,
           (SELECT COUNT(*) FROM news_posts p WHERE p.author_id = e.id) AS post_count
    FROM user_permissions up
    JOIN permissions pm ON pm.id = up.permission_id AND pm.code = 'news.publish' AND up.effect = 'allow'
    JOIN users u ON u.id = up.user_id
    JOIN employees e ON e.id = u.employee_id
    LEFT JOIN org_units ou ON ou.id = e.org_unit_id
    ORDER BY e.last_name, e.first_name
")->fetchAll();
$candidates = $pdo->query("
    SELECT e.id, e.employee_code, e.last_name, e.first_name, e.position
    FROM employees e JOIN users u ON u.employee_id = e.id AND u.is_active = 1
    WHERE e.is_active = 1 ORDER BY e.last_name, e.first_name
")->fetchAll();

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
?>

<div class="max-w-4xl w-full mx-auto space-y-6">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a><span class="mx-2">/</span>
            <a href="<?= BASE_URL ?>/modules/news/index.php" class="hover:text-[#f1592a]">Мэдээ мэдээлэл</a><span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Нийтлэгчид</span>
        </nav>
        <h1 class="text-3xl font-bold">Нийтлэгчид</h1>
        <p class="text-sm text-slate-500 dark:text-[#8b93a1] mt-1">Мэдээ мэдээлэл нийтлэх эрхтэй ажилтнууд. Superadmin эрхтэй хүмүүс үргэлж нийтлэх боломжтой.</p>
    </div>

    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 flex flex-col sm:flex-row gap-3">
        <input id="empSearch" list="empList" placeholder="Ажилтны нэр эсвэл кодоор хайх..." autocomplete="off"
               class="flex-1 border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
        <datalist id="empList">
            <?php foreach ($candidates as $c): ?>
                <option value="<?= htmlspecialchars(employeeName($c['last_name'], $c['first_name']) . ' (' . $c['employee_code'] . ')') ?>" data-id="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['position'] ?? '') ?></option>
            <?php endforeach; ?>
        </datalist>
        <button type="button" onclick="grantPublisher()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors whitespace-nowrap">Эрх олгох</button>
    </div>

    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
        <?php if (!$publishers): ?>
            <p class="p-10 text-center text-sm text-slate-400 dark:text-[#5a6172]">Одоогоор нийтлэгч томилоогүй байна.</p>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead><tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                <th class="text-left px-5 py-3">Ажилтан</th><th class="text-left px-5 py-3">Нэгж</th><th class="text-center px-5 py-3">Нийтлэл</th><th class="px-5 py-3"></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($publishers as $p): ?>
                <tr>
                    <td class="px-5 py-3"><p class="font-semibold"><?= htmlspecialchars(employeeName($p['last_name'], $p['first_name'])) ?> <span class="text-xs font-normal text-slate-400"><?= htmlspecialchars($p['employee_code']) ?></span></p><p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($p['position'] ?? '') ?></p></td>
                    <td class="px-5 py-3 text-slate-600 dark:text-[#c9cdd6]"><?= htmlspecialchars($p['unit_name'] ?? '—') ?></td>
                    <td class="px-5 py-3 text-center"><?= (int)$p['post_count'] ?></td>
                    <td class="px-5 py-3 text-right"><button type="button" onclick="revokePublisher(<?= (int)$p['id'] ?>)" class="text-xs font-semibold text-red-500 hover:underline">Эрх цуцлах</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
var AJAX = '<?= BASE_URL ?>/modules/news/ajax.php';
function grantPublisher() {
    var val = $('#empSearch').val();
    var opt = $('#empList option').filter(function () { return this.value === val; });
    if (!opt.length) { showToast('Жагсаалтаас ажилтан сонгоно уу.', 'error'); return; }
    $.post(AJAX, { action: 'grant_publisher', employee_id: opt.data('id') }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(function () { location.reload(); }, 500);
    }, 'json');
}
function revokePublisher(id) {
    if (!confirm('Энэ ажилтны нийтлэх эрхийг цуцлах уу? Өмнө нийтэлсэн мэдээнүүд нь хэвээр үлдэнэ.')) return;
    $.post(AJAX, { action: 'revoke_publisher', employee_id: id }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(function () { location.reload(); }, 500);
    }, 'json');
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
