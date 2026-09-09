<?php
/**
 * "Хэлтсийн удирдагч = KPI үнэлэгч" автомат синк.
 *
 * Ажилтны default_evaluator_id-г тухайн ажилтны org_unit-ийн удирдагчаас
 * (manager_employee_id) автоматаар тооцно. Хэрэв тухайн нэгжид удирдагч
 * тохируулаагүй бол эцэг нэгж рүү өгсөж, эхний олдсон удирдагчийг ашиглана.
 *
 * HR/superadmin гараар тохируулсан бол (evaluator_source='manual') автомат
 * синк тэр ажилтныг дахин дарж бичихгүй — зөвхөн 'auto' ажилтнуудад нөлөөлнө.
 */

/** org_unit-ээс эхлээд эцэг рүү өгсөж, эхний удирдагчийг олно (өөрийгөө хасаж) */
function kpiResolveAutoEvaluator(PDO $pdo, ?int $orgUnitId, int $employeeId): ?int {
    $guard = 0;
    while ($orgUnitId && $guard++ < 20) {
        $stmt = $pdo->prepare("SELECT manager_employee_id, parent_id FROM org_units WHERE id = ?");
        $stmt->execute([$orgUnitId]);
        $unit = $stmt->fetch();
        if (!$unit) return null;

        if ($unit['manager_employee_id'] && (int)$unit['manager_employee_id'] !== $employeeId) {
            return (int)$unit['manager_employee_id'];
        }
        $orgUnitId = $unit['parent_id'] ? (int)$unit['parent_id'] : null;
    }
    return null;
}

/**
 * Өгөгдсөн ажилтнуудын (эсвэл бүх 'auto' ажилтны) default_evaluator_id-г дахин тооцоолж хадгална.
 * @param int[]|null $employeeIds null бол бүх evaluator_source='auto' ажилтныг синк хийнэ
 */
function kpiSyncAutoEvaluators(PDO $pdo, ?array $employeeIds = null): void {
    if ($employeeIds === null) {
        $rows = $pdo->query("SELECT id, org_unit_id FROM employees WHERE evaluator_source = 'auto' AND is_active = 1")->fetchAll();
    } else {
        if (empty($employeeIds)) return;
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $pdo->prepare("SELECT id, org_unit_id FROM employees WHERE evaluator_source = 'auto' AND id IN ($placeholders)");
        $stmt->execute($employeeIds);
        $rows = $stmt->fetchAll();
    }

    $update = $pdo->prepare("UPDATE employees SET default_evaluator_id = ? WHERE id = ?");
    foreach ($rows as $row) {
        $evaluatorId = kpiResolveAutoEvaluator($pdo, $row['org_unit_id'] ? (int)$row['org_unit_id'] : null, (int)$row['id']);
        $update->execute([$evaluatorId, $row['id']]);
        if ($evaluatorId) {
            kpiGrantEvaluatorPermissions($pdo, $evaluatorId);
        }
    }
}

/**
 * Тухайн ажилтан удирддаг (org_units.manager_employee_id) бүх нэгж + тэдгээрийн
 * бүх дэд нэгжийн id-г буцаана. Жишээ нь газрын захирал бол доорх бүх хэлтэс/алба
 * (тэдгээрийн өөрсдийн удирдагчтай ч гэсэн) хамрагдана — хяналтын өргөн харагдацад ашиглана.
 */
function kpiManagedOrgUnitIds(PDO $pdo, int $managerEmployeeId): array {
    $rootStmt = $pdo->prepare("SELECT id FROM org_units WHERE manager_employee_id = ?");
    $rootStmt->execute([$managerEmployeeId]);
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

/** Үнэлэгчид kpi.approve/kpi.evaluate эрхийг (байхгүй бол) dynamic байдлаар олгоно */
function kpiGrantEvaluatorPermissions(PDO $pdo, int $evaluatorEmployeeId): void {
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
    $userStmt->execute([$evaluatorEmployeeId]);
    $userId = $userStmt->fetchColumn();
    if (!$userId) return;

    $permIds = $pdo->query("SELECT id FROM permissions WHERE code IN ('kpi.approve','kpi.evaluate')")->fetchAll(PDO::FETCH_COLUMN);
    $grant = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_id, effect) VALUES (?,?, 'allow')");
    foreach ($permIds as $pid) {
        $grant->execute([$userId, $pid]);
    }
}
