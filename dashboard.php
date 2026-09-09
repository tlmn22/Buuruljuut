<?php
$pageTitle = 'Миний хяналтын самбар';
$activePage = 'dashboard';
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';

// ────────────────────────────────────────────────────────────────
// TEST ДАТА — эдгээрийг дараа нь бодит DB query-гээр солино.
// ────────────────────────────────────────────────────────────────
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Өглөөний мэнд' : ($hour < 18 ? 'Өдрийн мэнд' : 'Оройн мэнд');
$todayLabel = date('Y') . ' оны ' . (int)date('n') . '-р сарын ' . (int)date('j') . ', ' . [
    'Sunday'=>'Ням','Monday'=>'Даваа','Tuesday'=>'Мягмар','Wednesday'=>'Лхагва',
    'Thursday'=>'Пүрэв','Friday'=>'Баасан','Saturday'=>'Бямба'
][date('l')] . ' гараг';

$mockKpi = [
    'period'   => '2026 оны III улирал',
    'status'   => 'in_progress',
    'score'    => 87,
    'items'    => [
        ['label' => 'Гүйцэтгэлийн нэгж (KPI)', 'score' => 90],
        ['label' => 'Үндсэн үүрэг', 'score' => 84],
        ['label' => 'Нэмэлт даалгавар', 'score' => 88],
    ],
];

$mockLeave = ['annual_total' => 15, 'used' => 6, 'sick_used' => 2];
$mockAttendance = ['month_present' => 18, 'month_workdays' => 20, 'checked_in' => true, 'check_in_time' => '08:47'];
$mockRegsUnread = 4;

