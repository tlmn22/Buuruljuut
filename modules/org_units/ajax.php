<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/kpi/evaluator_sync.php';
header('Content-Type: application/json');
requireRole('superadmin');

$action = $_POST['action'] ?? '';
$pdo    = getDB();

/** id-ийн бүх удам (descendant) id-г буцаана — эцэг сонгоход мөчлөг үүсэхээс сэргийлнэ */
function descendantIds(PDO $pdo, int $id): array {
    $ids = [];
    $queue = [$id];
    while ($queue) {
        $current = array_shift($queue);
        $stmt = $pdo->prepare("SELECT id FROM org_units WHERE parent_id = ?");
        $stmt->execute([$current]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($children as $childId) {
            $ids[] = $childId;
            $queue[] = $childId;
        }
    }
    return $ids;
}

try {
    switch ($action) {
        case 'create':
            $name       = trim($_POST['name'] ?? '');
            $unitType   = trim($_POST['unit_type'] ?? '') ?: null;
            $parentId   = intval($_POST['parent_id'] ?? 0) ?: null;
            $managerId  = intval($_POST['manager_employee_id'] ?? 0) ?: null;
            $desc       = trim($_POST['description'] ?? '') ?: null;
            $active     = intval($_POST['is_active'] ?? 1);

            if (!$name) throw new Exception('Нэрийг оруулна уу.');

            $companyId = $pdo->query("SELECT id FROM companies WHERE is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
            if (!$companyId) throw new Exception('Компани тохируулагдаагүй байна.');

            $pdo->prepare("INSERT INTO org_units (company_id, parent_id, unit_type, name, description, manager_employee_id, is_active)
                           VALUES (?,?,?,?,?,?,?)")
                ->execute([$companyId, $parentId, $unitType, $name, $desc, $managerId, $active]);

            if ($managerId) kpiSyncAutoEvaluators($pdo);

            echo json_encode(['success' => true, 'message' => 'Нэгж амжилттай нэмэгдлээ.']);
            break;

        case 'update':
            $id         = intval($_POST['id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            $unitType   = trim($_POST['unit_type'] ?? '') ?: null;
            $parentId   = intval($_POST['parent_id'] ?? 0) ?: null;
            $managerId  = intval($_POST['manager_employee_id'] ?? 0) ?: null;
            $desc       = trim($_POST['description'] ?? '') ?: null;
            $active     = intval($_POST['is_active'] ?? 1);

            if (!$id || !$name) throw new Exception('Мэдээлэл дутуу байна.');

            if ($parentId) {
                if ($parentId === $id) throw new Exception('Нэгж өөрийгөө эцэг болгож чадахгүй.');
                if (in_array($parentId, descendantIds($pdo, $id), true)) {
                    throw new Exception('Дэд нэгжээ эцэг болгож чадахгүй (мөчлөг үүснэ).');
                }
            }

            $pdo->prepare("UPDATE org_units SET parent_id=?, unit_type=?, name=?, description=?, manager_employee_id=?, is_active=? WHERE id=?")
                ->execute([$parentId, $unitType, $name, $desc, $managerId, $active, $id]);

            // Удирдагч/эцэг нэгж өөрчлөгдсөн байж болзошгүй тул 'auto' үнэлэгчдийг бүхэлд нь дахин тооцоолно
            kpiSyncAutoEvaluators($pdo);

            echo json_encode(['success' => true, 'message' => 'Нэгж амжилттай засагдлаа.']);
            break;

        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID олдсонгүй.');

            $empCount = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE org_unit_id = ?");
            $empCount->execute([$id]);
            if ($empCount->fetchColumn() > 0) {
                throw new Exception('Энэ нэгжид ажилтан бүртгэлтэй байна. Эхлээд ажилтныг шилжүүлнэ үү.');
            }

            $childCount = $pdo->prepare("SELECT COUNT(*) FROM org_units WHERE parent_id = ?");
            $childCount->execute([$id]);
            if ($childCount->fetchColumn() > 0) {
                throw new Exception('Энэ нэгжид дэд нэгж харьяалагдаж байна. Эхлээд тэдгээрийг устгах/шилжүүлнэ үү.');
            }

            $pdo->prepare("DELETE FROM org_units WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Нэгж устгагдлаа.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
