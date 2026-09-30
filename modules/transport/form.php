<?php
$pageTitle  = 'Тээврийн захиалга';
$activePage = 'transport';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

$id = intval($_GET['id'] ?? 0);
$req = null;
$passengers = [];
$vehicles = [];
$canActAsManager = false;
$canActAsDirector = false;

if ($id) {
    $stmt = $pdo->prepare("
        SELECT r.*, req.last_name AS requester_last, req.first_name AS requester_first,
               mgr.last_name AS manager_last, mgr.first_name AS manager_first,
               dir.last_name AS director_last, dir.first_name AS director_first,
               tm.last_name AS tm_last, tm.first_name AS tm_first,
               o.status AS order_status
        FROM transport_requests r
        JOIN employees req ON req.id = r.requester_id
        LEFT JOIN employees mgr ON mgr.id = r.manager_id
        LEFT JOIN employees dir ON dir.id = r.director_id
        LEFT JOIN employees tm ON tm.id = r.transport_manager_id
        LEFT JOIN transport_orders o ON o.id = r.order_id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) { http_response_code(404); die('Захиалга олдсонгүй.'); }

    $isOwner = (int)$req['requester_id'] === $myEmployeeId;
    $isManagerHere = (int)$req['manager_id'] === $myEmployeeId;
    $isDirector = transportHasRole($pdo, $myEmployeeId, 'director');
    $isTransportManager = transportHasRole($pdo, $myEmployeeId, 'transport_manager');
    $isParty = $isOwner || $isManagerHere || $isDirector || $isTransportManager || isHR() || isSuperAdmin();
    if (!$isParty) { header('Location: ' . BASE_URL . '/denied.php'); exit; }

    $canActAsManager = $isManagerHere && $req['status'] === 'pending_manager';
    $canActAsDirector = $isDirector && $req['status'] === 'pending_director';
    // Хуучин (нэгтгэх боломж гарахаас өмнөх) шууд бөглөсөн бичлэгийг л энд харуулна — шинэ бичлэг order.php-ээр дамжина
    $showLegacyFulfillment = $req['status'] === 'completed' && empty($req['order_id']);

    $p = $pdo->prepare("
        SELECT tp.*, e.last_name AS emp_last, e.first_name AS emp_first, e.employee_code AS emp_code
        FROM transport_request_passengers tp
        LEFT JOIN employees e ON e.id = tp.employee_id
        WHERE tp.request_id=? ORDER BY tp.sort_order, tp.id
    ");
    $p->execute([$id]);
    $passengers = $p->fetchAll();

    $v = $pdo->prepare("SELECT * FROM transport_request_vehicles WHERE request_id=? ORDER BY sort_order, id");
    $v->execute([$id]);
    $vehicles = $v->fetchAll();

    $unitsByVehicle = [];
    if ($vehicles) {
        $vehIds = array_column($vehicles, 'id');
        $ph = implode(',', array_fill(0, count($vehIds), '?'));
        $u = $pdo->prepare("SELECT * FROM transport_vehicle_units WHERE vehicle_id IN ($ph) ORDER BY vehicle_id, unit_index");
        $u->execute($vehIds);
        foreach ($u->fetchAll() as $row) {
            $unitsByVehicle[$row['vehicle_id']][$row['unit_index']] = $row;
        }
    }
} else {
    $isOwner = true;
    $unitsByVehicle = [];
    $showLegacyFulfillment = false;
}

$editable = $req
    ? ($isOwner && $req['status'] === 'manager_rejected')
    : true;

// JS рүү дамжуулах өгөгдөл
$vehicleTypesForJs = [];
foreach (TRANSPORT_VEHICLE_TYPES as $typeId => $t) { $vehicleTypesForJs[] = array_merge(['id' => $typeId], $t); }

$passengersForJs = array_map(function ($p) {
    return [
        'id' => (int)$p['id'],
        'guest' => $p['type'] === 'Зочин',
        'employee_id' => $p['employee_id'] ? (int)$p['employee_id'] : null,
        'name' => $p['employee_id'] ? trim($p['emp_last'] . '. ' . $p['emp_first'] . ' (' . $p['emp_code'] . ')') : (string)$p['full_name'],
        'org' => (string)$p['organization'],
        'pos' => (string)$p['position'],
        'unit' => (string)$p['unit_name'],
        'b' => (bool)$p['food_morning'],
        'l' => (bool)$p['food_lunch'],
        'd' => (bool)$p['food_dinner'],
    ];
}, $passengers);

$vehiclesForJs = [];
foreach (TRANSPORT_VEHICLE_TYPES as $typeId => $t) {
    $existing = null;
    foreach ($vehicles as $v) { if ($v['vehicle_type_id'] === $typeId) { $existing = $v; break; } }
    $vehiclesForJs[] = [
        'id' => $existing ? (int)$existing['id'] : 0,
        'type' => $typeId,
        'qty' => $existing ? (int)$existing['qty'] : 0,
    ];
}

// Тээврийн мэргэжилтний бөглөх хэсэг: машины мөр бүрийг qty-ийн дагуу физик машин (unit) болгож задална
$fulfillmentUnitsForJs = [];
foreach ($vehicles as $v) {
    $vt = TRANSPORT_VEHICLE_TYPES[$v['vehicle_type_id']] ?? ['full' => $v['vehicle_type_id'], 'meta' => ''];
    for ($i = 1; $i <= (int)$v['qty']; $i++) {
        $u = $unitsByVehicle[$v['id']][$i] ?? null;
        $fulfillmentUnitsForJs[] = [
            'uid' => $v['id'] . '-' . $i,
            'vehicle_id' => (int)$v['id'],
            'unit_index' => $i,
            'model' => $vt['full'],
            'meta' => $vt['meta'],
            'is_rented' => $u ? (bool)$u['is_rented'] : false,
            'rental_company' => $u['rental_company'] ?? '',
            'rental_days' => $u['rental_days'] ?? ($req['total_days'] ?? ''),
            'rental_rate' => $u['rental_rate'] ?? '',
            'plate' => $u['plate_number'] ?? '',
            'driver' => $u['driver_name'] ?? '',
            'phone' => $u['driver_phone'] ?? '',
            'fuel_type' => $u['fuel_type'] ?? 'diesel',
            'km' => $u['distance_km'] ?? ($req['total_km'] ?? ''),
            'norm' => $u['fuel_norm'] ?? '',
            'price' => $u['fuel_price'] ?? '',
            'card' => $u['fuel_card_number'] ?? '',
        ];
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
?>
<style>
.tr-page{max-width:1440px;margin:0 auto}
.tr-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;display:flex;flex-direction:column;gap:20px}
html.dark .tr-card{background:#1c212b;border-color:#2a2f3b}
.tr-sec-icon{width:36px;height:36px;border-radius:10px;background:#fcede6;color:#c9481f;display:flex;align-items:center;justify-content:center;flex-shrink:0}
html.dark .tr-sec-icon{background:#2a1810;color:#ff8a5c}
.tr-sec-head{cursor:pointer;user-select:none}
.tr-sec-chevron{color:#94a3b8;flex-shrink:0;transition:transform .2s}
html.dark .tr-sec-chevron{color:#8b93a1}
.tr-sec-head.tr-collapsed .tr-sec-chevron{transform:rotate(-90deg)}
.tr-sec-body{display:flex;flex-direction:column;gap:20px}
.tr-inp{width:100%;height:44px;padding:0 14px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;font:inherit;font-size:15px;color:#1c2430;outline:none;transition:border-color .15s,box-shadow .15s}
html.dark .tr-inp{background:#272c38;border-color:#2a2f3b;color:#f2f3f6}
.tr-inp::placeholder{color:#94a3b8}
.tr-inp:focus{border-color:#f1592a;box-shadow:0 0 0 3px rgba(241,89,42,.15)}
.tr-inp[readonly]{background:#f7f6f3;color:#55575c;border-style:dashed}
html.dark .tr-inp[readonly]{background:#20242e;color:#8b93a1}
.tr-inp.err{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.12)}
.tr-inp.sm{height:40px;font-size:14px}
.tr-lbl{display:block;font-size:13px;font-weight:600;color:#3a3c42;margin-bottom:6px}
html.dark .tr-lbl{color:#c9cdd6}
.tr-lbl-sub{font-size:12px;color:#64748b;font-weight:500}
html.dark .tr-lbl-sub{color:#8b93a1}
.tr-readout{height:44px;border-radius:10px;background:#f7f6f3;display:flex;align-items:center;justify-content:space-between;padding:0 14px}
html.dark .tr-readout{background:#20242e}
.tr-readout b{font-size:18px}
.tr-readout span{font-size:12px;color:#64748b}
html.dark .tr-readout span{color:#8b93a1}
.tr-search{position:relative}
.tr-search svg{position:absolute;left:14px;top:13px;pointer-events:none}
.tr-search .tr-inp{padding-left:42px}
.tr-seg-wrap{display:flex;padding:3px;border-radius:10px;background:#f1f5f9;flex-shrink:0}
html.dark .tr-seg-wrap{background:#272c38}
.tr-seg{border:0;background:transparent;font:inherit;font-size:13px;font-weight:600;color:#64748b;padding:0 12px;height:32px;border-radius:7px;cursor:pointer}
html.dark .tr-seg{color:#8b93a1}
.tr-seg.on{background:#fff;color:#1c2430;box-shadow:0 1px 2px rgba(0,0,0,.12)}
html.dark .tr-seg.on{background:#1c212b;color:#f2f3f6}
.tr-meal{display:inline-flex;align-items:center;height:36px;padding:0 12px;border-radius:999px;border:1px solid #e2e8f0;background:#fff;font:inherit;font-size:13px;font-weight:600;color:#64748b;cursor:pointer}
html.dark .tr-meal{background:#272c38;border-color:#2a2f3b;color:#8b93a1}
.tr-meal.on{background:#fcede6;border-color:#f1592a;color:#9e3616}
html.dark .tr-meal.on{background:#2a1810;color:#ff8a5c}
.tr-icon-btn{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;background:transparent;color:#64748b;cursor:pointer;flex-shrink:0}
html.dark .tr-icon-btn{color:#8b93a1}
.tr-icon-btn:hover{background:#fbeaea;color:#b42318}
.tr-icon-btn:disabled{opacity:.35;cursor:not-allowed;background:transparent}
.tr-btn-primary{height:46px;border-radius:11px;font:inherit;font-size:15px;font-weight:600;cursor:pointer;border:0;background:#f1592a;color:#fff;width:100%}
.tr-btn-primary:hover{background:#c33e12}
.tr-btn-primary:disabled{background:#f3c8ab;cursor:not-allowed}
html.dark .tr-btn-primary:disabled{background:#5a3a28;color:#8b93a1}
.tr-btn-ghost{height:46px;border-radius:11px;font:inherit;font-size:15px;font-weight:600;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#1c2430;width:100%}
html.dark .tr-btn-ghost{border-color:#2a2f3b;background:#272c38;color:#f2f3f6}
.tr-add-row{width:100%;height:52px;border:1.5px dashed #cbd5e1;border-radius:12px;background:transparent;font:inherit;font-size:14px;font-weight:600;color:#c9481f;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px}
html.dark .tr-add-row{border-color:#3a4151;color:#ff8a5c}
.tr-add-row:hover{background:#fcf3ef}
html.dark .tr-add-row:hover{background:#241814}
.tr-link-btn{border:0;background:transparent;font:inherit;font-size:12px;font-weight:600;color:#c9481f;cursor:pointer;padding:4px 6px;border-radius:6px}
html.dark .tr-link-btn{color:#ff8a5c}
.tr-item{border:1px solid #e2e8f0;border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:14px;background:#fdfcfa}
html.dark .tr-item{border-color:#2a2f3b;background:#20242e}
.tr-num{width:28px;height:28px;flex-shrink:0;border-radius:50%;background:#f1f5f9;font-size:13px;font-weight:700;color:#3a3c42;display:flex;align-items:center;justify-content:center}
html.dark .tr-num{background:#272c38;color:#c9cdd6}
.tr-stepper{display:flex;align-items:center;height:44px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;flex-shrink:0}
html.dark .tr-stepper{border-color:#2a2f3b;background:#272c38}
.tr-stepper b{min-width:32px;text-align:center;font-size:16px}
.tr-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:10px;background:#fffbeb;color:#92400e;font-size:13px;line-height:1.5}
html.dark .tr-notice{background:rgba(180,131,11,.15);color:#facc15}
.tr-notice svg{flex-shrink:0;margin-top:1px}
.tr-pill{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 12px;border-radius:999px;font-size:13px;font-weight:600;background:#f1f5f9;color:#3a3c42}
html.dark .tr-pill{background:#272c38;color:#c9cdd6}
.tr-pill.ok{background:#dcfce7;color:#15803d}
html.dark .tr-pill.ok{background:rgba(34,197,94,.15);color:#4ade80}
.tr-pill.bad{background:#fee2e2;color:#b42318}
html.dark .tr-pill.bad{background:rgba(220,38,38,.15);color:#f87171}
.tr-summary{position:sticky;top:24px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;display:flex;flex-direction:column;gap:18px}
html.dark .tr-summary{background:#1c212b;border-color:#2a2f3b}
.tr-srow{display:flex;justify-content:space-between;gap:12px;font-size:14px}
.tr-srow span:first-child{color:#64748b}
html.dark .tr-srow span:first-child{color:#8b93a1}
.tr-srow span:last-child{font-weight:600;text-align:right}
.tr-hr{height:1px;background:#e2e8f0}
html.dark .tr-hr{background:#2a2f3b}
.tr-mgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.tr-mgrid div{background:#f7f6f3;border-radius:10px;padding:10px 12px}
html.dark .tr-mgrid div{background:#20242e}
.tr-mgrid small{font-size:12px;color:#64748b;display:block}
html.dark .tr-mgrid small{color:#8b93a1}
.tr-mgrid b{font-size:22px}
.tr-portions{display:flex;justify-content:space-between;align-items:baseline;padding:12px 14px;border-radius:10px;background:#fcede6;color:#9e3616}
html.dark .tr-portions{background:#2a1810;color:#ff8a5c}
.tr-portions span{font-size:13px;font-weight:600}
.tr-portions b{font-size:20px}
.tr-check{display:flex;gap:10px;align-items:center;font-size:13px;color:#3a3c42}
html.dark .tr-check{color:#c9cdd6}
.tr-check i{width:20px;height:20px;border-radius:50%;border:1.5px solid #cbd5e1;display:flex;align-items:center;justify-content:center;font-style:normal;font-size:12px;font-weight:700;color:transparent;flex-shrink:0}
html.dark .tr-check i{border-color:#3a4151}
.tr-check i.ok{background:#16a34a;border-color:#16a34a;color:#fff}
.tr-step{display:flex;align-items:center;gap:10px;padding:8px 14px 8px 8px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;font-size:14px;font-weight:600}
html.dark .tr-step{background:#1c212b;border-color:#2a2f3b;color:#f2f3f6}
.tr-dot{width:26px;height:26px;border-radius:50%;background:#f1592a;color:#fff;font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tr-dot.done{background:#16a34a}
.tr-hidden{display:none!important}
.tr-group{border:1px solid #e2e8f0;border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:14px;background:#fff}
html.dark .tr-group{border-color:#2a2f3b;background:#272c38}
.tr-gtitle{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#3a3c42}
html.dark .tr-gtitle{color:#c9cdd6}
.tr-gtitle svg{color:#c9481f}
html.dark .tr-gtitle svg{color:#ff8a5c}
.tr-badge{display:inline-flex;align-items:center;gap:7px;height:30px;padding:0 12px;border-radius:999px;background:#fff4db;color:#8a5a00;font-size:12px;font-weight:600}
html.dark .tr-badge{background:rgba(180,131,11,.2);color:#facc15}
.tr-badge i{width:7px;height:7px;border-radius:50%;background:#d99a00}
.tr-bar{display:flex;height:10px;border-radius:999px;overflow:hidden;background:#e2e8f0}
html.dark .tr-bar{background:#2a2f3b}
.tr-total-box{padding:16px;border-radius:12px;background:#fcede6;display:flex;flex-direction:column;gap:4px}
html.dark .tr-total-box{background:#2a1810}
.tr-total-box span{font-size:13px;font-weight:600;color:#9e3616}
html.dark .tr-total-box span{color:#ff8a5c}
.tr-total-box b{font-size:28px;color:#8f2e14;line-height:1.1}
html.dark .tr-total-box b{color:#ff8a5c}
.tr-plate{text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.tr-tm-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.tr-tm-table th{text-align:left;white-space:nowrap;padding:9px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.02em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0}
html.dark .tr-tm-table th{color:#8b93a1;background:#20242e;border-color:#2a2f3b}
.tr-tm-table td{padding:7px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
html.dark .tr-tm-table td{border-color:#242833}
.tr-tm-table tbody tr:last-child td{border-bottom:0}
.tr-tm-table input,.tr-tm-table select{width:100%;min-width:96px;height:34px;padding:0 8px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;font:inherit;font-size:13px;color:#1c2430;outline:none}
html.dark .tr-tm-table input,html.dark .tr-tm-table select{background:#272c38;border-color:#2a2f3b;color:#f2f3f6}
.tr-tm-table input:disabled,.tr-tm-table select:disabled{opacity:.5;cursor:not-allowed}
.tr-tm-table .tr-tm-ro{font-weight:700;white-space:nowrap}
.tr-view-toggle{display:flex;padding:3px;border-radius:10px;background:#f1f5f9;flex-shrink:0}
html.dark .tr-view-toggle{background:#272c38}
.tr-view-toggle button{border:0;background:transparent;font:inherit;font-size:12px;font-weight:600;color:#64748b;padding:0 12px;height:30px;border-radius:7px;cursor:pointer;display:inline-flex;align-items:center;gap:5px}
html.dark .tr-view-toggle button{color:#8b93a1}
.tr-view-toggle button.on{background:#fff;color:#1c2430;box-shadow:0 1px 2px rgba(0,0,0,.12)}
html.dark .tr-view-toggle button.on{background:#1c212b;color:#f2f3f6}
</style>

<div class="tr-page flex flex-col gap-6">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div class="flex flex-col gap-2">
            <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] items-center gap-2">
                <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a><span>/</span>
                <a href="<?= BASE_URL ?>/modules/transport/index.php" class="hover:text-[#f1592a]">Тээврийн захиалга</a><span>/</span>
                <span class="text-[#1c2430] dark:text-[#f2f3f6] font-medium"><?= $req ? 'Захиалга #' . (int)$req['id'] : 'Шинэ захиалга' ?></span>
            </nav>
            <h1 class="text-3xl font-bold m-0"><?= $req ? 'Захиалга #' . (int)$req['id'] : 'Шинэ тээврийн захиалга' ?></h1>
            <p class="m-0 text-[15px] text-slate-500 dark:text-[#8b93a1]">Аяллын мэдээлэл, зорчих ажилтнууд, автомашин болон хоолны захиалгаа нэг дор бүртгэнэ.</p>
        </div>
        <?php if ($req): ?>
        <span class="px-3 py-1.5 rounded-full text-sm font-medium <?= TRANSPORT_STATUS_COLORS[$req['status']] ?>"><?= TRANSPORT_STATUS_LABELS[$req['status']] ?></span>
        <?php endif; ?>
    </div>

    <?php if ($req && in_array($req['status'], ['manager_rejected', 'director_rejected'], true)): ?>
        <div class="px-4 py-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/40 rounded-lg flex items-start gap-2">
            <span class="material-icons-outlined text-red-500" style="font-size:18px">error_outline</span>
            <p class="text-sm text-red-600 dark:text-red-400 m-0">
                <?= $req['status'] === 'manager_rejected' ? 'Шууд удирдлага буцаасан' : 'Тээвэр хариуцсан захирал буцаасан (Тээвэр хариуцсан менежерт)' ?>. Шалтгаан: <?= htmlspecialchars($req['status'] === 'manager_rejected' ? $req['manager_comment'] : $req['director_comment']) ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($req && $req['status'] === 'merged'): ?>
    <div class="tr-card">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3.5">
                <span class="tr-sec-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3v18M7 3v18M3 7.5h4M3 16.5h4M17 7.5h4M17 16.5h4"/></svg></span>
                <div>
                    <p class="font-semibold m-0">Энэ хүсэлт нэгтгэсэн захиалгад орсон байна.</p>
                    <p class="text-xs text-slate-500 dark:text-[#8b93a1] m-0">Тээвэр хариуцсан менежер, захиралтай хамт нэгтгэн боловсруулж байна.</p>
                </div>
            </div>
            <span class="px-3 py-1.5 rounded-full text-sm font-medium <?= TRANSPORT_ORDER_STATUS_COLORS[$req['order_status']] ?>"><?= TRANSPORT_ORDER_STATUS_LABELS[$req['order_status']] ?></span>
        </div>
    </div>
    <?php elseif ($req):
        $approvalSteps = ['Шууд удирдлага', 'Тээвэр хариуцсан менежер', 'Тээвэр хариуцсан захирал', 'Баталгаажсан'];
        $approvalActors = [
            $req['manager_last'] ? $req['manager_last'] . '. ' . $req['manager_first'] : null,
            $req['tm_last'] ? $req['tm_last'] . '. ' . $req['tm_first'] : null,
            $req['director_last'] ? $req['director_last'] . '. ' . $req['director_first'] : null,
            null,
        ];
        $approvalStepMap = ['pending_manager' => 0, 'manager_rejected' => 0, 'pending_transport_manager' => 1, 'director_rejected' => 1, 'pending_director' => 2, 'completed' => 3];
        $approvalCurrent = $approvalStepMap[$req['status']] ?? 0;
        $approvalDone = $req['status'] === 'completed';
    ?>
    <div class="tr-card">
        <div class="flex items-start">
            <?php foreach ($approvalSteps as $i => $label):
                $isDone = $i < $approvalCurrent || $approvalDone;
                $isActive = $i === $approvalCurrent && !$approvalDone;
                $circleClass = $isDone ? 'bg-green-500 text-white' : ($isActive ? 'bg-[#f1592a] text-white' : 'bg-slate-200 dark:bg-[#2a2f3b] text-slate-500 dark:text-[#8b93a1]');
                $labelClass = $isDone ? 'text-green-600 dark:text-green-400' : ($isActive ? 'text-[#f1592a]' : 'text-slate-400 dark:text-[#5a6172]');
                $lineClass = $i < $approvalCurrent || $approvalDone ? 'bg-green-500' : 'bg-slate-200 dark:bg-[#2a2f3b]';
            ?>
            <?php if ($i > 0): ?><div class="flex-1 h-0.5 mt-[22px] <?= $lineClass ?>"></div><?php endif; ?>
            <div class="flex flex-col items-center flex-none w-36">
                <div class="w-11 h-11 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0 <?= $circleClass ?>">
                    <?php if ($isDone): ?><span class="material-icons-outlined" style="font-size:20px">check</span><?php else: ?><?= $i + 1 ?><?php endif; ?>
                </div>
                <p class="text-xs font-semibold mt-2 text-center <?= $labelClass ?>"><?= $label ?></p>
                <?php if (!empty($approvalActors[$i])): ?><p class="text-[11px] text-slate-400 dark:text-[#5a6172] text-center m-0"><?= htmlspecialchars($approvalActors[$i]) ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="flex flex-wrap gap-3" id="steps"></div>
    <?php endif; ?>

    <div class="flex flex-col lg:flex-row gap-7 items-start">
        <div class="flex-1 min-w-0 flex flex-col gap-5">

            <!-- 1. Аяллын мэдээлэл -->
            <section class="tr-card">
                <div class="flex items-center justify-between gap-3.5 tr-sec-head" onclick="toggleSection(this)">
                    <div class="flex items-center gap-3.5">
                        <span class="tr-sec-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                        <div><h2 class="text-lg font-bold m-0">1. Аяллын мэдээлэл</h2><p class="m-0 mt-0.5 text-[13px] text-slate-500 dark:text-[#8b93a1]">Хаашаа, ямар зорилгоор, хэзээ явах вэ</p></div>
                    </div>
                    <svg class="tr-sec-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </div>
                <div class="tr-sec-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div><label class="tr-lbl" for="route">Аяллын чиглэл <span class="text-red-500">*</span></label>
                        <input id="route" class="tr-inp" type="text" placeholder="Жишээ: Төв аймаг — Голомт агуулах" <?= $editable ? '' : 'disabled' ?> value="<?= htmlspecialchars($req['travel_direction'] ?? '') ?>"></div>
                    <div><label class="tr-lbl" for="purpose">Аяллын зорилго <span class="text-red-500">*</span></label>
                        <select id="purpose" class="tr-inp" <?= $editable ? '' : 'disabled' ?>>
                            <option value="">Зорилго сонгох</option>
                            <?php foreach (TRANSPORT_PURPOSE_OPTIONS as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= ($req['travel_purpose'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-[1fr_1fr_180px_180px] gap-5 items-start">
                    <div><label class="tr-lbl" for="start">Эхлэх огноо <span class="text-red-500">*</span></label>
                        <input id="start" class="tr-inp" type="date" <?= $editable ? '' : 'disabled' ?> value="<?= htmlspecialchars($req['start_date'] ?? '') ?>"></div>
                    <div><label class="tr-lbl" for="end">Дуусах огноо <span class="text-red-500">*</span></label>
                        <input id="end" class="tr-inp" type="date" <?= $editable ? '' : 'disabled' ?> value="<?= htmlspecialchars($req['end_date'] ?? '') ?>">
                        <p class="text-xs text-red-500 mt-1.5 tr-hidden" id="dateErr">Дуусах огноо эхлэхээс өмнө байж болохгүй</p></div>
                    <div><span class="tr-lbl">Нийт өдөр</span><div class="tr-readout"><b id="days">—</b><span>автомат</span></div></div>
                    <div><label class="tr-lbl" for="km">Нийт зай</label>
                        <div class="relative"><input id="km" class="tr-inp" type="number" min="0" style="padding-right:44px" <?= $editable ? '' : 'disabled' ?> value="<?= htmlspecialchars($req['total_km'] ?? '') ?>"><span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-500 dark:text-[#8b93a1]">км</span></div></div>
                </div>
                </div>
            </section>

            <!-- 2. Зорчигчид ба хоол -->
            <section class="tr-card">
                <div class="flex justify-between items-center gap-4 flex-wrap tr-sec-head" onclick="toggleSection(this)">
                    <div class="flex items-center gap-3.5">
                        <span class="tr-sec-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.8c2 .7 3.2 2.5 3.5 5.2"/></svg></span>
                        <div><h2 class="text-lg font-bold m-0">2. Зорчигчид ба хоол</h2><p class="m-0 mt-0.5 text-[13px] text-slate-500 dark:text-[#8b93a1]">Ажилтныг нэрээр хайхад албан тушаал, нэгж автоматаар бөглөгдөнө</p></div>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if ($editable): ?>
                        <div class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-[#8b93a1]" onclick="event.stopPropagation()">
                            <span>Бүгдэд тэмдэглэх:</span>
                            <button type="button" class="tr-link-btn" data-all="b">Өглөө</button>
                            <button type="button" class="tr-link-btn" data-all="l">Өдөр</button>
                            <button type="button" class="tr-link-btn" data-all="d">Орой</button>
                        </div>
                        <?php endif; ?>
                        <svg class="tr-sec-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                </div>
                <div class="tr-sec-body">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-3" id="rows"></div>
                    <?php if ($editable): ?>
                    <button type="button" class="tr-add-row" id="addRow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>Зорчигч нэмэх</button>
                    <?php endif; ?>
                </div>
                </div>
            </section>

            <!-- 3. Автомашин -->
            <section class="tr-card">
                <div class="flex justify-between items-center gap-4 flex-wrap tr-sec-head" onclick="toggleSection(this)">
                    <div class="flex items-center gap-3.5">
                        <span class="tr-sec-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16V8.5A1.5 1.5 0 0 1 4.5 7H14v9"/><path d="M14 10h3.6l3.4 3.6V16"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/><path d="M9 17h6"/></svg></span>
                        <div><h2 class="text-lg font-bold m-0">3. Автомашин</h2><p class="m-0 mt-0.5 text-[13px] text-slate-500 dark:text-[#8b93a1]">Хүссэн машины төрөл, тоог оруулна уу</p></div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="tr-pill" id="capPill"></div>
                        <svg class="tr-sec-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                </div>
                <div class="tr-sec-body">
                <div class="tr-notice"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.6 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.6a2 2 0 0 0-3.4 0z"/></svg><span>Энэ бол захиалга үүсгэгчийн хүссэн машины төрөл, тоо. Бодит хуваарилалт хийхэд өөрчлөгдөх боломжтой.</span></div>
                <div class="flex flex-col gap-3" id="cars"></div>
                </div>
            </section>

        </div>

        <aside class="w-full lg:w-[340px] flex-shrink-0">
            <div class="tr-summary">
                <h2 class="text-base font-bold m-0">Захиалгын хураангуй</h2>
                <div class="flex flex-col gap-3">
                    <div class="tr-srow"><span>Чиглэл</span><span id="sRoute">—</span></div>
                    <div class="tr-srow"><span>Хугацаа</span><span id="sDays">— өдөр</span></div>
                    <div class="tr-srow"><span>Зай</span><span id="sKm">—</span></div>
                    <div class="tr-srow"><span>Зорчигч</span><span id="sPeople">1 хүн</span></div>
                    <div class="tr-srow"><span>Автомашин</span><span id="sCars">1 ширхэг</span></div>
                    <div class="tr-srow"><span>Суудлын багтаамж</span><span id="sCap">0</span></div>
                </div>
                <div class="tr-hr"></div>
                <div class="flex flex-col gap-2.5">
                    <span class="text-[13px] font-semibold text-[#3a3c42] dark:text-[#c9cdd6]">Хоол (өдөрт)</span>
                    <div class="tr-mgrid"><div><small>Өглөө</small><b id="cB">0</b></div><div><small>Өдөр</small><b id="cL">0</b></div><div><small>Орой</small><b id="cD">0</b></div></div>
                    <div class="tr-portions"><span>Нийт порц</span><b id="portions">0</b></div>
                </div>

                <?php if ($editable): ?>
                    <div class="tr-hr"></div>
                    <div class="flex flex-col gap-2" id="checks"></div>
                    <div class="flex flex-col gap-2">
                        <button type="button" class="tr-btn-primary" id="submit" disabled>Захиалга илгээх</button>
                        <a href="<?= BASE_URL ?>/modules/transport/index.php" class="text-center text-sm py-2 text-slate-500 dark:text-[#8b93a1] hover:underline">Цуцлах</a>
                    </div>
                <?php elseif ($canActAsManager || $canActAsDirector): ?>
                    <div class="tr-hr"></div>
                    <h3 class="font-bold m-0 text-sm"><?= $canActAsManager ? 'Шууд удирдлагын шийдвэр' : 'Тээвэр хариуцсан захирлын шийдвэр' ?></h3>
                    <textarea id="reviewComment" rows="2" placeholder="Буцаах бол шалтгаанаа бичнэ үү (заавал биш батлахад)..." class="tr-inp" style="height:auto;padding:10px 14px;resize:vertical"></textarea>
                    <div class="flex flex-col gap-2">
                        <button type="button" onclick="doApprove()" class="tr-btn-primary">Батлах</button>
                        <button type="button" onclick="doReject()" class="tr-btn-ghost">Буцаах</button>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <?php if ($showLegacyFulfillment): ?>
    <?php $tmEditable = false; ?>
    <section class="tr-card !p-0 overflow-hidden">
        <div class="flex items-center justify-between gap-4 flex-wrap px-7 py-6 border-b border-slate-100 dark:border-[#2a2f3b]">
            <div class="flex items-center gap-3.5">
                <span class="tr-sec-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-4"/></svg></span>
                <div><h2 class="text-lg font-bold m-0">Тээврийн мэргэжилтэн бөглөсөн мэдээлэл</h2><p class="m-0 mt-0.5 text-[13px] text-slate-500 dark:text-[#8b93a1]">Машин бүрийн түрээс, жолооч, шатахууны мэдээлэл (хуучин бүртгэл)</p></div>
            </div>
            <div class="flex items-center gap-2.5">
                <div class="tr-view-toggle" id="tmViewToggle">
                    <button type="button" class="on" data-view="cards"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>Карт</button>
                    <button type="button" data-view="table"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M3 10h18M9 4v16"/></svg>Хүснэгт</button>
                </div>
                <span class="tr-badge"><i></i>Бөглөгдсөн</span>
                <span class="text-[13px] text-slate-500 dark:text-[#8b93a1]" id="tmFilled"></span>
            </div>
        </div>
        <div class="flex flex-col lg:flex-row items-stretch">
            <div class="flex-1 min-w-0 p-7 flex flex-col gap-5">
                <div id="tmCars" class="flex flex-col gap-5"></div>
                <div id="tmTable" class="tr-hidden overflow-x-auto -mx-7 px-7"></div>
            </div>
            <aside class="w-full lg:w-[330px] flex-shrink-0 border-t lg:border-t-0 lg:border-l border-slate-100 dark:border-[#2a2f3b] bg-slate-50/50 dark:bg-[#171b24] p-6 flex flex-col gap-4">
                <h3 class="m-0 text-[15px] font-bold">Төсвийн тооцоо</h3>
                <div class="flex flex-col gap-3">
                    <div class="tr-srow"><span>Нийт км</span><span id="tmTotalKm"></span></div>
                    <div class="tr-srow"><span>Нийт шатахуун</span><span id="tmTotalLitres"></span></div>
                    <div class="tr-hr"></div>
                    <div class="tr-srow"><span>Түрээсийн дүн</span><span id="tmTotalRental"></span></div>
                    <div class="tr-srow"><span>Шатахууны дүн</span><span id="tmTotalFuel"></span></div>
                </div>
                <div class="tr-bar"><span id="tmBarRent" style="background:#f1592a"></span><span id="tmBarFuel" style="background:#3a3c42"></span></div>
                <div class="flex gap-4 text-xs text-slate-600 dark:text-[#c9cdd6]">
                    <span class="inline-flex items-center gap-1.5"><i class="w-2.5 h-2.5 rounded-sm inline-block" style="background:#f1592a"></i>Түрээс</span>
                    <span class="inline-flex items-center gap-1.5"><i class="w-2.5 h-2.5 rounded-sm inline-block" style="background:#3a3c42"></i>Шатахуун</span>
                </div>
                <div class="tr-total-box"><span>Нийт төсөв</span><b id="tmTotalAll"></b></div>
                <?php if ($req['transport_manager_note']): ?>
                    <div class="mt-auto"><span class="tr-lbl-sub block mb-1">Нэмэлт тэмдэглэл</span><p class="text-sm text-slate-600 dark:text-[#c9cdd6] m-0"><?= nl2br(htmlspecialchars($req['transport_manager_note'])) ?></p></div>
                <?php endif; ?>
            </aside>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
function toggleSection(head) {
  var body = head.nextElementSibling;
  if (!body || !body.classList.contains('tr-sec-body')) return;
  var collapsed = body.classList.toggle('tr-hidden');
  head.classList.toggle('tr-collapsed', collapsed);
}
(function () {
  var EDITABLE = <?= $editable ? 'true' : 'false' ?>;
  var TYPES = <?= json_encode($vehicleTypesForJs) ?>;
  var AJAX = '<?= BASE_URL ?>/modules/transport/ajax.php';
  var REQ_ID = <?= (int)$id ?>;

  var SUV_ICON = '<svg width="40" height="24" viewBox="0 0 40 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path d="M3 17v-5l4-6h17l6 6h6a1 1 0 0 1 1 1v4z"/><path d="M10 6v6M21 6l1 6M3 12h34"/><circle cx="10" cy="18" r="3" fill="#fff"/><circle cx="30" cy="18" r="3" fill="#fff"/></svg>';
  var TRASH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V4.5h6V7"/><path d="M6.5 7l1 13h9l1-13"/></svg>';
  var SEARCH_ICON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';

  var st = {
    route: <?= json_encode($req['travel_direction'] ?? '') ?>,
    purpose: <?= json_encode($req['travel_purpose'] ?? '') ?>,
    start: <?= json_encode($req['start_date'] ?? '') ?>,
    end: <?= json_encode($req['end_date'] ?? '') ?>,
    km: <?= json_encode($req['total_km'] ?? '') ?>,
    rows: <?= json_encode($passengersForJs) ?>,
    cars: <?= json_encode($vehiclesForJs) ?>
  };
  var rid = st.rows.reduce(function (m, r) { return Math.max(m, r.id || 0); }, 0);
  function newRow() { return { id: ++rid, guest: false, employee_id: null, name: '', org: '', pos: '', unit: '', b: false, l: false, d: false }; }
  if (!st.rows.length && EDITABLE) st.rows.push(newRow());

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function type(id) { for (var i = 0; i < TYPES.length; i++) if (TYPES[i].id === id) return TYPES[i]; return null; }
  function findRow(id) { return st.rows.filter(function (r) { return r.id === id; })[0]; }
  function findCar(typeId) { return st.cars.filter(function (c) { return c.type === typeId; })[0]; }
  function dis() { return EDITABLE ? '' : ' disabled'; }

  function days() {
    if (!st.start || !st.end) return { d: null, err: false };
    var diff = Math.round((new Date(st.end) - new Date(st.start)) / 86400000);
    return diff < 0 ? { d: null, err: true } : { d: diff + 1, err: false };
  }

  /* ---------- passengers ---------- */
  function renderRows() {
    var one = st.rows.length <= 1;
    $('rows').innerHTML = st.rows.map(function (r, i) {
      var ro = (r.guest || !EDITABLE) ? '' : ' readonly';
      function f(key, label) {
        return '<div><span class="tr-lbl-sub block mb-1.5">' + label + '</span><input class="tr-inp sm" type="text" placeholder="' + (r.guest ? 'Бичих' : 'Автоматаар бөглөгдөнө') + '"' + ro + dis() + ' data-row="' + r.id + '" data-field="' + key + '" value="' + esc(r[key]) + '"></div>';
      }
      function m(k, label) { return '<button type="button" class="tr-meal' + (r[k] ? ' on' : '') + '"' + dis() + ' data-row="' + r.id + '" data-meal="' + k + '">' + label + '</button>'; }
      return '<div class="tr-item"><div class="flex gap-3 items-center flex-wrap">' +
        '<span class="tr-num">' + (i + 1) + '</span>' +
        '<div class="tr-seg-wrap">' +
        '<button type="button" class="tr-seg' + (r.guest ? '' : ' on') + '"' + dis() + ' data-row="' + r.id + '" data-guest="0">Ажилтан</button>' +
        '<button type="button" class="tr-seg' + (r.guest ? ' on' : '') + '"' + dis() + ' data-row="' + r.id + '" data-guest="1">Зочин</button></div>' +
        '<div class="tr-search relative flex-1" style="min-width:200px">' + SEARCH_ICON + '<input class="tr-inp pax-name-search" type="text" autocomplete="off" placeholder="' + (r.guest ? 'Зочны овог, нэр' : 'Ажилтныг нэр эсвэл кодоор хайх…') + '"' + dis() + ' data-row="' + r.id + '" data-field="name" value="' + esc(r.name) + '">' +
        '<div class="typeahead-results hidden absolute z-20 mt-1 w-full bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-lg shadow-lg max-h-52 overflow-y-auto"></div></div>' +
        (EDITABLE ? '<button type="button" class="tr-icon-btn" data-del-row="' + r.id + '"' + (one ? ' disabled' : '') + '>' + TRASH + '</button>' : '') +
        '</div><div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end sm:pl-[52px]">' + f('org', 'Байгууллага') + f('pos', 'Албан тушаал') + f('unit', 'Газар, нэгж / хэлтэс') +
        '<div><span class="tr-lbl-sub block mb-1.5">Хоол</span><div class="flex gap-1.5">' + m('b', 'Өглөө') + m('l', 'Өдөр') + m('d', 'Орой') + '</div></div>' +
        '</div></div>';
    }).join('');
  }

  /* ---------- vehicles (LX470 / Land Cruiser 200 — тоог оруулна) ---------- */
  function renderCars() {
    $('cars').innerHTML = st.cars.map(function (c) {
      var t = type(c.type);
      var sub = t.meta + (c.qty ? ' × ' + c.qty + ' = ' + (t.seats * c.qty) + ' зорчигчийн багтаамж' : '');
      return '<div class="tr-item"><div class="flex gap-3 items-center flex-wrap">' +
        '<span class="tr-num" style="width:44px;height:44px;border-radius:12px;background:#fff">' + SUV_ICON + '</span>' +
        '<div class="flex-1 flex flex-col gap-0" style="min-width:160px"><span class="text-[15px] font-bold">' + esc(t.full) + '</span><span class="text-[13px] text-slate-500 dark:text-[#8b93a1]">' + esc(sub) + '</span></div>' +
        '<span class="text-xs text-slate-500 dark:text-[#8b93a1]">Тоо</span>' +
        '<div class="tr-stepper"><button type="button" class="tr-icon-btn" style="width:44px;height:42px" data-dec="' + c.type + '"' + (c.qty <= 0 ? ' disabled' : dis()) + '><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14"/></svg></button><b>' + c.qty + '</b><button type="button" class="tr-icon-btn" style="width:44px;height:42px" data-inc="' + c.type + '"' + dis() + '><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></button></div>' +
        '</div></div>';
    }).join('');
  }

  /* ---------- summary ---------- */
  function renderSummary() {
    var dd = days();
    $('end').classList.toggle('err', dd.err);
    $('dateErr').classList.toggle('tr-hidden', !dd.err);
    $('days').textContent = dd.d ? dd.d : '—';
    $('sRoute').textContent = (st.route || '').trim() || '—';
    $('sDays').textContent = (dd.d || '—') + ' өдөр';
    $('sKm').textContent = st.km ? st.km + ' км' : '—';
    $('sPeople').textContent = st.rows.length + ' хүн';
    var carTotal = 0, cap = 0;
    st.cars.forEach(function (c) { carTotal += c.qty; var t = type(c.type); if (t) cap += t.seats * c.qty; });
    $('sCars').textContent = carTotal + ' ширхэг';
    $('sCap').textContent = cap;
    var pill = $('capPill'), capOk = cap >= st.rows.length;
    pill.className = 'tr-pill' + (cap ? (capOk ? ' ok' : ' bad') : '');
    pill.textContent = cap ? 'Багтаамж ' + cap + ' · Зорчигч ' + st.rows.length + (capOk ? '' : ' — суудал хүрэлцэхгүй') : 'Зорчигч ' + st.rows.length;
    var cB = 0, cL = 0, cD = 0;
    st.rows.forEach(function (r) { if (r.b) cB++; if (r.l) cL++; if (r.d) cD++; });
    $('cB').textContent = cB; $('cL').textContent = cL; $('cD').textContent = cD;
    $('portions').textContent = (cB + cL + cD) * (dd.d || 1);

    var tripOk = !!((st.route || '').trim() && st.purpose && dd.d);
    var peopleOk = st.rows.every(function (r) { return (r.name || '').trim(); });
    var carsOk = st.cars.some(function (c) { return c.qty > 0; });
    if ($('checks')) {
      var checks = [[!!(st.route || '').trim(), 'Аяллын чиглэл'], [!!st.purpose, 'Аяллын зорилго'], [!!dd.d, 'Эхлэх, дуусах огноо'], [peopleOk, 'Зорчигчдын нэр'], [carsOk, 'Машины төрөл']];
      $('checks').innerHTML = checks.map(function (c) { return '<div class="tr-check"><i class="' + (c[0] ? 'ok' : '') + '">✓</i><span>' + c[1] + '</span></div>'; }).join('');
    }
    if ($('submit')) $('submit').disabled = !(tripOk && peopleOk && carsOk);
    if ($('steps')) {
      var steps = [['Аялал', tripOk], ['Зорчигчид ба хоол', peopleOk], ['Автомашин', carsOk]];
      $('steps').innerHTML = steps.map(function (s, i) { return '<div class="tr-step"><span class="tr-dot' + (s[1] ? ' done' : '') + '">' + (s[1] ? '✓' : i + 1) + '</span>' + s[0] + '</div>'; }).join('');
    }
  }

  /* ---------- events ---------- */
  ['route', 'purpose', 'start', 'end', 'km'].forEach(function (k) {
    var el = $(k); if (!el) return;
    el.addEventListener('input', function (e) { st[k] = e.target.value; if (k === 'start') $('end').min = e.target.value; renderSummary(); });
    el.addEventListener('change', function (e) { st[k] = e.target.value; renderSummary(); });
  });
  if ($('addRow')) $('addRow').addEventListener('click', function () { st.rows.push(newRow()); renderRows(); renderSummary(); });

  $('rows').addEventListener('input', function (e) {
    var t = e.target, r = findRow(+t.dataset.row);
    if (r && t.dataset.field) {
      r[t.dataset.field] = t.value;
      if (t.classList.contains('pax-name-search')) r.employee_id = null;
      renderSummary();
    }
  });
  $('rows').addEventListener('click', function (e) {
    if (!EDITABLE) return;
    var b = e.target.closest('button'); if (!b) return;
    var r = findRow(+b.dataset.row);
    if (b.dataset.guest != null && r) { r.guest = b.dataset.guest === '1'; renderRows(); }
    else if (b.dataset.meal && r) { r[b.dataset.meal] = !r[b.dataset.meal]; renderRows(); }
    else if (b.dataset.delRow) { st.rows = st.rows.filter(function (x) { return x.id !== +b.dataset.delRow; }); renderRows(); }
    renderSummary();
  });
  document.querySelectorAll('[data-all]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.dataset.all, on = !st.rows.every(function (r) { return r[k]; });
      st.rows.forEach(function (r) { r[k] = on; }); renderRows(); renderSummary();
    });
  });
  $('cars').addEventListener('click', function (e) {
    if (!EDITABLE) return;
    var b = e.target.closest('button'); if (!b) return;
    var d = b.dataset, c;
    if (d.inc) { findCar(d.inc).qty++; }
    else if (d.dec) { c = findCar(d.dec); if (c.qty > 0) c.qty--; }
    else return;
    renderCars(); renderSummary();
  });

  /* ---------- ажилтан typeahead (зорчигч) ---------- */
  var taTimer = null;
  document.addEventListener('input', function (e) {
    if (!e.target.matches('.pax-name-search')) return;
    var input = e.target;
    var box = input.parentElement.querySelector('.typeahead-results');
    var q = input.value.trim();
    clearTimeout(taTimer);
    if (q.length < 2) { box.classList.add('hidden'); box.innerHTML = ''; return; }
    taTimer = setTimeout(function () {
      fetch(AJAX + '?action=search_employees&q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (rows) {
        if (!rows.length) { box.classList.add('hidden'); box.innerHTML = ''; return; }
        box.innerHTML = '';
        rows.forEach(function (r) {
          var label = r.last_name + '. ' + r.first_name + ' (' + r.employee_code + ')';
          var item = document.createElement('div');
          item.className = 'px-3 py-2 text-xs hover:bg-slate-50 dark:hover:bg-[#272c38] cursor-pointer';
          item.textContent = label;
          item.addEventListener('click', function () {
            input.value = label;
            var row = findRow(+input.dataset.row);
            row.name = label; row.employee_id = r.id;
            if (!row.pos) row.pos = r.position || '';
            if (!row.unit) row.unit = r.unit_name || '';
            renderRows();
            box.classList.add('hidden'); box.innerHTML = '';
            renderSummary();
          });
          box.appendChild(item);
        });
        box.classList.remove('hidden');
      });
    }, 250);
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.pax-name-search, .typeahead-results')) {
      document.querySelectorAll('.typeahead-results').forEach(function (b) { b.classList.add('hidden'); b.innerHTML = ''; });
    }
  });

  /* ---------- илгээх ---------- */
  if ($('submit')) {
    $('submit').addEventListener('click', function () {
      var payload = {
        action: 'save_request', id: REQ_ID,
        travel_direction: st.route, travel_purpose: st.purpose, start_date: st.start, end_date: st.end,
        total_days: $('days').textContent === '—' ? 1 : $('days').textContent, total_km: st.km,
        passengers: JSON.stringify(st.rows.map(function (r) {
          return { employee_id: r.employee_id, type: r.guest ? 'Зочин' : 'Ажилтан', full_name: r.employee_id ? '' : r.name, organization: r.org, position: r.pos, unit_name: r.unit, food_morning: r.b ? 1 : 0, food_lunch: r.l ? 1 : 0, food_dinner: r.d ? 1 : 0 };
        })),
        vehicles: JSON.stringify(st.cars.filter(function (c) { return c.qty > 0; }).map(function (c) { return { vehicle_type_id: c.type, qty: c.qty }; }))
      };
      fetch(AJAX, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(payload) })
        .then(function (r) { return r.json(); }).then(function (r) {
          showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
          if (r.success) setTimeout(function () { location.href = '<?= BASE_URL ?>/modules/transport/index.php'; }, 700);
        });
    });
  }

  renderRows(); renderCars(); renderSummary();
})();

function reviewAction(action, extra) {
  var body = Object.assign({ action: action, id: <?= (int)$id ?> }, extra || {});
  $.post('<?= BASE_URL ?>/modules/transport/ajax.php', body, function (r) {
    showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
    if (r.success) setTimeout(function () { location.href = '<?= BASE_URL ?>/modules/transport/approvals.php'; }, 800);
  }, 'json');
}
function doApprove() {
  if (!confirm('Батлах уу?')) return;
  reviewAction('<?= $canActAsManager ? 'manager_approve' : 'director_approve' ?>');
}
function doReject() {
  var comment = $('#reviewComment').val().trim();
  if (!comment) { showToast('Буцаах шалтгаанаа бичнэ үү.', 'error'); return; }
  reviewAction('<?= $canActAsManager ? 'manager_reject' : 'director_reject' ?>', { comment: comment });
}
// ─── 4. Тээврийн мэргэжилтэн бөглөх хэсэг (машин бүрийн түрээс/жолооч/шатахуун) ──
(function () {
  var $cars = $('#tmCars');
  if (!$cars.length) return;
  var $table = $('#tmTable');
  var $wrap = $cars.add($table);
  var viewMode = 'cards';
  var TM_EDITABLE = <?= $tmEditable ?? false ? 'true' : 'false' ?>;
  var FUEL_TYPES = <?= json_encode(TRANSPORT_FUEL_TYPES) ?>;
  var cars = <?= json_encode($fulfillmentUnitsForJs) ?>;

  function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function fmt(n) { return Math.round(n).toLocaleString() + ' ₮'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function dis() { return TM_EDITABLE ? '' : ' disabled'; }
  function findCar(id) { return cars.filter(function (c) { return c.uid === id; })[0]; }
  function calc(c) {
    var rent = num(c.rental_days) * num(c.rental_rate);
    var litres = num(c.km) * num(c.norm) / 100;
    return { rent: rent, litres: litres, fuel: litres * num(c.price) };
  }
  function field(c, key, label, opts) {
    opts = opts || {};
    var input = '<input class="tr-inp sm' + (opts.cls ? ' ' + opts.cls : '') + '" type="' + (opts.type || 'text') + '"' + (opts.step ? ' step="' + opts.step + '"' : '') + (opts.type === 'number' ? ' min="0"' : '') + ' placeholder="' + (opts.ph || '') + '"' + dis() + ' data-car="' + c.uid + '" data-k="' + key + '" value="' + esc(c[key]) + '">';
    if (opts.unit) input = '<div class="tr-search relative"><span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-500 dark:text-[#8b93a1]">' + opts.unit + '</span>' + input.replace('tr-inp sm', 'tr-inp sm pr-12') + '</div>';
    return '<div><label class="tr-lbl-sub block mb-1.5">' + label + '</label>' + input + '</div>';
  }
  function render() {
    if (viewMode === 'table') { $cars.addClass('tr-hidden'); $table.removeClass('tr-hidden'); renderTable(); }
    else { $table.addClass('tr-hidden'); $cars.removeClass('tr-hidden'); renderCards(); }
    totals();
  }
  function renderCards() {
    $cars.html(cars.map(function (c) {
      var r = calc(c);
      return '<div class="tr-item">' +
        '<div class="flex justify-between items-center gap-3 flex-wrap">' +
          '<div class="flex gap-3 items-center"><span class="tr-num" style="width:44px;height:44px;border-radius:12px;background:#fff">' +
            '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16V8.5A1.5 1.5 0 0 1 4.5 7H14v9"/><path d="M14 10h3.6l3.4 3.6V16"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/><path d="M9 17h6"/></svg></span>' +
            '<div><div class="text-[15px] font-bold">' + esc(c.model) + (cars.filter(function(x){return x.vehicle_id===c.vehicle_id;}).length > 1 ? ' #' + c.unit_index : '') + '</div><div class="text-[13px] text-slate-500 dark:text-[#8b93a1]">' + esc(c.meta) + '</div></div></div>' +
          '<div class="flex gap-2 items-center"><span class="text-xs text-slate-500 dark:text-[#8b93a1]">Машины дүн</span><span class="text-lg font-bold" data-out="total' + c.uid + '">' + fmt(r.rent + r.fuel) + '</span></div>' +
        '</div>' +
        '<div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">' +
          '<div class="tr-group"><div class="tr-gtitle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>Түрээс</div>' +
            '<div><span class="tr-lbl-sub block mb-1.5">Түрээсийн төрөл</span><div class="tr-seg-wrap"><button type="button" class="tr-seg flex-1' + (c.is_rented ? '' : ' on') + '"' + dis() + ' data-car="' + c.uid + '" data-rented="0">Байгууллагын машин</button><button type="button" class="tr-seg flex-1' + (c.is_rented ? ' on' : '') + '"' + dis() + ' data-car="' + c.uid + '" data-rented="1">Гадны түрээс</button></div></div>' +
            field(c, 'rental_company', 'Түрээсийн компани', { ph: 'Компанийн нэр' }) +
            '<div class="grid grid-cols-[1fr_1.3fr_1.4fr] gap-2.5 items-end">' + field(c, 'rental_days', 'Хоног', { type: 'number' }) + field(c, 'rental_rate', 'Өдрийн үнэ', { type: 'number', unit: '₮' }) +
            '<div><span class="tr-lbl-sub block mb-1.5">Түрээсийн дүн</span><div class="tr-readout" data-out="rent' + c.uid + '">' + fmt(r.rent) + '</div></div></div>' +
            (!c.is_rented ? '<p class="text-xs text-slate-400 dark:text-[#5a6172] m-0">Байгууллагын өөрийн машин тул түрээсийн төлбөр тооцохгүй.</p>' : '') +
          '</div>' +
          '<div class="tr-group"><div class="tr-gtitle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.6-3.6 3.5-5.5 7-5.5s6.4 1.9 7 5.5"/></svg>Машин ба жолооч</div>' +
            field(c, 'plate', 'Улсын дугаар', { ph: '0000 УБА', cls: 'tr-plate' }) +
            '<div class="grid grid-cols-[1.4fr_1fr] gap-2.5">' + field(c, 'driver', 'Жолоочийн овог, нэр', { ph: 'Овог. Нэр' }) + field(c, 'phone', 'Жолоочийн утас', { type: 'tel', ph: '9911 2233' }) + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="tr-group"><div class="tr-gtitle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16"/><path d="M3 21h12M4 10h10"/><path d="M14 8h2a2 2 0 0 1 2 2v6a1.5 1.5 0 0 0 3 0V8l-3-3"/></svg>Шатахуун</div>' +
          '<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 items-end">' +
            '<div><span class="tr-lbl-sub block mb-1.5">Түлшний төрөл</span><div class="tr-seg-wrap">' + Object.keys(FUEL_TYPES).map(function (k) { return '<button type="button" class="tr-seg flex-1' + (c.fuel_type === k ? ' on' : '') + '"' + dis() + ' data-car="' + c.uid + '" data-fuel="' + k + '">' + FUEL_TYPES[k] + '</button>'; }).join('') + '</div></div>' +
            field(c, 'km', 'Явах зам', { type: 'number', unit: 'км' }) +
            field(c, 'norm', 'Норм', { type: 'number', step: '0.1', unit: 'л/100', ph: '0' }) +
            field(c, 'price', 'Нэгж үнэ', { type: 'number', unit: '₮/л', ph: '0' }) +
            field(c, 'card', 'Шатахууны картын №', { ph: 'Картын дугаар' }) +
            '<div><span class="tr-lbl-sub block mb-1.5">Шатахууны дүн</span><div class="tr-readout"><span data-out="fuel' + c.uid + '">' + fmt(r.fuel) + '</span><small data-out="lit' + c.uid + '">' + (Math.round(r.litres * 10) / 10) + ' л</small></div></div>' +
          '</div>' +
          '<p class="text-xs text-slate-400 dark:text-[#5a6172] m-0">Дүн = явах км × норм ÷ 100 × нэгж үнэ</p>' +
        '</div>' +
      '</div>';
    }).join(''));
  }
  function renderTable() {
    var head = ['Машин', 'Түрээсийн төрөл', 'Компани', 'Улсын дугаар', 'Жолоочийн нэр', 'Утас', 'Түлш', 'Шат. үнэ', 'Шат. норм', 'Картын №', 'Явах км', 'Хоног', 'Түрээс/өдөр', 'Түрээсийн дүн', 'Шатахууны дүн'];
    function tinp(c, key, opts) {
      opts = opts || {};
      return '<input type="' + (opts.type || 'text') + '"' + (opts.type === 'number' ? ' min="0"' + (opts.step ? ' step="' + opts.step + '"' : '') : '') + dis() + ' data-car="' + c.uid + '" data-k="' + key + '" value="' + esc(c[key]) + '">';
    }
    var rows = cars.map(function (c) {
      var r = calc(c);
      var multi = cars.filter(function (x) { return x.vehicle_id === c.vehicle_id; }).length > 1;
      return '<tr>' +
        '<td class="tr-tm-ro">' + esc(c.model) + (multi ? ' #' + c.unit_index : '') + '<br><span class="text-[11px] font-normal text-slate-400 dark:text-[#5a6172]">' + esc(c.meta) + '</span></td>' +
        '<td><select' + dis() + ' data-car="' + c.uid + '" data-k="is_rented"><option value="0"' + (c.is_rented ? '' : ' selected') + '>Байгууллагын</option><option value="1"' + (c.is_rented ? ' selected' : '') + '>Гадны түрээс</option></select></td>' +
        '<td>' + tinp(c, 'rental_company') + '</td>' +
        '<td>' + tinp(c, 'plate') + '</td>' +
        '<td>' + tinp(c, 'driver') + '</td>' +
        '<td>' + tinp(c, 'phone', { type: 'tel' }) + '</td>' +
        '<td><select' + dis() + ' data-car="' + c.uid + '" data-k="fuel_type">' + Object.keys(FUEL_TYPES).map(function (k) { return '<option value="' + k + '"' + (c.fuel_type === k ? ' selected' : '') + '>' + FUEL_TYPES[k] + '</option>'; }).join('') + '</select></td>' +
        '<td>' + tinp(c, 'price', { type: 'number' }) + '</td>' +
        '<td>' + tinp(c, 'norm', { type: 'number', step: '0.1' }) + '</td>' +
        '<td>' + tinp(c, 'card') + '</td>' +
        '<td>' + tinp(c, 'km', { type: 'number' }) + '</td>' +
        '<td>' + tinp(c, 'rental_days', { type: 'number' }) + '</td>' +
        '<td>' + tinp(c, 'rental_rate', { type: 'number' }) + '</td>' +
        '<td class="tr-tm-ro" data-out="rent' + c.uid + '">' + fmt(r.rent) + '</td>' +
        '<td class="tr-tm-ro" data-out="fuel' + c.uid + '">' + fmt(r.fuel) + '</td>' +
      '</tr>';
    }).join('');
    $table.html('<table class="tr-tm-table"><thead><tr>' + head.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead><tbody>' + rows + '</tbody></table>');
  }
  function out(key, text) { var el = $wrap.find('[data-out="' + key + '"]'); if (el.length) el.text(text); }
  function totals() {
    var rT = 0, fT = 0, kT = 0, lT = 0, filled = 0;
    cars.forEach(function (c) {
      var r = calc(c);
      rT += r.rent; fT += r.fuel; kT += num(c.km); lT += r.litres;
      out('total' + c.uid, fmt(r.rent + r.fuel)); out('rent' + c.uid, fmt(r.rent)); out('fuel' + c.uid, fmt(r.fuel)); out('lit' + c.uid, (Math.round(r.litres * 10) / 10) + ' л');
      var req = [c.plate, c.driver, c.phone, c.norm, c.price].concat(c.is_rented ? [c.rental_company, c.rental_rate] : []);
      if (req.every(function (x) { return String(x || '').trim(); })) filled++;
    });
    var all = rT + fT, rp = all ? Math.round(rT / all * 100) : 0;
    $('#tmFilled').text(filled + ' / ' + cars.length + ' машин бөглөгдсөн');
    $('#tmTotalKm').text(kT.toLocaleString() + ' км');
    $('#tmTotalLitres').text((Math.round(lT * 10) / 10) + ' л');
    $('#tmTotalRental').text(fmt(rT));
    $('#tmTotalFuel').text(fmt(fT));
    $('#tmTotalAll').text(fmt(all));
    $('#tmBarRent').css('width', (all ? rp : 0) + '%');
    $('#tmBarFuel').css('width', (all ? 100 - rp : 0) + '%');
  }

  $wrap.on('input', function (e) {
    var t = e.target, c = findCar(t.dataset.car);
    if (c && t.dataset.k) { c[t.dataset.k] = t.value; totals(); }
  });
  $cars.on('click', 'button', function (e) {
    if (!TM_EDITABLE) return;
    var b = this, c = findCar(b.dataset.car);
    if (!c) return;
    if (b.dataset.rented != null) c.is_rented = b.dataset.rented === '1';
    else if (b.dataset.fuel) c.fuel_type = b.dataset.fuel;
    else return;
    render();
  });
  $table.on('change', 'select[data-k]', function (e) {
    if (!TM_EDITABLE) return;
    var t = e.target, c = findCar(t.dataset.car);
    if (!c) return;
    if (t.dataset.k === 'is_rented') c.is_rented = t.value === '1';
    else c[t.dataset.k] = t.value;
    render();
  });
  $('#tmViewToggle').on('click', 'button', function () {
    viewMode = this.dataset.view;
    $('#tmViewToggle button').removeClass('on');
    $(this).addClass('on');
    render();
  });

  render();
})();
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
