<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
if (!hasPermission('regulations.manage')) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}
$pdo = getDB();

$regId = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM regulations WHERE id=?");
$stmt->execute([$regId]);
$reg = $stmt->fetch();
if (!$reg) { http_response_code(404); exit('Баримт олдсонгүй.'); }

$employees = $pdo->query("
    SELECT e.id, e.employee_code, e.last_name, e.first_name, e.position, e.org_unit_id, o.name AS org_name
    FROM employees e
    LEFT JOIN org_units o ON o.id = e.org_unit_id
    WHERE e.is_active = 1
    ORDER BY e.last_name, e.first_name
")->fetchAll();

$readStmt = $pdo->prepare("SELECT employee_id, read_at FROM regulation_reads WHERE regulation_id=?");
$readStmt->execute([$regId]);
$readByEmp = [];
foreach ($readStmt->fetchAll() as $r) $readByEmp[(int)$r['employee_id']] = $r['read_at'];

$rows = [];
foreach ($employees as $e) {
    $rows[] = $e + ['read_at' => $readByEmp[(int)$e['id']] ?? null];
}

$filename = 'Дурэм_журам_тайлан_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $reg['title']) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: public');

$thStyle = "border:1px solid #999;padding:5px 8px;font-weight:bold;text-align:center;background:#E2EFDA;";
$tdStyle = "border:1px solid #ccc;padding:4px 8px;";
$tdCenter = $tdStyle . "text-align:center;";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>Унших тайлан</x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
<![endif]-->
<style>table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 12px; }</style>
</head>
<body>
<table>
    <tr><th colspan="7" style="text-align:left;font-size:14px;padding:6px;background:#fff;">
        <?= htmlspecialchars($reg['title']) ?> — Унших тайлан
        <?= $reg['approved_date'] ? ' (Батлагдсан: ' . htmlspecialchars($reg['approved_date']) . ')' : '' ?>
    </th></tr>
    <tr><td colspan="7">&nbsp;</td></tr>
    <tr>
        <th style="<?= $thStyle ?>">№</th>
        <th style="<?= $thStyle ?>">Ажилтны код</th>
        <th style="<?= $thStyle ?>">Овог, нэр</th>
        <th style="<?= $thStyle ?>">Албан тушаал</th>
        <th style="<?= $thStyle ?>">Нэгж</th>
        <th style="<?= $thStyle ?>">Төлөв</th>
        <th style="<?= $thStyle ?>">Уншсан огноо</th>
    </tr>
    <?php $n = 0; foreach ($rows as $r): $n++; ?>
    <tr style="<?= $r['read_at'] ? '' : 'background:#FADBD8;' ?>">
        <td style="<?= $tdCenter ?>"><?= $n ?></td>
        <td style="<?= $tdCenter ?>"><?= htmlspecialchars($r['employee_code']) ?></td>
        <td style="<?= $tdStyle ?>"><?= htmlspecialchars($r['last_name'] . '. ' . $r['first_name']) ?></td>
        <td style="<?= $tdStyle ?>"><?= htmlspecialchars($r['position'] ?? '') ?></td>
        <td style="<?= $tdStyle ?>"><?= htmlspecialchars($r['org_name'] ?? '') ?></td>
        <td style="<?= $tdCenter ?>"><?= $r['read_at'] ? 'Үзсэн' : 'Үзээгүй' ?></td>
        <td style="<?= $tdCenter ?>"><?= htmlspecialchars($r['read_at'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</body>
</html>
