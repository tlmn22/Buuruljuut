<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json');
requireLogin();

$pdo       = getDB();
$empId     = (int)$_SESSION['user_id'];
$isManager = mrIsManager($pdo, $empId);
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

/** Захиалгыг өрөөний мэдээлэлтэй нь ачаална */
function mrLoadBooking(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT b.*, r.name AS room_name, r.floor FROM meetingroom_bookings b JOIN meetingroom_rooms r ON r.id = b.room_id WHERE b.id = ?");
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) throw new Exception('Захиалга олдсонгүй.');
    return $b;
}
/** scope=group бол давтамжийн бүх (ирээдүйн, тухайн төлөвтэй) захиалгыг, эс бөгөөс зөвхөн өөрийг нь */
function mrScopeIds(PDO $pdo, array $b, string $scope, array $statuses): array {
    if ($scope !== 'group' || !$b['recurrence_group']) return [(int)$b['id']];
    $ph = implode(',', array_fill(0, count($statuses), '?'));
    $st = $pdo->prepare("SELECT id FROM meetingroom_bookings WHERE recurrence_group = ? AND status IN ($ph) AND start_dt >= ?");
    $st->execute(array_merge([$b['recurrence_group']], $statuses, [min($b['start_dt'], date('Y-m-d H:i:s'))]));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
function mrLink(array $b): string {
    return '/modules/meetingroom/index.php?room=' . (int)$b['room_id'] . '&date=' . substr($b['start_dt'], 0, 10);
}
function mrRoomLabel(array $b): string {
    return $b['room_name'] . ($b['floor'] ? ' (' . $b['floor'] . ')' : '');
}

try {
    switch ($action) {

        // ── Өрөөний 7 хоногийн захиалга, хаалт ───────────────────────────
        case 'get_bookings':
            $roomId = (int)($_GET['room_id'] ?? 0);
            $weekStart = $_GET['week_start'] ?? '';
            if (!$roomId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) throw new Exception('Буруу хүсэлт.');
            $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

            $st = $pdo->prepare("
                SELECT b.id, b.start_dt, b.end_dt, b.title, b.description, b.status, b.requested_by,
                       b.has_external_guests, b.needs_equipment, b.recurrence, b.recurrence_group,
                       e.last_name, e.first_name, e.position
                FROM meetingroom_bookings b JOIN employees e ON e.id = b.requested_by
                WHERE b.room_id = ? AND b.status IN ('pending','approved') AND DATE(b.start_dt) BETWEEN ? AND ?
                ORDER BY b.start_dt");
            $st->execute([$roomId, $weekStart, $weekEnd]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $att = [];
            if ($rows) {
                $ids = array_column($rows, 'id');
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $a = $pdo->prepare("SELECT a.booking_id, a.attendee_name, e.last_name, e.first_name FROM meetingroom_booking_attendees a
                                    LEFT JOIN employees e ON e.id = a.employee_id WHERE a.booking_id IN ($ph) ORDER BY a.id");
                $a->execute($ids);
                foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) $att[(int)$r['booking_id']][] = $r['last_name'] ? employeeName($r['last_name'], $r['first_name']) : $r['attendee_name'];
            }
            $bookings = array_map(function ($r) use ($empId, $att) {
                return [
                    'id' => (int)$r['id'], 'start_dt' => $r['start_dt'], 'end_dt' => $r['end_dt'],
                    'title' => $r['title'], 'description' => $r['description'], 'status' => $r['status'],
                    'requester' => employeeName($r['last_name'], $r['first_name']), 'position' => $r['position'],
                    'attendees' => $att[(int)$r['id']] ?? [],
                    'guests' => (bool)$r['has_external_guests'], 'equipment' => (bool)$r['needs_equipment'],
                    'recurrence' => $r['recurrence'], 'is_group' => (bool)$r['recurrence_group'],
                    'is_mine' => (int)$r['requested_by'] === $empId,
                ];
            }, $rows);

            $bo = $pdo->prepare("SELECT id, start_dt, end_dt, reason, recurrence, recurrence_end, recurrence_group FROM meetingroom_blackouts
                                 WHERE room_id = ? AND DATE(start_dt) <= ? AND DATE(end_dt) >= ? ORDER BY start_dt");
            $bo->execute([$roomId, $weekEnd, $weekStart]);
            $blackouts = array_map(function ($b) {
                return ['id' => (int)$b['id'], 'start_dt' => $b['start_dt'], 'end_dt' => $b['end_dt'], 'reason' => $b['reason'],
                        'recurrence' => $b['recurrence'], 'recurrence_end' => $b['recurrence_end'], 'is_group' => (bool)$b['recurrence_group']];
            }, $bo->fetchAll(PDO::FETCH_ASSOC));

            echo json_encode(['success' => true, 'bookings' => $bookings, 'blackouts' => $blackouts]);
            break;

        // ── Тухайн цагт сул өрөөнүүд ──────────────────────────────────────
        case 'get_available_rooms':
            $date = $_GET['date'] ?? ''; $s = $_GET['start_time'] ?? ''; $e = $_GET['end_time'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e)) throw new Exception('Огноо болон цагаа зөв оруулна уу.');
            $startDt = "$date $s:00"; $endDt = "$date $e:00";
            if ($startDt >= $endDt) throw new Exception('Дуусах цаг эхлэх цагаас хойш байх ёстой.');
            $minCap = max(0, (int)($_GET['capacity'] ?? 0));
            $st = $pdo->prepare("
                SELECT * FROM meetingroom_rooms r WHERE r.is_active = 1 AND r.capacity >= ?
                  AND NOT EXISTS (SELECT 1 FROM meetingroom_bookings b WHERE b.room_id = r.id AND b.status IN ('pending','approved') AND b.start_dt < ? AND b.end_dt > ?)
                  AND NOT EXISTS (SELECT 1 FROM meetingroom_blackouts x WHERE x.room_id = r.id AND x.start_dt < ? AND x.end_dt > ?)
                ORDER BY r.capacity, r.name");
            $st->execute([$minCap, $endDt, $startDt, $endDt, $startDt]);
            $rooms = array_map(function ($r) {
                return ['id' => (int)$r['id'], 'name' => $r['name'], 'floor' => $r['floor'], 'capacity' => (int)$r['capacity'], 'photo' => mrPhotoUrl($r['photo'])];
            }, $st->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['success' => true, 'rooms' => $rooms]);
            break;

        // ── Захиалга үүсгэх ──────────────────────────────────────────────
        case 'create_booking':
            $roomId = (int)($_POST['room_id'] ?? 0);
            $startDt = trim($_POST['start_dt'] ?? ''); $endDt = trim($_POST['end_dt'] ?? '');
            $title = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
            $description = trim($_POST['description'] ?? '');
            $recurrence = array_key_exists($_POST['recurrence'] ?? '', MR_RECURRENCE_LABELS) ? $_POST['recurrence'] : 'none';
            $recurrenceEnd = trim($_POST['recurrence_end'] ?? '') ?: null;
            $attendees = json_decode($_POST['attendees'] ?? '[]', true) ?: [];

            if (!$roomId) throw new Exception('Өрөө сонгоно уу.');
            if ($title === '') throw new Exception('Хурлын гарчгаа оруулна уу.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $startDt) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $endDt)) throw new Exception('Огноо, цагаа зөв оруулна уу.');
            $start = new DateTime($startDt); $end = new DateTime($endDt);
            if ($end <= $start) throw new Exception('Дуусах цаг эхлэх цагаас хойш байх ёстой.');
            if ($start->format('Y-m-d') !== $end->format('Y-m-d')) throw new Exception('Хурал тухайн өдөртөө дуусах ёстой.');
            if ($start < new DateTime()) throw new Exception('Өнгөрсөн цагт захиалга хийх боломжгүй.');
            if ((int)$start->format('G') < MR_GRID_START_HOUR || $end->format('H:i') > sprintf('%02d:00', MR_GRID_END_HOUR)) {
                throw new Exception(sprintf('Захиалгыг %02d:00–%02d:00 цагийн хооронд хийнэ.', MR_GRID_START_HOUR, MR_GRID_END_HOUR));
            }
            $dates = mrExpandRecurrence($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $recurrence, $recurrenceEnd);

            $pdo->beginTransaction();
            // Өрөөг түгжинэ — зэрэг ирсэн хоёр хүсэлт нэг цагийг хоёулаа авахаас сэргийлнэ
            $room = $pdo->prepare("SELECT * FROM meetingroom_rooms WHERE id = ? AND is_active = 1 FOR UPDATE");
            $room->execute([$roomId]);
            $room = $room->fetch(PDO::FETCH_ASSOC);
            if (!$room) throw new Exception('Өрөө олдсонгүй эсвэл идэвхгүй байна.');

            $cb = $pdo->prepare("SELECT 1 FROM meetingroom_bookings WHERE room_id = ? AND status IN ('pending','approved') AND start_dt < ? AND end_dt > ? LIMIT 1");
            $cx = $pdo->prepare("SELECT COALESCE(reason, '') FROM meetingroom_blackouts WHERE room_id = ? AND start_dt < ? AND end_dt > ? LIMIT 1");
            $busy = []; $closed = []; $closedReason = '';
            foreach ($dates as $d) {
                $cb->execute([$roomId, $d['end'], $d['start']]);
                if ($cb->fetchColumn()) $busy[] = substr($d['start'], 0, 10);
                $cx->execute([$roomId, $d['end'], $d['start']]);
                if (($r = $cx->fetchColumn()) !== false) { $closed[] = substr($d['start'], 0, 10); $closedReason = $closedReason ?: $r; }
            }
            if ($busy) throw new Exception('Энэ цагт өөр захиалга байна: ' . implode(', ', array_slice($busy, 0, 5)) . (count($busy) > 5 ? ' …' : ''));
            if ($closed) throw new Exception('Өрөө энэ хугацаанд боломжгүй' . ($closedReason ? " ($closedReason)" : '') . ': ' . implode(', ', array_slice($closed, 0, 5)));

            $group = count($dates) > 1 ? 'rg_' . bin2hex(random_bytes(10)) : null;
            $ins = $pdo->prepare("INSERT INTO meetingroom_bookings (room_id, requested_by, start_dt, end_dt, title, description, has_external_guests, needs_equipment, recurrence, recurrence_end, recurrence_group)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $insA = $pdo->prepare("INSERT INTO meetingroom_booking_attendees (booking_id, employee_id, attendee_name) VALUES (?,?,?)");
            foreach ($dates as $d) {
                $ins->execute([$roomId, $empId, $d['start'], $d['end'], $title, $description ?: null,
                               !empty($_POST['has_external_guests']) ? 1 : 0, !empty($_POST['needs_equipment']) ? 1 : 0,
                               $recurrence, $recurrenceEnd, $group]);
                $bid = (int)$pdo->lastInsertId();
                foreach (array_slice($attendees, 0, 100) as $a) {
                    $eid = isset($a['employee_id']) ? (int)$a['employee_id'] : 0;
                    $name = mb_substr(trim((string)($a['name'] ?? '')), 0, 100);
                    if ($eid) $insA->execute([$bid, $eid, null]);
                    elseif ($name !== '') $insA->execute([$bid, null, $name]);
                }
            }
            $pdo->commit();

            $me = $pdo->prepare("SELECT last_name, first_name FROM employees WHERE id = ?"); $me->execute([$empId]); $me = $me->fetch(PDO::FETCH_ASSOC);
            mrNotify($pdo, mrManagerIds($pdo), 'meetingroom.request', 'Хурлын өрөөний шинэ захиалга: ' . $room['name'],
                employeeName($me['last_name'], $me['first_name']) . ' · ' . mrSlotLabel($dates[0]['start'], $dates[0]['end'])
                . (count($dates) > 1 ? ' (' . MR_RECURRENCE_LABELS[$recurrence] . ', нийт ' . count($dates) . ' удаа)' : '') . "\n" . $title,
                '/modules/meetingroom/admin.php', $empId);

            echo json_encode(['success' => true, 'message' => (count($dates) > 1 ? count($dates) . ' захиалга' : 'Захиалга') . ' илгээгдлээ. Хариуцагч батлахыг хүлээнэ үү.']);
            break;

        // ── Батлах / татгалзах (хариуцагч) ───────────────────────────────
        case 'approve_booking':
        case 'reject_booking':
            if (!$isManager) throw new Exception('Эрх хүрэлцэхгүй.');
            $b = mrLoadBooking($pdo, (int)($_POST['id'] ?? 0));
            if ($b['status'] !== 'pending') throw new Exception('Энэ захиалга хүлээгдэж буй төлөвт биш байна.');
            $ids = mrScopeIds($pdo, $b, $_POST['scope'] ?? 'one', ['pending']);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'approve_booking') {
                $pdo->prepare("UPDATE meetingroom_bookings SET status='approved', approved_by=?, approved_at=NOW() WHERE status='pending' AND id IN ($ph)")
                    ->execute(array_merge([$empId], $ids));
                $title = 'Хурлын өрөөний захиалга батлагдлаа ✅';
            } else {
                $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
                $pdo->prepare("UPDATE meetingroom_bookings SET status='rejected', reject_reason=?, approved_by=?, approved_at=NOW() WHERE status='pending' AND id IN ($ph)")
                    ->execute(array_merge([$reason ?: null, $empId], $ids));
                $title = 'Хурлын өрөөний захиалга татгалзагдлаа';
            }
            $body = mrRoomLabel($b) . ' · ' . mrSlotLabel($b['start_dt'], $b['end_dt']) . (count($ids) > 1 ? ' (нийт ' . count($ids) . ' удаа)' : '')
                  . ($b['title'] ? "\n" . $b['title'] : '') . (!empty($reason) ? "\nШалтгаан: " . $reason : '');
            mrNotify($pdo, [(int)$b['requested_by']], $action === 'approve_booking' ? 'meetingroom.approved' : 'meetingroom.rejected', $title, $body, mrLink($b), $empId);
            echo json_encode(['success' => true, 'message' => (count($ids) > 1 ? count($ids) . ' захиалга ' : 'Захиалга ') . ($action === 'approve_booking' ? 'баталгаажлаа.' : 'татгалзагдлаа.')]);
            break;

        // ── Цуцлах (захиалагч эсвэл хариуцагч) ───────────────────────────
        case 'cancel_booking':
            $b = mrLoadBooking($pdo, (int)($_POST['id'] ?? 0));
            $own = (int)$b['requested_by'] === $empId;
            if (!$own && !$isManager) throw new Exception('Эрх хүрэлцэхгүй.');
            if (!in_array($b['status'], ['pending', 'approved'], true)) throw new Exception('Энэ захиалгыг цуцлах боломжгүй.');
            if (strtotime($b['start_dt']) < time()) throw new Exception('Эхэлсэн захиалгыг цуцлах боломжгүй.');
            $ids = mrScopeIds($pdo, $b, $_POST['scope'] ?? 'one', ['pending', 'approved']);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE meetingroom_bookings SET status='cancelled', cancelled_by=? WHERE status IN ('pending','approved') AND start_dt >= NOW() AND id IN ($ph)")
                ->execute(array_merge([$empId], $ids));
            $body = mrRoomLabel($b) . ' · ' . mrSlotLabel($b['start_dt'], $b['end_dt']) . (count($ids) > 1 ? ' (нийт ' . count($ids) . ' удаа)' : '') . ($b['title'] ? "\n" . $b['title'] : '');
            if ($own) mrNotify($pdo, mrManagerIds($pdo), 'meetingroom.cancelled', 'Хурлын өрөөний захиалга цуцлагдлаа', $body, '/modules/meetingroom/admin.php', $empId);
            else      mrNotify($pdo, [(int)$b['requested_by']], 'meetingroom.cancelled', 'Таны хурлын өрөөний захиалгыг хариуцагч цуцаллаа', $body, mrLink($b), $empId);
            echo json_encode(['success' => true, 'message' => (count($ids) > 1 ? count($ids) . ' захиалга' : 'Захиалга') . ' цуцлагдлаа.']);
            break;

        // ── Боломжгүй цаг (хариуцагч) ────────────────────────────────────
        case 'add_blackout':
            if (!$isManager) throw new Exception('Эрх хүрэлцэхгүй.');
            $roomId = (int)($_POST['room_id'] ?? 0);
            $startDt = trim($_POST['start_dt'] ?? ''); $endDt = trim($_POST['end_dt'] ?? '');
            $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
            $recurrence = array_key_exists($_POST['recurrence'] ?? '', MR_RECURRENCE_LABELS) ? $_POST['recurrence'] : 'none';
            $recurrenceEnd = trim($_POST['recurrence_end'] ?? '') ?: null;
            if (!$roomId || !$startDt || !$endDt) throw new Exception('Өрөө, огноо, цагаа оруулна уу.');
            if ($endDt <= $startDt) throw new Exception('Дуусах цаг эхлэх цагаас хойш байх ёстой.');
            $dates = mrExpandRecurrence($startDt, $endDt, $recurrence, $recurrenceEnd, !empty($_POST['all_day']));

            // Энэ хугацаанд байгаа идэвхтэй захиалгууд
            $cf = $pdo->prepare("SELECT b.*, r.name AS room_name, r.floor FROM meetingroom_bookings b JOIN meetingroom_rooms r ON r.id = b.room_id
                                 WHERE b.room_id = ? AND b.status IN ('pending','approved') AND b.start_dt < ? AND b.end_dt > ? AND b.start_dt >= NOW()");
            $conflicts = [];
            foreach ($dates as $d) { $cf->execute([$roomId, $d['end'], $d['start']]); foreach ($cf->fetchAll(PDO::FETCH_ASSOC) as $c) $conflicts[$c['id']] = $c; }
            if ($conflicts && empty($_POST['force'])) {
                echo json_encode(['success' => false, 'need_confirm' => true, 'count' => count($conflicts),
                    'message' => count($conflicts) . ' захиалга энэ хугацаанд байна. Үргэлжлүүлбэл тэдгээр цуцлагдаж, захиалагчдад мэдэгдэнэ.']);
                break;
            }
            $pdo->beginTransaction();
            $group = count($dates) > 1 ? 'bg_' . bin2hex(random_bytes(10)) : null;
            $ins = $pdo->prepare("INSERT INTO meetingroom_blackouts (room_id, start_dt, end_dt, reason, recurrence, recurrence_end, recurrence_group, created_by) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($dates as $d) $ins->execute([$roomId, $d['start'], $d['end'], $reason ?: null, $recurrence, $recurrenceEnd, $group, $empId]);
            if ($conflicts) {
                $ph = implode(',', array_fill(0, count($conflicts), '?'));
                $pdo->prepare("UPDATE meetingroom_bookings SET status='cancelled', cancelled_by=? WHERE id IN ($ph)")->execute(array_merge([$empId], array_keys($conflicts)));
            }
            $pdo->commit();
            foreach ($conflicts as $c) {
                mrNotify($pdo, [(int)$c['requested_by']], 'meetingroom.cancelled', 'Хурлын өрөө боломжгүй болсон тул таны захиалга цуцлагдлаа',
                    mrRoomLabel($c) . ' · ' . mrSlotLabel($c['start_dt'], $c['end_dt']) . ($reason ? "\nШалтгаан: " . $reason : ''), mrLink($c), $empId);
            }
            echo json_encode(['success' => true, 'message' => (count($dates) > 1 ? count($dates) . ' удаагийн хугацаанд' : 'Энэ хугацаанд') . ' өрөөг хаалаа.' . ($conflicts ? ' ' . count($conflicts) . ' захиалга цуцлагдлаа.' : '')]);
            break;

        case 'remove_blackout':
            if (!$isManager) throw new Exception('Эрх хүрэлцэхгүй.');
            $st = $pdo->prepare("SELECT * FROM meetingroom_blackouts WHERE id = ?");
            $st->execute([(int)($_POST['id'] ?? 0)]);
            $bo = $st->fetch(PDO::FETCH_ASSOC);
            if (!$bo) throw new Exception('Хаалт олдсонгүй.');
            if (($_POST['scope'] ?? 'one') === 'group' && $bo['recurrence_group']) {
                $del = $pdo->prepare("DELETE FROM meetingroom_blackouts WHERE recurrence_group = ? AND end_dt >= NOW()");
                $del->execute([$bo['recurrence_group']]);
            } else {
                $del = $pdo->prepare("DELETE FROM meetingroom_blackouts WHERE id = ?");
                $del->execute([$bo['id']]);
            }
            echo json_encode(['success' => true, 'message' => ($del->rowCount() > 1 ? $del->rowCount() . ' хаалт' : 'Хаалт') . ' цуцлагдлаа.']);
            break;

        // ── Өрөө удирдах (хариуцагч) ─────────────────────────────────────
        case 'upload_room_photo':
            if (!$isManager) throw new Exception('Эрх хүрэлцэхгүй.');
            $file = $_FILES['photo'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new Exception('Зураг олдсонгүй.');
            if ($file['size'] > 3 * 1024 * 1024) throw new Exception('Зураг 3MB-аас хэтэрсэн байна.');
            $info = @getimagesize($file['tmp_name']);
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($types[$info[2]])) throw new Exception('Зөвхөн JPG, PNG, WEBP зураг оруулна уу.');
            if (!is_dir(MEETINGROOM_UPLOAD_PATH)) mkdir(MEETINGROOM_UPLOAD_PATH, 0755, true);
            $name = 'room_' . bin2hex(random_bytes(10)) . '.' . $types[$info[2]];
            if (!move_uploaded_file($file['tmp_name'], MEETINGROOM_UPLOAD_PATH . $name)) throw new Exception('Зураг хадгалахад алдаа гарлаа.');
            echo json_encode(['success' => true, 'photo' => $name, 'url' => MEETINGROOM_UPLOAD_URL . $name]);
            break;

        case 'create_room':
        case 'update_room':
            if (!$isManager) throw new Exception('Эрх хүрэлцэхгүй.');
            $id = (int)($_POST['id'] ?? 0);
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            if ($name === '') throw new Exception('Өрөөний нэрийг оруулна уу.');
            $capacity = max(1, min(500, (int)($_POST['capacity'] ?? 10)));
            $amenities = array_values(array_intersect(json_decode($_POST['amenities'] ?? '[]', true) ?: [], array_keys(MR_AMENITIES)));
            // Зураг — зөвхөн энэ модулийн хуулсан файлын нэр (замыг гараар оруулахыг зөвшөөрөхгүй)
            $photo = basename(trim($_POST['photo'] ?? ''));
            if ($photo !== '' && !mrPhotoUrl($photo)) $photo = '';
            $vals = [$name, mb_substr(trim($_POST['floor'] ?? ''), 0, 50) ?: null, $capacity, trim($_POST['notes'] ?? '') ?: null, $photo ?: null, $amenities ? json_encode($amenities) : null];
            if ($action === 'update_room') {
                if (!$id) throw new Exception('Өрөө олдсонгүй.');
                $pdo->prepare("UPDATE meetingroom_rooms SET name=?, floor=?, capacity=?, notes=?, photo=?, amenities=? WHERE id=?")->execute(array_merge($vals, [$id]));
            } else {
                $pdo->prepare("INSERT INTO meetingroom_rooms (name, floor, capacity, notes, photo, amenities) VALUES (?,?,?,?,?,?)")->execute($vals);
            }
            echo json_encode(['success' => true, 'message' => $action === 'update_room' ? 'Өрөөний мэдээлэл шинэчлэгдлээ.' : 'Өрөө нэмэгдлээ.']);
            break;

        case 'toggle_room':
            if (!$isManager) throw new Exception('Эрх хүрэлцэхгүй.');
            $pdo->prepare("UPDATE meetingroom_rooms SET is_active = 1 - is_active WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            echo json_encode(['success' => true, 'message' => 'Өрөөний төлөв өөрчлөгдлөө.']);
            break;

        // ── Хариуцагч томилох (HR / superadmin) ──────────────────────────
        case 'add_manager':
        case 'remove_manager':
            if (!mrCanAssignManagers()) throw new Exception('Эрх хүрэлцэхгүй.');
            $eid = (int)($_POST['employee_id'] ?? 0);
            if (!$eid) throw new Exception('Ажилтан сонгоно уу.');
            if ($action === 'add_manager') $pdo->prepare("INSERT IGNORE INTO meetingroom_managers (employee_id, granted_by) VALUES (?, ?)")->execute([$eid, $empId]);
            else $pdo->prepare("DELETE FROM meetingroom_managers WHERE employee_id = ?")->execute([$eid]);
            echo json_encode(['success' => true, 'message' => $action === 'add_manager' ? 'Хариуцагч нэмэгдлээ.' : 'Хариуцагч хасагдлаа.']);
            break;

        // ── Ажилтан хайх (оролцогч, хариуцагч сонгоход) ─────────────────
        case 'search_employees':
            $q = trim($_GET['q'] ?? '');
            if (mb_strlen($q) < 2) { echo json_encode(['success' => true, 'results' => []]); break; }
            $like = '%' . $q . '%';
            $st = $pdo->prepare("SELECT id, employee_code, last_name, first_name, position FROM employees
                                 WHERE is_active = 1 AND (last_name LIKE ? OR first_name LIKE ? OR employee_code LIKE ? OR CONCAT(last_name, ' ', first_name) LIKE ?)
                                 ORDER BY last_name, first_name LIMIT 10");
            $st->execute([$like, $like, $like, $like]);
            echo json_encode(['success' => true, 'results' => array_map(function ($r) {
                return ['id' => (int)$r['id'], 'code' => $r['employee_code'], 'name' => employeeName($r['last_name'], $r['first_name']), 'position' => $r['position'] ?? ''];
            }, $st->fetchAll(PDO::FETCH_ASSOC))]);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
