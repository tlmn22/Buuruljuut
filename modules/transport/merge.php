<?php
$pageTitle  = 'Тээвэр захиалга';
$activePage = 'transport-merge';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

if (!transportHasRole($pdo, $myEmployeeId, 'transport_manager') && !isSuperAdmin()) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';

$stmt = $pdo->prepare("
    SELECT r.*, req.last_name AS requester_last, req.first_name AS requester_first,
           (SELECT COUNT(*) FROM transport_request_passengers p WHERE p.request_id=r.id) AS pax_count,
           (SELECT GROUP_CONCAT(CONCAT(v.vehicle_type_id, ' x', v.qty) SEPARATOR ', ') FROM transport_request_vehicles v WHERE v.request_id=r.id) AS vehicle_summary
    FROM transport_requests r
    JOIN employees req ON req.id = r.requester_id
    WHERE r.status = 'pending_transport_manager'
    ORDER BY r.start_date, r.id
");
$stmt->execute();
$pending = $stmt->fetchAll();

$draftStmt = $pdo->prepare("
    SELECT o.*,
           (SELECT COUNT(*) FROM transport_requests r WHERE r.order_id=o.id) AS req_count,
           (SELECT COALESCE(SUM((SELECT COUNT(*) FROM transport_request_passengers pp WHERE pp.request_id=r.id)),0)
            FROM transport_requests r WHERE r.order_id=o.id) AS pax_total
    FROM transport_orders o
    WHERE o.transport_manager_id = ? AND o.status IN ('draft', 'director_rejected')
    ORDER BY o.updated_at DESC
");
$draftStmt->execute([$myEmployeeId]);
$myDrafts = $draftStmt->fetchAll();

function vehicleSummaryLabel(?string $raw): string {
    if (!$raw) return '—';
    $parts = array_map(function ($p) {
        [$typeId, $qty] = array_pad(explode(' x', $p, 2), 2, '1');
        $t = TRANSPORT_VEHICLE_TYPES[$typeId] ?? null;
        return $t ? $t['name'] . ' ×' . $qty : $typeId;
    }, explode(', ', $raw));
    return implode(', ', $parts);
}
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Тээвэр захиалга</span>
        </nav>
        <h1 class="text-3xl font-bold">Тээвэр захиалга</h1>
        <p class="text-sm text-slate-400 dark:text-[#5a6172] mt-1">Шууд удирдлагаар батлагдсан хүсэлтүүдээс сонгож, нэг бодит тээврийн захиалга болгон нэгтгэнэ</p>
    </div>
</div>

<?php if ($myDrafts): ?>
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 dark:border-[#2a2f3b] font-semibold text-sm">Дуусгаагүй захиалгууд</div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                <th class="text-left px-5 py-3">ID</th>
                <th class="text-left px-4 py-3">Хамрагдсан хүсэлт</th>
                <th class="text-center px-4 py-3">Зорчигч</th>
                <th class="text-left px-4 py-3">Төлөв</th>
                <th class="text-right px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($myDrafts as $o): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e]">
                <td class="px-5 py-3 font-medium">#<?= (int)$o['id'] ?></td>
                <td class="px-4 py-3 text-slate-500 dark:text-[#8b93a1]"><?= (int)$o['req_count'] ?> хүсэлт</td>
                <td class="px-4 py-3 text-center text-slate-500 dark:text-[#8b93a1]"><?= (int)$o['pax_total'] ?></td>
                <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= TRANSPORT_ORDER_STATUS_COLORS[$o['status']] ?>"><?= TRANSPORT_ORDER_STATUS_LABELS[$o['status']] ?></span></td>
                <td class="px-5 py-3 text-right">
                    <a href="<?= BASE_URL ?>/modules/transport/order.php?id=<?= (int)$o['id'] ?>" class="text-[#f1592a] font-medium text-xs hover:underline">Үргэлжлүүлэх</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<form id="mergeForm">
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between gap-3 flex-wrap">
        <span class="font-semibold text-sm">Менежерт хүлээгдэж буй хүсэлтүүд</span>
        <button type="button" id="mergeBtn" class="bg-[#f1592a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors disabled:opacity-40 disabled:cursor-not-allowed" disabled>Сонгосныг нэгтгэх</button>
    </div>
    <?php if (empty($pending)): ?>
        <div class="p-16 text-center text-slate-400 dark:text-[#5a6172]">
            <span class="material-icons-outlined block mb-3" style="font-size:48px">inbox</span>
            Одоогоор хүлээгдэж буй хүсэлт байхгүй байна.
        </div>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                <th class="text-left px-5 py-3"><input type="checkbox" id="checkAll"></th>
                <th class="text-left px-4 py-3">Хүсэгч</th>
                <th class="text-left px-4 py-3">Чиглэл</th>
                <th class="text-left px-4 py-3">Огноо</th>
                <th class="text-center px-4 py-3">Зорчигч</th>
                <th class="text-left px-4 py-3">Хүссэн машин</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($pending as $r): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e]">
                <td class="px-5 py-3"><input type="checkbox" class="reqCheck" value="<?= (int)$r['id'] ?>"></td>
                <td class="px-4 py-3">
                    <p class="font-medium"><?= htmlspecialchars($r['requester_last'] . '. ' . $r['requester_first']) ?></p>
                </td>
                <td class="px-4 py-3"><?= htmlspecialchars($r['travel_direction']) ?></td>
                <td class="px-4 py-3 text-xs text-slate-500 dark:text-[#8b93a1]"><?= htmlspecialchars($r['start_date']) ?> — <?= htmlspecialchars($r['end_date']) ?></td>
                <td class="px-4 py-3 text-center text-slate-500 dark:text-[#8b93a1]"><?= (int)$r['pax_count'] ?></td>
                <td class="px-4 py-3 text-slate-500 dark:text-[#8b93a1] text-xs"><?= htmlspecialchars(vehicleSummaryLabel($r['vehicle_summary'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</form>

<script>
(function () {
  var $checkAll = $('#checkAll'), $checks = $('.reqCheck'), $btn = $('#mergeBtn');
  function refresh() {
    var n = $('.reqCheck:checked').length;
    $btn.prop('disabled', n === 0).text(n ? 'Сонгосон ' + n + '-г нэгтгэх' : 'Сонгосныг нэгтгэх');
  }
  $checkAll.on('change', function () { $checks.prop('checked', this.checked); refresh(); });
  $checks.on('change', refresh);
  $btn.on('click', function () {
    var ids = $('.reqCheck:checked').map(function () { return +this.value; }).get();
    if (!ids.length) return;
    if (!confirm('Сонгосон ' + ids.length + ' хүсэлтийг нэгтгэж, шинэ захиалга үүсгэх үү?')) return;
    $.post('<?= BASE_URL ?>/modules/transport/ajax.php', { action: 'create_order', request_ids: JSON.stringify(ids) }, function (r) {
      showToast(r.message || (r.success ? 'Амжилттай' : 'Алдаа'), r.success ? 'success' : 'error');
      if (r.success) setTimeout(function () { location.href = '<?= BASE_URL ?>/modules/transport/order.php?id=' + r.order_id; }, 500);
    }, 'json');
  });
})();
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
