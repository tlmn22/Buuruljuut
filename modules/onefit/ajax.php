<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
requireLogin();

$pdo    = getDB();
$me     = currentUser();
$myCode = $me['code'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Excel export (GET) ────────────────────────────────────────────────────────
if ($action === 'export' && isset($_GET['id'])) {
    if (!isHR() && !isSuperAdmin()) { http_response_code(403); exit; }

    $id = (int)$_GET['id'];
    $period = $pdo->prepare("SELECT * FROM onefitoid WHERE id=? LIMIT 1");
    $period->execute([$id]);
    $period = $period->fetch();
    if (!$period) { http_response_code(404); exit; }

    $regs = $pdo->prepare("
        SELECT f.EmployeeNumber, e.last_name, e.first_name, e.position,
               ou.name AS div_name, f.PhoneNumber, f.Day, f.Salary, f.Date
        FROM onefit f
        LEFT JOIN employees e ON e.employee_code = f.EmployeeNumber
        LEFT JOIN org_units ou ON ou.id = e.org_unit_id
        WHERE f.ofId = ?
        ORDER BY f.Date ASC
    ");
    $regs->execute([$id]);
    $rows = $regs->fetchAll();

    $filename = 'OneFit_' . preg_replace('/[^a-zA-Z0-9]/', '_', $period['Name']) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['№', 'Ажилтны код', 'Овог', 'Нэр', 'Албан тушаал', 'Хэлтэс', 'Утас', 'Хоног', 'Суутгал зөвшөөрсөн', 'Бүртгүүлсэн огноо']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['EmployeeNumber'],
            $r['last_name'] ?? '',
            $r['first_name'] ?? '',
            $r['position'] ?? '',
            $r['div_name'] ?? '',
            $r['PhoneNumber'],
            $r['Day'] == 2 ? '60 хоног' : '30 хоног',
            $r['Salary'] ? 'Тийм' : 'Үгүй',
            $r['Date'],
        ]);
    }
    fclose($out);
    exit;
}

// ── JSON actions (POST) ───────────────────────────────────────────────────────
header('Content-Type: application/json');

try {
    switch ($action) {

        // ── Ажилтан бүртгүүлэх ───────────────────────────────────────────────
        case 'register':
            $phone  = trim($_POST['phone'] ?? '');
            $day    = (int)($_POST['day'] ?? 1);
            $salary = (int)($_POST['salary'] ?? 1);

            if (!$myCode) throw new Exception('Ажилтны код олдсонгүй.');
            if (!$phone)  throw new Exception('Утасны дугаар оруулна уу.');
            if (!in_array($day, [1, 2])) $day = 1;

            $period = $pdo->query("SELECT * FROM onefitoid WHERE Status=1 ORDER BY id DESC LIMIT 1")->fetch();
            if (!$period) throw new Exception('Идэвхтэй бүртгэл байхгүй байна.');

            $today = date('Y-m-d');
            if ($today < $period['StartDate'] || $today > $period['EndDate']) {
                throw new Exception('Бүртгэлийн хугацаа дууссан байна.');
            }

            // Давхар бүртгэл шалгах
            $chk = $pdo->prepare("SELECT id FROM onefit WHERE ofId=? AND EmployeeNumber=? LIMIT 1");
            $chk->execute([$period['id'], $myCode]);
            if ($chk->fetchColumn()) throw new Exception('Та аль хэдийн бүртгүүлсэн байна.');

            $pdo->prepare("
                INSERT INTO onefit (ofId, EmployeeNumber, PhoneNumber, Day, Salary, Date, Status)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ")->execute([$period['id'], $myCode, $phone, $day, $salary, date('Y/m/d')]);

            echo json_encode(['success' => true, 'message' => 'Амжилттай бүртгүүллээ!']);
            break;

        // ── Бүртгэлээс гарах ─────────────────────────────────────────────────
        case 'cancel':
            $period = $pdo->query("SELECT * FROM onefitoid WHERE Status=1 ORDER BY id DESC LIMIT 1")->fetch();
            if (!$period) throw new Exception('Идэвхтэй бүртгэл байхгүй.');

            $today = date('Y-m-d');
            if ($today > $period['EndDate']) throw new Exception('Бүртгэлийн хугацаа дууссан тул цуцлах боломжгүй.');

            $del = $pdo->prepare("DELETE FROM onefit WHERE ofId=? AND EmployeeNumber=?");
            $del->execute([$period['id'], $myCode]);

            echo json_encode(['success' => true, 'message' => 'Бүртгэл цуцлагдлаа.']);
            break;

        // ── Админ: ажилтан гараас нэмэх ─────────────────────────────────────
        case 'admin_register':
            if (!isHR() && !isSuperAdmin()) throw new Exception('Эрх хүрэлцэхгүй.');
            $empCode = trim($_POST['emp_code'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            if (!$empCode) throw new Exception('Ажилтны код оруулна уу.');
            if (!$phone)   throw new Exception('Утасны дугаар оруулна уу.');

            $empCheck = $pdo->prepare("SELECT id FROM employees WHERE employee_code=? AND is_active=1 LIMIT 1");
            $empCheck->execute([$empCode]);
            if (!$empCheck->fetchColumn()) throw new Exception('Тухайн кодтой идэвхтэй ажилтан олдсонгүй.');

            $period = $pdo->query("SELECT * FROM onefitoid WHERE Status=1 ORDER BY id DESC LIMIT 1")->fetch();
            if (!$period) throw new Exception('Идэвхтэй бүртгэл байхгүй байна.');

            $chk = $pdo->prepare("SELECT id FROM onefit WHERE ofId=? AND EmployeeNumber=? LIMIT 1");
            $chk->execute([$period['id'], $empCode]);
            if ($chk->fetchColumn()) throw new Exception('Тухайн ажилтан аль хэдийн бүртгүүлсэн байна.');

            $pdo->prepare("
                INSERT INTO onefit (ofId, EmployeeNumber, PhoneNumber, Day, Salary, Date, Status)
                VALUES (?, ?, ?, 1, 1, ?, 1)
            ")->execute([$period['id'], $empCode, $phone, date('Y/m/d')]);

            echo json_encode(['success' => true, 'message' => "$empCode — амжилттай нэмэгдлээ."]);
            break;

        // ── Шинэ бүртгэл нээх (HR) ───────────────────────────────────────────
        case 'create_period':
            if (!isHR() && !isSuperAdmin()) throw new Exception('Эрх хүрэлцэхгүй.');
            $name  = trim($_POST['name'] ?? '');
            $start = $_POST['start'] ?? '';
            $end   = $_POST['end'] ?? '';
            if (!$name || !$start || !$end) throw new Exception('Бүх талбарыг бөглөнө үү.');

            // Өмнөх идэвхтэй бүртгэлийг хаах
            $pdo->exec("UPDATE onefitoid SET Status=0 WHERE Status=1");

            $pdo->prepare("INSERT INTO onefitoid (Name, StartDate, EndDate, Status) VALUES (?, ?, ?, 1)")
                ->execute([$name, $start, $end]);

            echo json_encode(['success' => true, 'message' => 'Бүртгэл амжилттай нээгдлээ.']);
            break;

        // ── Бүртгэл хаах (HR) ────────────────────────────────────────────────
        case 'close_period':
            if (!isHR() && !isSuperAdmin()) throw new Exception('Эрх хүрэлцэхгүй.');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID олдсонгүй.');
            $pdo->prepare("UPDATE onefitoid SET Status=0 WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Бүртгэл хаагдлаа.']);
            break;

        default:
            throw new Exception('Үйлдэл олдсонгүй.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
