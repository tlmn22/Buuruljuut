<?php
$pageTitle  = 'KPI2 тохиргоо';
$activePage = 'kpi2-admin';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireRole('superadmin', 'hr');
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();

$periods = $pdo->query("SELECT * FROM kpi2_periods ORDER BY start_date DESC")->fetchAll();

$units = $pdo->query("SELECT id, parent_id, name FROM org_units WHERE is_active=1 ORDER BY parent_id IS NULL DESC, sort_order, name")->fetchAll();
$employees = $pdo->query("SELECT id, employee_code, last_name, first_name, position FROM employees WHERE is_active=1 ORDER BY last_name, first_name")->fetchAll();

$roleRows = $pdo->query("SELECT * FROM kpi2_unit_roles")->fetchAll();
$rolesByUnit = [];
foreach ($roleRows as $rr) { $rolesByUnit[$rr['org_unit_id']][$rr['role']] = $rr; }

$unitDepth = [];
$unitsById = [];
foreach ($units as $u) { $unitsById[$u['id']] = $u; }
foreach ($units as $u) {
    $depth = 0; $p = $u['parent_id'];
    while ($p && isset($unitsById[$p]) && $depth < 10) { $depth++; $p = $unitsById[$p]['parent_id']; }
    $unitDepth[$u['id']] = $depth;
}
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">KPI2 тохиргоо</span>
        </nav>
        <h1 class="text-3xl font-bold">KPI2 тохиргоо <span class="text-sm font-normal text-slate-400 dark:text-[#5a6172]">(туршилтын)</span></h1>
    </div>
</div>

