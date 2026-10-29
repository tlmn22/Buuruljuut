<?php
$pageTitle  = 'Хурлын өрөө';
$activePage = 'meetingroom';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';

$pdo       = getDB();
$empId     = (int)$_SESSION['user_id'];
$isManager = mrIsManager($pdo, $empId);

$rooms = $pdo->query("SELECT * FROM meetingroom_rooms WHERE is_active = 1 ORDER BY name")->fetchAll();
$selectedRoomId = (int)($_GET['room'] ?? ($rooms[0]['id'] ?? 0));
$selectedRoom = null;
foreach ($rooms as $r) if ((int)$r['id'] === $selectedRoomId) $selectedRoom = $r;
if (!$selectedRoom && $rooms) { $selectedRoom = $rooms[0]; $selectedRoomId = (int)$rooms[0]['id']; }

// 7 хоног: ?date= (мэдэгдлийн холбоос) эсвэл ?week= шилжилт
$anchor = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? new DateTime($_GET['date']) : new DateTime('today');
$weekStart = (clone $anchor)->modify('monday this week');
$weekOffset = (int)($_GET['week'] ?? 0);
if ($weekOffset) $weekStart->modify("$weekOffset weeks");
$weekEnd = (clone $weekStart)->modify('+6 days');
$days = [];
for ($i = 0; $i < 7; $i++) $days[] = (clone $weekStart)->modify("+$i days");
$isThisWeek = $weekStart->format('Y-m-d') === (new DateTime('monday this week'))->format('Y-m-d');
$navBase = '?room=' . $selectedRoomId . '&date=' . $weekStart->format('Y-m-d');

