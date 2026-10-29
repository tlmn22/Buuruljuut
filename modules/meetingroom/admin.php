<?php
$pageTitle  = 'Хурлын өрөө удирдах';
$activePage = 'meetingroom-admin';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
$pdo       = getDB();
$empId     = (int)$_SESSION['user_id'];
$isManager = mrIsManager($pdo, $empId);
$canAssign = mrCanAssignManagers();
if (!$isManager && !$canAssign) { header('Location: ' . BASE_URL . '/denied.php'); exit; }
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';

$rooms = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM meetingroom_bookings b WHERE b.room_id = r.id AND b.status = 'approved') AS approved_cnt
                      FROM meetingroom_rooms r ORDER BY r.is_active DESC, r.name")->fetchAll();

// Хүлээгдэж буй — давтамжийг нэг мөр болгон бүлэглэнэ
$pendingRows = $isManager ? $pdo->query("
    SELECT b.*, r.name AS room_name, r.floor, e.last_name, e.first_name, e.position
    FROM meetingroom_bookings b JOIN meetingroom_rooms r ON r.id = b.room_id JOIN employees e ON e.id = b.requested_by
    WHERE b.status = 'pending' AND b.start_dt >= NOW() ORDER BY b.start_dt")->fetchAll() : [];
$pending = [];
foreach ($pendingRows as $b) {
    $key = $b['recurrence_group'] ?: 'b' . $b['id'];
    if (!isset($pending[$key])) $pending[$key] = $b + ['count' => 0, 'last_dt' => $b['start_dt']];
    $pending[$key]['count']++;
    $pending[$key]['last_dt'] = $b['start_dt'];
}

$history = $isManager ? $pdo->query("
    SELECT b.*, r.name AS room_name, e.last_name, e.first_name, ap.last_name AS ap_last, ap.first_name AS ap_first
    FROM meetingroom_bookings b JOIN meetingroom_rooms r ON r.id = b.room_id JOIN employees e ON e.id = b.requested_by
    LEFT JOIN employees ap ON ap.id = b.approved_by
    WHERE b.status <> 'pending' OR b.start_dt < NOW() ORDER BY b.start_dt DESC LIMIT 50")->fetchAll() : [];

$managers = $pdo->query("SELECT mm.employee_id, e.last_name, e.first_name, e.position, e.employee_code
                         FROM meetingroom_managers mm JOIN employees e ON e.id = mm.employee_id ORDER BY e.last_name")->fetchAll();

$stats = $isManager ? $pdo->query("SELECT COUNT(*) total, SUM(status='approved') approved, SUM(status='pending' AND start_dt >= NOW()) pending,
    SUM(status='rejected') rejected, SUM(status='cancelled') cancelled,
    ROUND(AVG(CASE WHEN status='approved' THEN TIMESTAMPDIFF(MINUTE, start_dt, end_dt) END)) avg_min
    FROM meetingroom_bookings WHERE start_dt >= NOW() - INTERVAL 90 DAY")->fetch() : null;
$byHour = $isManager ? $pdo->query("SELECT HOUR(start_dt) h, COUNT(*) n FROM meetingroom_bookings WHERE status='approved' AND start_dt >= NOW() - INTERVAL 90 DAY GROUP BY h")->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$tabs = [];
if ($isManager) { $tabs['pending'] = ['Хүлээгдэж буй', count($pending)]; $tabs['rooms'] = ['Өрөөнүүд', count($rooms)]; $tabs['history'] = ['Түүх', null]; $tabs['stats'] = ['Статистик', null]; }
if ($canAssign) $tabs['managers'] = ['Хариуцагчид', count($managers)];
$card = 'bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm';
$inp  = 'w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30';
$lbl  = 'block text-xs font-semibold text-slate-500 dark:text-[#8b93a1] mb-1';
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a><span class="mx-2">/</span>
            <a href="<?= BASE_URL ?>/modules/meetingroom/index.php" class="hover:text-[#f1592a]">Хурлын өрөө</a><span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Удирдах</span>
        </nav>
        <h1 class="text-3xl font-bold">Хурлын өрөө удирдах</h1>
    </div>
    <?php if ($isManager): ?>
    <button type="button" onclick="openRoomModal()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12]">
        <span class="material-icons-outlined" style="font-size:18px">add</span> Өрөө нэмэх
    </button>
    <?php endif; ?>
</div>

<div class="flex gap-1 p-1 rounded-xl bg-slate-100 dark:bg-[#20242e] w-fit flex-wrap" id="tabs">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
    <button type="button" data-tab="<?= $key ?>" class="tab-btn px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-1.5 text-slate-500 dark:text-[#8b93a1]">
        <?= $label ?><?php if ($count !== null): ?><span class="px-1.5 rounded-full text-xs font-bold <?= $key === 'pending' && $count ? 'bg-[#f1592a] text-white' : 'bg-slate-200 dark:bg-[#2a2f3b]' ?>"><?= $count ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
</div>

<?php if ($isManager): ?>
<!-- Хүлээгдэж буй -->
<div class="tab-pane" id="pane-pending">
    <?php if (!$pending): ?>
        <div class="<?= $card ?> p-12 text-center text-sm text-slate-400 dark:text-[#5a6172]"><span class="material-icons-outlined block mb-2 text-green-500" style="font-size:40px">task_alt</span>Батлах захиалга алга.</div>
    <?php else: ?>
    <div class="<?= $card ?> divide-y divide-slate-100 dark:divide-[#2a2f3b]">
        <?php foreach ($pending as $b): $group = $b['count'] > 1; ?>
        <div class="p-5 flex flex-col md:flex-row md:items-center gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <p class="font-semibold"><?= htmlspecialchars($b['title'] ?: '—') ?></p>
                    <?php if ($group): ?><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-orange-50 dark:bg-[#2a1810] text-[#c9481f]"><?= MR_RECURRENCE_LABELS[$b['recurrence']] ?> · <?= $b['count'] ?> удаа</span><?php endif; ?>
                </div>
                <p class="text-sm text-slate-600 dark:text-[#c9cdd6] mt-1"><span class="material-icons-outlined align-middle text-slate-400" style="font-size:16px">meeting_room</span> <?= htmlspecialchars($b['room_name'] . ($b['floor'] ? ' · ' . $b['floor'] : '')) ?>
                    · <?= mrSlotLabel($b['start_dt'], $b['end_dt']) ?><?= $group ? ' … ' . date('Y.m.d', strtotime($b['last_dt'])) : '' ?></p>
                <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1"><?= htmlspecialchars(employeeName($b['last_name'], $b['first_name'])) ?> · <?= htmlspecialchars($b['position'] ?? '') ?>
                    <?= $b['has_external_guests'] ? ' · Гадны зочинтой' : '' ?><?= $b['needs_equipment'] ? ' · Тоног төхөөрөмж' : '' ?></p>
                <?php if ($b['description']): ?><p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1"><?= htmlspecialchars($b['description']) ?></p><?php endif; ?>
            </div>
            <div class="flex gap-2 flex-shrink-0">
                <button type="button" onclick="decide('approve_booking', <?= (int)$b['id'] ?>, <?= $group ? 'true' : 'false' ?>)" class="px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700"><?= $group ? 'Бүгдийг батлах' : 'Батлах' ?></button>
                <button type="button" onclick="decide('reject_booking', <?= (int)$b['id'] ?>, <?= $group ? 'true' : 'false' ?>)" class="px-4 py-2 rounded-lg border border-red-200 dark:border-red-900/40 text-red-600 text-sm font-medium hover:bg-red-50 dark:hover:bg-red-950/30">Татгалзах</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Өрөөнүүд -->
<div class="tab-pane hidden" id="pane-rooms">
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($rooms as $rm): $photo = mrPhotoUrl($rm['photo']); $ams = json_decode($rm['amenities'] ?? '[]', true) ?: []; ?>
        <div class="<?= $card ?> overflow-hidden <?= $rm['is_active'] ? '' : 'opacity-60' ?>">
            <?php if ($photo): ?><img src="<?= htmlspecialchars($photo) ?>" class="w-full h-36 object-cover" alt="">
            <?php else: ?><div class="w-full h-36 bg-slate-100 dark:bg-[#20242e] flex items-center justify-center"><span class="material-icons-outlined text-slate-300 dark:text-[#3a4151]" style="font-size:48px">meeting_room</span></div><?php endif; ?>
            <div class="p-4">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-bold"><?= htmlspecialchars($rm['name']) ?></h3>
                    <?php if (!$rm['is_active']): ?><span class="px-2 py-0.5 rounded-full text-xs bg-slate-100 dark:bg-[#272c38]">Идэвхгүй</span><?php endif; ?>
                </div>
                <p class="text-xs text-slate-500 dark:text-[#8b93a1] mt-1"><?= $rm['floor'] ? htmlspecialchars($rm['floor']) . ' · ' : '' ?><?= (int)$rm['capacity'] ?> хүн · <?= (int)$rm['approved_cnt'] ?> баталгаажсан захиалга</p>
                <?php if ($ams): ?><div class="flex flex-wrap gap-1 mt-2"><?php foreach ($ams as $a): if (!isset(MR_AMENITIES[$a])) continue; ?><span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-orange-50 dark:bg-[#2a1810] text-[#c9481f] text-[11px]"><span class="material-icons-outlined" style="font-size:12px"><?= MR_AMENITIES[$a][1] ?></span><?= MR_AMENITIES[$a][0] ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php if ($rm['notes']): ?><p class="text-xs text-slate-400 mt-2 line-clamp-2"><?= htmlspecialchars($rm['notes']) ?></p><?php endif; ?>
                <div class="flex gap-2 mt-3">
                    <button type="button" onclick='openRoomModal(<?= htmlspecialchars(json_encode(['id' => (int)$rm['id'], 'name' => $rm['name'], 'floor' => $rm['floor'], 'capacity' => (int)$rm['capacity'], 'notes' => $rm['notes'], 'photo' => $rm['photo'], 'photo_url' => $photo, 'amenities' => $ams], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)' class="flex-1 py-1.5 rounded-lg text-xs font-medium bg-slate-100 dark:bg-[#272c38] hover:bg-slate-200">Засах</button>
                    <button type="button" onclick="toggleRoom(<?= (int)$rm['id'] ?>, <?= (int)$rm['is_active'] ?>)" class="flex-1 py-1.5 rounded-lg text-xs font-medium <?= $rm['is_active'] ? 'text-red-600 bg-red-50 dark:bg-red-950/30' : 'text-green-700 bg-green-50 dark:bg-green-950/30' ?>"><?= $rm['is_active'] ? 'Идэвхгүй болгох' : 'Идэвхжүүлэх' ?></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$rooms): ?><div class="<?= $card ?> p-12 text-center text-sm text-slate-400 col-span-full">Өрөө бүртгэгдээгүй байна. "Өрөө нэмэх" дарж эхлүүлнэ үү.</div><?php endif; ?>
    </div>
</div>

<!-- Түүх -->
<div class="tab-pane hidden" id="pane-history">
    <div class="<?= $card ?> overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] text-xs uppercase tracking-wider text-slate-500 dark:text-[#8b93a1]">
                <th class="text-left px-5 py-3">Хурал</th><th class="text-left px-4 py-3">Өрөө</th><th class="text-left px-4 py-3">Цаг</th><th class="text-left px-4 py-3">Захиалагч</th><th class="text-left px-4 py-3">Төлөв</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($history as $b): [$sl, $sc] = MR_STATUS[$b['status']]; ?>
                <tr>
                    <td class="px-5 py-3 font-medium"><?= htmlspecialchars($b['title'] ?: '—') ?></td>
                    <td class="px-4 py-3 text-slate-600 dark:text-[#c9cdd6]"><?= htmlspecialchars($b['room_name']) ?></td>
                    <td class="px-4 py-3 text-xs whitespace-nowrap"><?= mrSlotLabel($b['start_dt'], $b['end_dt']) ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars(employeeName($b['last_name'], $b['first_name'])) ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $sc ?>"><?= $sl ?></span>
                        <?php if ($b['ap_last'] && in_array($b['status'], ['approved', 'rejected'], true)): ?><span class="block text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars(employeeName($b['ap_last'], $b['ap_first'])) ?></span><?php endif; ?>
                        <?php if ($b['reject_reason']): ?><span class="block text-[11px] text-red-500"><?= htmlspecialchars($b['reject_reason']) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="5" class="px-5 py-10 text-center text-slate-400">Түүх алга.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Статистик (сүүлийн 90 хоног) -->
<div class="tab-pane hidden" id="pane-stats">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <?php $avg = $stats['avg_min'] ? ($stats['avg_min'] >= 60 ? floor($stats['avg_min'] / 60) . 'ц ' . ($stats['avg_min'] % 60) . 'м' : $stats['avg_min'] . 'м') : '—';
        foreach ([['Нийт захиалга', (int)$stats['total']], ['Баталгаажсан', (int)$stats['approved']], ['Хүлээгдэж буй', (int)$stats['pending']], ['Татгалзсан / цуцалсан', (int)$stats['rejected'] + (int)$stats['cancelled']], ['Дундаж үргэлжлэх', $avg]] as [$l, $v]): ?>
        <div class="<?= $card ?> p-4"><p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= $l ?></p><p class="text-2xl font-bold mt-1"><?= $v ?></p></div>
        <?php endforeach; ?>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <div class="<?= $card ?> p-5">
            <h3 class="font-semibold mb-4">Өрөөнүүдийн ашиглалт <span class="text-xs font-normal text-slate-400">баталгаажсан захиалга</span></h3>
            <?php $max = max(array_column($rooms, 'approved_cnt') ?: [1]) ?: 1; foreach ($rooms as $rm): ?>
            <div class="mb-3"><div class="flex justify-between text-sm mb-1"><span class="truncate pr-3"><?= htmlspecialchars($rm['name']) ?></span><b><?= (int)$rm['approved_cnt'] ?></b></div>
                <div class="h-2 bg-slate-100 dark:bg-[#272c38] rounded-full overflow-hidden"><div class="h-full bg-[#f1592a] rounded-full" style="width:<?= round($rm['approved_cnt'] / $max * 100) ?>%"></div></div></div>
            <?php endforeach; ?>
        </div>
        <div class="<?= $card ?> p-5">
            <h3 class="font-semibold mb-4">Эрэлттэй цагууд <span class="text-xs font-normal text-slate-400">хурал эхэлсэн цагаар</span></h3>
            <div class="flex items-end gap-1.5 h-40">
                <?php $hmax = max($byHour ?: [1]) ?: 1; for ($h = MR_GRID_START_HOUR; $h < MR_GRID_END_HOUR; $h++): $n = (int)($byHour[$h] ?? 0); ?>
                <div class="flex-1 flex flex-col items-center gap-1 h-full justify-end" title="<?= sprintf('%02d:00', $h) ?> — <?= $n ?>">
                    <span class="text-[10px] text-slate-400"><?= $n ?: '' ?></span>
                    <div class="w-full rounded-t bg-[#f1592a]/80" style="height:<?= max(2, round($n / $hmax * 100)) ?>%"></div>
                    <span class="text-[10px] text-slate-400"><?= $h ?></span>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canAssign): ?>
<!-- Хариуцагчид -->
<div class="tab-pane hidden" id="pane-managers">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 dark:border-[#2a2f3b]"><h3 class="font-semibold">Хариуцагчид</h3><p class="text-xs text-slate-400 mt-0.5">Захиалга батлах, өрөө удирдах эрхтэй</p></div>
            <?php if (!$managers): ?><p class="py-10 text-center text-sm text-slate-400">Хариуцагч томилоогүй байна.</p><?php endif; ?>
            <div class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
                <?php foreach ($managers as $m): ?>
                <div class="px-5 py-3 flex items-center justify-between gap-3">
                    <div><p class="font-medium"><?= htmlspecialchars(employeeName($m['last_name'], $m['first_name'])) ?> <span class="text-xs text-slate-400"><?= htmlspecialchars($m['employee_code']) ?></span></p><p class="text-xs text-slate-400"><?= htmlspecialchars($m['position'] ?? '') ?></p></div>
                    <button type="button" onclick="setManager('remove_manager', <?= (int)$m['employee_id'] ?>)" class="text-xs font-semibold text-red-500 hover:underline">Хасах</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="<?= $card ?> p-5">
            <h3 class="font-semibold mb-3">Хариуцагч нэмэх</h3>
            <div class="relative">
                <input type="text" id="mgrSearch" autocomplete="off" placeholder="Нэр эсвэл кодоор хайх..." class="<?= $inp ?>">
                <div id="mgrSuggest" class="hidden absolute z-10 left-0 right-0 mt-1 bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-lg shadow-lg max-h-56 overflow-y-auto"></div>
            </div>
            <p class="text-xs text-slate-400 mt-2">Сонгомогц шууд хариуцагч болно.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isManager): ?>
<!-- Өрөө нэмэх / засах -->
<div id="roomModal" class="hidden fixed inset-0 z-50 items-center justify-center p-4" style="background:rgba(15,23,42,.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-md max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
            <h3 class="font-bold text-lg" id="roomModalTitle">Өрөө нэмэх</h3>
            <button type="button" onclick="closeRoomModal()" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-[#272c38]"><span class="material-icons-outlined">close</span></button>
        </div>
        <div class="px-6 py-5 space-y-4">
            <input type="hidden" id="rId"><input type="hidden" id="rPhoto">
            <div><label class="<?= $lbl ?>">Нэр <span class="text-red-500">*</span></label><input type="text" id="rName" maxlength="100" placeholder="Жишээ: Том хурлын өрөө" class="<?= $inp ?>"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="<?= $lbl ?>">Давхар / байршил</label><input type="text" id="rFloor" maxlength="50" placeholder="3-р давхар" class="<?= $inp ?>"></div>
                <div><label class="<?= $lbl ?>">Багтаамж (хүн)</label><input type="number" id="rCapacity" min="1" max="500" value="10" class="<?= $inp ?>"></div>
            </div>
            <div><label class="<?= $lbl ?>">Тоног төхөөрөмж</label>
                <div class="grid grid-cols-2 gap-1"><?php foreach (MR_AMENITIES as $k => [$l, $i]): ?><label class="flex items-center gap-2 text-sm p-1.5 rounded hover:bg-slate-50 dark:hover:bg-[#272c38]"><input type="checkbox" class="amenity" value="<?= $k ?>"><span class="material-icons-outlined text-slate-400" style="font-size:16px"><?= $i ?></span><?= $l ?></label><?php endforeach; ?></div></div>
            <div><label class="<?= $lbl ?>">Тэмдэглэл</label><textarea id="rNotes" rows="2" class="<?= $inp ?> resize-none"></textarea></div>
            <div><label class="<?= $lbl ?>">Зураг</label>
                <div class="flex items-center gap-3">
                    <img id="rPhotoPreview" class="hidden w-24 h-16 object-cover rounded-lg" alt="">
                    <button type="button" onclick="$('#rPhotoFile').click()" class="px-3 py-2 rounded-lg text-sm bg-slate-100 dark:bg-[#272c38]">Зураг сонгох</button>
                    <button type="button" id="rPhotoRemove" onclick="setPhoto('', null)" class="hidden text-xs text-red-500">Хасах</button>
                    <input type="file" id="rPhotoFile" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="uploadPhoto(this)">
                </div></div>
        </div>
        <div class="px-6 py-4 border-t border-slate-100 dark:border-[#2a2f3b] flex justify-end gap-2">
            <button type="button" onclick="closeRoomModal()" class="px-4 py-2 text-sm text-slate-500">Болих</button>
            <button type="button" onclick="saveRoom()" class="bg-[#f1592a] text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12]">Хадгалах</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var AJAX = '<?= BASE_URL ?>/modules/meetingroom/ajax.php';
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function post(data, reload) {
    $.post(AJAX, data, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success && reload !== false) setTimeout(function () { location.reload(); }, 600);
    }, 'json').fail(function () { showToast('Сервертэй холбогдоход алдаа гарлаа.', 'error'); });
}

// Табууд (сонгосныг hash-д хадгална)
function showTab(key) {
    $('.tab-pane').addClass('hidden'); $('#pane-' + key).removeClass('hidden');
    $('.tab-btn').removeClass('bg-white dark:bg-[#1c212b] text-[#1c2430] dark:text-[#f2f3f6] shadow-sm').addClass('text-slate-500 dark:text-[#8b93a1]');
    $('.tab-btn[data-tab="' + key + '"]').addClass('bg-white dark:bg-[#1c212b] text-[#1c2430] dark:text-[#f2f3f6] shadow-sm').removeClass('text-slate-500 dark:text-[#8b93a1]');
    history.replaceState(null, '', '#' + key);
}
$('#tabs').on('click', '.tab-btn', function () { showTab(this.dataset.tab); });
showTab($('.tab-btn[data-tab="' + location.hash.slice(1) + '"]').length ? location.hash.slice(1) : $('.tab-btn').first().data('tab'));

function decide(action, id, isGroup) {
    var reason = '';
    if (action === 'reject_booking') { reason = prompt(isGroup ? 'Давтамжийн бүх захиалгыг татгалзах шалтгаан:' : 'Татгалзах шалтгаан:'); if (reason === null) return; }
    post({ action: action, id: id, scope: isGroup ? 'group' : 'one', reason: reason });
}

// Өрөө
function setPhoto(name, url) {
    $('#rPhoto').val(name || '');
    $('#rPhotoPreview').attr('src', url || '').toggleClass('hidden', !url);
    $('#rPhotoRemove').toggleClass('hidden', !url);
}
function openRoomModal(rm) {
    rm = rm || {};
    $('#roomModalTitle').text(rm.id ? 'Өрөө засах' : 'Өрөө нэмэх');
    $('#rId').val(rm.id || ''); $('#rName').val(rm.name || ''); $('#rFloor').val(rm.floor || '');
    $('#rCapacity').val(rm.capacity || 10); $('#rNotes').val(rm.notes || '');
    $('.amenity').each(function () { this.checked = (rm.amenities || []).indexOf(this.value) !== -1; });
    setPhoto(rm.photo, rm.photo_url);
    $('#roomModal').removeClass('hidden').addClass('flex');
}
function closeRoomModal() { $('#roomModal').addClass('hidden').removeClass('flex'); }
function uploadPhoto(input) {
    if (!input.files[0]) return;
    var fd = new FormData(); fd.append('action', 'upload_room_photo'); fd.append('photo', input.files[0]);
    input.value = '';
    $.ajax({ url: AJAX, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (r) { if (r.success) setPhoto(r.photo, r.url); else showToast(r.message, 'error'); } });
}
function saveRoom() {
    if (!$('#rName').val().trim()) { showToast('Өрөөний нэрийг оруулна уу.', 'error'); return; }
    post({ action: $('#rId').val() ? 'update_room' : 'create_room', id: $('#rId').val(), name: $('#rName').val().trim(),
           floor: $('#rFloor').val().trim(), capacity: $('#rCapacity').val(), notes: $('#rNotes').val().trim(), photo: $('#rPhoto').val(),
           amenities: JSON.stringify($('.amenity:checked').map(function () { return this.value; }).get()) });
}
function toggleRoom(id, active) {
    if (!confirm(active ? 'Өрөөг идэвхгүй болгох уу? Шинэ захиалга авахгүй болно.' : 'Өрөөг идэвхжүүлэх үү?')) return;
    post({ action: 'toggle_room', id: id });
}

// Хариуцагч
function setManager(action, id) {
    if (action === 'remove_manager' && !confirm('Хариуцагчийг хасах уу?')) return;
    post({ action: action, employee_id: id });
}
var mgrTimer;
$('#mgrSearch').on('input', function () {
    clearTimeout(mgrTimer);
    var q = this.value.trim();
    if (q.length < 2) { $('#mgrSuggest').addClass('hidden'); return; }
    mgrTimer = setTimeout(function () {
        $.getJSON(AJAX, { action: 'search_employees', q: q }, function (r) {
            $('#mgrSuggest').html(r.results.map(function (e) {
                return '<div class="px-3 py-2 text-sm cursor-pointer hover:bg-slate-50 dark:hover:bg-[#272c38]" onclick="setManager(\'add_manager\',' + e.id + ')"><b>' + esc(e.name) + '</b> <span class="text-xs text-slate-400">' + esc(e.code) + ' · ' + esc(e.position) + '</span></div>';
            }).join('') || '<p class="px-3 py-2 text-sm text-slate-400">Олдсонгүй</p>').removeClass('hidden');
        });
    }, 250);
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
