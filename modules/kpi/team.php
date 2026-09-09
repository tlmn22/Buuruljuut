<?php
$pageTitle  = 'Багийн KPI';
$activePage = 'kpi-team';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/evaluator_sync.php';
requireLogin();
if (!hasPermission('kpi.approve') && !hasPermission('kpi.evaluate') && !hasPermission('kpi.view_all')) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];
$viewAll = hasPermission('kpi.view_all');

// Хэрэв би нэгжийн удирдагч бол (жишээ нь газрын захирал), дор нь буй бүх
// хэлтэс/албаны ажилтныг ч (тэдгээрийн шууд удирдагч өөр хэн нэг байсан ч) харна.
$managedUnitIds = $viewAll ? [] : kpiManagedOrgUnitIds($pdo, $myEmployeeId);

$periods = $pdo->query("SELECT * FROM kpi_periods ORDER BY start_date DESC")->fetchAll();

$selectedPeriodId = intval($_GET['period_id'] ?? 0);
if (!$selectedPeriodId && $periods) {
    foreach ($periods as $p) { if ($p['is_active']) { $selectedPeriodId = (int)$p['id']; break; } }
    if (!$selectedPeriodId) $selectedPeriodId = (int)$periods[0]['id'];
}

// ─── Ажилтнуудыг татах ─────────────────────────────────────────────
$rows = [];
if ($selectedPeriodId) {
    $params = [$selectedPeriodId, $myEmployeeId];
    $whereScope = '';
    if (!$viewAll) {
        $scopeParts = ["e.default_evaluator_id = ?"];
        $params[] = $myEmployeeId;
        if ($managedUnitIds) {
            $placeholders = implode(',', array_fill(0, count($managedUnitIds), '?'));
            $scopeParts[] = "e.org_unit_id IN ($placeholders)";
            array_push($params, ...$managedUnitIds);
        }
        $whereScope = " AND (" . implode(' OR ', $scopeParts) . ")";
    }

    $baseSql = "
        SELECT e.id as employee_id, e.employee_code, e.last_name, e.first_name, e.position,
               e.default_evaluator_id, e.org_unit_id,
               ev.id as eval_id, ev.status, ev.planning_submitted_at, ev.final_total_score, ev.evaluator_id
        FROM employees e
        LEFT JOIN kpi_evaluations ev ON ev.employee_id = e.id AND ev.period_id = ?
        WHERE e.is_active = 1 AND e.id != ?" . $whereScope . "
        ORDER BY e.last_name, e.first_name
    ";
    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// ─── Мод бүтэц үүсгэх (Газар → Хэлтэс → ... → Ажилтан) ──────────────
$employeesByUnit = [];
$unassigned = [];
foreach ($rows as $r) {
    if ($r['org_unit_id']) $employeesByUnit[(int)$r['org_unit_id']][] = $r;
    else $unassigned[] = $r;
}

$unitRows = $viewAll
    ? $pdo->query("SELECT id, parent_id, name FROM org_units ORDER BY sort_order, name")->fetchAll()
    : (function () use ($pdo, $managedUnitIds) {
        if (!$managedUnitIds) return [];
        $ph = implode(',', array_fill(0, count($managedUnitIds), '?'));
        $stmt = $pdo->prepare("SELECT id, parent_id, name FROM org_units WHERE id IN ($ph) ORDER BY sort_order, name");
        $stmt->execute($managedUnitIds);
        return $stmt->fetchAll();
    })();

$unitIncludedIds = array_column($unitRows, 'id');
$unitsByParent = [];
foreach ($unitRows as $u) {
    $parentKey = ($u['parent_id'] && in_array((int)$u['parent_id'], $unitIncludedIds, true)) ? (int)$u['parent_id'] : 0;
    $unitsByParent[$parentKey][] = $u;
}

$statusColors = [
    'planning'          => 'bg-slate-100 dark:bg-[#272c38] text-slate-600 dark:text-[#c9cdd6]',
    'planning_approved' => 'bg-blue-50 dark:bg-[#1c2a3d] text-[#f1592a]',
    'self_evaluated'    => 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400',
    'completed'         => 'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400',
];

function actionNeeded(array $e, bool $isDirect): ?string {
    if (!$isDirect || !$e['eval_id']) return null;
    if ($e['status'] === 'planning' && $e['planning_submitted_at']) return 'Батламж хүлээгдэж байна';
    if ($e['status'] === 'self_evaluated') return 'Үнэлгээ хүлээгдэж байна';
    return null;
}

$totalCount = count($rows);

// ─── Төлөв тус бүрээр тоолох (4 карт) ───────────────────────────────
$statusCounts = ['drafting' => 0, 'awaiting_approval' => 0, 'evaluating' => 0, 'completed' => 0];
foreach ($rows as $r) {
    if (!$r['eval_id']) continue; // KPI үүсгээгүй хүмүүсийг эндээс тоолохгүй
    if ($r['status'] === 'planning' && !$r['planning_submitted_at']) {
        $statusCounts['drafting']++;
    } elseif ($r['status'] === 'planning') {
        $statusCounts['awaiting_approval']++;
    } elseif (in_array($r['status'], ['planning_approved', 'self_evaluated'], true)) {
        $statusCounts['evaluating']++;
    } elseif ($r['status'] === 'completed') {
        $statusCounts['completed']++;
    }
}
$statusCards = [
    ['key' => 'drafting',          'label' => 'Төлөвлөгөө гаргаж байгаа',     'icon' => 'edit_note',    'iconBg' => 'bg-slate-100 dark:bg-[#272c38]',      'iconColor' => 'text-slate-500 dark:text-[#8b93a1]'],
    ['key' => 'awaiting_approval', 'label' => 'Батлахаар хүлээгдэж байгаа',   'icon' => 'hourglass_top','iconBg' => 'bg-amber-50 dark:bg-amber-950/30',    'iconColor' => 'text-amber-600 dark:text-amber-400'],
    ['key' => 'evaluating',        'label' => 'Үнэлгээ хийгдэж байгаа',       'icon' => 'rate_review',  'iconBg' => 'bg-blue-50 dark:bg-[#1c2a3d]',        'iconColor' => 'text-[#f1592a]'],
    ['key' => 'completed',         'label' => 'Дууссан',                     'icon' => 'task_alt',     'iconBg' => 'bg-green-50 dark:bg-green-950/30',    'iconColor' => 'text-green-600 dark:text-green-400'],
];
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Багийн KPI</span>
        </nav>
        <h1 class="text-3xl font-bold"><?= $viewAll ? 'Бүх ажилтны KPI' : 'Багийн KPI' ?></h1>
    </div>
    <?php if ($periods): ?>
    <select onchange="location.href='<?= BASE_URL ?>/modules/kpi/team.php?period_id='+this.value"
            class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
        <?php foreach ($periods as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id'] == $selectedPeriodId ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<?php if (!$periods): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">event_busy</span>
        Одоогоор KPI үнэлгээний хугацаа тохируулаагүй байна.
    </div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ($statusCards as $card): ?>
        <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 <?= $card['iconBg'] ?> <?= $card['iconColor'] ?>">
                <span class="material-icons-outlined"><?= $card['icon'] ?></span>
            </div>
            <div class="min-w-0">
                <p class="text-2xl font-bold leading-none"><?= $statusCounts[$card['key']] ?></p>
                <p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1.5"><?= $card['label'] ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-4 flex items-center gap-3">
    <span class="material-icons-outlined text-slate-400 dark:text-[#8b93a1]">groups</span>
    <span class="text-sm text-slate-500 dark:text-[#8b93a1]">Нийт: <strong class="text-[#1c2430] dark:text-[#f2f3f6]"><?= $totalCount ?></strong> ажилтан</span>
</div>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-2">
<?php
function renderEmployeeRow(array $r, int $depth, int $num, int $myEmployeeId, bool $viewAll, array $statusColors): void {
    $isDirect = (int)$r['default_evaluator_id'] === $myEmployeeId;
    $needs = actionNeeded($r, $isDirect);
    ?>
    <div class="flex items-center gap-3 py-2 pr-3 hover:bg-slate-50 dark:hover:bg-[#20242e] rounded-lg transition-colors" style="padding-left: <?= 20 + $depth * 24 ?>px">
        <span class="text-xs text-slate-400 dark:text-[#5a6172] w-5 flex-shrink-0"><?= $num ?></span>
        <div class="min-w-0 flex-1">
            <span class="font-medium"><?= htmlspecialchars($r['last_name'] . '. ' . $r['first_name']) ?></span>
            <span class="text-xs text-slate-400 dark:text-[#8b93a1] ml-1"><?= htmlspecialchars($r['employee_code']) ?></span>
            <?php if (!$viewAll && !$isDirect): ?><span class="text-xs text-slate-300 dark:text-[#3a4151]"> · хяналтаар</span><?php endif; ?>
        </div>
        <?php if ($r['eval_id']): ?>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium flex-shrink-0 <?= $statusColors[$r['status']] ?>"><?= KPI_STATUS_LABELS[$r['status']] ?></span>
        <?php else: ?>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium flex-shrink-0 border border-dashed border-slate-300 dark:border-[#3a4151] text-slate-400 dark:text-[#5a6172]">KPI үүсгээгүй байна</span>
        <?php endif; ?>
        <span class="text-sm font-semibold w-16 text-right flex-shrink-0">
            <?= ($r['eval_id'] && $r['status'] === 'completed') ? '<span class="text-[#f1592a]">' . number_format($r['final_total_score']*100, 1) . '%</span>' : '<span class="text-slate-300 dark:text-[#3a4151]">—</span>' ?>
        </span>
        <?php if ($needs): ?>
            <span class="hidden lg:inline-flex items-center gap-1 text-xs font-medium text-amber-600 dark:text-amber-400 flex-shrink-0 w-40">
                <span class="material-icons-outlined" style="font-size:14px">schedule</span><?= $needs ?>
            </span>
        <?php else: ?>
            <span class="hidden lg:inline-block w-40 flex-shrink-0"></span>
        <?php endif; ?>
        <span class="w-16 text-right flex-shrink-0">
            <?php if ($r['eval_id']): ?>
                <a href="<?= BASE_URL ?>/modules/kpi/sheet.php?id=<?= $r['eval_id'] ?>" class="text-[#f1592a] font-medium text-sm hover:underline"><?= $isDirect || $viewAll ? 'Нээх' : 'Харах' ?></a>
            <?php else: ?>
                <span class="text-slate-300 dark:text-[#3a4151]">—</span>
            <?php endif; ?>
        </span>
    </div>
    <?php
}

function renderUnitGroup(array $unit, array $unitsByParent, array $employeesByUnit, int $depth, int $num, bool $isRoot, int $myEmployeeId, bool $viewAll, array $statusColors): void {
    $hasChildren = !empty($unitsByParent[$unit['id']]);
    $employees = $employeesByUnit[(int)$unit['id']] ?? [];
    if (!$hasChildren && empty($employees)) return; // хоосон нэгжийг харуулахгүй
    ?>
    <div class="<?= $isRoot ? 'mt-4 first:mt-1' : '' ?>" style="padding-left: <?= $depth * 24 ?>px">
        <div class="flex items-center gap-2 py-2 <?= $isRoot ? '' : 'border-t border-slate-100 dark:border-[#2a2f3b]' ?>">
            <span class="material-icons-outlined text-[#f1592a]" style="font-size:16px"><?= $isRoot ? 'business' : 'device_hub' ?></span>
            <?php if (!$isRoot): ?><span class="text-xs text-slate-400 dark:text-[#5a6172] font-semibold"><?= $num ?></span><?php endif; ?>
            <span class="font-bold <?= $isRoot ? 'text-base' : 'text-sm' ?>"><?= htmlspecialchars($unit['name']) ?></span>
        </div>
        <?php foreach ($employees as $i => $r): renderEmployeeRow($r, $depth + 1, $i + 1, $myEmployeeId, $viewAll, $statusColors); endforeach; ?>
        <?php foreach ($unitsByParent[$unit['id']] ?? [] as $j => $child): renderUnitGroup($child, $unitsByParent, $employeesByUnit, $depth + 1, $j + 1, false, $myEmployeeId, $viewAll, $statusColors); endforeach; ?>
    </div>
    <?php
}

if (empty($unitsByParent[0] ?? []) && empty($unassigned)): ?>
    <div class="text-center py-16 text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">groups</span>
        <?= $viewAll ? 'Ажилтан байхгүй байна' : 'Танд харьяалагддаг ажилтан одоогоор байхгүй байна' ?>
    </div>
<?php else:
    foreach ($unitsByParent[0] ?? [] as $rootUnit) {
        renderUnitGroup($rootUnit, $unitsByParent, $employeesByUnit, 0, 0, true, $myEmployeeId, $viewAll, $statusColors);
    }
    if ($unassigned) { ?>
        <div class="mt-4">
            <div class="flex items-center gap-2 py-2">
                <span class="material-icons-outlined text-slate-400" style="font-size:16px">help_outline</span>
                <span class="font-bold text-base text-slate-400 dark:text-[#5a6172]">Нэгж тодорхойгүй</span>
            </div>
            <?php foreach ($unassigned as $i => $r): renderEmployeeRow($r, 1, $i + 1, $myEmployeeId, $viewAll, $statusColors); endforeach; ?>
        </div>
    <?php }
endif; ?>
</div>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
