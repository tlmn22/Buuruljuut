<?php
$pageTitle  = 'Нэгтгэсэн тээврийн захиалга';
$activePage = 'transport-merge';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

$id = intval($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('Захиалга олдсонгүй.'); }

$stmt = $pdo->prepare("
    SELECT o.*, tm.last_name AS tm_last, tm.first_name AS tm_first,
           dir.last_name AS director_last, dir.first_name AS director_first
    FROM transport_orders o
    LEFT JOIN employees tm ON tm.id = o.transport_manager_id
    LEFT JOIN employees dir ON dir.id = o.director_id
    WHERE o.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { http_response_code(404); die('Захиалга олдсонгүй.'); }

$isCreatorTM = (int)$order['transport_manager_id'] === $myEmployeeId;
$isDirector = transportHasRole($pdo, $myEmployeeId, 'director');
$isParty = $isCreatorTM || $isDirector || isHR() || isSuperAdmin();
if (!$isParty) { header('Location: ' . BASE_URL . '/denied.php'); exit; }

$editable = $isCreatorTM && in_array($order['status'], ['draft', 'director_rejected'], true);
$canActAsDirector = $isDirector && $order['status'] === 'pending_director';

$rq = $pdo->prepare("
    SELECT r.id, r.travel_direction, r.start_date, r.end_date, e.last_name, e.first_name,
           (SELECT COUNT(*) FROM transport_request_passengers p WHERE p.request_id=r.id) AS pax_count
    FROM transport_requests r
    JOIN employees e ON e.id = r.requester_id
    WHERE r.order_id = ?
    ORDER BY r.id
");
$rq->execute([$id]);
$linkedRequests = $rq->fetchAll();
$totalPax = array_sum(array_column($linkedRequests, 'pax_count'));

$availableRequests = [];
if ($editable) {
    $aq = $pdo->prepare("
        SELECT r.id, r.travel_direction, r.start_date, r.end_date, e.last_name, e.first_name,
               (SELECT COUNT(*) FROM transport_request_passengers p WHERE p.request_id=r.id) AS pax_count
        FROM transport_requests r
        JOIN employees e ON e.id = r.requester_id
        WHERE r.status = 'pending_transport_manager' AND r.order_id IS NULL
        ORDER BY r.start_date, r.id
    ");
    $aq->execute();
    $availableRequests = $aq->fetchAll();
}

$v = $pdo->prepare("SELECT * FROM transport_order_vehicles WHERE order_id=? ORDER BY sort_order, id");
$v->execute([$id]);
$vehicles = $v->fetchAll();

$unitsByVehicle = [];
if ($vehicles) {
    $vehIds = array_column($vehicles, 'id');
    $ph = implode(',', array_fill(0, count($vehIds), '?'));
    $u = $pdo->prepare("SELECT * FROM transport_order_vehicle_units WHERE order_vehicle_id IN ($ph) ORDER BY order_vehicle_id, unit_index");
    $u->execute($vehIds);
    foreach ($u->fetchAll() as $row) {
        $unitsByVehicle[$row['order_vehicle_id']][$row['unit_index']] = $row;
    }
}

$vehiclesForJs = [];
foreach (TRANSPORT_VEHICLE_TYPES as $typeId => $t) {
    $existing = null;
    foreach ($vehicles as $veh) { if ($veh['vehicle_type_id'] === $typeId) { $existing = $veh; break; } }
    $vehiclesForJs[] = [
        'id' => $existing ? (int)$existing['id'] : 0,
        'type' => $typeId,
        'qty' => $existing ? (int)$existing['qty'] : 0,
    ];
}

$fulfillmentUnitsForJs = [];
foreach ($vehicles as $veh) {
    $vt = TRANSPORT_VEHICLE_TYPES[$veh['vehicle_type_id']] ?? ['full' => $veh['vehicle_type_id'], 'meta' => ''];
    for ($i = 1; $i <= (int)$veh['qty']; $i++) {
        $u = $unitsByVehicle[$veh['id']][$i] ?? null;
        $fulfillmentUnitsForJs[] = [
            'uid' => $veh['id'] . '-' . $i,
            'vehicle_id' => (int)$veh['id'],
            'unit_index' => $i,
            'model' => $vt['full'],
            'meta' => $vt['meta'],
            'is_rented' => $u ? (bool)$u['is_rented'] : false,
            'rental_company' => $u['rental_company'] ?? '',
            'rental_days' => $u['rental_days'] ?? '',
            'rental_rate' => $u['rental_rate'] ?? '',
            'plate' => $u['plate_number'] ?? '',
            'driver' => $u['driver_name'] ?? '',
            'phone' => $u['driver_phone'] ?? '',
            'fuel_type' => $u['fuel_type'] ?? 'diesel',
            'km' => $u['distance_km'] ?? '',
            'norm' => $u['fuel_norm'] ?? '',
            'price' => $u['fuel_price'] ?? '',
            'card' => $u['fuel_card_number'] ?? '',
        ];
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
?>
<style>
.tr-page{max-width:1200px;margin:0 auto}
.tr-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;display:flex;flex-direction:column;gap:20px}
html.dark .tr-card{background:#1c212b;border-color:#2a2f3b}
.tr-sec-icon{width:36px;height:36px;border-radius:10px;background:#fcede6;color:#c9481f;display:flex;align-items:center;justify-content:center;flex-shrink:0}
html.dark .tr-sec-icon{background:#2a1810;color:#ff8a5c}
.tr-inp{width:100%;height:44px;padding:0 14px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;font:inherit;font-size:15px;color:#1c2430;outline:none;transition:border-color .15s,box-shadow .15s}
html.dark .tr-inp{background:#272c38;border-color:#2a2f3b;color:#f2f3f6}
.tr-inp.sm{height:40px;font-size:14px}
.tr-lbl-sub{font-size:12px;color:#64748b;font-weight:500}
html.dark .tr-lbl-sub{color:#8b93a1}
.tr-readout{height:44px;border-radius:10px;background:#f7f6f3;display:flex;align-items:center;justify-content:space-between;padding:0 14px}
html.dark .tr-readout{background:#20242e}
.tr-readout b{font-size:18px}
.tr-readout span{font-size:12px;color:#64748b}
html.dark .tr-readout span{color:#8b93a1}
.tr-search{position:relative}
.tr-search .tr-inp{padding-left:14px}
.tr-seg-wrap{display:flex;padding:3px;border-radius:10px;background:#f1f5f9;flex-shrink:0}
html.dark .tr-seg-wrap{background:#272c38}
.tr-seg{border:0;background:transparent;font:inherit;font-size:13px;font-weight:600;color:#64748b;padding:0 12px;height:32px;border-radius:7px;cursor:pointer}
html.dark .tr-seg{color:#8b93a1}
.tr-seg.on{background:#fff;color:#1c2430;box-shadow:0 1px 2px rgba(0,0,0,.12)}
html.dark .tr-seg.on{background:#1c212b;color:#f2f3f6}
.tr-btn-primary{height:46px;border-radius:11px;font:inherit;font-size:15px;font-weight:600;cursor:pointer;border:0;background:#f1592a;color:#fff;width:100%}
.tr-btn-primary:hover{background:#c33e12}
.tr-btn-primary:disabled{background:#f3c8ab;cursor:not-allowed}
html.dark .tr-btn-primary:disabled{background:#5a3a28;color:#8b93a1}
.tr-btn-ghost{height:46px;border-radius:11px;font:inherit;font-size:15px;font-weight:600;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#1c2430;width:100%}
html.dark .tr-btn-ghost{border-color:#2a2f3b;background:#272c38;color:#f2f3f6}
.tr-item{border:1px solid #e2e8f0;border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:14px;background:#fdfcfa}
html.dark .tr-item{border-color:#2a2f3b;background:#20242e}
.tr-num{width:28px;height:28px;flex-shrink:0;border-radius:50%;background:#f1f5f9;font-size:13px;font-weight:700;color:#3a3c42;display:flex;align-items:center;justify-content:center}
html.dark .tr-num{background:#272c38;color:#c9cdd6}
.tr-stepper{display:flex;align-items:center;height:44px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;flex-shrink:0}
html.dark .tr-stepper{border-color:#2a2f3b;background:#272c38}
.tr-stepper b{min-width:32px;text-align:center;font-size:16px}
.tr-icon-btn{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;background:transparent;color:#64748b;cursor:pointer;flex-shrink:0}
html.dark .tr-icon-btn{color:#8b93a1}
.tr-icon-btn:disabled{opacity:.35;cursor:not-allowed;background:transparent}
.tr-srow{display:flex;justify-content:space-between;gap:12px;font-size:14px}
.tr-srow span:first-child{color:#64748b}
html.dark .tr-srow span:first-child{color:#8b93a1}
.tr-srow span:last-child{font-weight:600;text-align:right}
.tr-hr{height:1px;background:#e2e8f0}
html.dark .tr-hr{background:#2a2f3b}
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
                <a href="<?= BASE_URL ?>/modules/transport/merge.php" class="hover:text-[#f1592a]">Тээвэр захиалга</a><span>/</span>
                <span class="text-[#1c2430] dark:text-[#f2f3f6] font-medium">Захиалга #<?= (int)$order['id'] ?></span>
            </nav>
            <h1 class="text-3xl font-bold m-0">Нэгтгэсэн захиалга #<?= (int)$order['id'] ?></h1>
            <p class="m-0 text-[15px] text-slate-500 dark:text-[#8b93a1]">Тээвэр хариуцсан менежер: <?= htmlspecialchars($order['tm_last'] . '. ' . $order['tm_first']) ?></p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-sm font-medium <?= TRANSPORT_ORDER_STATUS_COLORS[$order['status']] ?>"><?= TRANSPORT_ORDER_STATUS_LABELS[$order['status']] ?></span>
    </div>

    <?php if ($order['status'] === 'director_rejected'): ?>
        <div class="px-4 py-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/40 rounded-lg flex items-start gap-2">
            <span class="material-icons-outlined text-red-500" style="font-size:18px">error_outline</span>
            <p class="text-sm text-red-600 dark:text-red-400 m-0">Тээвэр хариуцсан захирал буцаасан. Шалтгаан: <?= htmlspecialchars($order['director_comment']) ?></p>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 items-start" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
    <section class="tr-card">
        <div class="flex items-center justify-between gap-3.5">
            <div class="flex items-center gap-3.5">
                <span class="tr-sec-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.8c2 .7 3.2 2.5 3.5 5.2"/></svg></span>
                <div><h2 class="text-lg font-bold m-0">Хамрагдсан хүсэлтүүд</h2><p class="m-0 mt-0.5 text-[13px] text-slate-500 dark:text-[#8b93a1]">Энэ захиалгад нэгтгэгдсэн ажилтны хүсэлтүүд</p></div>
            </div>
            <span class="tr-badge"><i></i><?= count($linkedRequests) ?> хүсэлт · <?= (int)$totalPax ?> зорчигч</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                    <th class="text-left font-semibold py-2 pr-3">Хүсэгч</th>
                    <th class="text-left font-semibold py-2 pr-3">Чиглэл</th>
                    <th class="text-left font-semibold py-2 pr-3">Огноо</th>
                    <th class="text-center font-semibold py-2 pr-3">Зорчигч</th>
                    <?php if ($editable): ?><th class="py-2"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]" id="linkedReqList">
            <?php foreach ($linkedRequests as $r): ?>
                <tr data-req-row="<?= (int)$r['id'] ?>">
                    <td class="py-2.5 pr-3"><a href="<?= BASE_URL ?>/modules/transport/form.php?id=<?= (int)$r['id'] ?>&view=1" class="font-semibold hover:text-[#f1592a] transition-colors"><?= htmlspecialchars($r['last_name'] . '. ' . $r['first_name']) ?></a></td>
                    <td class="py-2.5 pr-3 text-slate-500 dark:text-[#8b93a1]"><?= htmlspecialchars($r['travel_direction']) ?></td>
                    <td class="py-2.5 pr-3 text-xs text-slate-500 dark:text-[#8b93a1] whitespace-nowrap"><?= htmlspecialchars($r['start_date']) ?> — <?= htmlspecialchars($r['end_date']) ?></td>
                    <td class="py-2.5 pr-3 text-center text-slate-500 dark:text-[#8b93a1]"><?= (int)$r['pax_count'] ?></td>
                    <?php if ($editable): ?>
                    <td class="py-2.5 text-right">
                        <button type="button" class="tr-icon-btn" style="width:32px;height:32px" data-remove-req="<?= (int)$r['id'] ?>" title="Хасах"<?= count($linkedRequests) <= 1 ? ' disabled' : '' ?>><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V4.5h6V7"/><path d="M6.5 7l1 13h9l1-13"/></svg></button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($editable): ?>
        <div class="tr-hr"></div>
        <button type="button" class="tr-add-row" id="toggleAddReq"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>Хүсэлт нэмэх</button>
        <div id="addReqPanel" class="tr-hidden flex flex-col gap-3">
            <?php if (empty($availableRequests)): ?>
                <p class="text-sm text-slate-400 dark:text-[#5a6172] m-0">Одоогоор нэмэх боломжтой хүсэлт алга.</p>
            <?php else: ?>
                <div class="flex flex-col gap-2">
                    <?php foreach ($availableRequests as $r): ?>
                    <label class="tr-item flex-row items-center gap-3 cursor-pointer">
                        <input type="checkbox" class="addReqCheck" value="<?= (int)$r['id'] ?>">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold m-0"><?= htmlspecialchars($r['last_name'] . '. ' . $r['first_name']) ?></p>
                            <p class="text-xs text-slate-500 dark:text-[#8b93a1] m-0"><?= htmlspecialchars($r['travel_direction']) ?> · <?= htmlspecialchars($r['start_date']) ?> — <?= htmlspecialchars($r['end_date']) ?> · <?= (int)$r['pax_count'] ?> зорчигч</p>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="tr-btn-primary" id="addReqBtn" disabled>Сонгосныг нэмэх</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="tr-card">
        <div class="flex items-center justify-between gap-3.5">
            <div class="flex items-center gap-3.5">
                <span class="tr-sec-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16V8.5A1.5 1.5 0 0 1 4.5 7H14v9"/><path d="M14 10h3.6l3.4 3.6V16"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/><path d="M9 17h6"/></svg></span>
                <div><h2 class="text-lg font-bold m-0">Автомашин</h2><p class="m-0 mt-0.5 text-[13px] text-slate-500 dark:text-[#8b93a1]">Энэ бүлгийг зөөвөрлөхөд шаардагдах машины тоог сонгоно уу</p></div>
            </div>
        </div>
        <div class="flex flex-col gap-3" id="cars"></div>
    </section>
    </div>

    <section class="tr-card !p-0 overflow-hidden">
        <div class="flex items-center justify-between gap-4 flex-wrap px-7 py-6 border-b border-slate-100 dark:border-[#2a2f3b]">
            <div class="flex items-center gap-3.5">
                <span class="tr-sec-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-4"/></svg></span>
                <div><h2 class="text-lg font-bold m-0">Тээврийн мэргэжилтэн бөглөх</h2><p class="m-0 mt-0.5 text-[13px] text-slate-500 dark:text-[#8b93a1]">Машин бүрийн түрээс, жолооч, шатахууны мэдээллийг бөглөнө. Дүн автоматаар бодогдоно.</p></div>
            </div>
            <div class="flex items-center gap-2.5">
                <div class="tr-view-toggle" id="tmViewToggle">
                    <button type="button" class="on" data-view="cards"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>Карт</button>
                    <button type="button" data-view="table"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M3 10h18M9 4v16"/></svg>Хүснэгт</button>
                </div>
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
                <?php if ($editable): ?>
                <div class="flex flex-col gap-2 mt-auto">
                    <textarea id="tmNote" rows="2" placeholder="Нэмэлт тэмдэглэл (сонголтоор)..." class="tr-inp" style="height:auto;padding:10px 14px;resize:vertical"><?= htmlspecialchars($order['note'] ?? '') ?></textarea>
                    <button type="button" onclick="doComplete()" class="tr-btn-primary">Хадгалаад илгээх</button>
                    <?php if ($order['status'] === 'draft'): ?>
                    <button type="button" onclick="doCancel()" class="tr-btn-ghost">Захиалгыг цуцлах</button>
                    <?php endif; ?>
                </div>
                <?php elseif ($canActAsDirector): ?>
                <div class="flex flex-col gap-2 mt-auto">
                    <textarea id="reviewComment" rows="2" placeholder="Буцаах бол шалтгаанаа бичнэ үү (заавал биш батлахад)..." class="tr-inp" style="height:auto;padding:10px 14px;resize:vertical"></textarea>
                    <button type="button" onclick="doApprove()" class="tr-btn-primary">Батлах</button>
                    <button type="button" onclick="doReject()" class="tr-btn-ghost">Буцаах</button>
                </div>
                <?php elseif ($order['note']): ?>
                    <div class="mt-auto"><span class="tr-lbl-sub block mb-1">Нэмэлт тэмдэглэл</span><p class="text-sm text-slate-600 dark:text-[#c9cdd6] m-0"><?= nl2br(htmlspecialchars($order['note'])) ?></p></div>
                <?php endif; ?>
            </aside>
        </div>
    </section>
</div>

<script>
(function () {
  var EDITABLE = <?= $editable ? 'true' : 'false' ?>;
  var TYPES = <?= json_encode(array_map(function ($typeId, $t) { return array_merge(['id' => $typeId], $t); }, array_keys(TRANSPORT_VEHICLE_TYPES), TRANSPORT_VEHICLE_TYPES)) ?>;
  var SUV_ICON = '<svg width="40" height="24" viewBox="0 0 40 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path d="M3 17v-5l4-6h17l6 6h6a1 1 0 0 1 1 1v4z"/><path d="M10 6v6M21 6l1 6M3 12h34"/><circle cx="10" cy="18" r="3" fill="#fff"/><circle cx="30" cy="18" r="3" fill="#fff"/></svg>';
  var cars = <?= json_encode($vehiclesForJs) ?>;

  function $(id) { return document.getElementById(id); }
  function dis() { return EDITABLE ? '' : ' disabled'; }
  function type(id) { for (var i = 0; i < TYPES.length; i++) if (TYPES[i].id === id) return TYPES[i]; return null; }
  function findCar(typeId) { return cars.filter(function (c) { return c.type === typeId; })[0]; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function renderCars() {
    $('cars').innerHTML = cars.map(function (c) {
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
  $('cars').addEventListener('click', function (e) {
    if (!EDITABLE) return;
    var b = e.target.closest('button'); if (!b) return;
    var d = b.dataset, c;
    if (d.inc) { findCar(d.inc).qty++; }
    else if (d.dec) { c = findCar(d.dec); if (c.qty > 0) c.qty--; }
    else return;
    renderCars();
    window.rebuildFulfillmentFromCars();
  });
  renderCars();

  window.getOrderCars = function () { return cars; };
})();
</script>

<script>
// ─── Тээврийн мэргэжилтэн бөглөх хэсэг (машин бүрийн түрээс/жолооч/шатахуун) ──
(function () {
  var $cars = $('#tmCars');
  var $table = $('#tmTable');
  var $wrap = $cars.add($table);
  var viewMode = 'cards';
  var TM_EDITABLE = <?= $editable ? 'true' : 'false' ?>;
  var FUEL_TYPES = <?= json_encode(TRANSPORT_FUEL_TYPES) ?>;
  var TYPES = <?= json_encode(array_map(function ($typeId, $t) { return array_merge(['id' => $typeId], $t); }, array_keys(TRANSPORT_VEHICLE_TYPES), TRANSPORT_VEHICLE_TYPES)) ?>;
  var cars = <?= json_encode($fulfillmentUnitsForJs) ?>;

  function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function fmt(n) { return Math.round(n).toLocaleString() + ' ₮'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function dis() { return TM_EDITABLE ? '' : ' disabled'; }
  function findCar(id) { return cars.filter(function (c) { return c.uid === id; })[0]; }
  function typeInfo(id) { for (var i = 0; i < TYPES.length; i++) if (TYPES[i].id === id) return TYPES[i]; return null; }
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

  // Автомашины тоо (Тоо stepper) өөрчлөгдөхөд физик машины картуудыг дахин зохицуулна:
  // тухайн төрлийн (lx/lc) картуудын тоог qty-той тэнцүүлж, илүүг хасаад дутууг нэмнэ (утгыг хадгална)
  window.rebuildFulfillmentFromCars = function () {
    var orderCars = window.getOrderCars();
    var next = [];
    orderCars.forEach(function (oc) {
      if (!oc.qty) return;
      var t = typeInfo(oc.type);
      var existing = cars.filter(function (c) { return c.vehicle_id === oc.type; });
      for (var i = 1; i <= oc.qty; i++) {
        var prev = existing[i - 1];
        if (prev) { prev.unit_index = i; prev.uid = oc.type + '-' + i; next.push(prev); }
        else {
          next.push({
            uid: oc.type + '-' + i, vehicle_id: oc.type, unit_index: i,
            model: t.full, meta: t.meta, is_rented: false,
            rental_company: '', rental_days: '', rental_rate: '',
            plate: '', driver: '', phone: '', fuel_type: 'diesel',
            km: '', norm: '', price: '', card: '',
          });
        }
      }
    });
    cars = next;
    render();
  };

  window.doComplete = function () {
    if (!confirm('Хадгалаад Тээвэр хариуцсан захиралд илгээх үү?')) return;
    var orderCars = window.getOrderCars().filter(function (c) { return c.qty > 0; });
    if (!orderCars.length) { showToast('Дор хаяж нэг машин сонгоно уу.', 'error'); return; }
    var vehicles = orderCars.map(function (c) { return { vehicle_type_id: c.type, qty: c.qty }; });
    var units = cars.map(function (c) {
      return {
        vehicle_id: c.vehicle_id, unit_index: c.unit_index, is_rented: c.is_rented ? 1 : 0,
        rental_company: c.rental_company, rental_days: c.rental_days, rental_rate: c.rental_rate,
        plate_number: c.plate, driver_name: c.driver, driver_phone: c.phone,
        fuel_type: c.fuel_type, distance_km: c.km, fuel_norm: c.norm, fuel_price: c.price, fuel_card_number: c.card,
      };
    });
    $.post('<?= BASE_URL ?>/modules/transport/ajax.php', {
      action: 'order_save_vehicles', id: <?= (int)$id ?>,
      note: $('#tmNote').val().trim(), vehicles: JSON.stringify(vehicles), units: JSON.stringify(units),
    }, function (r) {
      showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
      if (r.success) setTimeout(function () { location.reload(); }, 700);
    }, 'json');
  };

  render();
})();

function doCancel() {
  if (!confirm('Энэ ноорог захиалгыг цуцлах уу? Хамрагдсан хүсэлтүүд буцаж, менежерт дахин харагдана.')) return;
  $.post('<?= BASE_URL ?>/modules/transport/ajax.php', { action: 'order_cancel', id: <?= (int)$id ?> }, function (r) {
    showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
    if (r.success) setTimeout(function () { location.href = '<?= BASE_URL ?>/modules/transport/merge.php'; }, 600);
  }, 'json');
}
function doApprove() {
  $.post('<?= BASE_URL ?>/modules/transport/ajax.php', { action: 'order_director_approve', id: <?= (int)$id ?> }, function (r) {
    showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
    if (r.success) setTimeout(function () { location.reload(); }, 700);
  }, 'json');
}
function doReject() {
  var comment = $('#reviewComment').val().trim();
  if (!comment) { showToast('Буцаах шалтгаанаа бичнэ үү.', 'error'); return; }
  $.post('<?= BASE_URL ?>/modules/transport/ajax.php', { action: 'order_director_reject', id: <?= (int)$id ?>, comment: comment }, function (r) {
    showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
    if (r.success) setTimeout(function () { location.reload(); }, 700);
  }, 'json');
}
</script>

<script>
(function () {
  var $toggle = $('#toggleAddReq'), $panel = $('#addReqPanel');
  if (!$toggle.length) return;
  $toggle.on('click', function () { $panel.toggleClass('tr-hidden'); });

  var $checks = $('.addReqCheck'), $addBtn = $('#addReqBtn');
  $checks.on('change', function () {
    var n = $('.addReqCheck:checked').length;
    $addBtn.prop('disabled', n === 0).text(n ? 'Сонгосон ' + n + '-г нэмэх' : 'Сонгосныг нэмэх');
  });
  $addBtn.on('click', function () {
    var ids = $('.addReqCheck:checked').map(function () { return +this.value; }).get();
    if (!ids.length) return;
    $.post('<?= BASE_URL ?>/modules/transport/ajax.php', { action: 'order_add_requests', id: <?= (int)$id ?>, request_ids: JSON.stringify(ids) }, function (r) {
      showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
      if (r.success) setTimeout(function () { location.reload(); }, 500);
    }, 'json');
  });

  $('#linkedReqList').on('click', 'button[data-remove-req]', function () {
    if (this.disabled) return;
    var reqId = +this.dataset.removeReq;
    if (!confirm('Энэ хүсэлтийг захиалгаас хасах уу? Хүсэгчид дахин "Тээвэр хариуцсан менежерт хүлээгдэж байна" төлөвөөр харагдана.')) return;
    $.post('<?= BASE_URL ?>/modules/transport/ajax.php', { action: 'order_remove_request', id: <?= (int)$id ?>, request_id: reqId }, function (r) {
      showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
      if (r.success) setTimeout(function () { location.reload(); }, 500);
    }, 'json');
  });
})();
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
