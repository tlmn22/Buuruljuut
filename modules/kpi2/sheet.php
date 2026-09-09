<?php
$pageTitle  = 'KPI2 хуудас';
$activePage = 'kpi2';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/evaluator.php';
requireLogin();
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

$evalId = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT ev.*, p.name as period_name, p.start_date, p.end_date,
           p.personal_kpi_weight, p.core_duty_weight, p.special_task_weight, p.self_weight, p.manager_weight,
           e.last_name, e.first_name, e.employee_code, e.org_unit_id as employee_org_unit_id,
           ap.last_name as approver_last, ap.first_name as approver_first
    FROM kpi2_evaluations ev
    JOIN kpi2_periods p ON p.id = ev.period_id
    JOIN employees e ON e.id = ev.employee_id
    LEFT JOIN employees ap ON ap.id = ev.approved_by
    WHERE ev.id = ?
");
$stmt->execute([$evalId]);
$eval = $stmt->fetch();
if (!$eval) { http_response_code(404); die('KPI2 хуудас олдсонгүй.'); }

$isOwner     = (int)$eval['employee_id'] === $myEmployeeId;
$isEvaluator = (int)$eval['evaluator_id'] === $myEmployeeId;
$canViewAll  = isHR() || isSuperAdmin();

// Нэгжийн kpi2 удирдагч (manager/director) доорх бүх хэлтэс/албаны хуудсыг хяналтаар (зөвхөн харах) нээж чадна
$isInManagedSubtree = false;
if (!$isOwner && !$isEvaluator && !$canViewAll && $eval['employee_org_unit_id']) {
    $isInManagedSubtree = in_array((int)$eval['employee_org_unit_id'], kpi2ManagedOrgUnitIds($pdo, $myEmployeeId), true);
}

if (!$isOwner && !$isEvaluator && !$canViewAll && !$isInManagedSubtree) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}

$itemsStmt = $pdo->prepare("SELECT * FROM kpi2_items WHERE evaluation_id = ? ORDER BY section, sort_order, id");
$itemsStmt->execute([$evalId]);
$allItems = $itemsStmt->fetchAll();
$bySection = ['personal_kpi' => [], 'core_duty' => [], 'special_task' => []];
foreach ($allItems as $it) { $bySection[$it['section']][] = $it; }

