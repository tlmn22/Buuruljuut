<?php
$pageTitle  = 'Тээвэр — тохиргоо';
$activePage = 'transport-admin';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireRole('superadmin');
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();

$roleRows = $pdo->query("
    SELECT tr.*, e.last_name, e.first_name, e.employee_code, e.position
    FROM transport_roles tr JOIN employees e ON e.id = tr.employee_id
    ORDER BY tr.role, e.last_name
")->fetchAll();
$directors = array_filter($roleRows, fn($r) => $r['role'] === 'director');
$transportManagers = array_filter($roleRows, fn($r) => $r['role'] === 'transport_manager');

$employees = $pdo->query("SELECT id, employee_code, last_name, first_name, position FROM employees WHERE is_active=1 ORDER BY last_name, first_name")->fetchAll();
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Тээвэр — тохиргоо</span>
        </nav>
        <h1 class="text-3xl font-bold">Тээврийн захиалгын тохиргоо</h1>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <?php foreach ([['director', 'Тээвэр хариуцсан захирал', $directors], ['transport_manager', 'Тээвэр хариуцсан менежер', $transportManagers]] as [$roleKey, $roleLabel, $rows]): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
            <h3 class="font-bold"><?= $roleLabel ?></h3>
        </div>
        <div class="p-4 space-y-2">
            <?php if (empty($rows)): ?>
                <p class="text-sm text-slate-400 dark:text-[#5a6172] px-2 py-2">Хэн ч оноогоогүй байна.</p>
            <?php else: foreach ($rows as $r): ?>
                <div class="flex items-center justify-between px-3 py-2 bg-slate-50 dark:bg-[#14171f] rounded-lg">
                    <div>
                        <p class="font-medium text-sm"><?= htmlspecialchars($r['last_name'] . '. ' . $r['first_name']) ?></p>
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($r['position'] ?? '') ?></p>
                    </div>
                    <button onclick="removeRole(<?= $r['id'] ?>)" class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 p-1.5 rounded-lg">
                        <span class="material-icons-outlined" style="font-size:16px">close</span>
                    </button>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="px-4 pb-4 flex gap-2">
            <select class="add-role-select flex-1 border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm" data-role="<?= $roleKey ?>">
                <option value="">— ажилтан сонгох —</option>
                <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['last_name'] . '. ' . $e['first_name'] . ($e['position'] ? ' — ' . $e['position'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="addRole(this)" class="bg-[#f1592a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12]">Нэмэх</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
const AJAX = '<?= BASE_URL ?>/modules/transport/ajax.php';

function addRole(btn) {
    const $select = $(btn).closest('div').find('.add-role-select');
    const employeeId = $select.val();
    const role = $select.data('role');
    if (!employeeId) { showToast('Ажилтан сонгоно уу.', 'error'); return; }
    $.post(AJAX, { action: 'role_set', employee_id: employeeId, role }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}

function removeRole(id) {
    if (!confirm('Хасахдаа итгэлтэй байна уу?')) return;
    $.post(AJAX, { action: 'role_remove', id }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