$mockRequests = [
    ['type' => 'Ээлжийн амралт', 'date' => '2026-08-18', 'range' => '2026.09.01 — 2026.09.05', 'status' => 'pending'],
    ['type' => 'Зардлын тайлан', 'date' => '2026-08-12', 'range' => '450,000₮', 'status' => 'approved'],
    ['type' => 'Эмнэлгийн чөлөө', 'date' => '2026-08-05', 'range' => '1 өдөр', 'status' => 'approved'],
];
$reqStatusMeta = [
    'pending'  => ['label' => 'Хүлээгдэж буй', 'cls' => 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400'],
    'approved' => ['label' => 'Батлагдсан',    'cls' => 'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400'],
    'rejected' => ['label' => 'Татгалзсан',    'cls' => 'bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400'],
];

$mockTrainings = [
    ['title' => 'Хөдөлмөрийн аюулгүй байдал', 'progress' => 100, 'due' => '2026-07-01', 'done' => true],
    ['title' => 'Мэдээллийн нууцлал ба аюулгүй байдал', 'progress' => 60,  'due' => '2026-09-10', 'done' => false],
    ['title' => 'Ёс зүйн дүрэм (Code of Conduct)', 'progress' => 0,   'due' => '2026-09-30', 'done' => false],
];

$mockAnnouncements = [
    ['title' => 'Улирлын ерөнхий цуглаан 8-р сарын 28-нд болно', 'time' => '2 цагийн өмнө', 'tag' => 'Мэдэгдэл'],
    ['title' => 'Шинэ дүрэм журам батлагдаж, танилцах шаардлагатай', 'time' => 'Өчигдөр', 'tag' => 'Дүрэм'],
    ['title' => 'OneFit spa захиалгын хугацаа сунгагдлаа', 'time' => '3 өдрийн өмнө', 'tag' => 'Benefits'],
];

$mockBirthdays = [
    ['name' => 'Б. Оюунчимэг', 'dept' => 'Санхүүгийн алба', 'date' => '08-25'],
    ['name' => 'Д. Ганбаатар', 'dept' => 'IT хэлтэс', 'date' => '08-27'],
    ['name' => 'Т. Сарангэрэл', 'dept' => 'Худалдан авалт', 'date' => '08-29'],
];

$mockQuickLinks = [
    ['label' => 'Ирц бүртгэх',   'icon' => 'schedule',        'href' => '#'],
    ['label' => 'Чөлөө хүсэх',   'icon' => 'event_available',  'href' => '#'],
    ['label' => 'Миний KPI',     'icon' => 'leaderboard',      'href' => BASE_URL . '/modules/kpi/index.php'],
    ['label' => 'Дүрэм журам',   'icon' => 'menu_book',        'href' => BASE_URL . '/modules/regulations/index.php'],
    ['label' => 'OneFit',        'icon' => 'fitness_center',   'href' => BASE_URL . '/modules/onefit/index.php'],
    ['label' => 'Профайл',       'icon' => 'person',           'href' => '#'],
];

$positionLabel = $_SESSION['user_position'] ?? roleLabel($user['role']);
?>

<!-- ─── Мэндчилгээ ─── -->
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl lg:text-3xl font-bold"><?= $greeting ?>, <?= htmlspecialchars(explode(' ', $user['name'])[0] ?: $user['name']) ?>!</h1>
        <p class="text-sm text-slate-500 dark:text-[#8b93a1] mt-1"><?= $todayLabel ?> · <?= htmlspecialchars($positionLabel) ?></p>
    </div>
    <div class="flex items-center gap-2 text-sm">
        <?php if ($mockAttendance['checked_in']): ?>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400 font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Ирц бүртгэгдсэн — <?= $mockAttendance['check_in_time'] ?>
            </span>
        <?php else: ?>
            <button onclick="mockAction(this,'Ирц бүртгэгдлээ ✅')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-[#f1592a] text-white font-medium hover:bg-[#c33e12] transition-colors">
                <span class="material-icons-outlined text-sm">schedule</span> Ирц бүртгэх
            </button>
        <?php endif; ?>
    </div>
</div>


<!-- ─── Стат карт ─── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl p-5">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-[#8b93a1]">KPI гүйцэтгэл</span>
            <span class="material-icons-outlined text-[#f1592a] text-lg">leaderboard</span>
        </div>
        <div class="mt-2 text-3xl font-bold"><?= $mockKpi['score'] ?>%</div>
        <div class="mt-3 h-1.5 bg-slate-100 dark:bg-[#272c38] rounded-full overflow-hidden">
            <div class="h-full bg-[#f1592a] rounded-full" style="width:<?= $mockKpi['score'] ?>%"></div>
        </div>
    </div>
    <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl p-5">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-[#8b93a1]">Амралтын үлдэгдэл</span>
            <span class="material-icons-outlined text-[#f1592a] text-lg">beach_access</span>
        </div>
        <div class="mt-2 text-3xl font-bold"><?= $mockLeave['annual_total'] - $mockLeave['used'] ?> <span class="text-base font-medium text-slate-400 dark:text-[#8b93a1]">хоног</span></div>
        <p class="mt-3 text-xs text-slate-400 dark:text-[#8b93a1]"><?= $mockLeave['used'] ?>/<?= $mockLeave['annual_total'] ?> ашигласан</p>
    </div>
    <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl p-5">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-[#8b93a1]">Энэ сарын ирц</span>
            <span class="material-icons-outlined text-[#f1592a] text-lg">event_available</span>
        </div>
        <div class="mt-2 text-3xl font-bold"><?= $mockAttendance['month_present'] ?>/<?= $mockAttendance['month_workdays'] ?></div>
        <p class="mt-3 text-xs text-slate-400 dark:text-[#8b93a1]">ажлын өдөр ирсэн</p>
    </div>
    <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl p-5">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-[#8b93a1]">Уншаагүй журам</span>
            <span class="material-icons-outlined text-[#f1592a] text-lg">menu_book</span>
        </div>
        <div class="mt-2 text-3xl font-bold"><?= $mockRegsUnread ?></div>
        <a href="<?= BASE_URL ?>/modules/regulations/index.php" class="mt-3 inline-block text-xs font-medium text-[#f1592a] hover:underline">Танилцах →</a>
    </div>
</div>

<!-- ─── Гол контент: 2 багана ─── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Зүүн багана -->
    <div class="lg:col-span-2 space-y-6">

        <!-- KPI карт -->
        <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="font-semibold text-lg">Миний KPI</h2>
                    <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-0.5"><?= htmlspecialchars($mockKpi['period']) ?></p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-blue-50 dark:bg-[#1c2a3d] text-[#f1592a]">Хэвлэлт хийгдэж байна</span>
            </div>
            <div class="space-y-3">
                <?php foreach ($mockKpi['items'] as $it): ?>
                <div>
                    <div class="flex items-center justify-between text-sm mb-1">
                        <span class="text-slate-600 dark:text-[#c9cdd6]"><?= htmlspecialchars($it['label']) ?></span>
                        <span class="font-semibold"><?= $it['score'] ?>%</span>
                    </div>
                    <div class="h-1.5 bg-slate-100 dark:bg-[#272c38] rounded-full overflow-hidden">
                        <div class="h-full bg-[#f1592a] rounded-full" style="width:<?= $it['score'] ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="<?= BASE_URL ?>/modules/kpi/index.php" class="mt-5 inline-flex items-center gap-1.5 text-sm font-medium text-[#f1592a] hover:underline">
                Дэлгэрэнгүй харах <span class="material-icons-outlined text-sm">arrow_forward</span>
            </a>
        </div>

        <!-- Миний хүсэлтүүд -->
        <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b]">
                <h2 class="font-semibold text-lg">Миний хүсэлтүүд</h2>
                <button onclick="mockAction(this,'Тест горим — удахгүй холбогдоно')" class="text-xs font-medium text-[#f1592a] hover:underline">+ Шинэ хүсэлт</button>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
                <?php foreach ($mockRequests as $r): $meta = $reqStatusMeta[$r['status']]; ?>
                <div class="flex items-center justify-between px-6 py-3.5">
                    <div>
                        <p class="text-sm font-medium"><?= htmlspecialchars($r['type']) ?></p>
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-0.5"><?= htmlspecialchars($r['range']) ?> · <?= htmlspecialchars($r['date']) ?></p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $meta['cls'] ?>"><?= $meta['label'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Заавал сургалт -->
        <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-6">
            <h2 class="font-semibold text-lg mb-4">Заавал сургалт</h2>
            <div class="space-y-4">
                <?php foreach ($mockTrainings as $t): ?>
                <div class="flex items-center gap-4">
                    <span class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 <?= $t['done'] ? 'bg-green-50 dark:bg-green-950/30 text-green-600 dark:text-green-400' : 'bg-orange-50 dark:bg-[#2a1f1a] text-[#f1592a]' ?>">
                        <span class="material-icons-outlined text-lg"><?= $t['done'] ? 'check_circle' : 'menu_book' ?></span>
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="font-medium truncate"><?= htmlspecialchars($t['title']) ?></span>
                            <span class="text-xs text-slate-400 dark:text-[#8b93a1] flex-shrink-0 ml-2"><?= $t['done'] ? 'Дууссан' : 'Дуусах: ' . $t['due'] ?></span>
                        </div>
                        <div class="h-1.5 bg-slate-100 dark:bg-[#272c38] rounded-full overflow-hidden">
                            <div class="h-full <?= $t['done'] ? 'bg-green-500' : 'bg-[#f1592a]' ?> rounded-full" style="width:<?= $t['progress'] ?>%"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- Баруун багана -->
    <div class="space-y-6">

        <!-- Мэдэгдэл -->
        <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-6">
            <h2 class="font-semibold text-lg mb-4">Мэдэгдэл</h2>
            <div class="space-y-4">
                <?php foreach ($mockAnnouncements as $a): ?>
                <div class="flex gap-3">
                    <span class="w-2 h-2 rounded-full bg-[#f1592a] mt-1.5 flex-shrink-0"></span>
                    <div class="min-w-0">
                        <p class="text-sm text-slate-700 dark:text-[#e5e7eb] leading-snug"><?= htmlspecialchars($a['title']) ?></p>
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1"><?= htmlspecialchars($a['tag']) ?> · <?= htmlspecialchars($a['time']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Төрсөн өдөр -->
        <div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-6">
            <h2 class="font-semibold text-lg mb-4">Энэ 7 хоногийн төрсөн өдөр</h2>
            <div class="space-y-3">
                <?php foreach ($mockBirthdays as $b): ?>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-[#f1592a] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                        <?= mb_substr($b['name'], 0, 1) ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium truncate"><?= htmlspecialchars($b['name']) ?></p>
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1] truncate"><?= htmlspecialchars($b['dept']) ?></p>
                    </div>
                    <span class="text-xs font-medium text-[#f1592a] flex-shrink-0"><?= $b['date'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Benefits -->
        <div class="bg-gradient-to-br from-[#0d1f3c] to-[#1a3a6b] rounded-xl p-6 text-white shadow-sm">
            <div class="flex items-center justify-between mb-3">
                <p class="text-[#00c9a7] text-xs font-semibold uppercase tracking-widest">Benefits</p>
                <span class="material-icons-outlined text-white/70 text-lg">fitness_center</span>
            </div>
            <p class="font-semibold mb-1">OneFit бүртгэл идэвхтэй</p>
            <p class="text-sm text-white/70 mb-4">Дуусах хугацаа: 2026.12.31</p>
            <a href="<?= BASE_URL ?>/modules/onefit/index.php" class="inline-flex items-center gap-1.5 text-sm font-medium bg-white/10 hover:bg-white/20 transition-colors px-4 py-2 rounded-lg">
                Дэлгэрэнгүй <span class="material-icons-outlined text-sm">arrow_forward</span>
            </a>
        </div>

    </div>
</div>

<script>
function mockAction(el, msg) {
    showToast(msg, 'success');
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
