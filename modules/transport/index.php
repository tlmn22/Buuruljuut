<?php
$pageTitle  = 'Тээврийн захиалга';
$activePage = 'transport';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

$stmt = $pdo->prepare("
    SELECT r.*, mgr.last_name AS manager_last, mgr.first_name AS manager_first,
           o.status AS order_status,
           (SELECT COUNT(*) FROM transport_request_passengers p WHERE p.request_id=r.id) AS pax_count,
           (SELECT COALESCE(SUM(qty),0) FROM transport_request_vehicles v WHERE v.request_id=r.id) AS vehicle_count
    FROM transport_requests r
    LEFT JOIN employees mgr ON mgr.id = r.manager_id
    LEFT JOIN transport_orders o ON o.id = r.order_id
    WHERE r.requester_id = ?
    ORDER BY r.id DESC
");
$stmt->execute([$myEmployeeId]);
$requests = $stmt->fetchAll();

// Захирал/менежер бол тухайн request дээр биш, global role тул нэг л удаа татна
function transportRoleHolderNames(PDO $pdo, string $role): string {
    $stmt = $pdo->prepare("SELECT e.last_name, e.first_name FROM transport_roles tr JOIN employees e ON e.id = tr.employee_id WHERE tr.role = ? ORDER BY e.last_name");
    $stmt->execute([$role]);
    $names = array_map(fn($r) => $r['last_name'] . '. ' . $r['first_name'], $stmt->fetchAll());
    return $names ? implode(', ', $names) : 'Тохируулаагүй';
}
$directorNames = transportRoleHolderNames($pdo, 'director');
$transportManagerNames = transportRoleHolderNames($pdo, 'transport_manager');

/** Тухайн захиалга яг одоо хэн дээр хүлээгдэж байгааг тодорхойлно */
function transportCurrentHolder(array $r, string $directorNames, string $tmNames): string {
    switch ($r['status']) {
        case 'pending_manager':
            return $r['manager_last'] ? $r['manager_last'] . '. ' . $r['manager_first'] : '—';
        case 'pending_director':
            return $directorNames;
        case 'pending_transport_manager':
            return $tmNames;
        case 'manager_rejected':
            return 'Танд буцаасан';
        case 'director_rejected':
            return $tmNames . ' (буцаасан)';
        case 'merged':
            return $r['order_status'] ? TRANSPORT_ORDER_STATUS_LABELS[$r['order_status']] : 'Нэгтгэгдсэн';
        case 'completed':
            return '—';
        default:
            return '—';
    }
}
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Тээврийн захиалга</span>
        </nav>
        <h1 class="text-3xl font-bold">Миний тээврийн захиалгууд</h1>
    </div>
    <a href="<?= BASE_URL ?>/modules/transport/form.php" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors flex items-center gap-2">
        <span class="material-icons-outlined text-sm">add_circle</span> Шинэ захиалга
    </a>
</div>

<?php if (empty($requests)): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">local_shipping</span>
        Танд одоогоор тээврийн захиалга байхгүй байна.
    </div>
<?php else: ?>
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                <th class="text-left px-5 py-3">Чиглэл</th>
                <th class="text-left px-4 py-3">Зорилго</th>
                <th class="text-left px-4 py-3">Огноо</th>
                <th class="text-center px-4 py-3">Хүн / Машин</th>
                <th class="text-left px-4 py-3">Төлөв</th>
                <th class="text-left px-4 py-3">Одоо хэн дээр</th>
                <th class="text-right px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($requests as $r): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e]">
                <td class="px-5 py-3 font-medium"><?= htmlspecialchars($r['travel_direction']) ?></td>
                <td class="px-4 py-3 text-slate-500 dark:text-[#8b93a1]"><?= $r['travel_purpose'] !== '' ? htmlspecialchars($r['travel_purpose']) : '—' ?></td>
                <td class="px-4 py-3 text-slate-500 dark:text-[#8b93a1] text-xs"><?= htmlspecialchars($r['start_date']) ?> — <?= htmlspecialchars($r['end_date']) ?></td>
                <td class="px-4 py-3 text-center text-slate-500 dark:text-[#8b93a1]"><?= (int)$r['pax_count'] ?> / <?= (int)$r['vehicle_count'] ?></td>
                <td class="px-4 py-3">
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= TRANSPORT_STATUS_COLORS[$r['status']] ?>"><?= TRANSPORT_STATUS_LABELS[$r['status']] ?></span>
                    <?php if (in_array($r['status'], ['manager_rejected', 'director_rejected'], true)): ?>
                        <p class="text-xs text-red-500 mt-1 max-w-xs"><?= htmlspecialchars($r['status'] === 'manager_rejected' ? $r['manager_comment'] : $r['director_comment']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-slate-600 dark:text-[#c9cdd6]">
                    <?php $holder = transportCurrentHolder($r, $directorNames, $transportManagerNames); ?>
                    <?php if (in_array($r['status'], ['manager_rejected', 'director_rejected'], true)): ?>
                        <span class="font-medium text-red-500"><?= htmlspecialchars($holder) ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($holder) ?>
                    <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-right">
                    <?php if ($r['status'] === 'manager_rejected'): ?>
                        <a href="<?= BASE_URL ?>/modules/transport/form.php?id=<?= $r['id'] ?>" class="text-[#f1592a] font-medium text-xs hover:underline">Засах</a>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>/modules/transport/form.php?id=<?= $r['id'] ?>&view=1" class="text-[#f1592a] font-medium text-xs hover:underline">Харах</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
