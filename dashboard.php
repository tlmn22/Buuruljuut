<?php
$pageTitle = 'Миний хяналтын самбар';
$activePage = 'dashboard';
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/dashboard_data.php';

$pdo = getDB();
$me = (int) $user['id'];
$myOrg = isset($_SESSION['user_org_unit_id']) ? (int) $_SESSION['user_org_unit_id'] : null;

$profile = dashProfile($pdo, $me);
$kpi = dashMyKpi($pdo, $me);
$todos = dashTodos($pdo, $me, $kpi, $profile['employee_code'] ?? null);
$transport = dashMyTransport($pdo, $me);
$celebrate = dashCelebrations($pdo, $profile['root_id'] ?? null, $me);
$hr = isHR() ? dashHrOverview($pdo) : null;
$unreadNews = dashUnreadNews($pdo, $me, $myOrg);
$ackStmt = $pdo->prepare("SELECT COUNT(*) FROM regulations r WHERE r.is_active = 1 AND r.require_ack = 1
    AND NOT EXISTS (SELECT 1 FROM regulation_reads rr WHERE rr.regulation_id = r.id AND rr.employee_id = ?)");
$ackStmt->execute([$me]);
$ackRegs = (int) $ackStmt->fetchColumn();

// Сүүлийн мэдээ мэдээлэл (ажилтанд харагдах, нийтлэгдсэн)
[$newsWhere, $newsParams] = newsVisibilityWhere($pdo, $me, $myOrg);
$newsStmt = $pdo->prepare("SELECT p.id, p.title, p.category, p.published_at,
        EXISTS (SELECT 1 FROM news_reads r WHERE r.post_id = p.id AND r.employee_id = ?) AS is_read
    FROM news_posts p WHERE $newsWhere AND p.status = 'published' AND p.published_at <= NOW()
    ORDER BY p.is_pinned DESC, p.published_at DESC LIMIT 4");
$newsStmt->execute(array_merge([$me], $newsParams));
$latestNews = $newsStmt->fetchAll();

$hour = (int) date('H');
$greeting = $hour < 12 ? 'Өглөөний мэнд' : ($hour < 18 ? 'Өдрийн мэнд' : 'Оройн мэнд');
$todayLabel = (int) date('n') . '-р сарын ' . (int) date('j') . ', ' . [
    'Sunday' => 'Ням',
    'Monday' => 'Даваа',
    'Tuesday' => 'Мягмар',
    'Wednesday' => 'Лхагва',
    'Thursday' => 'Пүрэв',
    'Friday' => 'Баасан',
    'Saturday' => 'Бямба',
][date('l')] . ' гараг';
$firstName = $profile['first_name'] ?? $user['name'];

$kpiCur = $kpi['current'];
$kpiScore = $kpi['last'] ? number_format($kpi['last']['final_total_score'] * 100, 1) : null;

$card = 'bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm';
function dashAvatar(array $p, string $size = 'w-9 h-9'): string
{
    if (!empty($p['photo']) && file_exists(UPLOAD_PATH . $p['photo']))
        return '<img src="' . UPLOAD_URL . htmlspecialchars($p['photo']) . '" class="' . $size . ' rounded-full object-cover flex-shrink-0" alt="">';
    return '<div class="' . $size . ' rounded-full bg-[#f1592a] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">' . htmlspecialchars(mb_substr($p['name'] ?? $p['last_name'] ?? '?', 0, 1)) . '</div>';
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/kpi2/assets/kpi2-status.css?v=5">

<!-- ─── Мэндчилгээ ─── -->
<div>
    <h1 class="text-2xl lg:text-3xl font-bold"><?= $greeting ?>, <?= htmlspecialchars($firstName) ?>!</h1>
    <p class="text-sm text-slate-500 dark:text-[#8b93a1] mt-1">
        <?= htmlspecialchars($profile['position'] ?? '') ?>
        <?php if (!empty($profile['unit_name'])): ?> · <?= htmlspecialchars($profile['unit_name']) ?><?php endif; ?>
        <?php if ($profile['tenure']): ?> · Ажилласан <?= $profile['tenure'] ?><?php endif; ?>
        · <?= $todayLabel ?>
    </p>
</div>

<!-- ─── Стат карт ─── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <?php
    $stats = [
        ['notifications', 'Шинэ мэдэгдэл', $notifUnread, 'Харах', 'javascript:void(0)', 'onclick="toggleNotif(event)"'],
        ['campaign', 'Уншаагүй мэдээ', $unreadNews, 'Унших', BASE_URL . '/modules/news/index.php', ''],
        ['menu_book', 'Танилцах журам', $ackRegs, 'Танилцах', BASE_URL . '/modules/regulations/index.php', ''],
        ['leaderboard', $kpiScore !== null ? 'KPI · ' . $kpi['last']['name'] : 'KPI оноо', $kpiScore !== null ? $kpiScore . '%' : '—', 'Миний KPI', BASE_URL . '/modules/kpi2/index.php', ''],
    ];
    foreach ($stats as [$icon, $label, $value, $cta, $href, $attr]): ?>
        <a href="<?= $href ?>" <?= $attr ?> class="<?= $card ?> p-5 hover:border-[#f1592a]/40 transition-colors block">
            <div class="flex items-center justify-between gap-2">
                <span
                    class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-[#8b93a1] truncate"><?= htmlspecialchars($label) ?></span>
                <span class="material-icons-outlined text-[#f1592a] text-lg"><?= $icon ?></span>
            </div>
            <div class="mt-2 text-3xl font-bold <?= $value === 0 ? 'text-slate-300 dark:text-[#3a4151]' : '' ?>">
                <?= $value ?>
            </div>
            <span class="mt-2 inline-block text-xs font-medium text-[#f1592a]"><?= $cta ?> →</span>
        </a>
    <?php endforeach; ?>
</div>

<!-- ─── Гол контент: 2 багана ─── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Зүүн багана -->
    <div class="lg:col-span-2 space-y-6">

        <!-- Таны хийх зүйлс -->
        <div class="<?= $card ?> overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b]">
                <h2 class="font-semibold text-lg">Таны хийх зүйлс</h2>
                <?php $actionCount = count(array_filter($todos, function ($t) {
                    return $t['tone'] === 'action';
                })); ?>
                <?php if ($actionCount): ?><span
                        class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[#f1592a] text-white"><?= $actionCount ?></span><?php endif; ?>
            </div>
            <?php if (!$todos): ?>
                <div class="px-6 py-10 text-center">
                    <span class="material-icons-outlined text-green-500" style="font-size:40px">task_alt</span>
                    <p class="text-sm text-slate-500 dark:text-[#8b93a1] mt-2">Хийх шаардлагатай зүйл алга. Сайхан өдөр
                        өнгөрүүлээрэй!</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
                    <?php foreach ($todos as $t): ?>
                        <a href="<?= htmlspecialchars($t['link']) ?>"
                            class="flex items-center gap-4 px-6 py-3.5 hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors group">
                            <span
                                class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?= $t['tone'] === 'action' ? 'bg-orange-50 dark:bg-[#2a1810] text-[#f1592a]' : 'bg-blue-50 dark:bg-[#1c2a3d] text-blue-600 dark:text-[#6ea8fe]' ?>">
                                <span class="material-icons-outlined" style="font-size:20px"><?= $t['icon'] ?></span>
                            </span>
                            <span class="flex-1 min-w-0">
                                <span
                                    class="block text-sm font-medium group-hover:text-[#f1592a]"><?= htmlspecialchars($t['text']) ?></span>
                                <?php if ($t['sub']): ?><span
                                        class="block text-xs text-slate-400 dark:text-[#8b93a1] truncate"><?= htmlspecialchars($t['sub']) ?></span><?php endif; ?>
                            </span>
                            <span
                                class="material-icons-outlined text-slate-300 dark:text-[#3a4151] group-hover:text-[#f1592a]">chevron_right</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Миний KPI -->
        <div class="<?= $card ?> p-6">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-semibold text-lg">Миний KPI</h2>
                    <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-0.5">
                        <?= $kpiCur ? htmlspecialchars($kpiCur['period_name']) : 'Идэвхтэй үнэлгээний хугацаа алга' ?>
                    </p>
                </div>
                <a href="<?= BASE_URL ?>/modules/kpi2/<?= $kpiCur && $kpiCur['eval_id'] ? 'sheet.php?id=' . (int) $kpiCur['eval_id'] : 'index.php' ?>"
                    class="text-sm font-medium text-[#f1592a] hover:underline flex items-center gap-1">Нээх <span
                        class="material-icons-outlined text-sm">arrow_forward</span></a>
            </div>
            <?php if ($kpiCur): ?>
                <div class="kpi-root" style="gap:12px">
                    <div class="flex items-center gap-4 flex-wrap" id="dashKpiState"
                        data-state="<?= htmlspecialchars($kpiCur['state']) ?>"></div>
                    <p class="text-sm text-slate-500 dark:text-[#8b93a1] m-0">
                        <?php switch ($kpiCur['state']) {
                            case 'none':
                                echo 'Хэлтсийн ажлын төлөвлөгөө батлагдсаны дараа таны KPI хуудас үүснэ.';
                                break;
                            case 'plan':
                                echo 'Төлөвлөгөөгөө бөглөж шууд удирдлагадаа илгээнэ үү.';
                                break;
                            case 'approve':
                                echo 'Шууд удирдлага батлахыг хүлээж байна' . ($kpiCur['ev_last'] ? ' — ' . htmlspecialchars(employeeName($kpiCur['ev_last'], $kpiCur['ev_first'])) : '') . '.';
                                break;
                            case 'self-eval':
                                echo 'Төлөвлөгөө батлагдсан. Хугацааны төгсгөлд өөрийн үнэлгээгээ хийнэ үү.';
                                break;
                            case 'mgr-eval':
                                echo 'Удирдлагын үнэлгээг хүлээж байна' . ($kpiCur['ev_last'] ? ' — ' . htmlspecialchars(employeeName($kpiCur['ev_last'], $kpiCur['ev_first'])) : '') . '.';
                                break;
                            case 'done':
                                echo 'Энэ хугацааны үнэлгээ дууссан.';
                                break;
                        } ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Миний тээврийн хүсэлтүүд -->
        <div class="<?= $card ?> overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b]">
                <h2 class="font-semibold text-lg">Миний тээврийн хүсэлтүүд</h2>
                <a href="<?= BASE_URL ?>/modules/transport/form.php"
                    class="text-xs font-medium text-[#f1592a] hover:underline">+ Шинэ хүсэлт</a>
            </div>
            <?php if (!$transport): ?>
                <p class="px-6 py-8 text-center text-sm text-slate-400 dark:text-[#5a6172]">Тээврийн хүсэлт гаргаагүй байна.
                </p>
            <?php else: ?>
                <div class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
                    <?php foreach ($transport as $r):
                        $merged = $r['status'] === 'merged' && $r['order_status'];
                        $label = $merged ? TRANSPORT_ORDER_STATUS_LABELS[$r['order_status']] : TRANSPORT_STATUS_LABELS[$r['status']];
                        $cls = $merged ? TRANSPORT_ORDER_STATUS_COLORS[$r['order_status']] : TRANSPORT_STATUS_COLORS[$r['status']]; ?>
                        <a href="<?= BASE_URL ?>/modules/transport/form.php?id=<?= (int) $r['id'] ?>&view=1"
                            class="flex items-center justify-between gap-3 px-6 py-3.5 hover:bg-slate-50 dark:hover:bg-[#20242e]">
                            <div class="min-w-0">
                                <p class="text-sm font-medium truncate"><?= htmlspecialchars($r['travel_direction']) ?></p>
                                <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-0.5">
                                    <?= htmlspecialchars($r['start_date']) ?> — <?= htmlspecialchars($r['end_date']) ?>
                                    <?php if ($r['depart_at']): ?> · Хөдлөх:
                                        <?= htmlspecialchars(substr($r['depart_at'], 0, 16)) ?>
                                        <?= $r['depart_location'] ? ', ' . htmlspecialchars($r['depart_location']) : '' ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span
                                class="px-2.5 py-1 rounded-full text-xs font-medium flex-shrink-0 <?= $cls ?>"><?= htmlspecialchars($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($hr): ?>
            <!-- HR: компанийн тойм -->
            <div class="<?= $card ?> p-6">
                <h2 class="font-semibold text-lg mb-4">Компанийн тойм</h2>
                <div class="grid grid-cols-3 gap-3 mb-5">
                    <div class="rounded-lg bg-slate-50 dark:bg-[#20242e] p-3">
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1]">Идэвхтэй ажилтан</p>
                        <p class="text-2xl font-bold"><?= $hr['headcount'] ?></p>
                    </div>
                    <div class="rounded-lg bg-slate-50 dark:bg-[#20242e] p-3">
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1]">Энэ сард орсон</p>
                        <p class="text-2xl font-bold"><?= $hr['new_month'] ?></p>
                    </div>
                    <div class="rounded-lg bg-slate-50 dark:bg-[#20242e] p-3">
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1]">Системд идэвхжсэн</p>
                        <p class="text-2xl font-bold"><?= $hr['activated'] ?><span
                                class="text-sm font-medium text-slate-400"> / <?= $hr['logins'] ?></span></p>
                    </div>
                </div>
                <div class="space-y-2.5">
                    <?php $max = max($hr['by_root'] ?: [1]);
                    foreach ($hr['by_root'] as $name => $n): ?>
                        <div>
                            <div class="flex justify-between text-sm mb-1"><span
                                    class="text-slate-600 dark:text-[#c9cdd6] truncate pr-3"><?= htmlspecialchars($name) ?></span><span
                                    class="font-semibold"><?= $n ?></span></div>
                            <div class="h-1.5 bg-slate-100 dark:bg-[#272c38] rounded-full overflow-hidden">
                                <div class="h-full bg-[#f1592a] rounded-full" style="width:<?= round($n / $max * 100) ?>%">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Баруун багана -->
    <div class="space-y-6">

        <!-- Мэдээ мэдээлэл -->
        <div class="<?= $card ?> p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold text-lg">Мэдээ мэдээлэл</h2>
                <a href="<?= BASE_URL ?>/modules/news/index.php"
                    class="text-sm font-medium text-[#f1592a] hover:underline">Бүгд</a>
            </div>
            <div class="space-y-4">
                <?php if (!$latestNews): ?>
                    <p class="text-sm text-slate-400 dark:text-[#5a6172]">Одоогоор мэдээ байхгүй.</p>
                <?php endif; ?>
                <?php foreach ($latestNews as $a): ?>
                    <a href="<?= BASE_URL ?>/modules/news/post.php?id=<?= (int) $a['id'] ?>" class="flex gap-3 group">
                        <span
                            class="w-2 h-2 rounded-full mt-1.5 flex-shrink-0 <?= $a['is_read'] ? 'bg-slate-200 dark:bg-[#2a2f3b]' : 'bg-[#f1592a]' ?>"></span>
                        <div class="min-w-0">
                            <p
                                class="text-sm text-slate-700 dark:text-[#e5e7eb] leading-snug group-hover:text-[#f1592a] <?= $a['is_read'] ? '' : 'font-semibold' ?>">
                                <?= htmlspecialchars($a['title']) ?>
                            </p>
                            <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1">
                                <?= (NEWS_CATEGORIES[$a['category']] ?? NEWS_CATEGORIES['general'])['label'] ?> ·
                                <?= timeAgoMn($a['published_at']) ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Төрсөн өдөр -->
        <?php $bds = $celebrate['birthdays']; ?>
        <div class="<?= $card ?> p-6">
            <h2 class="font-semibold text-lg mb-4 flex items-center gap-2">🎂 Төрсөн өдөр</h2>
            <?php if (!$bds): ?>
                <p class="text-sm text-slate-400 dark:text-[#5a6172]">Ойрын <?= DASH_BIRTHDAY_DAYS ?> хоногт төрсөн өдөр
                    байхгүй.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($bds as $i => $b): ?>
                        <?php if ($i === 6): ?>
                            <details class="space-y-3">
                                <summary class="text-xs font-semibold text-[#f1592a] cursor-pointer list-none">+ Бусад
                                    <?= count($bds) - 6 ?> хүн
                                </summary><?php endif; ?>
                            <div class="flex items-center gap-3 <?= $i >= 6 ? 'mt-3' : '' ?>">
                                <?= dashAvatar($b) ?>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium truncate">
                                        <?= htmlspecialchars($b['name']) ?>         <?= $b['id'] === $me ? ' 🎉' : '' ?>
                                    </p>
                                    <p class="text-xs text-slate-400 dark:text-[#8b93a1] truncate">
                                        <?= htmlspecialchars($b['unit']) ?>
                                    </p>
                                </div>
                                <span
                                    class="text-xs font-medium flex-shrink-0 <?= $b['days'] === 0 ? 'text-white bg-[#f1592a] px-2 py-0.5 rounded-full' : 'text-[#f1592a]' ?>"><?= dashWhenLabel($b['days']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($bds) > 6): ?>
                        </details><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Ажлын ой / шинэ ажилтнууд -->
        <?php $anns = array_slice($celebrate['anniversaries'], 0, 5);
        $news = array_slice($celebrate['newcomers'], 0, 5); ?>
        <?php if ($anns || $news): ?>
            <div class="<?= $card ?> p-6 space-y-5">
                <?php if ($anns): ?>
                    <div>
                        <h2 class="font-semibold text-lg mb-3 flex items-center gap-2">🎉 Ажлын ой <span
                                class="text-xs font-normal text-slate-400"></span></h2>
                        <div class="space-y-3">
                            <?php foreach ($anns as $a): ?>
                                <div class="flex items-center gap-3">
                                    <?= dashAvatar($a) ?>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium truncate"><?= htmlspecialchars($a['name']) ?></p>
                                        <p class="text-xs text-slate-400 dark:text-[#8b93a1] truncate">
                                            <?= htmlspecialchars($a['unit']) ?>
                                        </p>
                                    </div>
                                    <span class="text-xs font-semibold text-[#f1592a] flex-shrink-0 text-right"><?= $a['years'] ?>
                                        жил<br><span
                                            class="font-normal text-slate-400"><?= dashWhenLabel($a['days']) ?></span></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($news): ?>
                    <div>
                        <h2 class="font-semibold text-lg mb-3 flex items-center gap-2">👋 Шинэ ажилтнууд <span
                                class="text-xs font-normal text-slate-400">сүүлийн <?= DASH_NEWCOMER_DAYS ?> хоногт</span></h2>
                        <div class="space-y-3">
                            <?php foreach ($news as $n): ?>
                                <div class="flex items-center gap-3">
                                    <?= dashAvatar($n) ?>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium truncate"><?= htmlspecialchars($n['name']) ?></p>
                                        <p class="text-xs text-slate-400 dark:text-[#8b93a1] truncate">
                                            <?= htmlspecialchars($n['position'] ?? '') ?> · <?= htmlspecialchars($n['unit']) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Миний баг -->
        <div class="<?= $card ?> p-6">
            <h2 class="font-semibold text-lg mb-4">Миний баг</h2>
            <?php if ($profile['manager']):
                $m = $profile['manager']; ?>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-[#8b93a1] mb-2">Шууд
                    удирдлага</p>
                <div class="flex items-center gap-3 mb-4">
                    <?= dashAvatar($m, 'w-10 h-10') ?>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold truncate">
                            <?= htmlspecialchars(employeeName($m['last_name'], $m['first_name'])) ?>
                        </p>
                        <p class="text-xs text-slate-400 dark:text-[#8b93a1] truncate">
                            <?= htmlspecialchars($m['position'] ?? '') ?>
                        </p>
                    </div>
                    <?php if ($m['phone']): ?><a
                            href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $m['phone'])) ?>"
                            class="p-2 rounded-lg text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38]"
                            title="<?= htmlspecialchars($m['phone']) ?>"><span class="material-icons-outlined"
                                style="font-size:18px">call</span></a><?php endif; ?>
                    <?php if ($m['email']): ?><a href="mailto:<?= htmlspecialchars($m['email']) ?>"
                            class="p-2 rounded-lg text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38]"
                            title="<?= htmlspecialchars($m['email']) ?>"><span class="material-icons-outlined"
                                style="font-size:18px">mail</span></a><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="flex items-center justify-between text-sm">
                <span
                    class="text-slate-500 dark:text-[#8b93a1]"><?= htmlspecialchars($profile['unit_name'] ?? 'Нэгж тодорхойгүй') ?></span>
                <span class="font-semibold"><?= (int) $profile['team_count'] ?> хамт олон</span>
            </div>
            <?php if (!empty($profile['root_name'])): ?>
                <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1"><?= htmlspecialchars($profile['root_name']) ?>
                </p><?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/modules/kpi2/assets/kpi2-status.js?v=3"></script>
<script>
    (function () {
        var el = document.getElementById('dashKpiState');
        if (el && window.KPI2) el.innerHTML = KPI2.stepper(el.dataset.state) + KPI2.chip(el.dataset.state);
    })();
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>