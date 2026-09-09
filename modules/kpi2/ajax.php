<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/evaluator.php';
header('Content-Type: application/json');
requireLogin();

$action = $_POST['action'] ?? '';
$pdo    = getDB();
$me     = currentUser();
$myEmployeeId = (int)$me['id'];

function loadKpi2Evaluation(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM kpi2_evaluations WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('KPI2 хуудас олдсонгүй.');
    return $row;
}

function loadKpi2Batch(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM kpi2_task_batches WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Ажлын багц олдсонгүй.');
    return $row;
}

/** Тухайн батчийг одоо (draft/rejected үед) засах эрхтэй эсэх — HR/superadmin эсвэл тухайн нэгжийн одоогийн kpi2 manager */
function canManageKpi2Batch(PDO $pdo, array $batch, int $myEmployeeId): bool {
    if (isHR() || isSuperAdmin()) return true;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM kpi2_unit_roles WHERE org_unit_id=? AND role='manager' AND employee_id=?");
    $stmt->execute([$batch['org_unit_id'], $myEmployeeId]);
    return (bool)$stmt->fetchColumn();
}

/** Тухайн батчийг батлах/буцаах эрхтэй эсэх — HR/superadmin эсвэл kpi2ResolveApprover-оор олдсон director */
function canApproveKpi2Batch(PDO $pdo, array $batch, int $myEmployeeId): bool {
    if (isHR() || isSuperAdmin()) return true;
    return kpi2ResolveApprover($pdo, (int)$batch['org_unit_id']) === $myEmployeeId;
}

