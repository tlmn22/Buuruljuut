<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/kpi/evaluator_sync.php';
header('Content-Type: application/json');
requireRole('superadmin');

$action = $_POST['action'] ?? '';
$pdo    = getDB();

function uploadPhoto() {
    if (empty($_FILES['photo']['name'])) return null;

    $file    = $_FILES['photo'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed))
        throw new Exception('Зурагны төрөл буруу байна. JPG, PNG, WEBP байна уу.');
    if ($file['size'] > 2 * 1024 * 1024)
        throw new Exception('Зураг 2MB-аас хэтэрч байна.');

    if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);

    $filename = uniqid('emp_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $filename))
        throw new Exception('Зураг хадгалахад алдаа гарлаа.');

    return $filename;
}

/** Ажилтанд шинэ login (users + user_roles) үүсгэх */
function createLogin(PDO $pdo, int $employeeId, string $employeeCode, string $password, int $roleId): void {
    if (strlen($password) < 6) throw new Exception('Нууц үг хамгийн багадаа 6 тэмдэгт байна уу.');

    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $chk->execute([$employeeCode]);
    if ($chk->fetch()) throw new Exception('Энэ нэвтрэх нэр аль хэдийн бүртгэлтэй байна.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, is_active) VALUES (?,?,?,1)")
        ->execute([$employeeId, $employeeCode, $hash]);
    $userId = (int)$pdo->lastInsertId();

    if ($roleId) {
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$userId, $roleId]);
    }
}

try {
    switch ($action) {

        case 'create':
            $last_name  = trim($_POST['last_name'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $emp_code   = trim($_POST['employee_code'] ?? '');
            $register   = trim($_POST['register_number'] ?? '');
            $phone      = trim($_POST['phone'] ?? '') ?: null;
            $email      = trim($_POST['email'] ?? '') ?: null;
            $position   = trim($_POST['position'] ?? '') ?: null;
            $hire_date  = $_POST['hire_date'] ?: null;
            $contract   = trim($_POST['contract_type'] ?? '') ?: null;
            $unit_id    = intval($_POST['org_unit_id'] ?? 0) ?: null;
            $active     = intval($_POST['is_active'] ?? 1);

            if (!$last_name || !$first_name || !$emp_code || !$register)
                throw new Exception('Овог, нэр, код, регистр заавал оруулна уу.');

            $chk = $pdo->prepare("SELECT id FROM employees WHERE employee_code=? OR register_number=?");
            $chk->execute([$emp_code, $register]);
            if ($chk->fetch()) throw new Exception('Ажилтны код эсвэл регистр аль хэдийн бүртгэлтэй байна.');

            $photo = uploadPhoto();

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO employees
                (employee_code, last_name, first_name, register_number, email, phone, position,
                 hire_date, org_unit_id, photo, contract_type, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$emp_code, $last_name, $first_name, $register, $email, $phone, $position,
                             $hire_date, $unit_id, $photo, $contract, $active]);
            $employeeId = (int)$pdo->lastInsertId();

            if (intval($_POST['create_login'] ?? 0)) {
                $password = trim($_POST['password'] ?? '');
                if (!$password) throw new Exception('Нэвтрэх эрх үүсгэхийн тулд нууц үг оруулна уу.');
                createLogin($pdo, $employeeId, $emp_code, $password, intval($_POST['role_id'] ?? 0));
            }

            kpiSyncAutoEvaluators($pdo, [$employeeId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Ажилтан амжилттай нэмэгдлээ.']);
            break;

        case 'update':
            $id         = intval($_POST['id'] ?? 0);
            $last_name  = trim($_POST['last_name'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $emp_code   = trim($_POST['employee_code'] ?? '');
            $register   = trim($_POST['register_number'] ?? '');
            $phone      = trim($_POST['phone'] ?? '') ?: null;
            $email      = trim($_POST['email'] ?? '') ?: null;
            $position   = trim($_POST['position'] ?? '') ?: null;
            $hire_date  = $_POST['hire_date'] ?: null;
            $contract   = trim($_POST['contract_type'] ?? '') ?: null;
            $unit_id    = intval($_POST['org_unit_id'] ?? 0) ?: null;
            $active     = intval($_POST['is_active'] ?? 1);

            if (!$id || !$last_name || !$first_name || !$emp_code || !$register)
                throw new Exception('Мэдээлэл дутуу байна.');

            $chk = $pdo->prepare("SELECT id FROM employees WHERE (employee_code=? OR register_number=?) AND id!=?");
            $chk->execute([$emp_code, $register, $id]);
            if ($chk->fetch()) throw new Exception('Ажилтны код эсвэл регистр аль хэдийн бүртгэлтэй байна.');

            $photo = uploadPhoto();

            $pdo->beginTransaction();

            if ($photo) {
                $old = $pdo->prepare("SELECT photo FROM employees WHERE id=?");
                $old->execute([$id]);
                $oldPhoto = $old->fetchColumn();
                if ($oldPhoto && file_exists(UPLOAD_PATH . $oldPhoto)) unlink(UPLOAD_PATH . $oldPhoto);
                $pdo->prepare("UPDATE employees SET photo=? WHERE id=?")->execute([$photo, $id]);
            }

            $stmt = $pdo->prepare("
                UPDATE employees SET
                    employee_code=?, last_name=?, first_name=?, register_number=?, email=?, phone=?,
                    position=?, hire_date=?, org_unit_id=?, contract_type=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([$emp_code, $last_name, $first_name, $register, $email, $phone,
                             $position, $hire_date, $unit_id, $contract, $active, $id]);

            $userId = intval($_POST['user_id'] ?? 0);
            $roleId = intval($_POST['role_id'] ?? 0);
            $password = trim($_POST['password'] ?? '');

            if ($userId) {
                // Байгаа login-г шинэчлэх: username-г кодтой нь синк хийж, идэвх/нууц үг/эрхийг шинэчилнэ
                $pdo->prepare("UPDATE users SET username=?, is_active=? WHERE id=?")
                    ->execute([$emp_code, $active, $userId]);

                if ($password) {
                    if (strlen($password) < 6) throw new Exception('Нууц үг хамгийн багадаа 6 тэмдэгт байна уу.');
                    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                }

                if ($roleId) {
                    $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$userId]);
                    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$userId, $roleId]);
                }
            } elseif (intval($_POST['create_login'] ?? 0)) {
                if (!$password) throw new Exception('Нэвтрэх эрх үүсгэхийн тулд нууц үг оруулна уу.');
                createLogin($pdo, $id, $emp_code, $password, $roleId);
            }

            kpiSyncAutoEvaluators($pdo, [$id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Ажилтны мэдээлэл засагдлаа.']);
            break;

        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID олдсонгүй.');

            $old = $pdo->prepare("SELECT photo FROM employees WHERE id=?");
            $old->execute([$id]);
            $oldPhoto = $old->fetchColumn();
            if ($oldPhoto && file_exists(UPLOAD_PATH . $oldPhoto)) unlink(UPLOAD_PATH . $oldPhoto);

            // users/user_roles/user_permissions нь ON DELETE CASCADE-ээр автоматаар устана
            $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Ажилтан устгагдлаа.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
