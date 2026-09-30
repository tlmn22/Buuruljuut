<?php
$pageTitle  = 'Тээвэр — Батлах хүсэлтүүд';
$activePage = 'transport-approvals';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

$isDirector = transportHasRole($pdo, $myEmployeeId, 'director');
$isTransportManager = transportHasRole($pdo, $myEmployeeId, 'transport_manager');

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';

// ─── Шүүлтүүр ────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

// Надтай холбоотой бүх хүсэлт: шууд удирдлагаар (одоо ч, өмнө ч), эсвэл
// захирал/менежерээр аль хэдийн шийдвэрлэсэн, эсвэл яг одоо намайг хүлээж буй
$involvedParts = ["r.manager_id = ?", "r.director_id = ?", "r.transport_manager_id = ?"];
$involvedParams = [$myEmployeeId, $myEmployeeId, $myEmployeeId];
if ($isDirector) { $involvedParts[] = "r.status = 'pending_director'"; }
if ($isTransportManager) { $involvedParts[] = "r.status IN ('pending_transport_manager', 'director_rejected')"; }

$sql = "
    SELECT r.*, e.last_name, e.first_name, e.employee_code
    FROM transport_requests r
    JOIN employees e ON e.id = r.requester_id
    WHERE (" . implode(' OR ', $involvedParts) . ")
";
$params = $involvedParams;

if ($statusFilter !== '' && isset(TRANSPORT_STATUS_LABELS[$statusFilter])) {
    $sql .= " AND r.status = ?";
    $params[] = $statusFilter;
}
if ($dateFrom !== '') {
    $sql .= " AND r.start_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= " AND r.start_date <= ?";
    $params[] = $dateTo;
}
$sql .= " ORDER BY FIELD(r.status,'pending_manager','pending_transport_manager','director_rejected','pending_director','manager_rejected','completed'), r.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Тээвэр — Батлах хүсэлтүүд</span>
        </nav>
        <h1 class="text-3xl font-bold">Тээврийн батлах хүсэлтүүд</h1>
        <p class="text-sm text-slate-400 dark:text-[#5a6172] mt-1">Танд одоо хийх үйлдэл байгаа, эсвэл өмнө нь та оролцсон бүх хүсэлт</p>
    </div>
</div>