$isSubmitted = (bool)$eval['planning_submitted_at'];
$planningEditable = $isOwner && $eval['status'] === 'planning' && !$isSubmitted;
$selfEvalEditable  = $isOwner && $eval['status'] === 'planning_approved';
$mgmtEvalEditable  = $isEvaluator && $eval['status'] === 'self_evaluated';
$awaitingApproval  = $isEvaluator && $eval['status'] === 'planning' && $isSubmitted;

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<style>
    .ql-editor-view { padding: 2px 0; font-size: 13px; line-height: 1.5; }
    .ql-editor-view p { margin: 0 0 4px; }
    .ql-editor-view ul, .ql-editor-view ol { padding-left: 1.2em; margin: 0 0 4px; }
    #itemTargetEditor .ql-editor, #selfEvalNoteEditor .ql-editor { min-height: 100px; }
    html.dark .ql-toolbar.ql-snow { border-color: #2a2f3b; background: #272c38; }
    html.dark .ql-toolbar.ql-snow .ql-stroke { stroke: #c9cdd6; }
    html.dark .ql-toolbar.ql-snow .ql-fill { fill: #c9cdd6; }
    html.dark .ql-toolbar.ql-snow .ql-picker { color: #c9cdd6; }
    html.dark .ql-container.ql-snow { border-color: #2a2f3b; }
    html.dark .ql-editor { color: #f2f3f6; }
    html.dark .ql-editor.ql-blank::before { color: #5a6172; }
</style>
<div style="zoom:0.7">
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <a href="<?= BASE_URL ?>/modules/kpi2/index.php" class="hover:text-[#f1592a]">KPI2</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium"><?= htmlspecialchars($eval['period_name']) ?></span>
        </nav>
        <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-2xl font-bold"><?= htmlspecialchars($eval['last_name'] . '. ' . $eval['first_name']) ?> — <?= htmlspecialchars($eval['period_name']) ?></h1>
        </div>
        <p class="text-sm text-slate-500 dark:text-[#8b93a1] mt-1">
            <?= htmlspecialchars($eval['position_snapshot'] ?? '—') ?> · <?= htmlspecialchars($eval['org_unit_snapshot'] ?? '—') ?>
            · <?= htmlspecialchars($eval['start_date']) ?> — <?= htmlspecialchars($eval['end_date']) ?>
        </p>
    </div>
</div>

<?php
// 4 алхамт wizard — одоогийн үе шатыг тодорхойлно
$wizardSteps = [
    ['label' => 'Төлөвлөгөө гаргах',          'actor' => 'Ажилтан төлөвлөгөөг гаргана'],
    ['label' => 'Төлөвлөгөөг батлах',         'actor' => 'Шууд удирдлага'],
    ['label' => 'Үнэлгээ хийх',               'actor' => 'Ажилтан ажлуудаа үнэлж тайлбар хийнэ'],
    ['label' => 'Удирдлагын үнэлгээ',         'actor' => 'Удирдлага үнэлгээ тайлбар хийнэ'],
];
$currentStep = match (true) {
    $eval['status'] === 'planning' && !$isSubmitted => 0,
    $eval['status'] === 'planning'                  => 1,
    $eval['status'] === 'planning_approved'          => 2,
    $eval['status'] === 'self_evaluated'             => 3,
    default                                          => 4, // completed
};
?>
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-6">
    <div class="flex items-start">
        <?php foreach ($wizardSteps as $i => $step):
            $isDone   = $i < $currentStep;
            $isActive = $i === $currentStep;
            $circleClass = $isDone
                ? 'bg-[#f1592a] text-white'
                : ($isActive
                    ? 'bg-white dark:bg-[#1c212b] border-2 border-[#f1592a] text-[#f1592a]'
                    : 'bg-slate-100 dark:bg-[#272c38] text-slate-400 dark:text-[#5a6172]');
            $labelClass = $isDone || $isActive ? 'text-[#1c2430] dark:text-[#f2f3f6]' : 'text-slate-400 dark:text-[#5a6172]';
        ?>
        <?php if ($i > 0): ?>
            <div class="flex-1 h-0.5 mt-[18px] <?= $i <= $currentStep ? 'bg-[#f1592a]' : 'bg-slate-200 dark:bg-[#2a2f3b]' ?>"></div>
        <?php endif; ?>
        <div class="flex flex-col items-center flex-none w-32">
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

<?php if ($eval['status'] === 'completed'): ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5">
        <p class="text-xs text-slate-400 dark:text-[#8b93a1] uppercase tracking-wide mb-1">Үндсэн оноо</p>
        <p class="text-2xl font-bold"><?= number_format($eval['final_main_score'] * 100, 1) ?>%</p>
    </div>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5">
        <p class="text-xs text-slate-400 dark:text-[#8b93a1] uppercase tracking-wide mb-1">Бонус оноо</p>
        <p class="text-2xl font-bold">+<?= number_format($eval['final_bonus_score'] * 100, 1) ?>%</p>
    </div>
    <div class="bg-[#f1592a] text-white rounded-xl shadow-sm p-5">
        <p class="text-xs text-white/70 uppercase tracking-wide mb-1">Эцсийн нийт оноо</p>
        <p class="text-2xl font-bold"><?= number_format($eval['final_total_score'] * 100, 1) ?>%</p>
    </div>
</div>
<?php endif; ?>
</div>

<?php
function kpi2SectionColorClasses(string $key): string {
    return match ($key) {
        'personal_kpi' => 'bg-[#E2EFDA] dark:bg-[#1c3324] text-[#2d5016] dark:text-[#8fd19e]',
        'core_duty'    => 'bg-[#DEEBF7] dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0]',
        'special_task' => 'bg-[#A5A5A5] dark:bg-[#3a3f4a] text-white dark:text-[#e5e7eb]',
        default        => '',
    };
}

/**
 * Мөрийн бүтэн бүтэц. org_task_id-тай мөр (Хэлтэс/Алба-аас батлагдсан ажил) нь
 * ажилтанд ТҮГЖИГДСЭН — засах/устгах товч харагдахгүй, зөвхөн үнэлгээ хийнэ.
 */
function renderSection(string $key, string $title, array $items, array $ctx): void {
    $showTarget = $key === 'personal_kpi' || $key === 'core_duty';
    $showFreq   = $key === 'core_duty';
    $band       = kpi2SectionColorClasses($key);
    $hasAction  = $ctx['planningEditable'] || $ctx['selfEvalEditable'] || $ctx['mgmtEvalEditable'];
    ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
        <div class="px-6 py-4 flex items-center justify-between <?= $band ?>" style="zoom:0.7">
            <h3 class="font-bold"><?= htmlspecialchars($title) ?></h3>
            <?php if ($ctx['planningEditable']): ?>
                <button onclick='openItemModal("<?= $key ?>", null)' class="text-sm font-medium flex items-center gap-1 hover:underline">
                    <span class="material-icons-outlined" style="font-size:16px">add_circle</span> Мөр нэмэх
                </button>
            <?php endif; ?>
        </div>
        <div class="overflow-x-auto" style="zoom:0.72">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                    <th class="text-left px-4 py-3 w-8">#</th>
                    <th class="text-left px-4 py-3">Ажил / зорилт</th>
                    <?php if ($showTarget): ?><th class="text-left px-4 py-3">KPI-ийн зорилт</th><?php endif; ?>
                    <?php if ($showFreq): ?><th class="text-left px-4 py-3">Давтамж</th><th class="text-left px-4 py-3">Зорилт/Хэмжигдэхүүн</th><?php endif; ?>
                    <th class="text-left px-4 py-3 w-24">Ач холбогдол</th>
                    <th class="text-left px-4 py-3 w-24">Хүндрэл</th>
                    <th class="text-left px-4 py-3">Гүйцэтгэлийн тайлбар</th>
                    <th class="text-left px-4 py-3 w-28">Өөрийн үнэлгээ</th>
                    <th class="text-left px-4 py-3 w-28">Удирдлагын үнэлгээ</th>
                    <?php if ($hasAction): ?><th class="text-right px-4 py-3 w-32">Үйлдэл</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="section-<?= $key ?>">
            <?php if (empty($items)): ?>
                <tr><td colspan="20" class="text-center py-8 text-slate-400 dark:text-[#5a6172]">Мөр байхгүй байна</td></tr>
            <?php else: foreach ($items as $i => $it):
                $itemJson = htmlspecialchars(json_encode($it), ENT_QUOTES);
                $isLocked = $it['org_task_id'] !== null;
            ?>
                <tr data-id="<?= $it['id'] ?>" class="border-b border-slate-100 dark:border-[#2a2f3b]">
                    <td class="px-4 py-2 text-center font-semibold <?= $band ?>"><?= $i + 1 ?></td>
                    <td class="px-4 py-2">
                        <p class="w-56 whitespace-pre-wrap"><?= htmlspecialchars($it['title']) ?></p>
                        <?php if ($isLocked): ?>
                            <span class="inline-flex items-center gap-1 mt-1 text-[10px] font-medium text-slate-400 dark:text-[#5a6172]">
                                <span class="material-icons-outlined" style="font-size:11px">lock</span>Хэлтсээс батлагдсан
                                <?php if ($it['share_percent'] !== null): ?> · <?= rtrim(rtrim(number_format((float)$it['share_percent'], 1), '0'), '.') ?>%<?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <?php if ($showTarget): ?>
                    <td class="px-4 py-2">
                        <?php if ($it['kpi_target']): ?>
                            <div class="ql-editor-view w-48"><?= $it['kpi_target'] ?></div>
                        <?php else: ?>
                            <p class="w-48 text-slate-400 dark:text-[#5a6172]">—</p>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($showFreq): ?>
                    <td class="px-4 py-2"><?= htmlspecialchars(KPI2_FREQUENCY_LABELS[$it['frequency']] ?? '—') ?></td>
                    <td class="px-4 py-2"><p class="w-40 whitespace-pre-wrap"><?= htmlspecialchars($it['metric'] ?? '—') ?></p></td>
                    <?php endif; ?>
                    <td class="px-4 py-2"><?= (int)$it['importance_weight'] ?></td>
                    <td class="px-4 py-2"><?= (int)$it['difficulty_weight'] ?></td>
                    <td class="px-4 py-2">
                        <?php if ($it['performance_note']): ?>
                            <div class="ql-editor-view w-52 text-slate-600 dark:text-[#c9cdd6]"><?= $it['performance_note'] ?></div>
                        <?php else: ?>
                            <p class="w-52 text-slate-400 dark:text-[#5a6172]">—</p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2"><?= $it['self_score'] !== null ? $it['self_score'] : '—' ?></td>
                    <td class="px-4 py-2"><?= $it['manager_score'] !== null ? $it['manager_score'] : '—' ?></td>
                    <?php if ($hasAction): ?>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <?php if ($ctx['planningEditable'] && !$isLocked): ?>
                                <button onclick='openItemModal("<?= $key ?>", <?= $itemJson ?>)' class="text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38] p-1.5 rounded-lg">
                                    <span class="material-icons-outlined" style="font-size:16px">edit</span>
                                </button>
                                <button onclick="deleteItem(<?= $it['id'] ?>)" class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 p-1.5 rounded-lg">
                                    <span class="material-icons-outlined" style="font-size:16px">delete</span>
                                </button>
                            <?php elseif ($ctx['selfEvalEditable']): ?>
                                <button onclick='openSelfEvalModal(<?= $itemJson ?>)' class="text-[#f1592a] border border-[#f1592a]/40 hover:bg-orange-50 dark:hover:bg-[#272c38] px-3 py-1.5 rounded-lg text-xs font-medium">
                                    <?= $it['self_score'] !== null ? 'Засах' : 'Үнэлгээ хийх' ?>
                                </button>
                            <?php elseif ($ctx['mgmtEvalEditable']): ?>
                                <button onclick='openManagerEvalModal(<?= $itemJson ?>)' class="text-[#f1592a] border border-[#f1592a]/40 hover:bg-orange-50 dark:hover:bg-[#272c38] px-3 py-1.5 rounded-lg text-xs font-medium">
                                    <?= $it['manager_score'] !== null ? 'Засах' : 'Үнэлэх' ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}

$ctx = compact('planningEditable', 'selfEvalEditable', 'mgmtEvalEditable');
renderSection('personal_kpi', KPI2_SECTION_LABELS['personal_kpi'] . ' (' . ($eval['personal_kpi_weight']*100) . '%)', $bySection['personal_kpi'], $ctx);
renderSection('core_duty', KPI2_SECTION_LABELS['core_duty'] . ' (' . ($eval['core_duty_weight']*100) . '%)', $bySection['core_duty'], $ctx);
renderSection('special_task', KPI2_SECTION_LABELS['special_task'] . ' (бонус +' . ($eval['special_task_weight']*100) . '%)', $bySection['special_task'], $ctx);
?>

<div style="zoom:0.7">
<!-- Үйлдлийн товчнууд -->
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-4 flex flex-wrap items-center gap-3">
    <?php if ($planningEditable): ?>
        <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Нэмэлт ажлаа оруулж дуусаад төлөвлөгөөгөө батлуулах хүсэлт илгээнэ үү.</span>
        <button onclick="submitPlanning()" class="ml-auto bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors">
            Илгээх
        </button>
    <?php elseif ($isOwner && $eval['status'] === 'planning' && $isSubmitted): ?>
        <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Төлөвлөгөө батлагдахыг хүлээж байна.</span>
        <button onclick="recallPlanning()" class="ml-auto border border-slate-200 dark:border-[#2a2f3b] px-5 py-2.5 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38] transition-colors">
            Хүсэлтээ буцааж төлөвлөгөөг засах
        </button>
    <?php elseif ($awaitingApproval): ?>
        <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Ажилтны төлөвлөгөөг хянаж баталгаажуулна уу.</span>
        <div class="ml-auto flex gap-2">
            <button onclick="rejectPlanning()" class="border border-slate-200 dark:border-[#2a2f3b] px-5 py-2.5 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38] transition-colors">Буцаах</button>
            <button onclick="approvePlanning()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors">Батлах</button>
        </div>
    <?php elseif ($selfEvalEditable): ?>
        <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Мөр бүр дээрх товчоор гүйцэтгэлийн тайлбар, өөрийн үнэлгээгээ бөглөнө үү.</span>
        <button onclick="submitSelfEval()" class="ml-auto bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors">
            Илгээх
        </button>
    <?php elseif ($isOwner && $eval['status'] === 'planning_approved'): ?>
        <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Хугацааны төгсгөлд өөрийн үнэлгээгээ бөглөнө.</span>
    <?php elseif ($mgmtEvalEditable): ?>
        <div class="flex items-center gap-3 flex-1 flex-wrap">
            <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Мөр бүр дээрх "Үнэлэх" товчоор үнэлгээгээ өгөөд, ярилцлагын огноог оруулж хаана уу.</span>
            <div class="ml-auto flex items-center gap-2">
                <input type="date" id="interviewDate" class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm"/>
                <button onclick="submitManagerEval()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors">
                    KPI2 ийг хаах
                </button>
            </div>
        </div>
    <?php elseif ($isEvaluator && $eval['status'] === 'self_evaluated'): ?>
        <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Хүлээгдэж байна.</span>
    <?php else: ?>
        <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Энэ хуудас хаагдсан — зөвхөн харах горимд байна.</span>
    <?php endif; ?>
</div>
</div>

<!-- Мөр нэмэх/засах MODAL (төлөвлөлтийн шат — зөвхөн өөрийн нэмсэн мөрөнд) -->
<div id="itemModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto p-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold" id="itemModalTitle">Мөр нэмэх</h2>
            <button type="button" onclick="closeItemModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>
        <form id="itemForm" class="space-y-4">
            <input type="hidden" id="itemId" value=""/>
            <input type="hidden" id="itemSection" value=""/>
            <div>
                <label class="block text-sm font-medium mb-1.5">Ажил / зорилт <span class="text-red-500">*</span></label>
                <textarea id="itemTitle" rows="2" required
                    class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm resize-y"
                    placeholder="Ажил / зорилтын товч тайлбар..."></textarea>
            </div>
            <div id="itemTargetField" class="hidden">
                <label class="block text-sm font-medium mb-1.5">KPI-ийн зорилт</label>
                <div id="itemTargetEditor"></div>
            </div>
            <div id="itemFreqFields" class="hidden grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Давтамж</label>
                    <select id="itemFrequency" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                        <option value="">—</option>
                        <?php foreach (KPI2_FREQUENCY_LABELS as $fv => $fl): ?>
                            <option value="<?= $fv ?>"><?= $fl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Зорилт/Хэмжигдэхүүн</label>
                    <textarea id="itemMetric" rows="1" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm resize-y"></textarea>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Ач холбогдол</label>
                    <select id="itemImportance" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                        <option value="1">Дунд (1)</option>
                        <option value="2">Чухал (2)</option>
                        <option value="3">Маш чухал (3)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Хүндрэл</label>
                    <select id="itemDifficulty" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                        <option value="1">Дунд (1)</option>
                        <option value="2">Хүнд (2)</option>
                        <option value="3">Маш хүнд (3)</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeItemModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<!-- Өөрийн үнэлгээ MODAL -->
<div id="selfEvalModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto p-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold">Өөрийн үнэлгээ</h2>
            <button type="button" onclick="closeSelfEvalModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>
        <div class="mb-4 p-3 bg-slate-50 dark:bg-[#14171f] rounded-lg">
            <p class="text-sm font-semibold" id="selfEvalContextTitle"></p>
            <p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1" id="selfEvalContextExtra"></p>
        </div>
        <form id="selfEvalForm" class="space-y-4">
            <input type="hidden" id="selfEvalItemId" value=""/>
            <div>
                <label class="block text-sm font-medium mb-1.5">Гүйцэтгэлийн тайлбар</label>
                <div id="selfEvalNoteEditor"></div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Өөрийн үнэлгээ <span class="text-red-500">*</span></label>
                <select id="selfEvalScore" required class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                    <option value="">—</option>
                    <?php foreach (kpiScoreOptions() as $opt): ?><option value="<?= $opt ?>"><?= $opt ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeSelfEvalModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<!-- Удирдлагын үнэлгээ MODAL -->
<div id="managerEvalModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto p-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold">Удирдлагын үнэлгээ</h2>
            <button type="button" onclick="closeManagerEvalModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>
        <div class="mb-4 p-3 bg-slate-50 dark:bg-[#14171f] rounded-lg space-y-2">
            <p class="text-sm font-semibold" id="mgmtEvalContextTitle"></p>
            <p class="text-xs text-slate-500 dark:text-[#8b93a1]" id="mgmtEvalContextExtra"></p>
            <div class="text-xs text-slate-600 dark:text-[#c9cdd6] border-t border-slate-200 dark:border-[#2a2f3b] pt-2" id="mgmtEvalContextNote"></div>
        </div>
        <form id="managerEvalForm" class="space-y-4">
            <input type="hidden" id="mgmtEvalItemId" value=""/>
            <div>
                <label class="block text-sm font-medium mb-1.5">Удирдлагын үнэлгээ <span class="text-red-500">*</span></label>
                <select id="mgmtEvalScore" required class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm">
                    <option value="">—</option>
                    <?php foreach (kpiScoreOptions() as $opt): ?><option value="<?= $opt ?>"><?= $opt ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeManagerEvalModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<script>
const evalId = <?= $evalId ?>;
const RICH_TOOLBAR = [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }]];

function post(action, extra, cb) {
    $.post('<?= BASE_URL ?>/modules/kpi2/ajax.php', Object.assign({action, evaluation_id: evalId}, extra || {}), function(r) {
        showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа гарлаа'), r.success ? 'success' : 'error');
        if (r.success && cb) cb(r); else if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}

function deleteItem(id) {
    if (!confirm('Энэ мөрийг устгах уу?')) return;
    $.post('<?= BASE_URL ?>/modules/kpi2/ajax.php', { action: 'item_delete', id }, function(r) {
        showToast(r.message || '', r.success ? 'success' : 'error');
        if (r.success) $(`tr[data-id="${id}"]`).fadeOut(200, function(){ $(this).remove(); });
    }, 'json');
}

// ─── Мөр нэмэх/засах modal (төлөвлөлт) ───────────────────────────
let itemQuill = null;
function ensureItemQuill() {
    if (!itemQuill) {
        itemQuill = new Quill('#itemTargetEditor', {
            theme: 'snow', placeholder: 'KPI-ийн зорилтоо бичнэ үү...', modules: { toolbar: RICH_TOOLBAR },
        });
    }
    return itemQuill;
}
function openItemModal(section, item) {
    ensureItemQuill();
    $('#itemModalTitle').text(item ? 'Мөр засах' : 'Мөр нэмэх');
    $('#itemSection').val(section);
    $('#itemId').val(item ? item.id : '');
    $('#itemTitle').val(item ? item.title : '');
    itemQuill.root.innerHTML = (item && item.kpi_target) ? item.kpi_target : '';
    $('#itemFrequency').val(item && item.frequency ? item.frequency : '');
    $('#itemMetric').val(item && item.metric ? item.metric : '');
    $('#itemImportance').val(item ? String(item.importance_weight) : '1');
    $('#itemDifficulty').val(item ? String(item.difficulty_weight) : '1');
    $('#itemTargetField').toggle(section === 'personal_kpi');
    $('#itemFreqFields').toggle(section === 'core_duty');
    $('#itemModal').css('display', 'flex');
    setTimeout(() => $('#itemTitle').trigger('focus'), 100);
}
function closeItemModal() { $('#itemModal').hide(); }
$('#itemModal').on('click', function (e) { if ($(e.target).is('#itemModal')) closeItemModal(); });

$('#itemForm').on('submit', function (e) {
    e.preventDefault();
    const title = $('#itemTitle').val().trim();
    if (!title) { showToast('Ажлын тайлбарыг оруулна уу.', 'error'); return; }
    const id = $('#itemId').val();
    const payload = {
        id,
        section: $('#itemSection').val(),
        title,
        kpi_target: itemQuill ? itemQuill.root.innerHTML : '',
        frequency: $('#itemFrequency').val(),
        metric: $('#itemMetric').val(),
        importance_weight: $('#itemImportance').val(),
        difficulty_weight: $('#itemDifficulty').val(),
    };
    post(id ? 'item_save' : 'item_add', payload, () => location.reload());
});

// ─── Өөрийн үнэлгээ modal ─────────────────────────────────────────
let selfEvalQuill = null;
function ensureSelfEvalQuill() {
    if (!selfEvalQuill) {
        selfEvalQuill = new Quill('#selfEvalNoteEditor', {
            theme: 'snow', placeholder: 'Гүйцэтгэлийн тайлбараа бичнэ үү...', modules: { toolbar: RICH_TOOLBAR },
        });
    }
    return selfEvalQuill;
}
function openSelfEvalModal(item) {
    ensureSelfEvalQuill();
    $('#selfEvalItemId').val(item.id);
    $('#selfEvalContextTitle').text(item.title || '');
    $('#selfEvalContextExtra').text(item.kpi_target ? '' : (item.metric || ''));
    selfEvalQuill.root.innerHTML = item.performance_note || '';
    $('#selfEvalScore').val(item.self_score !== null && item.self_score !== undefined ? String(item.self_score) : '');
    $('#selfEvalModal').css('display', 'flex');
}
function closeSelfEvalModal() { $('#selfEvalModal').hide(); }
$('#selfEvalModal').on('click', function (e) { if ($(e.target).is('#selfEvalModal')) closeSelfEvalModal(); });

$('#selfEvalForm').on('submit', function (e) {
    e.preventDefault();
    const score = $('#selfEvalScore').val();
    if (score === '') { showToast('Өөрийн үнэлгээгээ сонгоно уу.', 'error'); return; }
    post('item_save', {
        id: $('#selfEvalItemId').val(),
        performance_note: selfEvalQuill ? selfEvalQuill.root.innerHTML : '',
        self_score: score,
    }, () => location.reload());
});

// ─── Удирдлагын үнэлгээ modal ─────────────────────────────────────
function openManagerEvalModal(item) {
    $('#mgmtEvalItemId').val(item.id);
    $('#mgmtEvalContextTitle').text(item.title || '');
    $('#mgmtEvalContextExtra').html(item.kpi_target || item.metric || '');
    $('#mgmtEvalContextNote').html(item.performance_note || '<span class="text-slate-400 dark:text-[#5a6172]">Ажилтан тайлбар бичээгүй байна.</span>');
    $('#mgmtEvalScore').val(item.manager_score !== null && item.manager_score !== undefined ? String(item.manager_score) : '');
    $('#managerEvalModal').css('display', 'flex');
}
function closeManagerEvalModal() { $('#managerEvalModal').hide(); }
$('#managerEvalModal').on('click', function (e) { if ($(e.target).is('#managerEvalModal')) closeManagerEvalModal(); });

$('#managerEvalForm').on('submit', function (e) {
    e.preventDefault();
    const score = $('#mgmtEvalScore').val();
    if (score === '') { showToast('Удирдлагын үнэлгээг сонгоно уу.', 'error'); return; }
    post('item_save', { id: $('#mgmtEvalItemId').val(), manager_score: score }, () => location.reload());
});

// ─── Үе шатны товчнууд ─────────────────────────────────────────────
function submitPlanning() { post('submit_planning'); }
function recallPlanning() { post('recall_planning'); }
function approvePlanning() { if (confirm('Төлөвлөгөөг батлах уу?')) post('approve_planning'); }
function rejectPlanning() { if (confirm('Ажилтанд буцаах уу?')) post('reject_planning'); }
function submitSelfEval() {
    if (confirm('Өөрийн үнэлгээгээ илгээх үү? Илгээсний дараа засах боломжгүй.')) post('submit_self_eval');
}
function submitManagerEval() {
    const interviewDate = $('#interviewDate').val();
    if (!interviewDate) { showToast('Ярилцлагын огноог сонгоно уу.', 'error'); return; }
    if (confirm('Үнэлгээг дуусгаж хуудсыг хаах уу?')) post('submit_manager_eval', { evaluation_interview_date: interviewDate });
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