<!-- Хугацаанууд -->
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
        <h3 class="font-bold">Үнэлгээний хугацаанууд</h3>
        <button onclick="openPeriodModal()" class="bg-[#f1592a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors flex items-center gap-1.5">
            <span class="material-icons-outlined" style="font-size:16px">add_circle</span> Хугацаа нэмэх
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                    <th class="text-left px-6 py-3">Нэр</th>
                    <th class="text-left px-6 py-3">Эхлэх</th>
                    <th class="text-left px-6 py-3">Дуусах</th>
                    <th class="text-left px-6 py-3">Төлөв</th>
                    <th class="text-right px-6 py-3">Үйлдэл</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($periods)): ?>
                <tr><td colspan="5" class="text-center py-10 text-slate-400 dark:text-[#5a6172]">Хугацаа байхгүй байна</td></tr>
            <?php else: foreach ($periods as $p): ?>
                <tr class="border-b border-slate-100 dark:border-[#2a2f3b]">
                    <td class="px-6 py-3 font-medium"><?= htmlspecialchars($p['name']) ?></td>
                    <td class="px-6 py-3"><?= htmlspecialchars($p['start_date']) ?></td>
                    <td class="px-6 py-3"><?= htmlspecialchars($p['end_date']) ?></td>
                    <td class="px-6 py-3">
                        <?php if ($p['is_active']): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400 rounded-full text-xs font-medium">Идэвхтэй</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400 rounded-full text-xs font-medium">Идэвхгүй</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-right space-x-1">
                        <button onclick='editPeriod(<?= json_encode($p) ?>)' class="inline-flex p-2 text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38] rounded-lg transition-colors">
                            <span class="material-icons-outlined" style="font-size:18px">edit</span>
                        </button>
                        <button onclick="deletePeriod(<?= $p['id'] ?>)" class="inline-flex p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition-colors">
                            <span class="material-icons-outlined" style="font-size:18px">delete</span>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Нэгж тус бүрийн KPI2 manager/director -->
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b]">
        <h3 class="font-bold">Нэгж тус бүрийн KPI2 manager / director</h3>
        <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1">Энэ бол жинхэнэ байгууллагын удирдлагаас тусдаа, зөвхөн KPI2 туршилтад ашиглагдана.</p>
    </div>
    <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
        <table class="w-full text-sm">
            <thead class="sticky top-0">
                <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                    <th class="text-left px-6 py-3">Нэгж</th>
                    <th class="text-left px-6 py-3 w-64">Manager (Хэлтсийн ажил төлөвлөгч)</th>
                    <th class="text-left px-6 py-3 w-64">Director (Батлагч)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($units as $u): $depth = $unitDepth[$u['id']]; ?>
                <tr class="border-b border-slate-100 dark:border-[#2a2f3b]">
                    <td class="px-6 py-3" style="padding-left: <?= 24 + $depth * 20 ?>px">
                        <span class="font-medium"><?= htmlspecialchars($u['name']) ?></span>
                    </td>
                    <?php foreach (['manager', 'director'] as $roleKey): $cur = $rolesByUnit[$u['id']][$roleKey] ?? null; ?>
                    <td class="px-6 py-3">
                        <select class="unit-role-select w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-2 py-1.5 text-sm"
                                data-unit="<?= $u['id'] ?>" data-role="<?= $roleKey ?>" data-role-id="<?= $cur['id'] ?? '' ?>">
                            <option value="">— сонгоогүй —</option>
                            <?php foreach ($employees as $ev): ?>
                                <option value="<?= $ev['id'] ?>" <?= $cur && (int)$cur['employee_id'] === (int)$ev['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ev['last_name'] . '. ' . $ev['first_name'] . ($ev['position'] ? ' — ' . $ev['position'] : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PERIOD MODAL -->
<div id="periodModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-md mx-4 p-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold" id="periodModalTitle">Хугацаа нэмэх</h2>
            <button onclick="closePeriodModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg"><span class="material-icons-outlined">close</span></button>
        </div>
        <form id="periodForm" class="space-y-4">
            <input type="hidden" id="periodId" value=""/>
            <div>
                <label class="block text-sm font-medium mb-1.5">Нэр <span class="text-red-500">*</span></label>
                <input type="text" id="periodName" required class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm" placeholder="Жишээ: 2026 оны 1-р улирал (KPI2 туршилт)"/>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Эхлэх огноо <span class="text-red-500">*</span></label>
                    <input type="date" id="periodStart" required class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Дуусах огноо <span class="text-red-500">*</span></label>
                    <input type="date" id="periodEnd" required class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm"/>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <input type="checkbox" id="periodActive" checked class="w-4 h-4 rounded cursor-pointer"/>
                <label for="periodActive" class="text-sm font-medium cursor-pointer">Идэвхтэй</label>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closePeriodModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<script>
const AJAX = '<?= BASE_URL ?>/modules/kpi2/ajax.php';

function openPeriodModal() {
    $('#periodModal').css('display', 'flex');
    $('#periodModalTitle').text('Хугацаа нэмэх');
    $('#periodId,#periodName,#periodStart,#periodEnd').val('');
    $('#periodActive').prop('checked', true);
}
function closePeriodModal() { $('#periodModal').hide(); }
function editPeriod(p) {
    openPeriodModal();
    $('#periodModalTitle').text('Хугацаа засах');
    $('#periodId').val(p.id);
    $('#periodName').val(p.name);
    $('#periodStart').val(p.start_date);
    $('#periodEnd').val(p.end_date);
    $('#periodActive').prop('checked', p.is_active == 1);
}
function deletePeriod(id) {
    if (!confirm('Устгахдаа итгэлтэй байна уу?')) return;
    $.post(AJAX, { action: 'period_delete', id }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}

$('#periodForm').on('submit', function(e) {
    e.preventDefault();
    const id = $('#periodId').val();
    $.post(AJAX, {
        action: id ? 'period_update' : 'period_create', id,
        name: $('#periodName').val(), start_date: $('#periodStart').val(), end_date: $('#periodEnd').val(),
        is_active: $('#periodActive').is(':checked') ? 1 : 0
    }, function(r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) { closePeriodModal(); setTimeout(() => location.reload(), 500); }
    }, 'json');
});

$(document).on('change', '.unit-role-select', function () {
    const $sel = $(this);
    const orgUnitId = $sel.data('unit');
    const role = $sel.data('role');
    const employeeId = $sel.val();
    const roleId = $sel.data('role-id');

    if (!employeeId) {
        if (!roleId) return;
        $.post(AJAX, { action: 'unit_role_remove', id: roleId }, function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) $sel.data('role-id', '');
        }, 'json');
        return;
    }

    $.post(AJAX, { action: 'unit_role_set', org_unit_id: orgUnitId, employee_id: employeeId, role }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
});

$('#periodModal').on('click', function(e) { if($(e.target).is('#periodModal')) closePeriodModal(); });
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
