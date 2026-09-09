<?php
$pageTitle  = 'KPI2 — Хэлтсийн ажлаа төлөвлөх';
$activePage = 'kpi2-manager';
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

// Миний удирддаг нэгжүүд (эсвэл HR/superadmin бол бүх Хэлтэс/Алба)
if ($isAdmin) {
    $myUnits = $pdo->query("SELECT id, name FROM org_units WHERE is_active=1 ORDER BY sort_order, name")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT ou.id, ou.name FROM kpi2_unit_roles r JOIN org_units ou ON ou.id = r.org_unit_id
                            WHERE r.role='manager' AND r.employee_id=? ORDER BY ou.name");
    $stmt->execute([$myEmployeeId]);
    $myUnits = $stmt->fetchAll();
}

if (empty($myUnits)) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<style>
    .ql-editor-view { padding: 2px 0; font-size: 13px; line-height: 1.5; }
    .ql-editor-view p { margin: 0 0 4px; }
    .ql-editor-view ul, .ql-editor-view ol { padding-left: 1.2em; margin: 0 0 4px; }
    #taskTargetEditor .ql-editor { min-height: 140px; }
    html.dark .ql-toolbar.ql-snow { border-color: #2a2f3b; background: #272c38; }
    html.dark .ql-toolbar.ql-snow .ql-stroke { stroke: #c9cdd6; }
    html.dark .ql-toolbar.ql-snow .ql-fill { fill: #c9cdd6; }
    html.dark .ql-toolbar.ql-snow .ql-picker { color: #c9cdd6; }
    html.dark .ql-container.ql-snow { border-color: #2a2f3b; }
    html.dark .ql-editor { color: #f2f3f6; }
    html.dark .ql-editor.ql-blank::before { color: #5a6172; }
</style>
<?php
$periods = $pdo->query("SELECT * FROM kpi2_periods WHERE is_active=1 ORDER BY start_date DESC")->fetchAll();
$selectedPeriodId = intval($_GET['period_id'] ?? 0) ?: ($periods[0]['id'] ?? 0);
$selectedUnitId   = intval($_GET['unit_id'] ?? 0) ?: (int)$myUnits[0]['id'];

$batch = null;
$tasks = [];
$assignmentsByTask = [];
$unitEmployees = [];
if ($selectedPeriodId && $selectedUnitId) {
    $b = $pdo->prepare("SELECT * FROM kpi2_task_batches WHERE period_id=? AND org_unit_id=?");
    $b->execute([$selectedPeriodId, $selectedUnitId]);
    $batch = $b->fetch();

    if ($batch) {
        $t = $pdo->prepare("SELECT * FROM kpi2_org_tasks WHERE batch_id=? ORDER BY sort_order, id");
        $t->execute([$batch['id']]);
        $tasks = $t->fetchAll();
        foreach ($tasks as $task) {
            $a = $pdo->prepare("SELECT a.*, e.last_name, e.first_name, e.employee_code
                                 FROM kpi2_org_task_assignments a JOIN employees e ON e.id = a.employee_id
                                 WHERE a.org_task_id=? ORDER BY e.last_name");
            $a->execute([$task['id']]);
            $assignmentsByTask[$task['id']] = $a->fetchAll();
        }
    }

    $e = $pdo->prepare("SELECT id, employee_code, last_name, first_name FROM employees WHERE org_unit_id=? AND is_active=1 ORDER BY last_name, first_name");
    $e->execute([$selectedUnitId]);
    $unitEmployees = $e->fetchAll();
}

$editable = $batch && in_array($batch['status'], ['draft', 'rejected'], true);
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">KPI2 — Хэлтсийн ажлаа төлөвлөх</span>
        </nav>
        <h1 class="text-3xl font-bold">Хэлтсийн ажлаа төлөвлөх <span class="text-sm font-normal text-slate-400 dark:text-[#5a6172]">(туршилтын)</span></h1>
    </div>
    <div class="flex gap-2">
        <select onchange="location.href='?period_id='+this.value+'&unit_id=<?= $selectedUnitId ?>'"
                class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
            <?php foreach ($periods as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $p['id'] == $selectedPeriodId ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select onchange="location.href='?period_id=<?= $selectedPeriodId ?>&unit_id='+this.value"
                class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
            <?php foreach ($myUnits as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $u['id'] == $selectedUnitId ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if (!$periods): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        Идэвхтэй KPI2 хугацаа тохируулаагүй байна. HR-т хандана уу.
    </div>
<?php elseif (!$batch): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center">
        <span class="material-icons-outlined block mb-3 text-slate-300 dark:text-[#5a6172]" style="font-size:48px">assignment</span>
        <p class="text-slate-500 dark:text-[#8b93a1] mb-4">Энэ нэгж, хугацаанд улирлын ажил төлөвлөгдөөгүй байна.</p>
        <button onclick="createBatch()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors">
            Улирлын ажил
        </button>
    </div>
<?php else: ?>

<?php
$batchWizardSteps = [
    ['label' => 'Ноорог',            'actor' => 'Хэлтсийн ажилтнуудын төлөвлөгөө гаргах'],
    ['label' => 'Хянагдаж байна',    'actor' => 'Газрын захирал батлахыг хүлээж байна'],
    ['label' => 'Батлагдсан',        'actor' => 'Ажлууд ажилтнуудын KPI дээр орсон'],
];
$batchCurrentStep = match ($batch['status']) {
    'draft', 'rejected' => 0,
    'pending'            => 1,
    'approved'           => 2,
    default              => 0,
};
?>
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-6">
    <?php if ($batch['status'] === 'rejected'): ?>
        <div class="mb-5 px-4 py-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/40 rounded-lg flex items-start gap-2">
            <span class="material-icons-outlined text-red-500" style="font-size:18px">error_outline</span>
            <p class="text-sm text-red-600 dark:text-red-400">
                Газрын захирал буцаасан.
                <?php if ($batch['review_note']): ?>Шалтгаан: <?= htmlspecialchars($batch['review_note']) ?><?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="flex items-start mb-6">
        <?php foreach ($batchWizardSteps as $i => $step):
            $isDone   = $i < $batchCurrentStep;
            $isActive = $i === $batchCurrentStep;
            $circleClass = $isDone
                ? 'bg-[#f1592a] text-white'
                : ($isActive
                    ? 'bg-white dark:bg-[#1c212b] border-2 border-[#f1592a] text-[#f1592a]'
                    : 'bg-slate-100 dark:bg-[#272c38] text-slate-400 dark:text-[#5a6172]');
            $labelClass = $isDone || $isActive ? 'text-[#1c2430] dark:text-[#f2f3f6]' : 'text-slate-400 dark:text-[#5a6172]';
        ?>
        <?php if ($i > 0): ?>
            <div class="flex-1 h-0.5 mt-[18px] <?= $i <= $batchCurrentStep ? 'bg-[#f1592a]' : 'bg-slate-200 dark:bg-[#2a2f3b]' ?>"></div>
        <?php endif; ?>
        <div class="flex flex-col items-center flex-none w-40">
            <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0 <?= $circleClass ?>">
                <?php if ($isDone): ?>
                    <span class="material-icons-outlined" style="font-size:18px">check</span>
                <?php else: ?>
                    <?= $i + 1 ?>
                <?php endif; ?>
            </div>
            <p class="text-xs font-semibold mt-2 text-center <?= $labelClass ?>"><?= $step['label'] ?></p>
            <p class="text-[11px] text-slate-400 dark:text-[#5a6172] text-center"><?= $step['actor'] ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
        <h3 class="font-bold">Улирлын ажлууд</h3>
        <?php if ($editable): ?>
            <button onclick="openTaskModal(null)" class="text-sm font-medium text-[#f1592a] flex items-center gap-1 hover:underline">
                <span class="material-icons-outlined" style="font-size:16px">add_circle</span> Ажил нэмэх
            </button>
        <?php endif; ?>
    </div>
    <?php if (empty($tasks)): ?>
        <div class="py-12 text-center text-slate-400 dark:text-[#5a6172]">Ажил байхгүй байна</div>
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
                <?php if ($editable): ?><th class="text-right px-4 py-3 w-24">Үйлдэл</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tasks as $i => $task): $assigns = $assignmentsByTask[$task['id']] ?? []; ?>
            <tr class="border-b border-slate-100 dark:border-[#2a2f3b] align-top">
                <td class="px-4 py-3 text-center text-slate-400 dark:text-[#5a6172]"><?= $i + 1 ?></td>
                <td class="px-4 py-3">
                    <p class="font-semibold"><?= htmlspecialchars($task['title']) ?></p>
                    <?php if ($task['kpi_target']): ?>
                        <div class="ql-editor-view text-slate-500 dark:text-[#8b93a1] mt-1 max-w-md"><?= $task['kpi_target'] ?></div>
                    <?php endif; ?>
                    <?php if ($task['metric']): ?>
                        <p class="text-xs text-slate-400 dark:text-[#5a6172] mt-1">Зорилт/Хэмжигдэхүүн: <?= htmlspecialchars($task['metric']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-slate-500 dark:text-[#8b93a1]"><?= htmlspecialchars(KPI2_FREQUENCY_LABELS[$task['frequency']] ?? '—') ?></td>
                <td class="px-3 py-3 text-center font-medium"><?= (int)$task['importance_weight'] ?></td>
                <td class="px-3 py-3 text-center font-medium"><?= (int)$task['difficulty_weight'] ?></td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <?php foreach ($assigns as $a): ?>
                            <span class="inline-flex items-center gap-1 pl-2.5 pr-2 py-1 bg-slate-100 dark:bg-[#272c38] rounded-full text-xs whitespace-nowrap">
                                <?= htmlspecialchars($a['last_name'] . '. ' . $a['first_name']) ?> · <?= rtrim(rtrim(number_format((float)$a['percent'], 1), '0'), '.') ?>%
                            </span>
                        <?php endforeach; ?>
                        <?php if (empty($assigns) && !$editable): ?>
                            <span class="text-xs text-slate-400 dark:text-[#5a6172]">Хуваарилаагүй</span>
                        <?php endif; ?>
                        <?php if ($editable): ?>
                            <button onclick='openAssignModal(<?= $task['id'] ?>, <?= htmlspecialchars(json_encode($assigns), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($task['title']), ENT_QUOTES) ?>)'
                                    class="inline-flex items-center gap-1 px-2.5 py-1 border border-dashed border-slate-300 dark:border-[#3a4151] rounded-full text-xs text-slate-500 dark:text-[#8b93a1] hover:border-[#f1592a] hover:text-[#f1592a] whitespace-nowrap">
                                <span class="material-icons-outlined" style="font-size:13px">groups</span> Хуваарилах
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
                <?php if ($editable): ?>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <button onclick='openTaskModal(<?= htmlspecialchars(json_encode($task), ENT_QUOTES) ?>)' class="text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38] p-1.5 rounded-lg">
                        <span class="material-icons-outlined" style="font-size:16px">edit</span>
                    </button>
                    <button onclick="deleteTask(<?= $task['id'] ?>)" class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 p-1.5 rounded-lg">
                        <span class="material-icons-outlined" style="font-size:16px">delete</span>
                    </button>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 flex items-center justify-end gap-3">
    <?php if ($editable): ?>
        <button onclick="submitBatch()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors">
            Батлуулахаар илгээх
        </button>
    <?php elseif ($batch['status'] === 'pending'): ?>
        <button onclick="recallBatch()" class="border border-slate-200 dark:border-[#2a2f3b] px-5 py-2.5 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38] transition-colors">
            Буцааж татах
        </button>
    <?php else: ?>
        <span class="text-sm text-slate-400 dark:text-[#5a6172]">Батлагдсан тул засах боломжгүй.</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Ажил нэмэх/засах MODAL -->
<div id="taskModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto p-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold" id="taskModalTitle">Ажил нэмэх</h2>
            <button type="button" onclick="closeTaskModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg"><span class="material-icons-outlined">close</span></button>
        </div>
        <form id="taskForm" class="space-y-4">
            <input type="hidden" id="taskId" value=""/>
            <div>
                <label class="block text-sm font-medium mb-1.5">Ажил / зорилт <span class="text-red-500">*</span></label>
                <textarea id="taskTitle" rows="2" required class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm resize-y"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">KPI-ийн зорилт, тайлбар</label>
                <div id="taskTargetEditor"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Давтамж</label>
                    <select id="taskFrequency" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                        <option value="">—</option>
                        <?php foreach (KPI2_FREQUENCY_LABELS as $fv => $fl): ?><option value="<?= $fv ?>"><?= $fl ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Зорилт/Хэмжигдэхүүн</label>
                    <input id="taskMetric" type="text" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm"/>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Ач холбогдол</label>
                    <select id="taskImportance" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                        <option value="1">Дунд (1)</option><option value="2">Чухал (2)</option><option value="3">Маш чухал (3)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Хүндрэл</label>
                    <select id="taskDifficulty" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                        <option value="1">Дунд (1)</option><option value="2">Хүнд (2)</option><option value="3">Маш хүнд (3)</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeTaskModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<!-- Хуваарилах MODAL — нэгжийн бүх ажилтан, тус бүрд хувь -->
<div id="assignModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto p-8">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-xl font-bold">Хуваарилах</h2>
            <button type="button" onclick="closeAssignModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg"><span class="material-icons-outlined">close</span></button>
        </div>
        <p class="text-sm text-slate-500 dark:text-[#8b93a1] mb-5" id="assignModalTaskTitle"></p>
        <form id="assignForm" class="space-y-4">
            <input type="hidden" id="assignTaskId" value=""/>
            <?php if (empty($unitEmployees)): ?>
                <p class="text-sm text-slate-400 dark:text-[#5a6172] text-center py-6">Энэ нэгжид идэвхтэй ажилтан байхгүй байна.</p>
            <?php else: ?>
            <div class="space-y-2 max-h-80 overflow-y-auto pr-1">
                <?php foreach ($unitEmployees as $ue): ?>
                    <div class="flex items-center gap-3">
                        <span class="flex-1 min-w-0 text-sm truncate"><?= htmlspecialchars($ue['last_name'] . '. ' . $ue['first_name']) ?> <span class="text-xs text-slate-400">(<?= htmlspecialchars($ue['employee_code']) ?>)</span></span>
                        <div class="flex items-center flex-shrink-0">
                            <input type="number" class="assign-percent-input w-20 border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-2 py-1.5 text-sm text-right"
                                   data-employee="<?= $ue['id'] ?>" min="0" max="100" step="0.1" value="0"/>
                            <span class="text-sm text-slate-400 ml-1">%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex items-center justify-between pt-2 border-t border-slate-100 dark:border-[#2a2f3b] text-sm font-semibold">
                <span>Нийт</span>
                <span id="assignTotalPercent">0%</span>
            </div>
            <?php endif; ?>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeAssignModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<script>
const AJAX = '<?= BASE_URL ?>/modules/kpi2/ajax.php';
const periodId = <?= (int)$selectedPeriodId ?>;
const unitId = <?= (int)$selectedUnitId ?>;

function createBatch() {
    $.post(AJAX, { action: 'batch_create', period_id: periodId, org_unit_id: unitId }, function(r) {
        showToast(r.message || (r.success ? 'Үүслээ' : 'Алдаа'), r.success ? 'success' : 'error');
        if (r.success) location.reload();
    }, 'json');
}

const RICH_TOOLBAR = [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }]];
let taskQuill = null;
function ensureTaskQuill() {
    if (!taskQuill) {
        taskQuill = new Quill('#taskTargetEditor', {
            theme: 'snow', placeholder: 'KPI-ийн зорилт, дэлгэрэнгүй тайлбараа бичнэ үү...', modules: { toolbar: RICH_TOOLBAR },
        });
    }
    return taskQuill;
}

function openTaskModal(task) {
    ensureTaskQuill();
    $('#taskModalTitle').text(task ? 'Ажил засах' : 'Ажил нэмэх');
    $('#taskId').val(task ? task.id : '');
    $('#taskTitle').val(task ? task.title : '');
    taskQuill.root.innerHTML = (task && task.kpi_target) ? task.kpi_target : '';
    $('#taskFrequency').val(task && task.frequency ? task.frequency : '');
    $('#taskMetric').val(task ? (task.metric || '') : '');
    $('#taskImportance').val(task ? String(task.importance_weight) : '1');
    $('#taskDifficulty').val(task ? String(task.difficulty_weight) : '1');
    $('#taskModal').css('display', 'flex');
}
function closeTaskModal() { $('#taskModal').hide(); }
$('#taskModal').on('click', function(e) { if ($(e.target).is('#taskModal')) closeTaskModal(); });

$('#taskForm').on('submit', function(e) {
    e.preventDefault();
    const id = $('#taskId').val();
    const payload = {
        batch_id: <?= $batch ? (int)$batch['id'] : 0 ?>,
        id,
        title: $('#taskTitle').val().trim(),
        kpi_target: taskQuill ? taskQuill.root.innerHTML : '',
        frequency: $('#taskFrequency').val(),
        metric: $('#taskMetric').val(),
        importance_weight: $('#taskImportance').val(),
        difficulty_weight: $('#taskDifficulty').val(),
    };
    if (!payload.title) { showToast('Ажлын тайлбарыг оруулна уу.', 'error'); return; }
    $.post(AJAX, Object.assign({ action: id ? 'batch_task_update' : 'batch_task_add' }, payload), function(r) {
        showToast(r.message || (r.success ? 'Хадгалагдлаа' : 'Алдаа'), r.success ? 'success' : 'error');
        if (r.success) location.reload();
    }, 'json');
});

function deleteTask(id) {
    if (!confirm('Энэ ажлыг устгах уу?')) return;
    $.post(AJAX, { action: 'batch_task_delete', id }, function(r) {
        showToast(r.message || '', r.success ? 'success' : 'error');
        if (r.success) location.reload();
    }, 'json');
}

function updateAssignTotal() {
    let total = 0;
    $('.assign-percent-input').each(function () { total += parseFloat($(this).val()) || 0; });
    total = Math.round(total * 10) / 10;
    const $t = $('#assignTotalPercent').text(total + '%');
    $t.toggleClass('text-green-600 dark:text-green-400', Math.abs(total - 100) < 0.05);
    $t.toggleClass('text-red-500', Math.abs(total - 100) >= 0.05);
}

function openAssignModal(taskId, assigns, title) {
    $('#assignTaskId').val(taskId);
    $('#assignModalTaskTitle').text(title || '');
    const byEmployee = {};
    (assigns || []).forEach(a => { byEmployee[a.employee_id] = a.percent; });
    $('.assign-percent-input').each(function () {
        const empId = $(this).data('employee');
        $(this).val(byEmployee[empId] !== undefined ? byEmployee[empId] : 0);
    });
    updateAssignTotal();
    $('#assignModal').css('display', 'flex');
}
function closeAssignModal() { $('#assignModal').hide(); }
$('#assignModal').on('click', function(e) { if ($(e.target).is('#assignModal')) closeAssignModal(); });
$(document).on('input', '.assign-percent-input', updateAssignTotal);

$('#assignForm').on('submit', function(e) {
    e.preventDefault();
    const assignments = {};
    $('.assign-percent-input').each(function () {
        const v = parseFloat($(this).val());
        if (v > 0) assignments[$(this).data('employee')] = v;
    });
    if ($.isEmptyObject(assignments)) { showToast('Дор хаяж нэг ажилтанд хувь онооно уу.', 'error'); return; }
    $.post(AJAX, {
        action: 'batch_assignment_bulk_set',
        org_task_id: $('#assignTaskId').val(),
        assignments: JSON.stringify(assignments),
    }, function(r) {
        showToast(r.message || (r.success ? 'Хадгалагдлаа' : 'Алдаа'), r.success ? 'success' : 'error');
        if (r.success) location.reload();
    }, 'json');
});

function submitBatch() {
    if (!confirm('Ажлын багцыг батлуулахаар илгээх үү?')) return;
    $.post(AJAX, { action: 'batch_submit', batch_id: <?= $batch ? (int)$batch['id'] : 0 ?> }, function(r) {
        showToast(r.message || '', r.success ? 'success' : 'error');
        if (r.success) location.reload();
    }, 'json');
}

function recallBatch() {
    if (!confirm('Багцыг буцааж татах уу?')) return;
    $.post(AJAX, { action: 'batch_recall', batch_id: <?= $batch ? (int)$batch['id'] : 0 ?> }, function(r) {
        showToast(r.message || '', r.success ? 'success' : 'error');
        if (r.success) location.reload();
    }, 'json');
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