$my = $pdo->prepare("
    SELECT b.*, r.name AS room_name, r.floor FROM meetingroom_bookings b JOIN meetingroom_rooms r ON r.id = b.room_id
    WHERE b.requested_by = ? AND (b.end_dt >= NOW() OR b.created_at >= NOW() - INTERVAL 14 DAY)
    ORDER BY (b.end_dt >= NOW()) DESC, b.start_dt LIMIT 30");
$my->execute([$empId]);
$myBookings = $my->fetchAll();

$dayNames = ['Да', 'Мя', 'Лх', 'Пү', 'Ба', 'Бя', 'Ня'];
$card = 'bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm';
$inp  = 'w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30';
$lbl  = 'block text-xs font-semibold text-slate-500 dark:text-[#8b93a1] mb-1';
?>
<style>
.mr-cell { height: 40px; }
.mr-booking { position: absolute; left: 2px; right: 2px; border-radius: 6px; padding: 2px 6px; overflow: hidden; cursor: pointer; z-index: 10; font-size: 11px; line-height: 1.25; }
.mr-booking.approved { background: #f1592a; color: #fff; }
.mr-booking.pending { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.mr-booking.mine.pending { background: #fff7ed; color: #9a3412; border: 1px dashed #f1592a; }
html.dark .mr-booking.pending { background: rgba(245,185,66,.18); color: #f5b942; border-color: rgba(245,185,66,.4); }
html.dark .mr-booking.mine.pending { background: rgba(241,89,42,.15); color: #ff8a5c; border-color: #ff8a5c; }
.mr-blackout { position: absolute; left: 0; right: 0; z-index: 5; cursor: pointer;
  background: repeating-linear-gradient(45deg, rgba(100,116,139,.16), rgba(100,116,139,.16) 6px, rgba(100,116,139,.28) 6px, rgba(100,116,139,.28) 12px);
  border-top: 1px solid rgba(100,116,139,.4); border-bottom: 1px solid rgba(100,116,139,.4); }
.mr-sel { background: rgba(241,89,42,.22) !important; }
</style>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a><span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Хурлын өрөө</span>
        </nav>
        <h1 class="text-3xl font-bold">Хурлын өрөө захиалга</h1>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <?php if ($isManager || mrCanAssignManagers()): ?>
        <a href="<?= BASE_URL ?>/modules/meetingroom/admin.php" class="px-4 py-2.5 rounded-lg text-sm font-medium border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38] flex items-center gap-1.5">
            <span class="material-icons-outlined" style="font-size:18px">admin_panel_settings</span> Удирдах
        </a>
        <?php endif; ?>
        <?php if ($isManager && $rooms): ?>
        <button type="button" onclick="openBlackoutModal()" class="px-4 py-2.5 rounded-lg text-sm font-medium border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38] flex items-center gap-1.5">
            <span class="material-icons-outlined" style="font-size:18px">block</span> Боломжгүй цаг
        </button>
        <?php endif; ?>
        <?php if ($rooms): ?>
        <button type="button" onclick="openBookingModal()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12] transition-colors">
            <span class="material-icons-outlined" style="font-size:18px">add</span> Захиалах
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$rooms): ?>
<div class="<?= $card ?> p-16 text-center">
    <span class="material-icons-outlined text-slate-300 dark:text-[#3a4151] block mb-3" style="font-size:48px">meeting_room</span>
    <p class="font-medium">Хурлын өрөө бүртгэгдээгүй байна</p>
    <p class="text-sm text-slate-400 dark:text-[#8b93a1] mt-1">Хариуцагч өрөө нэмсний дараа захиалах боломжтой болно.</p>
</div>
<?php else: ?>

<div class="flex flex-col lg:flex-row gap-6 items-start">
    <!-- ══ Зүүн багана ══ -->
    <div class="w-full lg:w-[330px] flex-shrink-0 space-y-6">
        <!-- Сул өрөө хайх -->
        <div class="<?= $card ?> p-5">
            <h2 class="font-semibold mb-3 flex items-center gap-2"><span class="material-icons-outlined text-[#f1592a]" style="font-size:20px">search</span>Сул өрөө хайх</h2>
            <div class="space-y-3">
                <div><label class="<?= $lbl ?>">Огноо</label><input type="date" id="sDate" class="<?= $inp ?>" min="<?= date('Y-m-d') ?>"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="<?= $lbl ?>">Эхлэх</label><input type="time" id="sStart" step="900" class="<?= $inp ?>"></div>
                    <div><label class="<?= $lbl ?>">Дуусах</label><input type="time" id="sEnd" step="900" class="<?= $inp ?>"></div>
                </div>
                <div><label class="<?= $lbl ?>">Хүний тоо <span class="font-normal">(заавал биш)</span></label><input type="number" id="sCap" min="1" class="<?= $inp ?>"></div>
                <div class="flex gap-2">
                    <button type="button" onclick="searchAvailable()" class="flex-1 bg-[#f1592a] text-white py-2.5 rounded-lg text-sm font-medium hover:bg-[#c33e12]">Хайх</button>
                    <button type="button" id="clearSearchBtn" onclick="clearSearch()" class="hidden px-3 text-sm text-slate-500 hover:text-[#f1592a]">Цэвэрлэх</button>
                </div>
            </div>
            <div id="searchResults" class="hidden mt-4 space-y-2"></div>
        </div>

        <!-- Өрөөнүүд -->
        <div class="<?= $card ?> p-4">
            <h2 class="font-semibold mb-3 px-1">Өрөөнүүд</h2>
            <div class="space-y-2">
                <?php foreach ($rooms as $rm): $sel = (int)$rm['id'] === $selectedRoomId; $photo = mrPhotoUrl($rm['photo']);
                    $ams = json_decode($rm['amenities'] ?? '[]', true) ?: []; ?>
                <a href="?room=<?= (int)$rm['id'] ?>&date=<?= $weekStart->format('Y-m-d') ?>"
                   class="flex items-center gap-3 p-2.5 rounded-lg border-2 transition-colors <?= $sel ? 'border-[#f1592a] bg-orange-50/60 dark:bg-[#2a1810]' : 'border-transparent hover:bg-slate-50 dark:hover:bg-[#20242e]' ?>">
                    <?php if ($photo): ?><img src="<?= htmlspecialchars($photo) ?>" class="w-12 h-10 object-cover rounded-md flex-shrink-0" alt="">
                    <?php else: ?><span class="w-12 h-10 rounded-md bg-slate-100 dark:bg-[#272c38] flex items-center justify-center flex-shrink-0"><span class="material-icons-outlined text-slate-400">meeting_room</span></span><?php endif; ?>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold truncate"><?= htmlspecialchars($rm['name']) ?></span>
                        <span class="block text-xs text-slate-400 dark:text-[#8b93a1]"><?= $rm['floor'] ? htmlspecialchars($rm['floor']) . ' · ' : '' ?><?= (int)$rm['capacity'] ?> хүн</span>
                        <?php if ($ams): ?><span class="flex gap-1 mt-1"><?php foreach ($ams as $a): if (!isset(MR_AMENITIES[$a])) continue; ?><span class="material-icons-outlined text-slate-400" style="font-size:14px" title="<?= MR_AMENITIES[$a][0] ?>"><?= MR_AMENITIES[$a][1] ?></span><?php endforeach; ?></span><?php endif; ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Миний захиалгууд -->
        <div class="<?= $card ?> overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
                <h2 class="font-semibold">Миний захиалгууд</h2>
                <?php if ($myBookings): ?><span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-[#272c38]"><?= count($myBookings) ?></span><?php endif; ?>
            </div>
            <?php if (!$myBookings): ?>
                <p class="py-10 text-center text-sm text-slate-400 dark:text-[#5a6172]">Захиалга алга</p>
            <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-[#2a2f3b] max-h-[480px] overflow-y-auto">
                <?php foreach ($myBookings as $b): [$sl, $sc, $si] = MR_STATUS[$b['status']]; $past = strtotime($b['end_dt']) < time(); ?>
                <div class="px-5 py-3 <?= $past ? 'opacity-60' : '' ?>">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold truncate"><?= htmlspecialchars($b['title'] ?: $b['room_name']) ?></p>
                            <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-0.5"><?= htmlspecialchars($b['room_name']) ?> · <?= mrSlotLabel($b['start_dt'], $b['end_dt']) ?></p>
                            <?php if ($b['recurrence'] !== 'none'): ?><p class="text-[11px] text-[#c9481f] mt-0.5"><?= MR_RECURRENCE_LABELS[$b['recurrence']] ?></p><?php endif; ?>
                            <?php if ($b['status'] === 'rejected' && $b['reject_reason']): ?><p class="text-xs text-red-500 mt-0.5"><?= htmlspecialchars($b['reject_reason']) ?></p><?php endif; ?>
                        </div>
                        <?php if (!$past && in_array($b['status'], ['pending', 'approved'], true)): ?>
                        <button type="button" onclick="cancelBooking(<?= (int)$b['id'] ?>, <?= $b['recurrence_group'] ? 'true' : 'false' ?>)" title="Цуцлах" class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"><span class="material-icons-outlined" style="font-size:18px">close</span></button>
                        <?php endif; ?>
                    </div>
                    <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium <?= $sc ?>"><span class="material-icons-outlined" style="font-size:12px"><?= $si ?></span><?= $sl ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ Баруун багана: календарь ══ -->
    <div class="flex-1 min-w-0 w-full <?= $card ?> overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between gap-3 flex-wrap">
            <div class="min-w-0">
                <p class="font-semibold truncate"><?= htmlspecialchars($selectedRoom['name']) ?> <span class="text-sm font-normal text-slate-400"><?= $selectedRoom['floor'] ? '· ' . htmlspecialchars($selectedRoom['floor']) : '' ?> · <?= (int)$selectedRoom['capacity'] ?> хүн</span></p>
                <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= $weekStart->format('Y.m.d') ?> — <?= $weekEnd->format('Y.m.d') ?><?= $isThisWeek ? ' · Энэ 7 хоног' : '' ?></p>
            </div>
            <div class="flex items-center gap-1">
                <a href="<?= $navBase ?>&week=-1" class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#272c38]" title="Өмнөх 7 хоног"><span class="material-icons-outlined" style="font-size:20px">chevron_left</span></a>
                <?php if (!$isThisWeek): ?><a href="?room=<?= $selectedRoomId ?>" class="px-3 py-1.5 text-xs font-semibold text-[#f1592a] rounded-lg hover:bg-orange-50 dark:hover:bg-[#2a1810]">Өнөөдөр</a><?php endif; ?>
                <a href="<?= $navBase ?>&week=1" class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#272c38]" title="Дараагийн 7 хоног"><span class="material-icons-outlined" style="font-size:20px">chevron_right</span></a>
            </div>
        </div>

        <div class="overflow-x-auto">
            <div class="min-w-[640px]">
                <div class="grid border-b border-slate-100 dark:border-[#2a2f3b]" style="grid-template-columns:48px repeat(7,1fr)">
                    <div></div>
                    <?php foreach ($days as $i => $d): $today = $d->format('Y-m-d') === date('Y-m-d'); $we = $i >= 5; ?>
                    <div class="py-2 text-center border-l border-slate-100 dark:border-[#2a2f3b] <?= $we ? 'bg-slate-50/70 dark:bg-[#171b24]' : '' ?>">
                        <p class="text-[11px] font-semibold <?= $today ? 'text-[#f1592a]' : 'text-slate-400 dark:text-[#8b93a1]' ?>"><?= $dayNames[$i] ?></p>
                        <p class="text-sm font-bold <?= $today ? 'w-7 h-7 mx-auto rounded-full bg-[#f1592a] text-white flex items-center justify-center' : '' ?>"><?= $d->format('j') ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="overflow-y-auto" style="max-height:520px" id="calScroll">
                    <div class="relative">
                        <div class="grid" style="grid-template-columns:48px repeat(7,1fr)">
                            <?php for ($h = MR_GRID_START_HOUR; $h < MR_GRID_END_HOUR; $h++): ?>
                                <div class="mr-cell flex items-start justify-end pr-1.5 pt-0.5 text-[10px] text-slate-400 dark:text-[#5a6172] border-b border-slate-100 dark:border-[#2a2f3b]"><?= sprintf('%02d:00', $h) ?></div>
                                <?php foreach ($days as $i => $d):
                                    $ds = $d->format('Y-m-d');
                                    $past = $ds < date('Y-m-d') || ($ds === date('Y-m-d') && $h < (int)date('G'));
                                ?>
                                <div class="mr-cell cal-cell border-l border-b border-slate-100 dark:border-[#2a2f3b] select-none <?= $past ? 'bg-slate-50 dark:bg-[#171b24]' : 'cursor-pointer hover:bg-orange-50/60 dark:hover:bg-[#2a1810]/50' ?> <?= $i >= 5 && !$past ? 'bg-slate-50/50 dark:bg-[#171b24]/60' : '' ?>"
                                     data-day="<?= $ds ?>" data-hour="<?= $h ?>" data-past="<?= $past ? 1 : 0 ?>"></div>
                                <?php endforeach; ?>
                            <?php endfor; ?>
                        </div>
                        <div class="absolute inset-0 pointer-events-none grid" style="left:48px;grid-template-columns:repeat(7,1fr)">
                            <?php foreach ($days as $d): ?><div class="relative" id="dayCol_<?= $d->format('Y-m-d') ?>"></div><?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="px-5 py-3 border-t border-slate-100 dark:border-[#2a2f3b] flex items-center gap-4 flex-wrap text-xs text-slate-500 dark:text-[#8b93a1]">
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-[#f1592a]"></span>Баталгаажсан</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-amber-100 border border-amber-300"></span>Хүлээгдэж буй</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded border border-dashed border-[#f1592a] bg-orange-50"></span>Миний (хүлээгдэж буй)</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded mr-blackout" style="position:static"></span>Боломжгүй</span>
            <span class="ml-auto">Хоосон нүдэн дээр дарж эсвэл чирж захиална</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Захиалах цонх -->
<div id="bookingModal" class="hidden fixed inset-0 z-50 items-center justify-center p-4" style="background:rgba(15,23,42,.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
            <h3 class="font-bold text-lg">Хурлын өрөө захиалах</h3>
            <button type="button" onclick="closeModal('#bookingModal')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-[#272c38]"><span class="material-icons-outlined">close</span></button>
        </div>
        <div class="px-6 py-5 space-y-4">
            <div><label class="<?= $lbl ?>">Өрөө</label>
                <select id="bRoom" class="<?= $inp ?>"><?php foreach ($rooms as $rm): ?><option value="<?= (int)$rm['id'] ?>" <?= (int)$rm['id'] === $selectedRoomId ? 'selected' : '' ?>><?= htmlspecialchars($rm['name'] . ($rm['floor'] ? ' (' . $rm['floor'] . ')' : '') . ' — ' . $rm['capacity'] . ' хүн') ?></option><?php endforeach; ?></select></div>
            <div><label class="<?= $lbl ?>">Хурлын гарчиг <span class="text-red-500">*</span></label><input type="text" id="bTitle" maxlength="200" placeholder="Жишээ: Долоо хоногийн уулзалт" class="<?= $inp ?>"></div>
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-3 sm:col-span-1"><label class="<?= $lbl ?>">Огноо</label><input type="date" id="bDate" min="<?= date('Y-m-d') ?>" class="<?= $inp ?>"></div>
                <div><label class="<?= $lbl ?>">Эхлэх</label><input type="time" id="bStart" step="900" class="<?= $inp ?>"></div>
                <div><label class="<?= $lbl ?>">Дуусах</label><input type="time" id="bEnd" step="900" class="<?= $inp ?>"></div>
            </div>
            <div><label class="<?= $lbl ?>">Тайлбар <span class="font-normal">(заавал биш)</span></label><textarea id="bDesc" rows="2" class="<?= $inp ?> resize-none"></textarea></div>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" id="bGuests"> Гадны зочинтой</label>
                <label class="flex items-center gap-2"><input type="checkbox" id="bEquip"> Тоног төхөөрөмж хэрэгтэй</label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="<?= $lbl ?>">Давтамж</label>
                    <select id="bRecurrence" class="<?= $inp ?>" onchange="$('#bRecEndWrap').toggleClass('hidden', this.value === 'none')"><?php foreach (MR_RECURRENCE_LABELS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div id="bRecEndWrap" class="hidden"><label class="<?= $lbl ?>">Давтамж дуусах</label><input type="date" id="bRecEnd" class="<?= $inp ?>"></div>
            </div>
            <div>
                <label class="<?= $lbl ?>">Оролцогчид <span class="font-normal">(заавал биш)</span></label>
                <div class="relative">
                    <input type="text" id="attSearch" autocomplete="off" placeholder="Ажилтан хайх эсвэл гадны хүний нэр бичээд Enter" class="<?= $inp ?>">
                    <div id="attSuggest" class="hidden absolute z-10 left-0 right-0 mt-1 bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                </div>
                <div id="attList" class="flex flex-wrap gap-1.5 mt-2"></div>
            </div>
            <p class="text-xs text-slate-400 dark:text-[#8b93a1]">Захиалга хариуцагч батласны дараа баталгаажна. Хариуг 🔔 мэдэгдлээр авна.</p>
        </div>
        <div class="px-6 py-4 border-t border-slate-100 dark:border-[#2a2f3b] flex justify-end gap-2">
            <button type="button" onclick="closeModal('#bookingModal')" class="px-4 py-2 text-sm text-slate-500">Болих</button>
            <button type="button" id="bSubmit" onclick="submitBooking()" class="bg-[#f1592a] text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12]">Захиалга илгээх</button>
        </div>
    </div>
</div>

<!-- Захиалгын дэлгэрэнгүй -->
<div id="detailModal" class="hidden fixed inset-0 z-50 items-center justify-center p-4" style="background:rgba(15,23,42,.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-md">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
            <h3 class="font-bold" id="detailTitle">Захиалга</h3>
            <button type="button" onclick="closeModal('#detailModal')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-[#272c38]"><span class="material-icons-outlined">close</span></button>
        </div>
        <div id="detailBody" class="px-5 py-4 space-y-2.5 text-sm"></div>
        <div id="detailActions" class="px-5 pb-5 flex flex-wrap gap-2"></div>
    </div>
</div>

<?php if ($isManager): ?>
<!-- Боломжгүй цаг -->
<div id="blackoutModal" class="hidden fixed inset-0 z-50 items-center justify-center p-4" style="background:rgba(15,23,42,.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between">
            <h3 class="font-bold text-lg">Өрөөг боломжгүй болгох</h3>
            <button type="button" onclick="closeModal('#blackoutModal')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-[#272c38]"><span class="material-icons-outlined">close</span></button>
        </div>
        <div class="px-6 py-5 space-y-4">
            <p class="text-xs text-slate-500 dark:text-[#8b93a1] bg-slate-50 dark:bg-[#20242e] rounded-lg px-3 py-2">Засвар, цэвэрлэгээ г.м. Энэ хугацаанд захиалга авахгүй. Тухайн цагт байгаа захиалгууд цуцлагдаж, захиалагчдад мэдэгдэнэ.</p>
            <div><label class="<?= $lbl ?>">Өрөө</label><select id="boRoom" class="<?= $inp ?>"><?php foreach ($rooms as $rm): ?><option value="<?= (int)$rm['id'] ?>" <?= (int)$rm['id'] === $selectedRoomId ? 'selected' : '' ?>><?= htmlspecialchars($rm['name']) ?></option><?php endforeach; ?></select></div>
            <div><label class="<?= $lbl ?>">Огноо</label><input type="date" id="boDate" class="<?= $inp ?>"></div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="boAllDay" checked onchange="$('#boTimes').toggleClass('hidden', this.checked)"> Бүтэн өдөр</label>
            <div id="boTimes" class="hidden grid grid-cols-2 gap-3">
                <div><label class="<?= $lbl ?>">Эхлэх</label><input type="time" id="boStart" class="<?= $inp ?>"></div>
                <div><label class="<?= $lbl ?>">Дуусах</label><input type="time" id="boEnd" class="<?= $inp ?>"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="<?= $lbl ?>">Давтамж</label><select id="boRecurrence" class="<?= $inp ?>" onchange="$('#boRecEndWrap').toggleClass('hidden', this.value === 'none')"><?php foreach (MR_RECURRENCE_LABELS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div id="boRecEndWrap" class="hidden"><label class="<?= $lbl ?>">Давтамж дуусах</label><input type="date" id="boRecEnd" class="<?= $inp ?>"></div>
            </div>
            <div><label class="<?= $lbl ?>">Шалтгаан</label><input type="text" id="boReason" maxlength="255" placeholder="Жишээ: Засвар" class="<?= $inp ?>"></div>
        </div>
        <div class="px-6 py-4 border-t border-slate-100 dark:border-[#2a2f3b] flex justify-end gap-2">
            <button type="button" onclick="closeModal('#blackoutModal')" class="px-4 py-2 text-sm text-slate-500">Болих</button>
            <button type="button" onclick="submitBlackout(false)" class="bg-slate-800 dark:bg-[#272c38] text-white px-5 py-2 rounded-lg text-sm font-medium">Боломжгүй болгох</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var AJAX = '<?= BASE_URL ?>/modules/meetingroom/ajax.php';
var ROOM_ID = <?= $selectedRoomId ?>, WEEK_START = '<?= $weekStart->format('Y-m-d') ?>';
var IS_MANAGER = <?= $isManager ? 'true' : 'false' ?>;
var GRID_START = <?= MR_GRID_START_HOUR ?>, GRID_END = <?= MR_GRID_END_HOUR ?>, CELL_H = 40;
var REC_LABELS = <?= json_encode(MR_RECURRENCE_LABELS, JSON_UNESCAPED_UNICODE) ?>;
var attendees = [];

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function pad(n) { return String(n).padStart(2, '0'); }
function dateKey(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function parseDt(s) { var p = s.split(/[- :]/); return new Date(+p[0], p[1] - 1, +p[2], +p[3], +p[4]); } // локал цагаар
function hm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
function openModal(sel) { $(sel).removeClass('hidden').addClass('flex'); }
function closeModal(sel) { $(sel).addClass('hidden').removeClass('flex'); }
function post(data, ok) {
    $.post(AJAX, data, function (r) {
        if (r.need_confirm) { if (confirm(r.message)) ok && ok(r, true); return; }
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success && ok) ok(r, false);
    }, 'json').fail(function () { showToast('Сервертэй холбогдоход алдаа гарлаа.', 'error'); });
}

// ── Календарь ──
function placeBlock(dayKeyStr, startH, endH, el) {
    var col = document.getElementById('dayCol_' + dayKeyStr);
    startH = Math.max(startH, GRID_START); endH = Math.min(endH, GRID_END);
    if (!col || endH <= startH) return;
    el.style.top = ((startH - GRID_START) * CELL_H) + 'px';
    el.style.height = Math.max((endH - startH) * CELL_H - 2, 18) + 'px';
    col.appendChild(el);
}
function loadBookings() {
    if (!ROOM_ID) return;
    $.getJSON(AJAX, { action: 'get_bookings', room_id: ROOM_ID, week_start: WEEK_START }, function (r) {
        if (!r.success) return;
        $('[id^="dayCol_"]').empty();
        r.blackouts.forEach(function (bo) {
            var s = parseDt(bo.start_dt), e = parseDt(bo.end_dt);
            for (var d = new Date(s.getFullYear(), s.getMonth(), s.getDate()); d < e; d.setDate(d.getDate() + 1)) {
                var ds = new Date(Math.max(s, d)), next = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1), de = new Date(Math.min(e, next));
                var el = document.createElement('div');
                el.className = 'mr-blackout pointer-events-auto';
                el.title = bo.reason || 'Боломжгүй';
                el.onclick = function () { showBlackout(bo); };
                placeBlock(dateKey(d), ds.getHours() + ds.getMinutes() / 60, de.getTime() === next.getTime() ? 24 : de.getHours() + de.getMinutes() / 60, el);
            }
        });
        r.bookings.forEach(function (b) {
            var s = parseDt(b.start_dt), e = parseDt(b.end_dt);
            var el = document.createElement('div');
            el.className = 'mr-booking pointer-events-auto ' + b.status + (b.is_mine ? ' mine' : '');
            el.innerHTML = '<b>' + hm(s) + '–' + hm(e) + '</b><br>' + esc(b.title || b.requester);
            el.onclick = function (ev) { ev.stopPropagation(); showDetail(b); };
            placeBlock(dateKey(s), s.getHours() + s.getMinutes() / 60, e.getHours() + e.getMinutes() / 60, el);
        });
    });
}
function row(icon, html) { return '<div class="flex items-start gap-2.5"><span class="material-icons-outlined text-slate-400" style="font-size:18px">' + icon + '</span><span class="min-w-0 break-words">' + html + '</span></div>'; }
function showDetail(b) {
    var s = parseDt(b.start_dt), e = parseDt(b.end_dt);
    $('#detailTitle').text(b.title || 'Захиалга');
    $('#detailBody').html(
        row('schedule', esc(b.start_dt.slice(0, 10)) + ' · ' + hm(s) + '–' + hm(e) + (b.recurrence !== 'none' ? ' <span class="text-[#c9481f]">(' + esc(REC_LABELS[b.recurrence]) + ')</span>' : '')) +
        row('person', esc(b.requester) + (b.position ? ' <span class="text-slate-400">· ' + esc(b.position) + '</span>' : '')) +
        (b.description ? row('notes', esc(b.description)) : '') +
        (b.attendees.length ? row('group', esc(b.attendees.join(', '))) : '') +
        (b.guests || b.equipment ? row('info', [b.guests ? 'Гадны зочинтой' : '', b.equipment ? 'Тоног төхөөрөмж хэрэгтэй' : ''].filter(Boolean).join(' · ')) : '') +
        row(b.status === 'approved' ? 'check_circle' : 'hourglass_top', '<b class="' + (b.status === 'approved' ? 'text-green-600' : 'text-amber-600') + '">' + (b.status === 'approved' ? 'Баталгаажсан' : 'Хариуцагч батлахыг хүлээж байна') + '</b>')
    );
    var a = '', future = s > new Date();
    if (IS_MANAGER && b.status === 'pending') {
        a += '<button class="flex-1 py-2 rounded-lg bg-green-600 text-white text-sm font-medium" onclick="decide(\'approve_booking\',' + b.id + ',' + b.is_group + ')">Батлах</button>';
        a += '<button class="flex-1 py-2 rounded-lg border border-red-200 text-red-600 text-sm font-medium" onclick="decide(\'reject_booking\',' + b.id + ',' + b.is_group + ')">Татгалзах</button>';
    }
    if ((b.is_mine || IS_MANAGER) && future) a += '<button class="flex-1 py-2 rounded-lg border border-slate-200 dark:border-[#2a2f3b] text-sm" onclick="cancelBooking(' + b.id + ',' + b.is_group + ')">Цуцлах</button>';
    $('#detailActions').html(a);
    openModal('#detailModal');
}
function askScope(isGroup, verb) {
    if (!isGroup) return 'one';
    var all = confirm('Энэ нь давтамжтай захиалга.\n\nOK — давтамжийн бүх (ирээдүйн) захиалгыг ' + verb + '\nCancel — зөвхөн энэ удаагийнхыг');
    return all ? 'group' : 'one';
}
function decide(action, id, isGroup) {
    var scope = askScope(isGroup, action === 'approve_booking' ? 'батлах' : 'татгалзах'), reason = '';
    if (action === 'reject_booking') { reason = prompt('Татгалзах шалтгаан:'); if (reason === null) return; }
    post({ action: action, id: id, scope: scope, reason: reason }, function () { closeModal('#detailModal'); loadBookings(); });
}
function cancelBooking(id, isGroup) {
    var scope = askScope(isGroup, 'цуцлах');
    if (scope === 'one' && !confirm('Захиалгыг цуцлах уу?')) return;
    post({ action: 'cancel_booking', id: id, scope: scope }, function () { setTimeout(function () { location.reload(); }, 500); });
}
function showBlackout(bo) {
    var s = parseDt(bo.start_dt), e = parseDt(bo.end_dt);
    $('#detailTitle').text('Боломжгүй хугацаа');
    $('#detailBody').html(row('block', esc(bo.reason || 'Шалтгаан заагаагүй')) + row('schedule', esc(bo.start_dt.slice(0, 16)) + ' — ' + esc(bo.end_dt.slice(0, 16))) +
        (bo.recurrence !== 'none' ? row('repeat', esc(REC_LABELS[bo.recurrence]) + (bo.recurrence_end ? ' · ' + esc(bo.recurrence_end) + ' хүртэл' : '')) : ''));
    $('#detailActions').html(IS_MANAGER ? '<button class="flex-1 py-2 rounded-lg border border-red-200 text-red-600 text-sm font-medium" onclick="removeBlackout(' + bo.id + ',' + bo.is_group + ')">Хаалтыг цуцлах</button>' : '');
    openModal('#detailModal');
}
function removeBlackout(id, isGroup) {
    var scope = askScope(isGroup, 'цуцлах');
    if (scope === 'one' && !confirm('Энэ хаалтыг цуцлах уу?')) return;
    post({ action: 'remove_blackout', id: id, scope: scope }, function () { closeModal('#detailModal'); loadBookings(); });
}

// ── Захиалах ──
function openBookingModal(date, start, end) {
    var now = new Date();
    $('#bRoom').val(ROOM_ID);
    $('#bDate').val(date || dateKey(now));
    $('#bStart').val(start || pad(Math.min(Math.max(now.getHours() + 1, GRID_START), GRID_END - 1)) + ':00');
    $('#bEnd').val(end || pad(Math.min(Math.max(now.getHours() + 2, GRID_START + 1), GRID_END)) + ':00');
    openModal('#bookingModal');
    setTimeout(function () { $('#bTitle').focus(); }, 50);
}
function submitBooking() {
    var date = $('#bDate').val(), s = $('#bStart').val(), e = $('#bEnd').val();
    if (!$('#bTitle').val().trim()) { showToast('Хурлын гарчгаа оруулна уу.', 'error'); return; }
    if (!date || !s || !e) { showToast('Огноо, цагаа оруулна уу.', 'error'); return; }
    if (s >= e) { showToast('Дуусах цаг эхлэх цагаас хойш байх ёстой.', 'error'); return; }
    if ($('#bRecurrence').val() !== 'none' && !$('#bRecEnd').val()) { showToast('Давтамж дуусах огноог оруулна уу.', 'error'); return; }
    $('#bSubmit').prop('disabled', true);
    $.post(AJAX, {
        action: 'create_booking', room_id: $('#bRoom').val(), start_dt: date + ' ' + s, end_dt: date + ' ' + e,
        title: $('#bTitle').val().trim(), description: $('#bDesc').val().trim(),
        has_external_guests: $('#bGuests').is(':checked') ? 1 : 0, needs_equipment: $('#bEquip').is(':checked') ? 1 : 0,
        recurrence: $('#bRecurrence').val(), recurrence_end: $('#bRecEnd').val(), attendees: JSON.stringify(attendees)
    }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(function () { location.href = '?room=' + $('#bRoom').val() + '&date=' + date; }, 700);
        else $('#bSubmit').prop('disabled', false);
    }, 'json').fail(function () { $('#bSubmit').prop('disabled', false); showToast('Сервертэй холбогдоход алдаа гарлаа.', 'error'); });
}

// Оролцогчид
function renderAttendees() {
    $('#attList').html(attendees.map(function (a, i) {
        return '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-slate-100 dark:bg-[#272c38] text-xs">' + esc(a.name) +
            (a.employee_id ? '' : ' <span class="text-slate-400">(гадны)</span>') +
            '<button type="button" class="text-slate-400 hover:text-red-500" onclick="attendees.splice(' + i + ',1);renderAttendees()">×</button></span>';
    }).join(''));
}
var attTimer;
$('#attSearch').on('input', function () {
    clearTimeout(attTimer);
    var q = this.value.trim();
    if (q.length < 2) { $('#attSuggest').addClass('hidden'); return; }
    attTimer = setTimeout(function () {
        $.getJSON(AJAX, { action: 'search_employees', q: q }, function (r) {
            if (!r.results.length) { $('#attSuggest').addClass('hidden'); return; }
            $('#attSuggest').html(r.results.map(function (e) {
                return '<div class="att-opt px-3 py-2 text-sm cursor-pointer hover:bg-slate-50 dark:hover:bg-[#272c38]" data-id="' + e.id + '" data-name="' + esc(e.name) + '">' +
                    '<b>' + esc(e.name) + '</b> <span class="text-xs text-slate-400">' + esc(e.code) + ' · ' + esc(e.position) + '</span></div>';
            }).join('')).removeClass('hidden');
        });
    }, 250);
}).on('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var name = this.value.trim();
    if (name) { attendees.push({ name: name }); this.value = ''; $('#attSuggest').addClass('hidden'); renderAttendees(); }
});
$('#attSuggest').on('click', '.att-opt', function () {
    var id = +this.dataset.id;
    if (!attendees.some(function (a) { return a.employee_id === id; })) attendees.push({ employee_id: id, name: this.dataset.name });
    $('#attSearch').val(''); $('#attSuggest').addClass('hidden'); renderAttendees();
});

// ── Сул өрөө хайх ──
function searchAvailable() {
    var date = $('#sDate').val(), s = $('#sStart').val(), e = $('#sEnd').val();
    if (!date || !s || !e) { showToast('Огноо, цагаа оруулна уу.', 'error'); return; }
    $.getJSON(AJAX, { action: 'get_available_rooms', date: date, start_time: s, end_time: e, capacity: $('#sCap').val() }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        $('#clearSearchBtn').removeClass('hidden');
        $('#searchResults').removeClass('hidden').html(r.rooms.length
            ? '<p class="text-xs font-semibold text-green-600 mb-1">' + r.rooms.length + ' өрөө сул байна</p>' + r.rooms.map(function (rm) {
                return '<button type="button" class="w-full flex items-center gap-3 p-2.5 rounded-lg border border-green-200 dark:border-green-900/40 bg-green-50/60 dark:bg-green-950/20 text-left hover:bg-green-50" onclick="pickRoom(' + rm.id + ',\'' + date + '\',\'' + s + '\',\'' + e + '\')">' +
                    (rm.photo ? '<img src="' + esc(rm.photo) + '" class="w-12 h-9 object-cover rounded" alt="">' : '<span class="material-icons-outlined text-green-600">meeting_room</span>') +
                    '<span class="min-w-0"><b class="text-sm block truncate">' + esc(rm.name) + '</b><span class="text-xs text-slate-500">' + (rm.floor ? esc(rm.floor) + ' · ' : '') + rm.capacity + ' хүн</span></span></button>';
            }).join('')
            : '<p class="text-sm text-red-500">Энэ цагт сул өрөө алга.</p>');
    });
}
function pickRoom(id, date, s, e) { openBookingModal(date, s, e); $('#bRoom').val(id); }
function clearSearch() { $('#sDate,#sStart,#sEnd,#sCap').val(''); $('#searchResults').addClass('hidden').empty(); $('#clearSearchBtn').addClass('hidden'); }

// ── Боломжгүй цаг ──
function openBlackoutModal() { $('#boRoom').val(ROOM_ID); $('#boDate').val(dateKey(new Date())); $('#boReason').val(''); openModal('#blackoutModal'); }
function submitBlackout(force) {
    var date = $('#boDate').val(), allDay = $('#boAllDay').is(':checked'), startDt, endDt;
    if (!date) { showToast('Огноо сонгоно уу.', 'error'); return; }
    if (allDay) { var n = new Date(date + 'T00:00:00'); n.setDate(n.getDate() + 1); startDt = date + ' 00:00:00'; endDt = dateKey(n) + ' 00:00:00'; }
    else {
        var s = $('#boStart').val(), e = $('#boEnd').val();
        if (!s || !e || s >= e) { showToast('Цагаа зөв оруулна уу.', 'error'); return; }
        startDt = date + ' ' + s + ':00'; endDt = date + ' ' + e + ':00';
    }
    post({ action: 'add_blackout', room_id: $('#boRoom').val(), start_dt: startDt, end_dt: endDt, all_day: allDay ? 1 : 0,
           recurrence: $('#boRecurrence').val(), recurrence_end: $('#boRecEnd').val(), reason: $('#boReason').val().trim(), force: force ? 1 : 0 },
        function (r, needConfirm) { if (needConfirm) return submitBlackout(true); closeModal('#blackoutModal'); loadBookings(); });
}

// ── Нүдээр сонгох (дарах / чирэх) ──
var drag = null;
function paintDrag() {
    $('.cal-cell').removeClass('mr-sel');
    if (!drag) return;
    var lo = Math.min(drag.a, drag.b), hi = Math.max(drag.a, drag.b);
    $('.cal-cell[data-day="' + drag.day + '"]').filter(function () { var h = +this.dataset.hour; return h >= lo && h <= hi; }).addClass('mr-sel');
}
$(document).on('mousedown', '.cal-cell', function (e) {
    if (this.dataset.past === '1') return;
    e.preventDefault();
    drag = { day: this.dataset.day, a: +this.dataset.hour, b: +this.dataset.hour };
    paintDrag();
}).on('mouseover', '.cal-cell', function () {
    if (drag && this.dataset.day === drag.day && this.dataset.past !== '1') { drag.b = +this.dataset.hour; paintDrag(); }
}).on('mouseup', function () {
    if (!drag) return;
    var lo = Math.min(drag.a, drag.b), hi = Math.max(drag.a, drag.b), day = drag.day;
    drag = null; paintDrag();
    openBookingModal(day, pad(lo) + ':00', pad(Math.min(hi + 1, GRID_END)) + ':00');
});

$(function () {
    loadBookings();
    var h = new Date().getHours();
    $('#calScroll').scrollTop(Math.max(0, (h - GRID_START - 1) * CELL_H));
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