try {
    switch ($action) {

        // ─── Мөр нэмэх (зөвхөн ажилтны өөрийн нэмэлт ажил) ────────────
        case 'item_add':
            $evalId  = intval($_POST['evaluation_id'] ?? 0);
            $section = $_POST['section'] ?? '';
            $eval = loadKpi2Evaluation($pdo, $evalId);

            if ((int)$eval['employee_id'] !== $myEmployeeId) throw new Exception('Энэ хуудсыг засах эрхгүй байна.');
            if ($eval['status'] !== 'planning' || $eval['planning_submitted_at']) throw new Exception('Одоо мөр нэмэх боломжгүй.');
            if (!in_array($section, ['personal_kpi', 'core_duty', 'special_task'], true)) throw new Exception('Буруу хэсэг.');

            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM kpi2_items WHERE evaluation_id=? AND section=?");
            $maxOrder->execute([$evalId, $section]);
            $sort = (int)$maxOrder->fetchColumn() + 1;

            $title      = trim($_POST['title'] ?? '');
            $kpiTarget  = kpiSanitizeHtml($_POST['kpi_target'] ?? '');
            $frequency  = trim($_POST['frequency'] ?? '') ?: null;
            $metric     = trim($_POST['metric'] ?? '') ?: null;
            $importance = max(1, min(3, intval($_POST['importance_weight'] ?? 1)));
            $difficulty = max(1, min(3, intval($_POST['difficulty_weight'] ?? 1)));
            if ($title === '') throw new Exception('Ажлын тайлбарыг оруулна уу.');

            $pdo->prepare("INSERT INTO kpi2_items (evaluation_id, section, sort_order, title, kpi_target, frequency, metric, importance_weight, difficulty_weight)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$evalId, $section, $sort, $title, $kpiTarget, $frequency, $metric, $importance, $difficulty]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        // ─── Мөр хадгалах ──────────────────────────────────────────────
        case 'item_save':
            $itemId = intval($_POST['id'] ?? 0);
            $item = $pdo->prepare("SELECT ki.*, e.employee_id, e.evaluator_id, e.status, e.planning_submitted_at
                                    FROM kpi2_items ki JOIN kpi2_evaluations e ON e.id = ki.evaluation_id
                                    WHERE ki.id = ?");
            $item->execute([$itemId]);
            $row = $item->fetch();
            if (!$row) throw new Exception('Мөр олдсонгүй.');

            $isOwner     = (int)$row['employee_id'] === $myEmployeeId;
            $isEvaluator = (int)$row['evaluator_id'] === $myEmployeeId;

            if ($isOwner && $row['status'] === 'planning' && !$row['planning_submitted_at']) {
                if ($row['org_task_id'] !== null) throw new Exception('Энэ мөр хэлтсээс батлагдсан тул засах боломжгүй.');
                $pdo->prepare("UPDATE kpi2_items SET title=?, kpi_target=?, frequency=?, metric=?, importance_weight=?, difficulty_weight=? WHERE id=?")
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
                $pdo->prepare("UPDATE kpi2_items SET performance_note=?, self_score=? WHERE id=?")
                    ->execute([kpiSanitizeHtml($_POST['performance_note'] ?? ''), $_POST['self_score'] !== '' ? $_POST['self_score'] : null, $itemId]);
            } elseif ($isEvaluator && $row['status'] === 'self_evaluated') {
                $pdo->prepare("UPDATE kpi2_items SET manager_score=? WHERE id=?")
                    ->execute([$_POST['manager_score'] !== '' ? $_POST['manager_score'] : null, $itemId]);
            } else {
                throw new Exception('Одоогийн төлөвт энэ мөрийг засах эрхгүй байна.');
            }

            echo json_encode(['success' => true]);
            break;

        case 'item_delete':
            $itemId = intval($_POST['id'] ?? 0);
            $item = $pdo->prepare("SELECT ki.evaluation_id, ki.org_task_id, e.employee_id, e.status, e.planning_submitted_at
                                    FROM kpi2_items ki JOIN kpi2_evaluations e ON e.id = ki.evaluation_id WHERE ki.id=?");
            $item->execute([$itemId]);
            $row = $item->fetch();
            if (!$row) throw new Exception('Мөр олдсонгүй.');
            if ((int)$row['employee_id'] !== $myEmployeeId || $row['status'] !== 'planning' || $row['planning_submitted_at'])
                throw new Exception('Одоо энэ мөрийг устгах боломжгүй.');
            if ($row['org_task_id'] !== null) throw new Exception('Энэ мөр хэлтсээс батлагдсан тул устгах боломжгүй.');

            $pdo->prepare("DELETE FROM kpi2_items WHERE id=?")->execute([$itemId]);
            echo json_encode(['success' => true]);
            break;

        // ─── Алхам 1: Ажилтан төлөвлөгөөгөө илгээх ───────────────────
        case 'submit_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadKpi2Evaluation($pdo, $evalId);
            if ((int)$eval['employee_id'] !== $myEmployeeId) throw new Exception('Эрхгүй үйлдэл.');
            if ($eval['status'] !== 'planning') throw new Exception('Энэ хуудас аль хэдийн илгээгдсэн байна.');

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM kpi2_items WHERE evaluation_id=?");
            $cnt->execute([$evalId]);
            if ($cnt->fetchColumn() < 1) throw new Exception('Дор хаяж нэг зорилт/ажил байна уу.');

            $pdo->prepare("UPDATE kpi2_evaluations SET planning_submitted_at = NOW() WHERE id = ?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Төлөвлөгөө батламжид илгээгдлээ.']);
            break;

        case 'recall_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadKpi2Evaluation($pdo, $evalId);
            if ((int)$eval['employee_id'] !== $myEmployeeId || $eval['status'] !== 'planning') throw new Exception('Эрхгүй үйлдэл.');
            $pdo->prepare("UPDATE kpi2_evaluations SET planning_submitted_at = NULL WHERE id = ?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Дахин засах боломжтой боллоо.']);
            break;

        // ─── Алхам 2: Удирдлага батлах / буцаах ──────────────────────
        case 'approve_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadKpi2Evaluation($pdo, $evalId);
            if ((int)$eval['evaluator_id'] !== $myEmployeeId) throw new Exception('Та энэ ажилтны үнэлэгч биш байна.');
            if ($eval['status'] !== 'planning' || !$eval['planning_submitted_at']) throw new Exception('Илгээгдээгүй төлөвлөгөөг батлах боломжгүй.');

            $pdo->prepare("UPDATE kpi2_evaluations SET status='planning_approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$myEmployeeId, $evalId]);
            echo json_encode(['success' => true, 'message' => 'Төлөвлөгөө батлагдлаа.']);
            break;

        case 'reject_planning':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadKpi2Evaluation($pdo, $evalId);
            if ((int)$eval['evaluator_id'] !== $myEmployeeId) throw new Exception('Та энэ ажилтны үнэлэгч биш байна.');
            if ($eval['status'] !== 'planning' || !$eval['planning_submitted_at']) throw new Exception('Энэ хуудсыг буцаах боломжгүй.');

            $pdo->prepare("UPDATE kpi2_evaluations SET planning_submitted_at = NULL WHERE id = ?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Ажилтанд засахаар буцаагдлаа.']);
            break;

        // ─── Алхам 3: Ажилтан өөрийн үнэлгээгээ илгээх ────────────────
        case 'submit_self_eval':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $eval = loadKpi2Evaluation($pdo, $evalId);
            if ((int)$eval['employee_id'] !== $myEmployeeId) throw new Exception('Эрхгүй үйлдэл.');
            if ($eval['status'] !== 'planning_approved') throw new Exception('Одоо өөрийн үнэлгээ илгээх боломжгүй.');

            $missing = $pdo->prepare("SELECT COUNT(*) FROM kpi2_items WHERE evaluation_id=? AND self_score IS NULL");
            $missing->execute([$evalId]);
            if ($missing->fetchColumn() > 0) throw new Exception('Бүх мөрийн өөрийн үнэлгээг бөглөнө үү.');

            $pdo->prepare("UPDATE kpi2_evaluations SET status='self_evaluated', self_submitted_at=NOW() WHERE id=?")->execute([$evalId]);
            echo json_encode(['success' => true, 'message' => 'Өөрийн үнэлгээ илгээгдлээ.']);
            break;

        // ─── Алхам 4: Удирдлага үнэлгээгээ илгээж хуудсыг хаах ────────
        case 'submit_manager_eval':
            $evalId = intval($_POST['evaluation_id'] ?? 0);
            $interviewDate = $_POST['evaluation_interview_date'] ?: null;
            $eval = loadKpi2Evaluation($pdo, $evalId);
            if ((int)$eval['evaluator_id'] !== $myEmployeeId) throw new Exception('Та энэ ажилтны үнэлэгч биш байна.');
            if ($eval['status'] !== 'self_evaluated') throw new Exception('Одоо удирдлагын үнэлгээ илгээх боломжгүй.');

            $missing = $pdo->prepare("SELECT COUNT(*) FROM kpi2_items WHERE evaluation_id=? AND manager_score IS NULL");
            $missing->execute([$evalId]);
            if ($missing->fetchColumn() > 0) throw new Exception('Бүх мөрийн удирдлагын үнэлгээг бөглөнө үү.');

            $pdo->prepare("UPDATE kpi2_evaluations SET status='completed', manager_submitted_at=NOW(), evaluation_interview_date=? WHERE id=?")
                ->execute([$interviewDate, $evalId]);

            kpi2RecalculateAndSave($pdo, $evalId);
            echo json_encode(['success' => true, 'message' => 'Үнэлгээ дуусаж, хуудас хаагдлаа.']);
            break;

        // ─── Менежер: ажлын багц үүсгэх (draft) ───────────────────────
        case 'batch_create':
            $periodId  = intval($_POST['period_id'] ?? 0);
            $orgUnitId = intval($_POST['org_unit_id'] ?? 0);
            if (!$periodId || !$orgUnitId) throw new Exception('Хугацаа, нэгжийг сонгоно уу.');

            $isManagerHere = $pdo->prepare("SELECT COUNT(*) FROM kpi2_unit_roles WHERE org_unit_id=? AND role='manager' AND employee_id=?");
            $isManagerHere->execute([$orgUnitId, $myEmployeeId]);
            if (!$isManagerHere->fetchColumn() && !isHR() && !isSuperAdmin()) throw new Exception('Та энэ нэгжийн KPI2 менежер биш байна.');

            $chk = $pdo->prepare("SELECT id FROM kpi2_task_batches WHERE period_id=? AND org_unit_id=?");
            $chk->execute([$periodId, $orgUnitId]);
            $existing = $chk->fetchColumn();
            if ($existing) { echo json_encode(['success' => true, 'id' => (int)$existing]); break; }

            $pdo->prepare("INSERT INTO kpi2_task_batches (period_id, org_unit_id, created_by, status) VALUES (?,?,?,'draft')")
                ->execute([$periodId, $orgUnitId, $myEmployeeId]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        // ─── Менежер: ажлын багцад ажил нэмэх/засах/устгах ────────────
        case 'batch_task_add':
            $batchId = intval($_POST['batch_id'] ?? 0);
            $batch = loadKpi2Batch($pdo, $batchId);
            if (!canManageKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Эрхгүй үйлдэл.');
            if (!in_array($batch['status'], ['draft', 'rejected'], true)) throw new Exception('Энэ багц одоо засах боломжгүй төлөвт байна.');

            $title      = trim($_POST['title'] ?? '');
            if ($title === '') throw new Exception('Ажлын тайлбарыг оруулна уу.');
            $kpiTarget  = kpiSanitizeHtml($_POST['kpi_target'] ?? '');
            $frequency  = trim($_POST['frequency'] ?? '') ?: null;
            $metric     = trim($_POST['metric'] ?? '') ?: null;
            $importance = max(1, min(3, intval($_POST['importance_weight'] ?? 1)));
            $difficulty = max(1, min(3, intval($_POST['difficulty_weight'] ?? 1)));

            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM kpi2_org_tasks WHERE batch_id=?");
            $maxOrder->execute([$batchId]);
            $sort = (int)$maxOrder->fetchColumn() + 1;

            $pdo->prepare("INSERT INTO kpi2_org_tasks (batch_id, title, kpi_target, frequency, metric, importance_weight, difficulty_weight, sort_order)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$batchId, $title, $kpiTarget, $frequency, $metric, $importance, $difficulty, $sort]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'batch_task_update':
            $taskId = intval($_POST['id'] ?? 0);
            $task = $pdo->prepare("SELECT t.*, b.org_unit_id, b.status as batch_status FROM kpi2_org_tasks t JOIN kpi2_task_batches b ON b.id=t.batch_id WHERE t.id=?");
            $task->execute([$taskId]);
            $row = $task->fetch();
            if (!$row) throw new Exception('Ажил олдсонгүй.');
            $batch = loadKpi2Batch($pdo, (int)$row['batch_id']);
            if (!canManageKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Эрхгүй үйлдэл.');
            if (!in_array($batch['status'], ['draft', 'rejected'], true)) throw new Exception('Энэ багц одоо засах боломжгүй төлөвт байна.');

            $title = trim($_POST['title'] ?? '');
            if ($title === '') throw new Exception('Ажлын тайлбарыг оруулна уу.');

            $pdo->prepare("UPDATE kpi2_org_tasks SET title=?, kpi_target=?, frequency=?, metric=?, importance_weight=?, difficulty_weight=? WHERE id=?")
                ->execute([
                    $title,
                    kpiSanitizeHtml($_POST['kpi_target'] ?? ''),
                    trim($_POST['frequency'] ?? '') ?: null,
                    trim($_POST['metric'] ?? '') ?: null,
                    max(1, min(3, intval($_POST['importance_weight'] ?? 1))),
                    max(1, min(3, intval($_POST['difficulty_weight'] ?? 1))),
                    $taskId,
                ]);
            echo json_encode(['success' => true]);
            break;

        case 'batch_task_delete':
            $taskId = intval($_POST['id'] ?? 0);
            $task = $pdo->prepare("SELECT t.batch_id FROM kpi2_org_tasks t WHERE t.id=?");
            $task->execute([$taskId]);
            $batchId = $task->fetchColumn();
            if (!$batchId) throw new Exception('Ажил олдсонгүй.');
            $batch = loadKpi2Batch($pdo, (int)$batchId);
            if (!canManageKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Эрхгүй үйлдэл.');
            if (!in_array($batch['status'], ['draft', 'rejected'], true)) throw new Exception('Энэ багц одоо засах боломжгүй төлөвт байна.');

            $pdo->prepare("DELETE FROM kpi2_org_tasks WHERE id=?")->execute([$taskId]);
            echo json_encode(['success' => true]);
            break;

        // ─── Менежер: ажилд нэгжийн БҮХ ажилтныг хувиар нь нэг дор хуваарилах ──
        // (нийт 100% байх ёстой — тухайн ажлын хувиарлалтыг бүхэлд нь солино)
        case 'batch_assignment_bulk_set':
            $taskId = intval($_POST['org_task_id'] ?? 0);
            $task = $pdo->prepare("SELECT t.*, b.org_unit_id, b.status as batch_status, b.id as batch_id FROM kpi2_org_tasks t JOIN kpi2_task_batches b ON b.id=t.batch_id WHERE t.id=?");
            $task->execute([$taskId]);
            $row = $task->fetch();
            if (!$row) throw new Exception('Ажил олдсонгүй.');
            $batch = loadKpi2Batch($pdo, (int)$row['batch_id']);
            if (!canManageKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Эрхгүй үйлдэл.');
            if (!in_array($batch['status'], ['draft', 'rejected'], true)) throw new Exception('Энэ багц одоо засах боломжгүй төлөвт байна.');

            $raw = json_decode($_POST['assignments'] ?? '[]', true);
            if (!is_array($raw)) throw new Exception('Буруу өгөгдөл.');

            $unitEmp = $pdo->prepare("SELECT id FROM employees WHERE org_unit_id=? AND is_active=1");
            $unitEmp->execute([$row['org_unit_id']]);
            $validIds = array_map('intval', $unitEmp->fetchAll(PDO::FETCH_COLUMN));

            $clean = [];
            $total = 0.0;
            foreach ($raw as $employeeId => $percent) {
                $employeeId = (int)$employeeId;
                $percent = (float)$percent;
                if ($percent <= 0) continue;
                if (!in_array($employeeId, $validIds, true)) throw new Exception('Ажилтан энэ нэгжид харьяалагддаггүй байна.');
                $clean[$employeeId] = $percent;
                $total += $percent;
            }
            if (empty($clean)) throw new Exception('Дор хаяж нэг ажилтанд хувь онооно уу.');
            if (abs($total - 100.0) > 0.5) throw new Exception('Нийт хувь 100% байх ёстой (одоо ' . rtrim(rtrim(number_format($total, 1), '0'), '.') . '%).');

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM kpi2_org_task_assignments WHERE org_task_id=?")->execute([$taskId]);
                $insert = $pdo->prepare("INSERT INTO kpi2_org_task_assignments (org_task_id, employee_id, percent) VALUES (?,?,?)");
                foreach ($clean as $employeeId => $percent) {
                    $insert->execute([$taskId, $employeeId, $percent]);
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Хуваарилалт хадгалагдлаа.']);
            break;

        // ─── Менежер: багцаа батлуулахаар илгээх / буцааж татах ───────
        case 'batch_submit':
            $batchId = intval($_POST['batch_id'] ?? 0);
            $batch = loadKpi2Batch($pdo, $batchId);
            if (!canManageKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Эрхгүй үйлдэл.');
            if (!in_array($batch['status'], ['draft', 'rejected'], true)) throw new Exception('Энэ багц аль хэдийн илгээгдсэн байна.');

            $taskCnt = $pdo->prepare("SELECT COUNT(*) FROM kpi2_org_tasks WHERE batch_id=?");
            $taskCnt->execute([$batchId]);
            if ($taskCnt->fetchColumn() < 1) throw new Exception('Дор хаяж нэг ажил нэмнэ үү.');

            $noAssign = $pdo->prepare("SELECT COUNT(*) FROM kpi2_org_tasks t
                                        WHERE t.batch_id=? AND NOT EXISTS (SELECT 1 FROM kpi2_org_task_assignments a WHERE a.org_task_id=t.id)");
            $noAssign->execute([$batchId]);
            if ($noAssign->fetchColumn() > 0) throw new Exception('Ажил бүрд дор хаяж нэг ажилтан оноогоогүй байна.');

            $pdo->prepare("UPDATE kpi2_task_batches SET status='pending', submitted_at=NOW(), reviewed_by=NULL, reviewed_at=NULL, review_note=NULL WHERE id=?")
                ->execute([$batchId]);
            echo json_encode(['success' => true, 'message' => 'Ажлын багц батлуулахаар илгээгдлээ.']);
            break;

        case 'batch_recall':
            $batchId = intval($_POST['batch_id'] ?? 0);
            $batch = loadKpi2Batch($pdo, $batchId);
            if (!canManageKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Эрхгүй үйлдэл.');
            if ($batch['status'] !== 'pending') throw new Exception('Энэ багцыг буцааж татах боломжгүй.');

            $pdo->prepare("UPDATE kpi2_task_batches SET status='draft', submitted_at=NULL WHERE id=?")->execute([$batchId]);
            echo json_encode(['success' => true, 'message' => 'Багц дахин засах боломжтой боллоо.']);
            break;

        // ─── Захирал: багц батлах (fan-out) / буцаах ──────────────────
        case 'batch_approve':
            $batchId = intval($_POST['batch_id'] ?? 0);
            $batch = loadKpi2Batch($pdo, $batchId);
            if (!canApproveKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Та энэ багцыг батлах эрхгүй байна.');
            if ($batch['status'] !== 'pending') throw new Exception('Энэ багц батлахад бэлэн төлөвт биш байна.');

            $pdo->prepare("UPDATE kpi2_task_batches SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$myEmployeeId, $batchId]);

            $tasks = $pdo->prepare("SELECT * FROM kpi2_org_tasks WHERE batch_id=? ORDER BY sort_order, id");
            $tasks->execute([$batchId]);
            $taskRows = $tasks->fetchAll();

            $findEval  = $pdo->prepare("SELECT id FROM kpi2_evaluations WHERE employee_id=? AND period_id=?");
            $empInfo   = $pdo->prepare("SELECT position, org_unit_id FROM employees WHERE id=?");
            $unitName  = $pdo->prepare("SELECT name FROM org_units WHERE id=?");
            $createEval = $pdo->prepare("INSERT INTO kpi2_evaluations (period_id, employee_id, evaluator_id, position_snapshot, org_unit_snapshot, status)
                                          VALUES (?,?,?,?,?, 'planning')");
            $insertItem = $pdo->prepare("INSERT IGNORE INTO kpi2_items
                (evaluation_id, section, org_task_id, share_percent, sort_order, title, kpi_target, frequency, metric, importance_weight, difficulty_weight)
                VALUES (?, 'core_duty', ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($taskRows as $task) {
                $assigns = $pdo->prepare("SELECT * FROM kpi2_org_task_assignments WHERE org_task_id=?");
                $assigns->execute([$task['id']]);
                foreach ($assigns->fetchAll() as $assign) {
                    $employeeId = (int)$assign['employee_id'];

                    $findEval->execute([$employeeId, $batch['period_id']]);
                    $evalId = $findEval->fetchColumn();
                    if (!$evalId) {
                        $empInfo->execute([$employeeId]);
                        $emp = $empInfo->fetch();
                        $unitNameVal = null;
                        if ($emp && $emp['org_unit_id']) {
                            $unitName->execute([$emp['org_unit_id']]);
                            $unitNameVal = $unitName->fetchColumn() ?: null;
                        }
                        $evaluatorId = kpi2ResolveEvaluator($pdo, $emp['org_unit_id'] ? (int)$emp['org_unit_id'] : null, $employeeId);
                        $createEval->execute([$batch['period_id'], $employeeId, $evaluatorId, $emp['position'] ?? null, $unitNameVal]);
                        $evalId = (int)$pdo->lastInsertId();
                    }

                    $insertItem->execute([
                        $evalId, $task['id'], $assign['percent'], (int)$task['sort_order'],
                        $task['title'], $task['kpi_target'], $task['frequency'], $task['metric'],
                        $task['importance_weight'], $task['difficulty_weight'],
                    ]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Ажлын багц батлагдаж, ажилтнуудын KPI2 дээр орлоо.']);
            break;

        case 'batch_reject':
            $batchId = intval($_POST['batch_id'] ?? 0);
            $note = trim($_POST['note'] ?? '') ?: null;
            $batch = loadKpi2Batch($pdo, $batchId);
            if (!canApproveKpi2Batch($pdo, $batch, $myEmployeeId)) throw new Exception('Та энэ багцыг буцаах эрхгүй байна.');
            if ($batch['status'] !== 'pending') throw new Exception('Энэ багцыг буцаах боломжгүй.');

            $pdo->prepare("UPDATE kpi2_task_batches SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
                ->execute([$myEmployeeId, $note, $batchId]);
            echo json_encode(['success' => true, 'message' => 'Ажлын багц менежерт буцаагдлаа.']);
            break;

        // ─── HR/Superadmin: KPI2 хугацаа удирдах ──────────────────────
        case 'period_create':
            requireRole('superadmin', 'hr');
            $name = trim($_POST['name'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end   = $_POST['end_date'] ?? '';
            if (!$name || !$start || !$end) throw new Exception('Мэдээлэл дутуу байна.');
            $pdo->prepare("INSERT INTO kpi2_periods (name, start_date, end_date) VALUES (?,?,?)")
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
            $pdo->prepare("UPDATE kpi2_periods SET name=?, start_date=?, end_date=?, is_active=? WHERE id=?")
                ->execute([$name, $start, $end, $active, $id]);
            echo json_encode(['success' => true, 'message' => 'Хугацаа шинэчлэгдлээ.']);
            break;

        case 'period_delete':
            requireRole('superadmin', 'hr');
            $id = intval($_POST['id'] ?? 0);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM kpi2_evaluations WHERE period_id=?");
            $cnt->execute([$id]);
            if ($cnt->fetchColumn() > 0) throw new Exception('Энэ хугацаагаар үүссэн KPI2 хуудас байна, устгах боломжгүй.');
            $pdo->prepare("DELETE FROM kpi2_periods WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Хугацаа устгагдлаа.']);
            break;

        // ─── HR/Superadmin: KPI2 туршилтын manager/director оноох ─────
        case 'unit_role_set':
            requireRole('superadmin', 'hr');
            $orgUnitId = intval($_POST['org_unit_id'] ?? 0);
            $employeeId = intval($_POST['employee_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            if (!$orgUnitId || !$employeeId) throw new Exception('Нэгж, ажилтныг сонгоно уу.');
            if (!in_array($role, ['manager', 'director'], true)) throw new Exception('Буруу үүрэг.');

            $pdo->prepare("INSERT INTO kpi2_unit_roles (org_unit_id, employee_id, role) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE employee_id=VALUES(employee_id)")
                ->execute([$orgUnitId, $employeeId, $role]);
            echo json_encode(['success' => true, 'message' => 'Тохируулагдлаа.']);
            break;

        case 'unit_role_remove':
            requireRole('superadmin', 'hr');
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM kpi2_unit_roles WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Хасагдлаа.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