<form method="get" class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-4 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs font-medium text-slate-500 dark:text-[#8b93a1] mb-1">Төлөв</label>
        <select name="status" class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
            <option value="">Бүгд</option>
            <?php foreach (TRANSPORT_STATUS_LABELS as $key => $label): ?>
                <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-500 dark:text-[#8b93a1] mb-1">Огноо — Эхлэх</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-500 dark:text-[#8b93a1] mb-1">Огноо — Хүртэл</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm">
    </div>
    <button type="submit" class="bg-[#f1592a] text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors">Шүүх</button>
    <?php if ($statusFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
        <a href="<?= BASE_URL ?>/modules/transport/approvals.php" class="text-sm text-slate-500 dark:text-[#8b93a1] hover:underline px-2 py-2">Цэвэрлэх</a>
    <?php endif; ?>
    <span class="ml-auto text-sm text-slate-400 dark:text-[#5a6172] self-center">Нийт: <?= count($requests) ?></span>
</form>

<div class="font-semibold text-sm text-slate-600 dark:text-[#c9cdd6]">Ажилтны хүсэлтүүд</div>
<?php if (empty($requests)): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">inbox</span>
        Тохирох хүсэлт олдсонгүй.
    </div>
<?php else: ?>
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                <th class="text-left px-5 py-3">Хүсэгч</th>
                <th class="text-left px-4 py-3">Чиглэл</th>
                <th class="text-left px-4 py-3">Огноо</th>
                <th class="text-left px-4 py-3">Төлөв</th>
                <th class="text-right px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($requests as $r):
                $canAct = ($r['status'] === 'pending_manager' && (int)$r['manager_id'] === $myEmployeeId)
                    || ($r['status'] === 'pending_director' && $isDirector)
                    || (in_array($r['status'], ['pending_transport_manager', 'director_rejected'], true) && $isTransportManager);
                $rowHref = $r['status'] === 'merged'
                    ? BASE_URL . '/modules/transport/order.php?id=' . (int)$r['order_id']
                    : BASE_URL . '/modules/transport/form.php?id=' . $r['id'];
            ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e]">
                <td class="px-5 py-3">
                    <p class="font-medium"><?= htmlspecialchars($r['last_name'] . '. ' . $r['first_name']) ?></p>
                    <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($r['employee_code']) ?></p>
                </td>
                <td class="px-4 py-3"><?= htmlspecialchars($r['travel_direction']) ?></td>
                <td class="px-4 py-3 text-xs text-slate-500 dark:text-[#8b93a1]"><?= htmlspecialchars($r['start_date']) ?> — <?= htmlspecialchars($r['end_date']) ?></td>
                <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= TRANSPORT_STATUS_COLORS[$r['status']] ?>"><?= TRANSPORT_STATUS_LABELS[$r['status']] ?></span></td>
                <td class="px-5 py-3 text-right">
                    <a href="<?= $rowHref ?>" class="text-[#f1592a] font-medium text-xs hover:underline"><?= $r['status'] === 'merged' ? 'Захиалгыг харах' : ($canAct ? 'Харах / Шийдвэрлэх' : 'Харах') ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php
$orderParts = ["o.transport_manager_id = ?", "o.director_id = ?"];
$orderParams = [$myEmployeeId, $myEmployeeId];
if ($isDirector) { $orderParts[] = "o.status = 'pending_director'"; }
if ($isTransportManager) { $orderParts[] = "o.status IN ('draft', 'director_rejected')"; }

$osql = "
    SELECT o.*, tm.last_name AS tm_last, tm.first_name AS tm_first,
           (SELECT COUNT(*) FROM transport_requests r WHERE r.order_id=o.id) AS req_count,
           (SELECT COALESCE(SUM((SELECT COUNT(*) FROM transport_request_passengers pp WHERE pp.request_id=r.id)),0)
            FROM transport_requests r WHERE r.order_id=o.id) AS pax_total
    FROM transport_orders o
    LEFT JOIN employees tm ON tm.id = o.transport_manager_id
    WHERE (" . implode(' OR ', $orderParts) . ")
    ORDER BY FIELD(o.status,'draft','director_rejected','pending_director','completed'), o.id DESC
";
$ostmt = $pdo->prepare($osql);
$ostmt->execute($orderParams);
$orders = $ostmt->fetchAll();
?>

<div class="font-semibold text-sm text-slate-600 dark:text-[#c9cdd6] mt-2">Тээвэр захиалга</div>
<?php if (empty($orders)): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">local_shipping</span>
        Нэгтгэсэн захиалга алга.
    </div>
<?php else: ?>
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                <th class="text-left px-5 py-3">ID</th>
                <th class="text-left px-4 py-3">Менежер</th>
                <th class="text-center px-4 py-3">Хүсэлт / Зорчигч</th>
                <th class="text-left px-4 py-3">Төлөв</th>
                <th class="text-right px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($orders as $o):
                $canActOrder = ($o['status'] === 'pending_director' && $isDirector)
                    || (in_array($o['status'], ['draft', 'director_rejected'], true) && (int)$o['transport_manager_id'] === $myEmployeeId);
            ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e]">
                <td class="px-5 py-3 font-medium">#<?= (int)$o['id'] ?></td>
                <td class="px-4 py-3"><?= htmlspecialchars($o['tm_last'] . '. ' . $o['tm_first']) ?></td>
                <td class="px-4 py-3 text-center text-slate-500 dark:text-[#8b93a1]"><?= (int)$o['req_count'] ?> / <?= (int)$o['pax_total'] ?></td>
                <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= TRANSPORT_ORDER_STATUS_COLORS[$o['status']] ?>"><?= TRANSPORT_ORDER_STATUS_LABELS[$o['status']] ?></span></td>
                <td class="px-5 py-3 text-right">
                    <a href="<?= BASE_URL ?>/modules/transport/order.php?id=<?= (int)$o['id'] ?>" class="text-[#f1592a] font-medium text-xs hover:underline"><?= $canActOrder ? 'Харах / Шийдвэрлэх' : 'Харах' ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
