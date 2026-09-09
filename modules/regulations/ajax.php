<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json');
requireLogin();

$action = $_POST['action'] ?? '';
$pdo    = getDB();
$me     = currentUser();

function requireRegulationsManage(): void {
    if (!hasPermission('regulations.manage')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Эрх хүрэлцэхгүй байна.']);
        exit;
    }
}

function requireSuperAdminJson(): void {
    if (!isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Эрх хүрэлцэхгүй байна.']);
        exit;
    }
}

function uploadRegulationFile(): array {
    if (empty($_FILES['file']['name'])) return [null, null];

    $file = $_FILES['file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') throw new Exception('Зөвхөн PDF файл оруулна уу.');
    if ($file['size'] > REGULATIONS_MAX_FILE_SIZE) throw new Exception('Файлын хэмжээ 15MB-аас хэтэрч байна.');
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Файл хуулахад алдаа гарлаа.');

    if (!is_dir(REGULATIONS_UPLOAD_PATH)) mkdir(REGULATIONS_UPLOAD_PATH, 0755, true);

    $filename = uniqid('reg_', true) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], REGULATIONS_UPLOAD_PATH . $filename))
        throw new Exception('Файл хадгалахад алдаа гарлаа.');

    return [$filename, $file['name']];
}

try {
    switch ($action) {

        // ─── Ангилал ────────────────────────────────────────────────
        case 'category_create':
            requireRegulationsManage();
            $name = trim($_POST['name'] ?? '');
            if (!$name) throw new Exception('Ангиллын нэрийг оруулна уу.');
            $pdo->prepare("INSERT INTO regulation_categories (name) VALUES (?)")->execute([$name]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Ангилал нэмэгдлээ.']);
            break;

        case 'category_update':
            requireRegulationsManage();
            $id   = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (!$id || !$name) throw new Exception('Мэдээлэл дутуу байна.');
            $pdo->prepare("UPDATE regulation_categories SET name=? WHERE id=?")->execute([$name, $id]);
            echo json_encode(['success' => true, 'message' => 'Ангилал засагдлаа.']);
            break;

        case 'category_delete':
            requireRegulationsManage();
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID олдсонгүй.');
            $pdo->prepare("DELETE FROM regulation_categories WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Ангилал устгагдлаа.']);
            break;

        // ─── Дүрэм журам ────────────────────────────────────────────
        case 'create':
            requireRegulationsManage();
            $title       = trim($_POST['title'] ?? '');
            $categoryId  = intval($_POST['category_id'] ?? 0) ?: null;
            $description = trim($_POST['description'] ?? '') ?: null;
            $approved    = $_POST['approved_date'] ?: null;
            $requireAck  = intval($_POST['require_ack'] ?? 0);
            $orgUnitIds  = array_filter(array_map('intval', $_POST['org_unit_ids'] ?? []));

            if (!$title) throw new Exception('Гарчгийг оруулна уу.');
            [$fileName, $origName] = uploadRegulationFile();
            if (!$fileName) throw new Exception('PDF файл сонгоно уу.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO regulations (category_id, title, description, file_path, file_original_name,
                                          approved_date, require_ack, created_by, is_active)
                VALUES (?,?,?,?,?,?,?,?,1)
            ");
            $stmt->execute([$categoryId, $title, $description, $fileName, $origName,
                             $approved, $requireAck, $me['auth_id']]);
            $regId = (int)$pdo->lastInsertId();

            if ($orgUnitIds) {
                $ins = $pdo->prepare("INSERT INTO regulation_org_units (regulation_id, org_unit_id) VALUES (?,?)");
                foreach (array_unique($orgUnitIds) as $uid) $ins->execute([$regId, $uid]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Дүрэм журам нэмэгдлээ.']);
            break;

        case 'update':
            requireRegulationsManage();
            $id          = intval($_POST['id'] ?? 0);
            $title       = trim($_POST['title'] ?? '');
            $categoryId  = intval($_POST['category_id'] ?? 0) ?: null;
            $description = trim($_POST['description'] ?? '') ?: null;
            $approved    = $_POST['approved_date'] ?: null;
            $requireAck  = intval($_POST['require_ack'] ?? 0);
            $active      = intval($_POST['is_active'] ?? 1);
            $orgUnitIds  = array_filter(array_map('intval', $_POST['org_unit_ids'] ?? []));

            if (!$id || !$title) throw new Exception('Мэдээлэл дутуу байна.');

            [$fileName, $origName] = uploadRegulationFile();

            $pdo->beginTransaction();

            if ($fileName) {
                $old = $pdo->prepare("SELECT file_path FROM regulations WHERE id=?");
                $old->execute([$id]);
                $oldFile = $old->fetchColumn();
                if ($oldFile && file_exists(REGULATIONS_UPLOAD_PATH . $oldFile)) unlink(REGULATIONS_UPLOAD_PATH . $oldFile);
                $pdo->prepare("UPDATE regulations SET file_path=?, file_original_name=? WHERE id=?")
                    ->execute([$fileName, $origName, $id]);
            }

            $pdo->prepare("
                UPDATE regulations SET
                    category_id=?, title=?, description=?, approved_date=?, require_ack=?, is_active=?
                WHERE id=?
            ")->execute([$categoryId, $title, $description, $approved, $requireAck, $active, $id]);

            $pdo->prepare("DELETE FROM regulation_org_units WHERE regulation_id=?")->execute([$id]);
            if ($orgUnitIds) {
                $ins = $pdo->prepare("INSERT INTO regulation_org_units (regulation_id, org_unit_id) VALUES (?,?)");
                foreach (array_unique($orgUnitIds) as $uid) $ins->execute([$id, $uid]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Дүрэм журам засагдлаа.']);
            break;

        case 'delete':
            requireRegulationsManage();
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID олдсонгүй.');
            $old = $pdo->prepare("SELECT file_path FROM regulations WHERE id=?");
            $old->execute([$id]);
            $oldFile = $old->fetchColumn();
            if ($oldFile && file_exists(REGULATIONS_UPLOAD_PATH . $oldFile)) unlink(REGULATIONS_UPLOAD_PATH . $oldFile);
            $pdo->prepare("DELETE FROM regulations WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Дүрэм журам устгагдлаа.']);
            break;

        // ─── Уншсан бүртгэл ─────────────────────────────────────────
        case 'mark_read':
            $regId = intval($_POST['id'] ?? 0);
            if (!$regId) throw new Exception('ID олдсонгүй.');
            $pdo->prepare("INSERT IGNORE INTO regulation_reads (regulation_id, employee_id) VALUES (?,?)")
                ->execute([$regId, $me['id']]);
            echo json_encode(['success' => true]);
            break;

        // ─── Хариуцсан ажилтан томилох (зөвхөн superadmin) ─────────
        case 'grant_manager':
            requireSuperAdminJson();
            $employeeId = intval($_POST['employee_id'] ?? 0);
            if (!$employeeId) throw new Exception('Ажилтан сонгоно уу.');
            $userStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id=?");
            $userStmt->execute([$employeeId]);
            $userId = $userStmt->fetchColumn();
            if (!$userId) throw new Exception('Энэ ажилтан нэвтрэх эрхгүй тул томилох боломжгүй.');
            $permStmt = $pdo->prepare("SELECT id FROM permissions WHERE code='regulations.manage'");
            $permStmt->execute();
            $permId = $permStmt->fetchColumn();
            $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_id, effect) VALUES (?,?, 'allow')")
                ->execute([$userId, $permId]);
            echo json_encode(['success' => true, 'message' => 'Хариуцсан ажилтнаар томиллоо.']);
            break;

        case 'revoke_manager':
            requireSuperAdminJson();
            $employeeId = intval($_POST['employee_id'] ?? 0);
            if (!$employeeId) throw new Exception('Ажилтан сонгоно уу.');
            $userStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id=?");
            $userStmt->execute([$employeeId]);
            $userId = $userStmt->fetchColumn();
            if ($userId) {
                $permStmt = $pdo->prepare("SELECT id FROM permissions WHERE code='regulations.manage'");
                $permStmt->execute();
                $permId = $permStmt->fetchColumn();
                $pdo->prepare("DELETE FROM user_permissions WHERE user_id=? AND permission_id=?")
                    ->execute([$userId, $permId]);
            }
            echo json_encode(['success' => true, 'message' => 'Эрхийг цуцаллаа.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
