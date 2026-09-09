<?php
$pageTitle  = 'KPI тайлан нэгтгэл';
$activePage = 'kpi-report';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
if (!hasPermission('kpi.view_all')) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}
$pdo = getDB();

$periods = $pdo->query("SELECT * FROM kpi_periods ORDER BY start_date DESC")->fetchAll();
$selectedPeriodId = intval($_GET['period_id'] ?? 0);
if (!$selectedPeriodId && $periods) {
    foreach ($periods as $p) { if ($p['is_active']) { $selectedPeriodId = (int)$p['id']; break; } }
    if (!$selectedPeriodId) $selectedPeriodId = (int)$periods[0]['id'];
}
$period = null;
foreach ($periods as $p) { if ((int)$p['id'] === $selectedPeriodId) { $period = $p; break; } }

/** Сар бүрийн даваа-баасан (ажлын өдөр) тоог period-ийн хугацаанд тооцно — жинхэнэ баярын өдрийг тооцохгүй, зөвхөн долоо хоногийн эцэс/эхлэлийг хасна */
function kpiReportWeekdayColumns(?array $period): array {
    if (!$period) return [];
    $start = new DateTime($period['start_date']);
    $end   = new DateTime($period['end_date']);
    $months = [];
    $cursor = (clone $start)->modify('first day of this month');
    while ($cursor <= $end) {
        $monthStart = max($start, clone $cursor);
        $monthEnd   = min($end, (clone $cursor)->modify('last day of this month'));
        $count = 0;
        $d = clone $monthStart;
        while ($d <= $monthEnd) {
            if ((int)$d->format('N') <= 5) $count++;
            $d->modify('+1 day');
        }
        $months[] = ['label' => $cursor->format('n') . ' сар', 'count' => $count];
        $cursor->modify('first day of next month');
    }
    return $months;
}
$monthColumns = kpiReportWeekdayColumns($period);
$totalWorkDays = array_sum(array_column($monthColumns, 'count'));

$employees = [];
$unassigned = [];
if ($selectedPeriodId) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.employee_code, e.last_name, e.first_name, e.position, e.is_active, e.org_unit_id,
               ev.status AS kpi_status, ev.final_total_score
        FROM employees e
        LEFT JOIN kpi_evaluations ev ON ev.employee_id = e.id AND ev.period_id = ?
        WHERE e.is_active = 1
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([$selectedPeriodId]);
    $employees = $stmt->fetchAll();
}

// ─── Газар → Хэлтэс → ... мод бүтэц (team.php-тэй адил зарчим) ────────
$employeesByUnit = [];
foreach ($employees as $e) {
    if ($e['org_unit_id']) $employeesByUnit[(int)$e['org_unit_id']][] = $e;
    else $unassigned[] = $e;
}
$unitRows = $pdo->query("SELECT id, parent_id, name FROM org_units ORDER BY sort_order, name")->fetchAll();
$unitsByParent = [];
foreach ($unitRows as $u) {
    $unitsByParent[$u['parent_id'] ? (int)$u['parent_id'] : 0][] = $u;
}

$totalCols = 4 + count($monthColumns) + 3 + 3 + 3 + 1;

/** Ажилтны кодоор тогтмол (давтагдашгүй биш ч тогтмол) псевдо-ирцийн хувийг гаргана — зөвхөн загвар/тест дата, бодит ирц биш */
function kpiReportTestAttendancePct(string $employeeCode): float {
    $seed = crc32($employeeCode);
    return (55 + ($seed % 46)) / 100; // 0.55 - 1.00 хооронд тогтмол хуваарилагдана
}

