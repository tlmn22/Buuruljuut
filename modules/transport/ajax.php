<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json');
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo    = getDB();
$me     = currentUser();
$myEmployeeId = (int)$me['id'];

function loadTransportRequest(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM transport_requests WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Захиалга олдсонгүй.');
    return $row;
}

function loadTransportOrder(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM transport_orders WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Нэгтгэсэн захиалга олдсонгүй.');
    return $row;
}

/** Тухайн машины мөрүүдийн физик нэгжийг (жолооч/түрээс/шатахуун) хадгална — save_request/order_save_vehicles-д хамтдаа ашиглана */
function saveVehicleUnits(PDO $pdo, string $unitsTable, string $vehicleFk, array $ownVehIds, array $units): void {
    $insertUnit = $pdo->prepare("INSERT INTO $unitsTable
        ($vehicleFk, unit_index, is_rented, rental_company, rental_days, rental_rate, rental_total,
         plate_number, driver_name, driver_phone, fuel_type, distance_km, fuel_norm, fuel_price, fuel_card_number, fuel_total)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    foreach ($ownVehIds as $vehId) {
        $pdo->prepare("DELETE FROM $unitsTable WHERE $vehicleFk=?")->execute([$vehId]);
    }

    foreach ($units as $u) {
        $vehId = intval($u['vehicle_id'] ?? 0);
        if (!in_array($vehId, $ownVehIds, true)) continue;

        $isRented = !empty($u['is_rented']) ? 1 : 0;
        $rentalDays = ($u['rental_days'] ?? '') !== '' ? (int)$u['rental_days'] : null;
        $rentalRate = ($u['rental_rate'] ?? '') !== '' ? (float)$u['rental_rate'] : null;
        $rentalTotal = ($rentalDays !== null && $rentalRate !== null) ? round($rentalDays * $rentalRate, 2) : 0;

        $distanceKm = ($u['distance_km'] ?? '') !== '' ? (float)$u['distance_km'] : null;
        $fuelNorm = ($u['fuel_norm'] ?? '') !== '' ? (float)$u['fuel_norm'] : null;
        $fuelPrice = ($u['fuel_price'] ?? '') !== '' ? (float)$u['fuel_price'] : null;
        $litres = ($distanceKm !== null && $fuelNorm !== null) ? ($distanceKm * $fuelNorm / 100) : 0;
        $fuelTotal = ($fuelPrice !== null) ? round($litres * $fuelPrice, 2) : 0;

        $fuelType = trim($u['fuel_type'] ?? '');
        if ($fuelType !== '' && !isset(TRANSPORT_FUEL_TYPES[$fuelType])) throw new Exception('Буруу түлшний төрөл.');

        $insertUnit->execute([
            $vehId, max(1, intval($u['unit_index'] ?? 1)), $isRented,
            trim($u['rental_company'] ?? '') ?: null,
            $rentalDays, $rentalRate, $rentalTotal,
            trim($u['plate_number'] ?? '') ?: null, trim($u['driver_name'] ?? '') ?: null, trim($u['driver_phone'] ?? '') ?: null,
            $fuelType ?: null, $distanceKm, $fuelNorm, $fuelPrice, trim($u['fuel_card_number'] ?? '') ?: null, $fuelTotal,
        ]);
    }
}

try {
    switch ($action) {

        // ─── Ажилтан хайх (typeahead) ─────────────────────────────────
        case 'search_employees':
            $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
            if (mb_strlen($q) < 2) { echo json_encode([]); break; }
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare("
                SELECT e.id, e.employee_code, e.last_name, e.first_name, e.position, ou.name AS unit_name
                FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                WHERE e.is_active=1 AND (e.last_name LIKE ? OR e.first_name LIKE ? OR e.employee_code LIKE ?)
                ORDER BY e.last_name LIMIT 15
            ");
            $stmt->execute([$like, $like, $like]);
            echo json_encode($stmt->fetchAll());
            break;

        // ─── Захиалга үүсгэх / засах (нэг дор бүх мэдээллийг хадгална) ──
        case 'save_request':
            $id = intval($_POST['id'] ?? 0);
            $isNew = !$id;

            if (!$isNew) {
                $existing = loadTransportRequest($pdo, $id);
                if ((int)$existing['requester_id'] !== $myEmployeeId) throw new Exception('Энэ захиалгыг засах эрхгүй байна.');
                if ($existing['status'] !== 'manager_rejected') throw new Exception('Одоо энэ захиалгыг засах боломжгүй.');
            }

            $travelDirection = trim($_POST['travel_direction'] ?? '');
            $travelPurpose   = $_POST['travel_purpose'] ?? '';
            $startDate       = $_POST['start_date'] ?? '';
            $endDate         = $_POST['end_date'] ?? '';
            $totalDays       = max(1, intval($_POST['total_days'] ?? 1));
            $totalKm         = $_POST['total_km'] !== '' ? (float)$_POST['total_km'] : null;

            if ($travelDirection === '') throw new Exception('Аяллын чиглэлээ оруулна уу.');
            if (!in_array($travelPurpose, TRANSPORT_PURPOSE_OPTIONS, true)) throw new Exception('Аяллын зорилгоо сонгоно уу.');
            if (!$startDate || !$endDate) throw new Exception('Эхлэх, дуусах огноог оруулна уу.');
            if ($endDate < $startDate) throw new Exception('Дуусах огноо эхлэх огнооноос өмнө байж болохгүй.');

            $passengers = json_decode($_POST['passengers'] ?? '[]', true) ?: [];
            $vehicles   = json_decode($_POST['vehicles'] ?? '[]', true) ?: [];
            if (empty($passengers)) throw new Exception('Дор хаяж нэг ажилтан/зорчигч нэмнэ үү.');
            if (empty($vehicles)) throw new Exception('Дор хаяж нэг машин нэмнэ үү.');

            $pdo->beginTransaction();
            try {
                if ($isNew) {
                    $managerId = transportResolveManager($pdo, $myEmployeeId);
                    if (!$managerId) throw new Exception('Таны шууд удирдлагыг тодорхойлж чадсангүй. HR-т хандана уу.');

                    $pdo->prepare("INSERT INTO transport_requests
                        (requester_id, travel_direction, travel_purpose, start_date, end_date, total_days, total_km, status, manager_id)
                        VALUES (?,?,?,?,?,?,?, 'pending_manager', ?)")
                        ->execute([$myEmployeeId, $travelDirection, $travelPurpose, $startDate, $endDate, $totalDays, $totalKm, $managerId]);
                    $id = (int)$pdo->lastInsertId();
                } else {
                    // Дахин илгээхэд шууд удирдлагаас эхлээд шинээр батлуулна (хянагч солигдож ч байж болно)
                    $managerId = transportResolveManager($pdo, $myEmployeeId);
                    $pdo->prepare("UPDATE transport_requests SET
                        travel_direction=?, travel_purpose=?, start_date=?, end_date=?, total_days=?, total_km=?,
                        status='pending_manager', manager_id=?, manager_reviewed_at=NULL, manager_comment=NULL,
                        director_id=NULL, director_reviewed_at=NULL, director_comment=NULL
                        WHERE id=?")
                        ->execute([$travelDirection, $travelPurpose, $startDate, $endDate, $totalDays, $totalKm, $managerId, $id]);

                    $pdo->prepare("DELETE FROM transport_request_passengers WHERE request_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM transport_request_vehicles WHERE request_id=?")->execute([$id]);
                }

                $insertPax = $pdo->prepare("INSERT INTO transport_request_passengers
                    (request_id, employee_id, type, full_name, organization, position, unit_name, food_morning, food_lunch, food_dinner, sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($passengers as $i => $p) {
                    $empId = intval($p['employee_id'] ?? 0) ?: null;
                    $insertPax->execute([
                        $id, $empId,
                        trim($p['type'] ?? '') ?: null,
                        trim($p['full_name'] ?? '') ?: null,
                        trim($p['organization'] ?? '') ?: null,
                        trim($p['position'] ?? '') ?: null,
                        trim($p['unit_name'] ?? '') ?: null,
                        !empty($p['food_morning']) ? 1 : 0,
                        !empty($p['food_lunch']) ? 1 : 0,
                        !empty($p['food_dinner']) ? 1 : 0,
                        $i,
                    ]);
                }

                $insertVeh = $pdo->prepare("INSERT INTO transport_request_vehicles (request_id, vehicle_type_id, qty, selected_seats, sort_order) VALUES (?,?,?,?,?)");
                foreach ($vehicles as $i => $v) {
                    $typeId = trim($v['vehicle_type_id'] ?? '');
                    if ($typeId === '') continue;
                    if (!isset(TRANSPORT_VEHICLE_TYPES[$typeId])) throw new Exception('Буруу машины төрөл.');
                    $type = TRANSPORT_VEHICLE_TYPES[$typeId];
                    $qty  = max(1, intval($v['qty'] ?? 1));

                    $seats = array_values(array_unique(array_map('intval', $v['selected_seats'] ?? [])));
                    if ($type['kind'] === 'bus' && count($seats) > $type['seats'] * $qty) {
                        throw new Exception('Сонгосон суудлын тоо машины багтаамжаас хэтэрсэн байна.');
                    }
                    $seatsJson = ($type['kind'] === 'bus' && $seats) ? json_encode($seats) : null;

                    $insertVeh->execute([$id, $typeId, $qty, $seatsJson, $i]);
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Захиалга илгээгдлээ.', 'id' => $id]);
            break;

        // ─── Шууд удирдлага: батлах / буцаах ────────────────────────────
        case 'manager_approve':
            $id = intval($_POST['id'] ?? 0);
            $req = loadTransportRequest($pdo, $id);
            if ((int)$req['manager_id'] !== $myEmployeeId) throw new Exception('Та энэ захиалгыг батлах эрхгүй байна.');
            if ($req['status'] !== 'pending_manager') throw new Exception('Энэ захиалга одоо батлах шатанд биш байна.');

            $pdo->prepare("UPDATE transport_requests SET status='pending_transport_manager', manager_reviewed_at=NOW(), manager_comment=NULL WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Батлагдаж, тээвэр хариуцсан менежерт илгээгдлээ.']);
            break;

        case 'manager_reject':
            $id = intval($_POST['id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            $req = loadTransportRequest($pdo, $id);
            if ((int)$req['manager_id'] !== $myEmployeeId) throw new Exception('Та энэ захиалгыг буцаах эрхгүй байна.');
            if ($req['status'] !== 'pending_manager') throw new Exception('Энэ захиалга одоо буцаах шатанд биш байна.');
            if ($comment === '') throw new Exception('Буцаах шалтгаанаа бичнэ үү.');

            $pdo->prepare("UPDATE transport_requests SET status='manager_rejected', manager_reviewed_at=NOW(), manager_comment=? WHERE id=?")->execute([$comment, $id]);
            echo json_encode(['success' => true, 'message' => 'Ажилтанд буцаагдлаа.']);
            break;

        // ─── Тээвэр хариуцсан захирал: батлах / буцаах ───────────────────
        case 'director_approve':
            $id = intval($_POST['id'] ?? 0);
            if (!transportHasRole($pdo, $myEmployeeId, 'director')) throw new Exception('Та Тээвэр хариуцсан захирал биш байна.');
            $req = loadTransportRequest($pdo, $id);
            if ($req['status'] !== 'pending_director') throw new Exception('Энэ захиалга одоо батлах шатанд биш байна.');

            $pdo->prepare("UPDATE transport_requests SET status='completed', director_id=?, director_reviewed_at=NOW(), director_comment=NULL WHERE id=?")
                ->execute([$myEmployeeId, $id]);
            echo json_encode(['success' => true, 'message' => 'Батлагдаж, захиалга баталгаажлаа.']);
            break;

        case 'director_reject':
            $id = intval($_POST['id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            if (!transportHasRole($pdo, $myEmployeeId, 'director')) throw new Exception('Та Тээвэр хариуцсан захирал биш байна.');
            $req = loadTransportRequest($pdo, $id);
            if ($req['status'] !== 'pending_director') throw new Exception('Энэ захиалга одоо буцаах шатанд биш байна.');
            if ($comment === '') throw new Exception('Буцаах шалтгаанаа бичнэ үү.');

            $pdo->prepare("UPDATE transport_requests SET status='director_rejected', director_id=?, director_reviewed_at=NOW(), director_comment=? WHERE id=?")
                ->execute([$myEmployeeId, $comment, $id]);
            echo json_encode(['success' => true, 'message' => 'Тээвэр хариуцсан менежерт буцаагдлаа.']);
            break;

        // ─── Тээвэр хариуцсан менежер: хэд хэдэн ажилтны хүсэлтийг нэгтгэж нэг
        //     бодит тээврийн захиалга (transport_orders) үүсгэх ─────────────
        case 'create_order':
            if (!transportHasRole($pdo, $myEmployeeId, 'transport_manager')) throw new Exception('Та Тээвэр хариуцсан менежер биш байна.');
            $requestIds = array_map('intval', json_decode($_POST['request_ids'] ?? '[]', true) ?: []);
            $requestIds = array_values(array_unique(array_filter($requestIds)));
            if (count($requestIds) < 1) throw new Exception('Дор хаяж нэг хүсэлт сонгоно уу.');

            $pdo->beginTransaction();
            try {
                $ph = implode(',', array_fill(0, count($requestIds), '?'));
                $chk = $pdo->prepare("SELECT id FROM transport_requests WHERE id IN ($ph) AND status='pending_transport_manager' AND order_id IS NULL");
                $chk->execute($requestIds);
                $validIds = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
                if (count($validIds) !== count($requestIds)) throw new Exception('Сонгосон хүсэлтүүдийн зарим нь энэ шатанд байхгүй эсвэл аль хэдийн нэгтгэгдсэн байна.');

                $pdo->prepare("INSERT INTO transport_orders (transport_manager_id, status) VALUES (?, 'draft')")->execute([$myEmployeeId]);
                $orderId = (int)$pdo->lastInsertId();

                $upd = $pdo->prepare("UPDATE transport_requests SET status='merged', order_id=? WHERE id=?");
                foreach ($validIds as $rid) { $upd->execute([$orderId, $rid]); }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Нэгтгэгдэж, шинэ захиалга үүслээ.', 'order_id' => $orderId]);
            break;

        case 'order_cancel':
            $orderId = intval($_POST['id'] ?? 0);
            $order = loadTransportOrder($pdo, $orderId);
            if ((int)$order['transport_manager_id'] !== $myEmployeeId) throw new Exception('Та энэ захиалгыг цуцлах эрхгүй байна.');
            if ($order['status'] !== 'draft') throw new Exception('Зөвхөн ноорог захиалгыг цуцлах боломжтой.');

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE transport_requests SET status='pending_transport_manager', order_id=NULL WHERE order_id=?")->execute([$orderId]);
                $pdo->prepare("DELETE FROM transport_orders WHERE id=?")->execute([$orderId]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Захиалга цуцлагдаж, хүсэлтүүд буцлаа.']);
            break;

        case 'order_add_requests':
            $orderId = intval($_POST['id'] ?? 0);
            if (!transportHasRole($pdo, $myEmployeeId, 'transport_manager')) throw new Exception('Та Тээвэр хариуцсан менежер биш байна.');
            $order = loadTransportOrder($pdo, $orderId);
            if ((int)$order['transport_manager_id'] !== $myEmployeeId) throw new Exception('Та энэ захиалгыг засах эрхгүй байна.');
            if (!in_array($order['status'], ['draft', 'director_rejected'], true)) throw new Exception('Энэ захиалга одоо энэ шатанд биш байна.');

            $requestIds = array_map('intval', json_decode($_POST['request_ids'] ?? '[]', true) ?: []);
            $requestIds = array_values(array_unique(array_filter($requestIds)));
            if (count($requestIds) < 1) throw new Exception('Дор хаяж нэг хүсэлт сонгоно уу.');

            $pdo->beginTransaction();
            try {
                $ph = implode(',', array_fill(0, count($requestIds), '?'));
                $chk = $pdo->prepare("SELECT id FROM transport_requests WHERE id IN ($ph) AND status='pending_transport_manager' AND order_id IS NULL");
                $chk->execute($requestIds);
                $validIds = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
                if (count($validIds) !== count($requestIds)) throw new Exception('Сонгосон хүсэлтүүдийн зарим нь энэ шатанд байхгүй эсвэл аль хэдийн нэгтгэгдсэн байна.');

                $upd = $pdo->prepare("UPDATE transport_requests SET status='merged', order_id=? WHERE id=?");
                foreach ($validIds as $rid) { $upd->execute([$orderId, $rid]); }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Хүсэлт нэмэгдлээ.']);
            break;

        case 'order_remove_request':
            $orderId = intval($_POST['id'] ?? 0);
            $requestId = intval($_POST['request_id'] ?? 0);
            if (!transportHasRole($pdo, $myEmployeeId, 'transport_manager')) throw new Exception('Та Тээвэр хариуцсан менежер биш байна.');
            $order = loadTransportOrder($pdo, $orderId);
            if ((int)$order['transport_manager_id'] !== $myEmployeeId) throw new Exception('Та энэ захиалгыг засах эрхгүй байна.');
            if (!in_array($order['status'], ['draft', 'director_rejected'], true)) throw new Exception('Энэ захиалга одоо энэ шатанд биш байна.');

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM transport_requests WHERE order_id=?");
            $cntStmt->execute([$orderId]);
            if ((int)$cntStmt->fetchColumn() <= 1) throw new Exception('Захиалгад дор хаяж нэг хүсэлт байх ёстой. Бүрмөсөн цуцлахыг хүсвэл захиалгыг цуцална уу.');

            $upd = $pdo->prepare("UPDATE transport_requests SET status='pending_transport_manager', order_id=NULL WHERE id=? AND order_id=?");
            $upd->execute([$requestId, $orderId]);
            if ($upd->rowCount() === 0) throw new Exception('Энэ хүсэлт олдсонгүй.');

            echo json_encode(['success' => true, 'message' => 'Хүсэлт хасагдаж, менежерт буцлаа.']);
            break;

        case 'order_save_vehicles':
            $orderId = intval($_POST['id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if (!transportHasRole($pdo, $myEmployeeId, 'transport_manager')) throw new Exception('Та Тээвэр хариуцсан менежер биш байна.');
            $order = loadTransportOrder($pdo, $orderId);
            if ((int)$order['transport_manager_id'] !== $myEmployeeId) throw new Exception('Та энэ захиалгыг засах эрхгүй байна.');
            if (!in_array($order['status'], ['draft', 'director_rejected'], true)) throw new Exception('Энэ захиалга одоо энэ шатанд биш байна.');

            $vehicles = json_decode($_POST['vehicles'] ?? '[]', true) ?: [];
            $units    = json_decode($_POST['units'] ?? '[]', true) ?: [];
            if (empty($vehicles)) throw new Exception('Дор хаяж нэг машин нэмнэ үү.');

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM transport_order_vehicles WHERE order_id=?")->execute([$orderId]);

                $insertVeh = $pdo->prepare("INSERT INTO transport_order_vehicles (order_id, vehicle_type_id, qty, sort_order) VALUES (?,?,?,?)");
                $newVehIds = []; // vehicle_type_id ('lx'/'lc') -> newly-inserted transport_order_vehicles.id
                foreach ($vehicles as $i => $v) {
                    $typeId = trim($v['vehicle_type_id'] ?? '');
                    if ($typeId === '' || !isset(TRANSPORT_VEHICLE_TYPES[$typeId])) throw new Exception('Буруу машины төрөл.');
                    $qty = max(1, intval($v['qty'] ?? 1));
                    $insertVeh->execute([$orderId, $typeId, $qty, $i]);
                    $newVehIds[$typeId] = (int)$pdo->lastInsertId();
                }

                // units[].vehicle_id нь frontend дээр vehicle_type_id (lx/lc) — өөрөөр хэлбэл шинээр
                // үүссэн transport_order_vehicles.id рүү map хийнэ (машин бүрийг зөвхөн 1 удаа сонгодог тул түлхүүр давхцахгүй)
                $mappedUnits = array_map(function ($u) use ($newVehIds) {
                    $u['vehicle_id'] = $newVehIds[$u['vehicle_id']] ?? 0;
                    return $u;
                }, $units);

                saveVehicleUnits($pdo, 'transport_order_vehicle_units', 'order_vehicle_id', array_values($newVehIds), $mappedUnits);

                $pdo->prepare("UPDATE transport_orders SET status='pending_director', note=?, director_id=NULL, director_reviewed_at=NULL, director_comment=NULL WHERE id=?")
                    ->execute([$note ?: null, $orderId]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Хадгалагдаж, тээвэр хариуцсан захиралд илгээгдлээ.']);
            break;

        // ─── Тээвэр хариуцсан захирал: нэгтгэсэн захиалгыг батлах / буцаах ──
        case 'order_director_approve':
            $orderId = intval($_POST['id'] ?? 0);
            if (!transportHasRole($pdo, $myEmployeeId, 'director')) throw new Exception('Та Тээвэр хариуцсан захирал биш байна.');
            $order = loadTransportOrder($pdo, $orderId);
            if ($order['status'] !== 'pending_director') throw new Exception('Энэ захиалга одоо батлах шатанд биш байна.');

            $pdo->prepare("UPDATE transport_orders SET status='completed', director_id=?, director_reviewed_at=NOW(), director_comment=NULL WHERE id=?")
                ->execute([$myEmployeeId, $orderId]);
            echo json_encode(['success' => true, 'message' => 'Батлагдаж, захиалга баталгаажлаа.']);
            break;

        case 'order_director_reject':
            $orderId = intval($_POST['id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            if (!transportHasRole($pdo, $myEmployeeId, 'director')) throw new Exception('Та Тээвэр хариуцсан захирал биш байна.');
            $order = loadTransportOrder($pdo, $orderId);
            if ($order['status'] !== 'pending_director') throw new Exception('Энэ захиалга одоо буцаах шатанд биш байна.');
            if ($comment === '') throw new Exception('Буцаах шалтгаанаа бичнэ үү.');

            $pdo->prepare("UPDATE transport_orders SET status='director_rejected', director_id=?, director_reviewed_at=NOW(), director_comment=? WHERE id=?")
                ->execute([$myEmployeeId, $comment, $orderId]);
            echo json_encode(['success' => true, 'message' => 'Тээвэр хариуцсан менежерт буцаагдлаа.']);
            break;

        // ─── SuperAdmin: Тээвэр хариуцсан захирал/менежер тохируулах ─────
        case 'role_set':
            requireRole('superadmin');
            $employeeId = intval($_POST['employee_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            if (!$employeeId) throw new Exception('Ажилтан сонгоно уу.');
            if (!in_array($role, ['director', 'transport_manager'], true)) throw new Exception('Буруу үүрэг.');

            $pdo->prepare("INSERT IGNORE INTO transport_roles (employee_id, role) VALUES (?,?)")->execute([$employeeId, $role]);
            echo json_encode(['success' => true, 'message' => 'Нэмэгдлээ.']);
            break;

        case 'role_remove':
            requireRole('superadmin');
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM transport_roles WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Хасагдлаа.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
