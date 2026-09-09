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