/** Газар/Хэлтэс/Алба гүнээс хамаарч банерын өнгийг сонгоно (Excel загвартай ойролцоо) */
function kpiReportGroupColor(int $depth): string {
    $palette = [
        'bg-[#FCE4D6] dark:bg-[#3a2418] text-[#8a3a0a] dark:text-[#f0a876]', // газар — цайвар улбар шар
        'bg-[#DEEBF7] dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0]', // хэлтэс — цайвар цэнхэр
        'bg-[#E2EFDA] dark:bg-[#1c3324] text-[#2d5016] dark:text-[#8fd19e]', // алба/хэсэг — цайвар ногоон
    ];
    return $palette[$depth % count($palette)];
}

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
?>
<style>
    @media print {
        #sidebarPrintHide, header, .no-print { display: none !important; }
        body { background: white !important; }
    }
    .kpi-report-table th, .kpi-report-table td { border: 1px solid #d6dbe2; }
    html.dark .kpi-report-table th, html.dark .kpi-report-table td { border-color: #2a2f3b; }
</style>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 no-print">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">KPI тайлан нэгтгэл</span>
        </nav>
        <h1 class="text-3xl font-bold">KPI тайлан нэгтгэл</h1>
    </div>
    <div class="flex items-center gap-3">
        <?php if ($periods): ?>
        <select onchange="location.href='<?= BASE_URL ?>/modules/kpi/report.php?period_id='+this.value"
                class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
            <?php foreach ($periods as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $p['id'] == $selectedPeriodId ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/kpi/report_export.php?period_id=<?= $selectedPeriodId ?>" class="bg-white dark:bg-[#272c38] border border-slate-200 dark:border-[#2a2f3b] text-slate-700 dark:text-[#c9cdd6] px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-50 dark:hover:bg-[#313745] transition-colors flex items-center gap-1.5">
            <span class="material-icons-outlined" style="font-size:16px">table_view</span> Excel-ээр татах
        </a>
        <button onclick="window.print()" class="bg-[#f1592a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors flex items-center gap-1.5">
            <span class="material-icons-outlined" style="font-size:16px">print</span> Хэвлэх
        </button>
    </div>
</div>

<?php if (!$period): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172] no-print">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">event_busy</span>
        Одоогоор KPI үнэлгээний хугацаа тохируулаагүй байна.
    </div>
<?php else: ?>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-2 overflow-x-auto">
    <table class="kpi-report-table border-collapse text-xs w-full" style="min-width:1600px">
        <thead>
            <tr class="bg-[#E2EFDA] dark:bg-[#1c3324] text-[#2d5016] dark:text-[#8fd19e] font-bold text-center">
                <th rowspan="3" class="px-2 py-2 align-middle">№</th>
                <th rowspan="3" class="px-2 py-2 align-middle">№</th>
                <th rowspan="3" class="px-3 py-2 align-middle" style="min-width:200px">Ажилтны овог, нэр</th>
                <th rowspan="3" class="px-2 py-2 align-middle">ERP код</th>
                <th colspan="<?= count($monthColumns) + 4 ?>" class="px-3 py-2 bg-[#DEEBF7] dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0]">
                    Тайлант хугацаанд ажилласан хугацаа<br>(<?= htmlspecialchars($period['start_date']) ?> - <?= htmlspecialchars($period['end_date']) ?>)
                </th>
                <th colspan="3" class="px-3 py-2 bg-[#E2EFDA] dark:bg-[#1c3324] text-[#2d5016] dark:text-[#8fd19e]">Урамшуулал тооцох цалингийн хувь, хэмжээ</th>
                <th colspan="2" class="px-3 py-2 bg-[#DEEBF7] dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0]">Урамшуулал олгох хувь, хэмжээ</th>
            </tr>
            <tr class="bg-[#DEEBF7] dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0] font-semibold text-center">
                <th colspan="<?= count($monthColumns) ?>" class="px-2 py-1.5">Ажиллавал зохих өдөр (<?= $totalWorkDays ?>)</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl" >Ажилласан өдөр</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">Өвчтэй, чөлөөтэй өдөр</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">Ажилласан хугацаа (%-иар)</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">Үндсэн цалин<br>(<?= htmlspecialchars($period['end_date']) ?>-ны өдрийн байдлаар)</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">Урамшуулал тооцох үндсэн цалингийн хувь</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">Урамшуулал тооцох цалингийн дүн</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">АГУ-ний дундаж хувь</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">Урамшуулал олгох үндсэн цалингийн хувь</th>
                <th rowspan="2" class="px-2 py-1.5 align-middle" style="writing-mode:vertical-rl">Урамшуулал олгох мөнгөн дүн</th>
            </tr>
            <tr class="bg-[#DEEBF7] dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0] font-semibold text-center">
                <?php foreach ($monthColumns as $m): ?>
                    <th class="px-2 py-1.5"><?= htmlspecialchars($m['label']) ?> (<?= $m['count'] ?>)</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php
        $rowNum = 0;

        function renderReportEmployeeRow(array $e, int &$rowNum, int $groupNum, array $monthColumns): void {
            $agu = ($e['kpi_status'] === 'completed' && $e['final_total_score'] !== null) ? number_format($e['final_total_score'] * 100, 1) . '%' : '—';
            $rowNum++;

            $pct = kpiReportTestAttendancePct($e['employee_code']);
            $isLowAttendance = $pct < 0.70;
            $workedTotal = 0;
            $monthWorked = [];
            foreach ($monthColumns as $m) {
                $wd = (int)round($m['count'] * $pct);
                $monthWorked[] = $wd;
                $workedTotal += $wd;
            }
            $requiredTotal = array_sum(array_column($monthColumns, 'count'));
            $absentTotal = $requiredTotal - $workedTotal;
            $rowClass = $isLowAttendance
                ? 'bg-red-50 dark:bg-red-950/30 hover:bg-red-100 dark:hover:bg-red-950/50'
                : 'hover:bg-slate-50 dark:hover:bg-[#20242e]';
            $pctClass = $isLowAttendance
                ? 'text-red-600 dark:text-red-400 font-bold'
                : '';
            ?>
            <tr class="<?= $rowClass ?>">
                <td class="px-2 py-1.5 text-center"><?= $rowNum ?></td>
                <td class="px-2 py-1.5 text-center"><?= $groupNum ?></td>
                <td class="px-3 py-1.5 font-medium whitespace-nowrap"><?= htmlspecialchars($e['last_name'] . '. ' . $e['first_name']) ?><?= $e['position'] ? '<br><span class="text-slate-400 dark:text-[#5a6172] font-normal">' . htmlspecialchars($e['position']) . '</span>' : '' ?></td>
                <td class="px-2 py-1.5 text-center font-mono"><?= htmlspecialchars($e['employee_code']) ?></td>
                <?php foreach ($monthWorked as $wd): ?><td class="px-2 py-1.5 text-center"><?= $wd ?></td><?php endforeach; ?>
                <td class="px-2 py-1.5 text-center font-semibold"><?= $workedTotal ?></td>
                <td class="px-2 py-1.5 text-center"><?= $absentTotal ?></td>
                <td class="px-2 py-1.5 text-center <?= $pctClass ?>"><?= number_format($pct * 100, 1) ?>%</td>
                <td class="px-2 py-1.5 text-center">******</td>
                <td class="px-2 py-1.5">50%</td>
                <td class="px-2 py-1.5">******</td>
                <td class="px-2 py-1.5 text-center font-semibold <?= $agu !== '—' ? 'text-[#f1592a]' : 'text-slate-400 dark:text-[#5a6172]' ?>"><?= $agu ?></td>
                <td class="px-2 py-1.5">80%</td>
                <td class="px-2 py-1.5"></td>
            </tr>
            <?php
        }

        function renderReportUnitGroup(array $unit, array $unitsByParent, array $employeesByUnit, int $depth, int &$rowNum, array $monthColumns, int $totalCols): void {
            $hasChildren = !empty($unitsByParent[$unit['id']]);
            $unitEmployees = $employeesByUnit[(int)$unit['id']] ?? [];
            if (!$hasChildren && empty($unitEmployees)) return; // хоосон нэгжийг харуулахгүй
            $band = kpiReportGroupColor($depth);
            ?>
            <tr class="<?= $band ?> font-bold">
                <td class="px-2 py-1.5"></td>
                <td class="px-2 py-1.5"></td>
                <td class="px-3 py-1.5" colspan="2" style="padding-left: <?= 12 + $depth * 20 ?>px"><?= htmlspecialchars(mb_strtoupper($unit['name'])) ?></td>
                <?php for ($c = 0; $c < count($monthColumns) + 3; $c++): ?><td class="px-2 py-1.5"></td><?php endfor; ?>
                <td class="px-2 py-1.5 text-center">-</td>
                <td class="px-2 py-1.5"></td>
                <td class="px-2 py-1.5"></td>
                <td class="px-2 py-1.5 text-center">-</td>
                <td class="px-2 py-1.5"></td>
                <td class="px-2 py-1.5 text-center">-</td>
            </tr>
            <?php
            foreach ($unitEmployees as $i => $e) { renderReportEmployeeRow($e, $rowNum, $i + 1, $monthColumns); }
            foreach ($unitsByParent[$unit['id']] ?? [] as $child) { renderReportUnitGroup($child, $unitsByParent, $employeesByUnit, $depth + 1, $rowNum, $monthColumns, $totalCols); }
        }

        if (empty($employees)): ?>
            <tr><td colspan="<?= $totalCols ?>" class="text-center py-10 text-slate-400 dark:text-[#5a6172]">Ажилтан байхгүй байна</td></tr>
        <?php else:
            foreach ($unitsByParent[0] ?? [] as $rootUnit) {
                renderReportUnitGroup($rootUnit, $unitsByParent, $employeesByUnit, 0, $rowNum, $monthColumns, $totalCols);
            }
            if ($unassigned): ?>
                <tr class="bg-slate-100 dark:bg-[#272c38] font-bold">
                    <td class="px-2 py-1.5"></td>
                    <td class="px-2 py-1.5"></td>
                    <td class="px-3 py-1.5" colspan="<?= $totalCols - 2 ?>">НЭГЖ ТОДОРХОЙГҮЙ</td>
                </tr>
                <?php foreach ($unassigned as $i => $e) { renderReportEmployeeRow($e, $rowNum, $i + 1, $monthColumns); } ?>
            <?php endif;
        endif; ?>
        </tbody>
    </table>
</div>

<p class="text-xs text-slate-400 dark:text-[#5a6172] no-print flex items-center gap-2 flex-wrap">
    <span>Ирцийн баганууд (сар тус бүр, Ажилласан өдөр, Ажилласан хугацаа %) загвар/тест дата бөгөөд цалин, урамшууллын баганууд гараар бөглөх/эсвэл цаашид тусад нь автоматжуулах боломжтой.
    "АГУ-ний дундаж хувь" багана зөвхөн KPI-ээ бүрэн хааж дууссан ажилтанд харагдана.</span>
    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400 font-medium whitespace-nowrap">
        <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span> Ажиллах ёстой өдрийн 70%-иас доош ирцтэй
    </span>
</p>

<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
