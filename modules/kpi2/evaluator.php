<?php
/**
 * KPI2-ийн бүрэн тусгаарлагдсан "хэн хэнийг батлах/үнэлэх" шатлал.
 *
 * Одоогийн kpi/evaluator_sync.php-ээс ЗОРИУДЛАН тусдаа: org_units.manager_employee_id
 * болон employees.default_evaluator_id-г огт ашиглахгүй. Оронд нь энэ модулийн
 * өөрийн kpi2_unit_roles хүснэгтэд (org_unit + role='manager'/'director' → employee)
 * тулгуурлана — HR/superadmin kpi2/admin.php-ээс чөлөөтэй тохируулж, туршиж болно.
 */

/**
 * Тухайн org_unit-ээс эхлээд эцэг рүү өгсөж, $employeeId-с өөр эхний
 * kpi2_unit_roles мөрийг олно (ямар ч role — manager эсвэл director).
 * Энгийн ажилтанд өөрийн хэлтсийн manager олдоно; хэрэв тухайн хэлтсийн
 * manager нь өөрөө байвал (өөрийгөө үнэлэх боломжгүй тул) дараагийн Газар
 * түвшний director хүртэл дээшээ хайна.
 */
function kpi2ResolveEvaluator(PDO $pdo, ?int $orgUnitId, int $employeeId): ?int {
    $guard = 0;
    while ($orgUnitId && $guard++ < 20) {
        $stmt = $pdo->prepare("SELECT employee_id FROM kpi2_unit_roles WHERE org_unit_id = ? AND employee_id != ? ORDER BY role LIMIT 1");
        $stmt->execute([$orgUnitId, $employeeId]);
        $found = $stmt->fetchColumn();
        if ($found) return (int)$found;

        $parentStmt = $pdo->prepare("SELECT parent_id FROM org_units WHERE id = ?");
        $parentStmt->execute([$orgUnitId]);
        $parentId = $parentStmt->fetchColumn();
        $orgUnitId = $parentId ? (int)$parentId : null;
    }
    return null;
}

/**
 * Тухайн org_unit-ийн ЭЦЭГ нэгжүүдээс (өөрөөсөө биш) эхний role='director'
 * оноогдсон ажилтныг олно — Хэлтэс/Алба-ны батлуулах ажлын багцыг хэн
 * (Газрын захирал) батлахыг тодорхойлно.
 */
function kpi2ResolveApprover(PDO $pdo, int $orgUnitId): ?int {
    $parentStmt = $pdo->prepare("SELECT parent_id FROM org_units WHERE id = ?");
    $parentStmt->execute([$orgUnitId]);
    $parentId = $parentStmt->fetchColumn();
    $orgUnitId = $parentId ? (int)$parentId : null;

    $guard = 0;
    while ($orgUnitId && $guard++ < 20) {
        $stmt = $pdo->prepare("SELECT employee_id FROM kpi2_unit_roles WHERE org_unit_id = ? AND role = 'director' LIMIT 1");
        $stmt->execute([$orgUnitId]);
        $found = $stmt->fetchColumn();
        if ($found) return (int)$found;

        $parentStmt = $pdo->prepare("SELECT parent_id FROM org_units WHERE id = ?");
        $parentStmt->execute([$orgUnitId]);
        $parentId = $parentStmt->fetchColumn();
        $orgUnitId = $parentId ? (int)$parentId : null;
    }
    return null;
}

/**
 * Тухайн ажилтан kpi2_unit_roles-ээр manager/director байгаа бүх нэгж +
 * тэдгээрийн бүх дэд нэгжийн id-г буцаана (kpiManagedOrgUnitIds-тэй адил,
 * гэхдээ эх сурвалж нь kpi2_unit_roles).
 */
function kpi2ManagedOrgUnitIds(PDO $pdo, int $employeeId): array {
    $rootStmt = $pdo->prepare("SELECT org_unit_id FROM kpi2_unit_roles WHERE employee_id = ?");
    $rootStmt->execute([$employeeId]);
    $queue = $rootStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$queue) return [];

    $all = [];
    $guard = 0;
    while ($queue && $guard++ < 500) {
        $current = array_shift($queue);
        if (in_array($current, $all, true)) continue;
        $all[] = (int)$current;

        $childStmt = $pdo->prepare("SELECT id FROM org_units WHERE parent_id = ?");
        $childStmt->execute([$current]);
        foreach ($childStmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $queue[] = $childId;
        }
    }
    return $all;
}

/**
 * kpi2_unit_roles өөрчлөгдсөний дараа тухайн нэгж + бүх дэд нэгжийн ажилтнуудын
 * БАТЛАГДААГҮЙ (status='planning') KPI2 хуудсуудын evaluator_id-г дахин тооцоолно.
 * Батлагдсан/үнэлэгдэж буй хуудсанд хүрэхгүй (батлах алхмыг өмнөх хүн хийчихсэн).
 * Шинэчлэгдсэн хуудасны тоог буцаана.
 */
