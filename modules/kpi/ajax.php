<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/evaluator_sync.php';
header('Content-Type: application/json');
requireLogin();

$action = $_POST['action'] ?? '';
$pdo    = getDB();
$me     = currentUser();
$myEmployeeId = (int)$me['id'];

/** Тухайн evaluation-г татаад, олдоогүй бол Exception шидэх */
function loadEvaluation(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM kpi_evaluations WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('KPI хуудас олдсонгүй.');
    return $row;
}

function loadPeriod(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM kpi_periods WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Хугацаа олдсонгүй.');
    return $row;
}

try {
    switch ($action) {

        // ─── Ажилтан: шинэ хуудас үүсгэх ────────────────────────────
        case 'create_evaluation':
            $periodId = intval($_POST['period_id'] ?? 0);
            if (!$periodId) throw new Exception('Хугацааг сонгоно уу.');

            $chk = $pdo->prepare("SELECT id FROM kpi_evaluations WHERE employee_id=? AND period_id=?");
            $chk->execute([$myEmployeeId, $periodId]);
            if ($chk->fetch()) throw new Exception('Энэ хугацаанд таны KPI хуудас аль хэдийн үүссэн байна.');

            $emp = $pdo->prepare("SELECT e.position, e.default_evaluator_id, ou.name as unit_name
                                   FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                                   WHERE e.id = ?");
            $emp->execute([$myEmployeeId]);
            $empRow = $emp->fetch();
            if (!$empRow) throw new Exception('Ажилтны мэдээлэл олдсонгүй.');
            if (!$empRow['default_evaluator_id']) throw new Exception('Таны KPI тохиргоо бүрэн хийгдээгүй байна. Та HR т хандана уу.');

            $pdo->prepare("INSERT INTO kpi_evaluations
                (period_id, employee_id, evaluator_id, position_snapshot, org_unit_snapshot, status)
                VALUES (?,?,?,?,?, 'planning')")
                ->execute([$periodId, $myEmployeeId, $empRow['default_evaluator_id'], $empRow['position'], $empRow['unit_name']]);

            echo json_encode(['success' => true, 'message' => 'Шинэ KPI хуудас үүслээ.', 'id' => (int)$pdo->lastInsertId()]);
            break;

        // ─── Мөр нэмэх ───────────────────────────────────────────────
        case 'item_add':
            $evalId  = intval($_POST['evaluation_id'] ?? 0);
            $section = $_POST['section'] ?? '';
            $eval = loadEvaluation($pdo, $evalId);

            if ((int)$eval['employee_id'] !== $myEmployeeId) throw new Exception('Энэ хуудсыг засах эрхгүй байна.');
            if ($eval['status'] !== 'planning' || $eval['planning_submitted_at']) throw new Exception('Одоо мөр нэмэх боломжгүй.');
            if (!in_array($section, ['personal_kpi', 'core_duty', 'special_task'], true)) throw new Exception('Буруу хэсэг.');

            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM kpi_items WHERE evaluation_id=? AND section=?");
            $maxOrder->execute([$evalId, $section]);
            $sort = (int)$maxOrder->fetchColumn() + 1;

            // Modal-аас бүх талбарыг нэг дор дамжуулж, шууд бөглөгдсөн мөр үүсгэнэ
            $title      = trim($_POST['title'] ?? '');
            $kpiTarget  = kpiSanitizeHtml($_POST['kpi_target'] ?? '');
            $frequency  = trim($_POST['frequency'] ?? '') ?: null;
            $metric     = trim($_POST['metric'] ?? '') ?: null;
            $importance = max(1, min(3, intval($_POST['importance_weight'] ?? 1)));
            $difficulty = max(1, min(3, intval($_POST['difficulty_weight'] ?? 1)));
            if ($title === '') throw new Exception('Ажлын тайлбарыг оруулна уу.');

            $pdo->prepare("INSERT INTO kpi_items (evaluation_id, section, sort_order, title, kpi_target, frequency, metric, importance_weight, difficulty_weight)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$evalId, $section, $sort, $title, $kpiTarget, $frequency, $metric, $importance, $difficulty]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        // ─── Мөр хадгалах (төлөвлөлт эсвэл үнэлгээ шатнаас хамаарч талбар өөр) ──
        case 'item_save':
            $itemId = intval($_POST['id'] ?? 0);
            $item = $pdo->prepare("SELECT ki.*, e.employee_id, e.evaluator_id, e.status, e.planning_submitted_at
                                    FROM kpi_items ki JOIN kpi_evaluations e ON e.id = ki.evaluation_id
                                    WHERE ki.id = ?");
            $item->execute([$itemId]);
            $row = $item->fetch();
            if (!$row) throw new Exception('Мөр олдсонгүй.');

            $isOwner     = (int)$row['employee_id'] === $myEmployeeId;
            $isEvaluator = (int)$row['evaluator_id'] === $myEmployeeId;

            if ($isOwner && $row['status'] === 'planning' && !$row['planning_submitted_at']) {
                $pdo->prepare("UPDATE kpi_items SET title=?, kpi_target=?, frequency=?, metric=?, importance_weight=?, difficulty_weight=? WHERE id=?")
                    ->execute([
                        trim($_POST['title'] ?? ''),
                        kpiSanitizeHtml($_POST['kpi_target'] ?? ''),
                        trim($_POST['frequency'] ?? '') ?: null,
                        trim($_POST['metric'] ?? '') ?: null,
                        max(1, min(3, intval($_POST['importance_weight'] ?? 1))),
                        max(1, min(3, intval($_POST['difficulty_weight'] ?? 1))),
                        $itemId,
                    ]);
            } elseif ($isOwner && $row['status'] === 'planning_approved') {
                $pdo->prepare("UPDATE kpi_items SET performance_note=?, self_score=? WHERE id=?")
                    ->execute([kpiSanitizeHtml($_POST['performance_note'] ?? ''), $_POST['self_score'] !== '' ? $_POST['self_score'] : null, $itemId]);
            } elseif ($isEvaluator && $row['status'] === 'self_evaluated') {
                $pdo->prepare("UPDATE kpi_items SET manager_score=? WHERE id=?")
                    ->execute([$_POST['manager_score'] !== '' ? $_POST['manager_score'] : null, $itemId]);
            } else {
                throw new Exception('Одоогийн төлөвт энэ мөрийг засах эрхгүй байна.');
            }

            echo json_encode(['success' => true]);
            break;

        case 'item_delete':
            $itemId = intval($_POST['id'] ?? 0);
            $item = $pdo->prepare("SELECT ki.evaluation_id, e.employee_id, e.status, e.planning_submitted_at
                                    FROM kpi_items ki JOIN kpi_evaluations e ON e.id = ki.evaluation_id WHERE ki.id=?");
            $item->execute([$itemId]);
            $row = $item->fetch();
            if (!$row) throw new Exception('Мөр олдсонгүй.');
            if ((int)$row['employee_id'] !== $myEmployeeId || $row['status'] !== 'planning' || $row['planning_submitted_at'])
                throw new Exception('Одоо энэ мөрийг устгах боломжгүй.');

            $pdo->prepare("DELETE FROM kpi_items WHERE id=?")->execute([$itemId]);
            echo json_encode(['success' => true]);
            break;

        // ─── Алхам 1: Ажилтан төлөвлөгөөгөө илгээх ───────────────────
        case 'submit_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadEvaluation($pdo, $evalId);
            if ((int)$eval['employee_id'] !== $myEmployeeId) throw new Exception('Эрхгүй үйлдэл.');
            if ($eval['status'] !== 'planning') throw new Exception('Энэ хуудас аль хэдийн илгээгдсэн байна.');

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM kpi_items WHERE evaluation_id=?");
            $cnt->execute([$evalId]);
            if ($cnt->fetchColumn() < 1) throw new Exception('Дор хаяж нэг зорилт/ажил нэмнэ үү.');

            $pdo->prepare("UPDATE kpi_evaluations SET planning_submitted_at = NOW() WHERE id = ?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Төлөвлөгөө батламжид илгээгдлээ.']);
            break;

        // ─── Ажилтан: батламжаас буцаагаад дахин засах (илгээхээс өмнө) ──
        case 'recall_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadEvaluation($pdo, $evalId);
            if ((int)$eval['employee_id'] !== $myEmployeeId || $eval['status'] !== 'planning') throw new Exception('Эрхгүй үйлдэл.');
            $pdo->prepare("UPDATE kpi_evaluations SET planning_submitted_at = NULL WHERE id = ?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Дахин засах боломжтой боллоо.']);
            break;

        // ─── Алхам 2: Удирдлага батлах / буцаах ──────────────────────
        case 'approve_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadEvaluation($pdo, $evalId);
            if ((int)$eval['evaluator_id'] !== $myEmployeeId) throw new Exception('Та энэ ажилтны үнэлэгч биш байна.');
            if ($eval['status'] !== 'planning' || !$eval['planning_submitted_at']) throw new Exception('Илгээгдээгүй төлөвлөгөөг батлах боломжгүй.');

            $pdo->prepare("UPDATE kpi_evaluations SET status='planning_approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$myEmployeeId, $evalId]);
            echo json_encode(['success' => true, 'message' => 'Төлөвлөгөө батлагдлаа.']);
            break;

        case 'reject_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadEvaluation($pdo, $evalId);
            if ((int)$eval['evaluator_id'] !== $myEmployeeId) throw new Exception('Та энэ ажилтны үнэлэгч биш байна.');
            if ($eval['status'] !== 'planning' || !$eval['planning_submitted_at']) throw new Exception('Энэ хуудсыг буцаах боломжгүй.');

            $pdo->prepare("UPDATE kpi_evaluations SET planning_submitted_at = NULL WHERE id = ?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Ажилтанд засахаар буцаагдлаа.']);
            break;

        // ─── Алхам 3: Ажилтан өөрийн үнэлгээгээ илгээх ────────────────
        case 'submit_self_eval':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadEvaluation($pdo, $evalId);
            if ((int)$eval['employee_id'] !== $myEmployeeId) throw new Exception('Эрхгүй үйлдэл.');
            if ($eval['status'] !== 'planning_approved') throw new Exception('Одоо өөрийн үнэлгээ илгээх боломжгүй.');

            $missing = $pdo->prepare("SELECT COUNT(*) FROM kpi_items WHERE evaluation_id=? AND self_score IS NULL");
            $missing->execute([$evalId]);
            if ($missing->fetchColumn() > 0) throw new Exception('Бүх мөрийн өөрийн үнэлгээг бөглөнө үү.');

            $pdo->prepare("UPDATE kpi_evaluations SET status='self_evaluated', self_submitted_at=NOW() WHERE id=?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Өөрийн үнэлгээ илгээгдлээ.']);
            break;

        // ─── Алхам 4: Удирдлага үнэлгээгээ илгээж хуудсыг хаах ────────
        case 'submit_manager_eval':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $interviewDate = $_POST['evaluation_interview_date'] ?: null;
            $eval = loadEvaluation($pdo, $evalId);
            if ((int)$eval['evaluator_id'] !== $myEmployeeId) throw new Exception('Та энэ ажилтны үнэлэгч биш байна.');
            if ($eval['status'] !== 'self_evaluated') throw new Exception('Одоо удирдлагын үнэлгээ илгээх боломжгүй.');

            $missing = $pdo->prepare("SELECT COUNT(*) FROM kpi_items WHERE evaluation_id=? AND manager_score IS NULL");
            $missing->execute([$evalId]);
            if ($missing->fetchColumn() > 0) throw new Exception('Бүх мөрийн удирдлагын үнэлгээг бөглөнө үү.');

            $pdo->prepare("UPDATE kpi_evaluations SET status='completed', manager_submitted_at=NOW(), evaluation_interview_date=? WHERE id=?")
                ->execute([$interviewDate, $evalId]);

            kpiRecalculateAndSave($pdo, $evalId);
            echo json_encode(['success' => true, 'message' => 'Үнэлгээ дуусаж, хуудас хаагдлаа.']);
            break;

        // ─── HR/Superadmin: хугацаа удирдах ───────────────────────────
        case 'period_create':
            requireRole('superadmin', 'hr');
            $name = trim($_POST['name'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end   = $_POST['end_date'] ?? '';
            if (!$name || !$start || !$end) throw new Exception('Мэдээлэл дутуу байна.');
            $pdo->prepare("INSERT INTO kpi_periods (name, start_date, end_date) VALUES (?,?,?)")
                ->execute([$name, $start, $end]);
            echo json_encode(['success' => true, 'message' => 'Хугацаа үүслээ.']);
            break;

        case 'period_update':
            requireRole('superadmin', 'hr');
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end   = $_POST['end_date'] ?? '';
            $active = intval($_POST['is_active'] ?? 1);
            if (!$id || !$name || !$start || !$end) throw new Exception('Мэдээлэл дутуу байна.');
            $pdo->prepare("UPDATE kpi_periods SET name=?, start_date=?, end_date=?, is_active=? WHERE id=?")
                ->execute([$name, $start, $end, $active, $id]);
            echo json_encode(['success' => true, 'message' => 'Хугацаа шинэчлэгдлээ.']);
            break;

        case 'period_delete':
            requireRole('superadmin', 'hr');
            $id = intval($_POST['id'] ?? 0);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM kpi_evaluations WHERE period_id=?");
            $cnt->execute([$id]);
            if ($cnt->fetchColumn() > 0) throw new Exception('Энэ хугацаагаар үүссэн KPI хуудас байна, устгах боломжгүй.');
            $pdo->prepare("DELETE FROM kpi_periods WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Хугацаа устгагдлаа.']);
            break;

        // ─── HR/Superadmin: үнэлэгч тохируулах ────────────────────────
        case 'set_evaluator':
            requireRole('superadmin', 'hr');
            $employeeId = intval($_POST['employee_id'] ?? 0);
            $evaluatorId = intval($_POST['evaluator_id'] ?? 0) ?: null;
            if (!$employeeId) throw new Exception('Ажилтан сонгоно уу.');
            if ($evaluatorId === $employeeId) throw new Exception('Ажилтан өөрийгөө үнэлэгч болгож чадахгүй.');

            // Гараар сонгосон тул 'manual' болгоно — цаашид хэлтсийн удирдагч солигдоход дахин дарж бичихгүй
            $pdo->prepare("UPDATE employees SET default_evaluator_id=?, evaluator_source='manual' WHERE id=?")
                ->execute([$evaluatorId, $employeeId]);

            $warning = '';
            if ($evaluatorId) {
                $userStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
                $userStmt->execute([$evaluatorId]);
                if ($userStmt->fetchColumn()) {
                    kpiGrantEvaluatorPermissions($pdo, $evaluatorId);
                } else {
                    $warning = ' Анхаар: энэ ажилтанд нэвтрэх бүртгэл байхгүй тул KPI үнэлэх боломжгүй.';
                }
            }

            echo json_encode(['success' => true, 'message' => 'Үнэлэгч тохируулагдлаа (гараар).' . $warning]);
            break;

        // ─── HR/Superadmin: гараар тохируулсныг цуцалж, дахин автомат (хэлтсийн удирдагч) руу шилжүүлэх ──
        case 'reset_evaluator_auto':
            requireRole('superadmin', 'hr');
            $employeeId = intval($_POST['employee_id'] ?? 0);
            if (!$employeeId) throw new Exception('Ажилтан сонгоно уу.');

            $pdo->prepare("UPDATE employees SET evaluator_source='auto' WHERE id=?")->execute([$employeeId]);
            kpiSyncAutoEvaluators($pdo, [$employeeId]);

            echo json_encode(['success' => true, 'message' => 'Автомат (хэлтсийн удирдагч) руу шилжлээ.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
