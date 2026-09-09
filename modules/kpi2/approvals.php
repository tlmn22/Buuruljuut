<?php
$pageTitle  = 'KPI2 — KPI батлах хүсэлтүүд';
$activePage = 'kpi2-approvals';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/evaluator.php';
requireLogin();
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];
$isAdmin = isHR() || isSuperAdmin();

if (!$isAdmin && !kpi2IsDirector($pdo, $myEmployeeId)) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
?>
<style>
    .ql-editor-view { padding: 2px 0; font-size: 13px; line-height: 1.5; }
    .ql-editor-view p { margin: 0 0 4px; }
    .ql-editor-view ul, .ql-editor-view ol { padding-left: 1.2em; margin: 0 0 4px; }
</style>
<?php
$managedUnitIds = $isAdmin ? [] : kpi2ManagedOrgUnitIds($pdo, $myEmployeeId);

if ($isAdmin) {
    $allBatches = $pdo->query("
        SELECT b.*, p.name as period_name, p.start_date as period_start, ou.name as unit_name
        FROM kpi2_task_batches b
        JOIN kpi2_periods p ON p.id = b.period_id
        JOIN org_units ou ON ou.id = b.org_unit_id
        ORDER BY FIELD(b.status,'pending','draft','rejected','approved'), b.submitted_at DESC, b.id DESC
    ")->fetchAll();
} elseif ($managedUnitIds) {
    $ph = implode(',', array_fill(0, count($managedUnitIds), '?'));
    $stmt = $pdo->prepare("
        SELECT b.*, p.name as period_name, p.start_date as period_start, ou.name as unit_name
        FROM kpi2_task_batches b
        JOIN kpi2_periods p ON p.id = b.period_id
        JOIN org_units ou ON ou.id = b.org_unit_id
        WHERE b.org_unit_id IN ($ph)
        ORDER BY FIELD(b.status,'pending','draft','rejected','approved'), b.submitted_at DESC, b.id DESC
    ");
    $stmt->execute($managedUnitIds);
    $allBatches = $stmt->fetchAll();
} else {
    $allBatches = [];
}

// Улирлаар шүүх сонголт — эрхийн хамрах хүрээн доторх батчуудад байгаа улирлуудаас гаргана
$periodOptions = [];
foreach ($allBatches as $b) {
    $periodOptions[$b['period_id']] = ['name' => $b['period_name'], 'start' => $b['period_start']];
}
uasort($periodOptions, fn($a, $b) => $b['start'] <=> $a['start']);

$selectedPeriodId = intval($_GET['period_id'] ?? 0);
$batches = $selectedPeriodId
    ? array_values(array_filter($allBatches, fn($b) => (int)$b['period_id'] === $selectedPeriodId))
    : $allBatches;

// Батч бүрийн ажил + оноолтыг урьдчилж татаж авна (нэг дор дэлгэрэнгүйг нь эхлэн харуулах зорилгоор)
$tasksByBatch = [];
$assignmentsByTask = [];
if ($batches) {
    $batchIds = array_column($batches, 'id');
    $ph = implode(',', array_fill(0, count($batchIds), '?'));

    $t = $pdo->prepare("SELECT * FROM kpi2_org_tasks WHERE batch_id IN ($ph) ORDER BY sort_order, id");
    $t->execute($batchIds);
    $allTasks = $t->fetchAll();
    foreach ($allTasks as $task) { $tasksByBatch[$task['batch_id']][] = $task; }

    if ($allTasks) {
        $taskIds = array_column($allTasks, 'id');
        $ph2 = implode(',', array_fill(0, count($taskIds), '?'));
        $a = $pdo->prepare("SELECT a.*, e.last_name, e.first_name, e.employee_code
                             FROM kpi2_org_task_assignments a JOIN employees e ON e.id = a.employee_id
                             WHERE a.org_task_id IN ($ph2) ORDER BY e.last_name");
        $a->execute($taskIds);
        foreach ($a->fetchAll() as $row) { $assignmentsByTask[$row['org_task_id']][] = $row; }
    }
}

$statusMeta = [
    'draft'    => ['label' => 'Ноорог',         'badge' => 'bg-slate-100 dark:bg-[#272c38] text-slate-600 dark:text-[#c9cdd6]',        'bar' => 'bg-slate-300 dark:bg-[#3a4151]'],
    'pending'  => ['label' => 'Хүлээгдэж байна', 'badge' => 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400',      'bar' => 'bg-amber-400'],
    'approved' => ['label' => 'Батлагдсан',      'badge' => 'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400',      'bar' => 'bg-green-500'],
    'rejected' => ['label' => 'Буцаагдсан',      'badge' => 'bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400',              'bar' => 'bg-red-400'],
];

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'draft' => 0];
foreach ($batches as $b) { $counts[$b['status']]++; }
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">KPI2 — KPI батлах хүсэлтүүд</span>
        </nav>
        <h1 class="text-3xl font-bold">KPI батлах хүсэлтүүд <span class="text-sm font-normal text-slate-400 dark:text-[#5a6172]">(туршилтын)</span></h1>
    </div>
    <?php if ($periodOptions): ?>
    <select onchange="location.href='?period_id='+this.value"
            class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
        <option value="0" <?= $selectedPeriodId === 0 ? 'selected' : '' ?>>Бүх улирал</option>
        <?php foreach ($periodOptions as $pid => $p): ?>
            <option value="<?= $pid ?>" <?= $selectedPeriodId === (int)$pid ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400">
            <span class="material-icons-outlined">hourglass_top</span>
        </div>
        <div class="min-w-0"><p class="text-2xl font-bold leading-none"><?= $counts['pending'] ?></p><p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1.5">Хүлээгдэж байна</p></div>
    </div>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 bg-green-50 dark:bg-green-950/30 text-green-600 dark:text-green-400">
            <span class="material-icons-outlined">task_alt</span>
        </div>
        <div class="min-w-0"><p class="text-2xl font-bold leading-none"><?= $counts['approved'] ?></p><p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1.5">Батлагдсан</p></div>
    </div>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 bg-red-50 dark:bg-red-950/30 text-red-500 dark:text-red-400">
            <span class="material-icons-outlined">undo</span>
        </div>
        <div class="min-w-0"><p class="text-2xl font-bold leading-none"><?= $counts['rejected'] ?></p><p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1.5">Буцаагдсан</p></div>
    </div>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 bg-slate-100 dark:bg-[#272c38] text-slate-500 dark:text-[#8b93a1]">
            <span class="material-icons-outlined">edit_note</span>
        </div>
        <div class="min-w-0"><p class="text-2xl font-bold leading-none"><?= $counts['draft'] ?></p><p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1.5">Ноорог</p></div>
    </div>
</div>

<?php if (empty($batches)): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">inbox</span>
        Ажлын багц байхгүй байна
    </div>
<?php else: ?>
<div class="space-y-3">
    <?php foreach ($batches as $b): $tasks = $tasksByBatch[$b['id']] ?? []; $isPending = $b['status'] === 'pending'; ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden flex batch-card" data-status="<?= $b['status'] ?>">
        <div class="w-1.5 flex-shrink-0 <?= $statusMeta[$b['status']]['bar'] ?>"></div>
        <div class="flex-1 min-w-0">
            <button type="button" onclick="toggleBatch(<?= $b['id'] ?>)" class="w-full flex items-center justify-between gap-3 px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-bold"><?= htmlspecialchars($b['unit_name']) ?></span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusMeta[$b['status']]['badge'] ?>"><?= $statusMeta[$b['status']]['label'] ?></span>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1">
                        <?= htmlspecialchars($b['period_name']) ?> · <?= count($tasks) ?> ажил
                        <?php if ($b['status'] === 'rejected' && $b['review_note']): ?> · <span class="text-red-500">Шалтгаан: <?= htmlspecialchars($b['review_note']) ?></span><?php endif; ?>
                    </p>
                </div>
                <span class="material-icons-outlined batch-chevron text-slate-400 dark:text-[#5a6172] transition-transform duration-200 flex-shrink-0">expand_more</span>
            </button>
            <div id="batch-body-<?= $b['id'] ?>" class="hidden border-t border-slate-100 dark:border-[#2a2f3b]">
                <?php if (empty($tasks)): ?>
                    <div class="py-8 text-center text-slate-400 dark:text-[#5a6172]">Ажил байхгүй байна</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                            <th class="text-left px-4 py-3 w-8">№</th>
                            <th class="text-left px-4 py-3">Ажил / зорилт</th>
                            <th class="text-left px-4 py-3 w-32">Давтамж</th>
                            <th class="text-center px-3 py-3 w-20">Ач холбогдол</th>
                            <th class="text-center px-3 py-3 w-20">Хүндрэл</th>
                            <th class="text-left px-4 py-3 w-64">Ажилтнууд</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tasks as $i => $task): $assigns = $assignmentsByTask[$task['id']] ?? []; ?>
                        <tr class="border-b border-slate-100 dark:border-[#2a2f3b] last:border-b-0 align-top">
                            <td class="px-4 py-3 text-center text-slate-400 dark:text-[#5a6172]"><?= $i + 1 ?></td>
                            <td class="px-4 py-3">
                                <p class="font-medium"><?= htmlspecialchars($task['title']) ?></p>
                                <?php if ($task['kpi_target']): ?>
                                    <div class="ql-editor-view text-slate-500 dark:text-[#8b93a1] mt-1 max-w-md"><?= $task['kpi_target'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-[#8b93a1]"><?= htmlspecialchars(KPI2_FREQUENCY_LABELS[$task['frequency']] ?? '—') ?></td>
                            <td class="px-3 py-3 text-center font-medium"><?= (int)$task['importance_weight'] ?></td>
                            <td class="px-3 py-3 text-center font-medium"><?= (int)$task['difficulty_weight'] ?></td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach ($assigns as $a): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 bg-slate-100 dark:bg-[#272c38] rounded-full text-xs whitespace-nowrap">
                                            <?= htmlspecialchars($a['last_name'] . '. ' . $a['first_name']) ?> · <?= rtrim(rtrim(number_format((float)$a['percent'], 1), '0'), '.') ?>%
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
                <?php if ($isPending): ?>
                    <div class="px-5 py-3.5 bg-slate-50 dark:bg-[#14171f] flex items-center justify-end gap-2">
                        <button onclick="rejectBatch(<?= $b['id'] ?>)" class="border border-slate-200 dark:border-[#2a2f3b] px-4 py-2 rounded-lg text-sm font-medium hover:bg-white dark:hover:bg-[#272c38]">Буцаах</button>
                        <button onclick="approveBatch(<?= $b['id'] ?>)" class="bg-[#f1592a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12]">Батлах</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Буцаах шалтгаан MODAL -->
<div id="rejectModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-8">
        <h2 class="text-xl font-bold mb-4">Багцыг буцаах</h2>
        <textarea id="rejectNote" rows="3" placeholder="Шалтгаанаа бичнэ үү..." class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm resize-y"></textarea>
        <div class="flex gap-3 pt-4">
            <button onclick="closeRejectModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
            <button onclick="confirmReject()" class="flex-1 py-2.5 bg-red-500 text-white rounded-lg font-medium hover:bg-red-600">Буцаах</button>
        </div>
    </div>
</div>

<script>
const AJAX = '<?= BASE_URL ?>/modules/kpi2/ajax.php';
let rejectBatchId = null;

function toggleBatch(id) {
    const $body = $('#batch-body-' + id);
    const $chevron = $body.closest('.batch-card').find('.batch-chevron');
    $body.slideToggle(150);
    $chevron.toggleClass('rotate-180');
}

function approveBatch(id) {
    if (!confirm('Энэ ажлын багцыг батлах уу? Батлагдсаны дараа тухайн ажлууд ажилтнуудын KPI2 дээр автоматаар орно.')) return;
    $.post(AJAX, { action: 'batch_approve', batch_id: id }, function(r) {
        showToast(r.message || '', r.success ? 'success' : 'error');
        if (r.success) location.reload();
    }, 'json');
}

function rejectBatch(id) { rejectBatchId = id; $('#rejectNote').val(''); $('#rejectModal').css('display', 'flex'); }
function closeRejectModal() { $('#rejectModal').hide(); }
$('#rejectModal').on('click', function(e) { if ($(e.target).is('#rejectModal')) closeRejectModal(); });

function confirmReject() {
    $.post(AJAX, { action: 'batch_reject', batch_id: rejectBatchId, note: $('#rejectNote').val() }, function(r) {
        showToast(r.message || '', r.success ? 'success' : 'error');
        if (r.success) { closeRejectModal(); location.reload(); }
    }, 'json');
}

// Хүлээгдэж буй эхний батчийг анхнаасаа нээлттэй харуулна
$(function () {
    const $first = $('.batch-card[data-status="pending"]').first();
    if ($first.length) {
        $first.find('[id^="batch-body-"]').show();
        $first.find('.batch-chevron').addClass('rotate-180');
    }
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