function kpi2ResyncEvaluators(PDO $pdo, int $orgUnitId): int {
    $unitIds = [];
    $queue = [$orgUnitId];
    $guard = 0;
    while ($queue && $guard++ < 500) {
        $current = (int)array_shift($queue);
        if (in_array($current, $unitIds, true)) continue;
        $unitIds[] = $current;
        $childStmt = $pdo->prepare("SELECT id FROM org_units WHERE parent_id = ?");
        $childStmt->execute([$current]);
        foreach ($childStmt->fetchAll(PDO::FETCH_COLUMN) as $childId) $queue[] = $childId;
    }

    $ph = implode(',', array_fill(0, count($unitIds), '?'));
    $stmt = $pdo->prepare("
        SELECT ev.id, ev.employee_id, ev.evaluator_id, e.org_unit_id
        FROM kpi2_evaluations ev
        JOIN employees e ON e.id = ev.employee_id
        WHERE ev.status = 'planning' AND e.org_unit_id IN ($ph)
    ");
    $stmt->execute($unitIds);

    $update = $pdo->prepare("UPDATE kpi2_evaluations SET evaluator_id = ? WHERE id = ?");
    $changed = 0;
    foreach ($stmt->fetchAll() as $ev) {
        $newEvaluator = kpi2ResolveEvaluator($pdo, (int)$ev['org_unit_id'], (int)$ev['employee_id']);
        if ($newEvaluator !== ($ev['evaluator_id'] !== null ? (int)$ev['evaluator_id'] : null)) {
            $update->execute([$newEvaluator, $ev['id']]);
            $changed++;
        }
    }
    return $changed;
}

/**
 * Ажилтны тухайн хугацааны KPI2 хуудсыг буцаана; байхгүй бол үүсгэнэ
 * (батлагч = kpi2ResolveEvaluator, албан тушаал/нэгжийн snapshot-той). Хуудасны id-г буцаана.
 */
function kpi2EnsureEvaluation(PDO $pdo, int $periodId, int $employeeId): int {
    $find = $pdo->prepare("SELECT id FROM kpi2_evaluations WHERE employee_id=? AND period_id=?");
    $find->execute([$employeeId, $periodId]);
    $evalId = $find->fetchColumn();
    if ($evalId) return (int)$evalId;

    $empInfo = $pdo->prepare("SELECT position, org_unit_id FROM employees WHERE id=?");
    $empInfo->execute([$employeeId]);
    $emp = $empInfo->fetch() ?: ['position' => null, 'org_unit_id' => null];

    $unitNameVal = null;
    if ($emp['org_unit_id']) {
        $unitName = $pdo->prepare("SELECT name FROM org_units WHERE id=?");
        $unitName->execute([$emp['org_unit_id']]);
        $unitNameVal = $unitName->fetchColumn() ?: null;
    }
    $evaluatorId = kpi2ResolveEvaluator($pdo, $emp['org_unit_id'] ? (int)$emp['org_unit_id'] : null, $employeeId);

    $pdo->prepare("INSERT INTO kpi2_evaluations (period_id, employee_id, evaluator_id, position_snapshot, org_unit_snapshot, status)
                   VALUES (?,?,?,?,?, 'planning')")
        ->execute([$periodId, $employeeId, $evaluatorId, $emp['position'], $unitNameVal]);
    return (int)$pdo->lastInsertId();
}

/**
 * Батлагдсан ажлын багцын нэгжийн БҮХ идэвхтэй ажилтанд (ажил оноогдсон эсэхээс үл хамааран)
 * тухайн хугацааны KPI2 хуудас үүсгэнэ. Шинээр үүссэн хуудасны тоог буцаана.
 */
function kpi2EnsureEvaluationsForUnit(PDO $pdo, int $periodId, int $orgUnitId): int {
    $emps = $pdo->prepare("SELECT e.id FROM employees e
                           WHERE e.org_unit_id=? AND e.is_active=1
                             AND NOT EXISTS (SELECT 1 FROM kpi2_evaluations ev WHERE ev.employee_id=e.id AND ev.period_id=?)");
    $emps->execute([$orgUnitId, $periodId]);
    $created = 0;
    foreach ($emps->fetchAll(PDO::FETCH_COLUMN) as $employeeId) {
        kpi2EnsureEvaluation($pdo, $periodId, (int)$employeeId);
        $created++;
    }
    return $created;
}

/** Тухайн ажилтан аль нэг нэгжид kpi2 manager эсэхийг шалгана */
function kpi2IsManager(PDO $pdo, int $employeeId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM kpi2_unit_roles WHERE employee_id = ? AND role = 'manager'");
    $stmt->execute([$employeeId]);
    return (bool)$stmt->fetchColumn();
}

/** Тухайн ажилтан аль нэг нэгжид kpi2 director эсэхийг шалгана */
function kpi2IsDirector(PDO $pdo, int $employeeId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM kpi2_unit_roles WHERE employee_id = ? AND role = 'director'");
    $stmt->execute([$employeeId]);
    return (bool)$stmt->fetchColumn();
}
