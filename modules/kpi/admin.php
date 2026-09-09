<?php
$pageTitle  = 'KPI тохиргоо';
$activePage = 'kpi-admin';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireRole('superadmin', 'hr');
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();

$periods = $pdo->query("SELECT * FROM kpi_periods ORDER BY start_date DESC")->fetchAll();

$employees = $pdo->query("
    SELECT e.id, e.employee_code, e.last_name, e.first_name, e.position, e.default_evaluator_id, e.evaluator_source, ou.name as unit_name
    FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
    WHERE e.is_active = 1
    ORDER BY e.last_name, e.first_name
")->fetchAll();
$evaluatorOptions = $employees; // хэн ч үнэлэгч байж болно
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">KPI тохиргоо</span>
        </nav>
        <h1 class="text-3xl font-bold">KPI тохиргоо</h1>
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
                    <th class="text-left px-6 py-3">Жин (KPI/Чиг үүрэг/Бонус)</th>
                    <th class="text-left px-6 py-3">Төлөв</th>
                    <th class="text-right px-6 py-3">Үйлдэл</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($periods)): ?>
                <tr><td colspan="6" class="text-center py-10 text-slate-400 dark:text-[#5a6172]">Хугацаа байхгүй байна</td></tr>
            <?php else: foreach ($periods as $p): ?>
                <tr class="border-b border-slate-100 dark:border-[#2a2f3b]">
                    <td class="px-6 py-3 font-medium"><?= htmlspecialchars($p['name']) ?></td>
                    <td class="px-6 py-3"><?= htmlspecialchars($p['start_date']) ?></td>
                    <td class="px-6 py-3"><?= htmlspecialchars($p['end_date']) ?></td>
                    <td class="px-6 py-3 text-slate-500 dark:text-[#8b93a1]">
                        <?= (int)($p['personal_kpi_weight']*100) ?>% / <?= (int)($p['core_duty_weight']*100) ?>% / +<?= (int)($p['special_task_weight']*100) ?>%
                    </td>
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

<!-- Үнэлэгч тохиргоо -->
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between gap-3 flex-wrap">
        <h3 class="font-bold">Үнэлэгч тохиргоо — хэн хэнийг үнэлэхийг тохируулна</h3>
        <input type="text" id="evalSearch" onkeyup="filterEvalTable()" placeholder="Ажилтан хайх..."
               class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm w-56"/>
    </div>
    <div class="overflow-x-auto max-h-[520px] overflow-y-auto">
        <table class="w-full text-sm">
            <thead class="sticky top-0">
                <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                    <th class="text-left px-6 py-3">Ажилтан</th>
                    <th class="text-left px-6 py-3">Нэгж</th>
                    <th class="text-left px-6 py-3 w-64">Үнэлэгч</th>
                    <th class="text-left px-6 py-3 w-36">Эх сурвалж</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $e): $isManual = $e['evaluator_source'] === 'manual'; ?>
                <tr class="border-b border-slate-100 dark:border-[#2a2f3b] eval-row" data-name="<?= strtolower(htmlspecialchars($e['last_name'].' '.$e['first_name'].' '.$e['employee_code'])) ?>">
                    <td class="px-6 py-3">
                        <p class="font-medium"><?= htmlspecialchars($e['last_name'] . '. ' . $e['first_name']) ?></p>
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($e['position'] ?? '') ?></p>
                    </td>
                    <td class="px-6 py-3 text-slate-500 dark:text-[#8b93a1]"><?= htmlspecialchars($e['unit_name'] ?? '—') ?></td>
                    <td class="px-6 py-3">
                        <select class="evaluator-select w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-2 py-1.5 text-sm" data-employee="<?= $e['id'] ?>">
                            <option value="">-- Үнэлэгч сонгоогүй --</option>
                            <?php foreach ($evaluatorOptions as $ev): if ($ev['id'] == $e['id']) continue; ?>
                                <option value="<?= $ev['id'] ?>" <?= (int)$e['default_evaluator_id'] === (int)$ev['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ev['last_name'] . '. ' . $ev['first_name'] . ($ev['position'] ? ' — ' . $ev['position'] : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="px-6 py-3 source-cell" data-employee="<?= $e['id'] ?>">
                        <?php if ($isManual): ?>
                            <div class="flex items-center gap-1.5">
                                <span class="inline-flex items-center px-2 py-1 bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400 rounded-full text-xs font-medium">Гараар</span>
                                <button type="button" onclick="resetEvaluatorAuto(<?= $e['id'] ?>)" title="Хэлтсийн удирдагч руу автоматаар буцаах"
                                        class="text-slate-400 hover:text-[#f1592a] p-1 rounded-lg hover:bg-slate-50 dark:hover:bg-[#272c38]">
                                    <span class="material-icons-outlined" style="font-size:16px">restart_alt</span>
                                </button>
                            </div>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2 py-1 bg-slate-100 dark:bg-[#272c38] text-slate-500 dark:text-[#8b93a1] rounded-full text-xs font-medium">Автомат</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="px-6 py-3 text-xs text-slate-400 dark:text-[#8b93a1] border-t border-slate-100 dark:border-[#2a2f3b]">
        <strong>Автомат</strong> — ажилтны нэгжийн удирдагч (алба/хэлтсийн захирал) автоматаар үнэлэгч болно; нэгжид удирдагч байхгүй бол эцэг нэгж рүү өгсөж хайна.
        <strong>Гараар</strong> сонгосныг систем хадгалж, удирдагч солигдоход дахин дарж бичихгүй.
    </p>
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
                <input type="text" id="periodName" required class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm" placeholder="Жишээ: 2026 оны эхний хагас жил"/>
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
    $.post('<?= BASE_URL ?>/modules/kpi/ajax.php', { action: 'period_delete', id }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}

$('#periodForm').on('submit', function(e) {
    e.preventDefault();
    const id = $('#periodId').val();
    $.post('<?= BASE_URL ?>/modules/kpi/ajax.php', {
        action: id ? 'period_update' : 'period_create', id,
        name: $('#periodName').val(), start_date: $('#periodStart').val(), end_date: $('#periodEnd').val(),
        is_active: $('#periodActive').is(':checked') ? 1 : 0
    }, function(r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) { closePeriodModal(); setTimeout(() => location.reload(), 500); }
    }, 'json');
});

$(document).on('change', '.evaluator-select', function () {
    const employeeId = $(this).data('employee');
    const evaluatorId = $(this).val();
    $.post('<?= BASE_URL ?>/modules/kpi/ajax.php', { action: 'set_evaluator', employee_id: employeeId, evaluator_id: evaluatorId }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
});

function resetEvaluatorAuto(employeeId) {
    if (!confirm('Автомат горимд буцааж, хэлтсийн удирдагчаар үнэлэгчийг дахин тооцоолох уу?')) return;
    $.post('<?= BASE_URL ?>/modules/kpi/ajax.php', { action: 'reset_evaluator_auto', employee_id: employeeId }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}

function filterEvalTable() {
    const s = $('#evalSearch').val().toLowerCase();
    $('.eval-row').each(function () {
        $(this).toggle(!s || $(this).data('name').includes(s));
    });
}

$('#periodModal').on('click', function(e) { if($(e.target).is('#periodModal')) closePeriodModal(); });
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
