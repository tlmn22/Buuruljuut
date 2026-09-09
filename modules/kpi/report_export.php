<?php
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
if (!$period) { http_response_code(404); exit('Хугацаа сонгогдоогүй байна.'); }

function kpiReportExportWeekdayColumns(array $period): array {
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
$monthColumns = kpiReportExportWeekdayColumns($period);
$totalWorkDays = array_sum(array_column($monthColumns, 'count'));

$employees = [];
$unassigned = [];
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

$totalDataCols = 4 + count($monthColumns) + 3 + 3 + 3 + 0;

function kpiReportExportTestAttendancePct(string $employeeCode): float {
    $seed = crc32($employeeCode);
    return (55 + ($seed % 46)) / 100;
}

function kpiReportExportGroupColor(int $depth): string {
    $palette = ['#FCE4D6', '#DEEBF7', '#E2EFDA'];
    return $palette[$depth % count($palette)];
}

$filename = 'KPI_tailan_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $period['name']) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: public');

$thStyle  = "border:1px solid #999;padding:4px 6px;font-weight:bold;text-align:center;vertical-align:middle;";
$tdStyle  = "border:1px solid #ccc;padding:3px 6px;";
$tdCenter = $tdStyle . "text-align:center;";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>KPI тайлан</x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 11px; }
</style>
</head>
<body>
<table>
    <tr>
        <th colspan="<?= 4 + count($monthColumns) + 9 ?>" style="text-align:left;font-size:14px;padding:6px;">
            KPI тайлан нэгтгэл — <?= htmlspecialchars($period['name']) ?> (<?= htmlspecialchars($period['start_date']) ?> - <?= htmlspecialchars($period['end_date']) ?>)
        </th>
    </tr>
    <tr><td colspan="<?= 4 + count($monthColumns) + 9 ?>">&nbsp;</td></tr>
    <tr style="background:#E2EFDA;">
        <th rowspan="3" style="<?= $thStyle ?>">№</th>
        <th rowspan="3" style="<?= $thStyle ?>">№</th>
        <th rowspan="3" style="<?= $thStyle ?>">Ажилтны овог, нэр</th>
        <th rowspan="3" style="<?= $thStyle ?>">ERP код</th>
        <th colspan="<?= count($monthColumns) + 4 ?>" style="<?= $thStyle ?>background:#DEEBF7;">Тайлант хугацаанд ажилласан хугацаа (<?= htmlspecialchars($period['start_date']) ?> - <?= htmlspecialchars($period['end_date']) ?>)</th>
        <th colspan="3" style="<?= $thStyle ?>background:#E2EFDA;">Урамшуулал тооцох цалингийн хувь, хэмжээ</th>
        <th colspan="2" style="<?= $thStyle ?>background:#DEEBF7;">Урамшуулал олгох хувь, хэмжээ</th>
    </tr>
    <tr style="background:#DEEBF7;">
        <th colspan="<?= count($monthColumns) ?>" style="<?= $thStyle ?>">Ажиллавал зохих өдөр (<?= $totalWorkDays ?>)</th>
        <th rowspan="2" style="<?= $thStyle ?>">Ажилласан өдөр</th>
        <th rowspan="2" style="<?= $thStyle ?>">Өвчтэй, чөлөөтэй өдөр</th>
        <th rowspan="2" style="<?= $thStyle ?>">Ажилласан хугацаа (%-иар)</th>
        <th rowspan="2" style="<?= $thStyle ?>">Үндсэн цалин (<?= htmlspecialchars($period['end_date']) ?>-ны өдрийн байдлаар)</th>
        <th rowspan="2" style="<?= $thStyle ?>">Урамшуулал тооцох үндсэн цалингийн хувь</th>
        <th rowspan="2" style="<?= $thStyle ?>">Урамшуулал тооцох цалингийн дүн</th>
        <th rowspan="2" style="<?= $thStyle ?>">АГУ-ний дундаж хувь</th>
        <th rowspan="2" style="<?= $thStyle ?>">Урамшуулал олгох үндсэн цалингийн хувь</th>
        <th rowspan="2" style="<?= $thStyle ?>">Урамшуулал олгох мөнгөн дүн</th>
    </tr>
    <tr style="background:#DEEBF7;">
        <?php foreach ($monthColumns as $m): ?>
            <th style="<?= $thStyle ?>"><?= htmlspecialchars($m['label']) ?> (<?= $m['count'] ?>)</th>
        <?php endforeach; ?>
    </tr>
    <?php
    $rowNum = 0;

    function renderExportEmployeeRow(array $e, int &$rowNum, int $groupNum, array $monthColumns, string $tdStyle, string $tdCenter): void {
        $agu = ($e['kpi_status'] === 'completed' && $e['final_total_score'] !== null) ? number_format($e['final_total_score'] * 100, 1) . '%' : '—';
        $rowNum++;
        $name = $e['last_name'] . '. ' . $e['first_name'];
        if ($e['position']) $name .= "\n" . $e['position'];

        $pct = kpiReportExportTestAttendancePct($e['employee_code']);
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
        $rowBg = $isLowAttendance ? 'background:#FADBD8;' : '';
        $pctStyle = $tdCenter . ($isLowAttendance ? 'color:#c0392b;font-weight:bold;' : '');
        ?>
        <tr style="<?= $rowBg ?>">
            <td style="<?= $tdCenter . $rowBg ?>"><?= $rowNum ?></td>
            <td style="<?= $tdCenter . $rowBg ?>"><?= $groupNum ?></td>
            <td style="<?= $tdStyle . $rowBg ?>"><?= nl2br(htmlspecialchars($name)) ?></td>
            <td style="<?= $tdCenter . $rowBg ?>"><?= htmlspecialchars($e['employee_code']) ?></td>
            <?php foreach ($monthWorked as $wd): ?><td style="<?= $tdCenter . $rowBg ?>"><?= $wd ?></td><?php endforeach; ?>
            <td style="<?= $tdCenter . $rowBg ?>"><?= $workedTotal ?></td>
            <td style="<?= $tdCenter . $rowBg ?>"><?= $absentTotal ?></td>
            <td style="<?= $pctStyle ?>"><?= number_format($pct * 100, 1) ?>%</td>
            <td style="<?= $tdCenter . $rowBg ?>">******</td>
            <td style="<?= $tdStyle . $rowBg ?>"></td>
            <td style="<?= $tdStyle . $rowBg ?>"></td>
            <td style="<?= $tdCenter . $rowBg ?>"><?= $agu ?></td>
            <td style="<?= $tdStyle . $rowBg ?>"></td>
            <td style="<?= $tdStyle . $rowBg ?>"></td>
        </tr>
        <?php
    }

    function renderExportUnitGroup(array $unit, array $unitsByParent, array $employeesByUnit, int $depth, int &$rowNum, array $monthColumns, string $tdStyle, string $tdCenter): void {
        $hasChildren = !empty($unitsByParent[$unit['id']]);
        $unitEmployees = $employeesByUnit[(int)$unit['id']] ?? [];
        if (!$hasChildren && empty($unitEmployees)) return;
        $color = kpiReportExportGroupColor($depth);
        ?>
        <tr style="background:<?= $color ?>;font-weight:bold;">
            <td style="<?= $tdStyle ?>"></td>
            <td style="<?= $tdStyle ?>"></td>
            <td colspan="2" style="<?= $tdStyle ?>padding-left:<?= 6 + $depth * 14 ?>px;"><?= htmlspecialchars(mb_strtoupper($unit['name'])) ?></td>
            <?php for ($c = 0; $c < count($monthColumns) + 3; $c++): ?><td style="<?= $tdStyle ?>"></td><?php endfor; ?>
            <td style="<?= $tdCenter ?>">-</td>
            <td style="<?= $tdStyle ?>"></td>
            <td style="<?= $tdStyle ?>"></td>
            <td style="<?= $tdCenter ?>">-</td>
            <td style="<?= $tdStyle ?>"></td>
            <td style="<?= $tdCenter ?>">-</td>
        </tr>
        <?php
        foreach ($unitEmployees as $i => $e) { renderExportEmployeeRow($e, $rowNum, $i + 1, $monthColumns, $tdStyle, $tdCenter); }
        foreach ($unitsByParent[$unit['id']] ?? [] as $child) { renderExportUnitGroup($child, $unitsByParent, $employeesByUnit, $depth + 1, $rowNum, $monthColumns, $tdStyle, $tdCenter); }
    }

    if (empty($employees)): ?>
        <tr><td colspan="<?= $totalDataCols ?>" style="<?= $tdCenter ?>">Ажилтан байхгүй байна</td></tr>
    <?php else:
        foreach ($unitsByParent[0] ?? [] as $rootUnit) {
            renderExportUnitGroup($rootUnit, $unitsByParent, $employeesByUnit, 0, $rowNum, $monthColumns, $tdStyle, $tdCenter);
        }
        if ($unassigned): ?>
            <tr style="background:#f0f0f0;font-weight:bold;">
                <td style="<?= $tdStyle ?>"></td>
                <td style="<?= $tdStyle ?>"></td>
                <td colspan="<?= $totalDataCols - 2 ?>" style="<?= $tdStyle ?>">НЭГЖ ТОДОРХОЙГҮЙ</td>
            </tr>
            <?php foreach ($unassigned as $i => $e) { renderExportEmployeeRow($e, $rowNum, $i + 1, $monthColumns, $tdStyle, $tdCenter); } ?>
        <?php endif;
    endif; ?>
</table>
</body>
</html>
