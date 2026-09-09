<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/kpi2/evaluator.php';
requireLogin();
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="mn">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' | ' : '' ?>Buuruljuut Intranet</title>

    <link rel="icon" href="https://www.buuruljuut.mn/favicon.ico?favicon.04c18f48.ico" type="image/x-icon" />

    <!-- PWA -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json?v=<?= time() ?>" />
    <meta name="theme-color" content="#f1592a" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <meta name="apple-mobile-web-app-title" content="Intranet" />
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/icon-192.png" />

    <!-- Өдөр/шөнө горим — Tailwind CSS-ээс өмнө: горим сонголтыг эрт тавьж flash-аас сэргийлнэ -->
    <script>
        (function () {
            var saved = localStorage.getItem('theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        #mobileOverlay { display: none; }
        #mobileSidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
        #mobileSidebar.open { transform: translateX(0); }
        #mobileOverlay.open { display: block; }
    </style>
</head>

<?php
// Sidebar-д хэрэгтэй хувьсагчдыг урьдчилан тодорхойлно
$ap = $activePage ?? '';
$empPhoto = null;
if (isset($_SESSION['user_id'])) {
    $stm = getDB()->prepare("SELECT photo FROM employees WHERE id=? LIMIT 1");
    $stm->execute([$_SESSION['user_id']]);
    $empPhoto = $stm->fetchColumn();
}
function ac(string $page): string { global $ap; return $ap === $page ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white'; }
function inGroup(array $pages): bool { global $ap; return in_array($ap, $pages); }
?>
<body class="bg-[#f6f6f8] text-[#1c2430] dark:bg-[#14171f] dark:text-[#f2f3f6] transition-colors">

<!-- Mobile overlay -->
<div id="mobileOverlay" class="fixed inset-0 bg-black/50 z-40 lg:hidden" onclick="closeMobileSidebar()"></div>

<!-- Mobile sidebar -->
<aside id="mobileSidebar" class="fixed top-0 left-0 h-full w-72 bg-[#1e222c] z-50 flex flex-col lg:hidden shadow-2xl overflow-y-auto">
    <?php include __DIR__ . '/sidebar_nav.php'; ?>
</aside>

<div class="flex min-h-screen">

        <!-- Desktop sidebar -->
        <aside class="w-64 bg-[#1e222c] hidden lg:flex flex-col sticky top-0 h-screen">
            <?php
            // sidebar_nav.php-г шууд дуудахгүй, агуулгыг inline гаргана
            ?>
            <div class="p-5 flex items-center justify-center border-b border-[#2a2f3b] flex-shrink-0">
                <img src="<?= BASE_URL ?>/assets/icons/logo.png" alt="Buuruljuut" class="h-12 w-48 object-contain" />
            </div>
            <nav class="flex-1 px-4 space-y-1 py-4 overflow-y-auto min-h-0">
                <a href="<?= BASE_URL ?>/dashboard.php" class="flex items-center gap-3 px-4 py-3 <?= ac('dashboard') ?> rounded-lg transition-colors">
                    <span class="material-icons-outlined">home</span>Миний самбар
                </a>
                <?php $regOpen = inGroup(['regulations','regulations-admin']); ?>
                <div class="menu-group">
                    <button onclick="toggleGroup(this)" class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $regOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
                        <div class="flex items-center gap-3"><span class="material-icons-outlined">menu_book</span><span>Дүрэм журам</span></div>
                        <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $regOpen ? 'rotate-180' : '' ?>">expand_more</span>
                    </button>
                    <div class="accordion-items pl-4 mt-1 space-y-1 <?= $regOpen ? '' : 'hidden' ?>">
                        <a href="<?= BASE_URL ?>/modules/regulations/index.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('regulations') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">library_books</span>Жагсаалт
                        </a>
                        <?php if (hasPermission('regulations.manage')): ?>
                        <a href="<?= BASE_URL ?>/modules/regulations/admin.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('regulations-admin') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">admin_panel_settings</span>Удирдах
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $benefitsOpen = inGroup(['onefit','onefit-admin']); ?>
                <div class="menu-group">
                    <button onclick="toggleGroup(this)" class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $benefitsOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
                        <div class="flex items-center gap-3"><span class="material-icons-outlined">card_giftcard</span><span>Benefits</span></div>
                        <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $benefitsOpen ? 'rotate-180' : '' ?>">expand_more</span>
                    </button>
                    <div class="accordion-items pl-4 mt-1 space-y-1 <?= $benefitsOpen ? '' : 'hidden' ?>">
                        <a href="<?= BASE_URL ?>/modules/onefit/index.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('onefit') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">fitness_center</span>OneFit
                        </a>
                        <?php if(isHR() || isSuperAdmin()): ?>
                        <a href="<?= BASE_URL ?>/modules/onefit/admin.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('onefit-admin') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">admin_panel_settings</span>OneFit Admin
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $selfOpen = inGroup(['self-service']); ?>
                <div class="menu-group">
                    <button onclick="toggleGroup(this)" class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $selfOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
                        <div class="flex items-center gap-3"><span class="material-icons-outlined">manage_accounts</span><span>Self Service</span></div>
                        <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $selfOpen ? 'rotate-180' : '' ?>">expand_more</span>
                    </button>
                    <div class="accordion-items pl-4 mt-1 space-y-1 <?= $selfOpen ? '' : 'hidden' ?>">
                        <div class="flex items-center gap-3 px-4 py-2.5 text-[#5a6172] rounded-lg text-sm cursor-default"><span class="material-icons-outlined text-base">schedule</span>Удахгүй...</div>
                    </div>
                </div>
                <?php $kpiOpen = inGroup(['kpi','kpi-team','kpi-admin','kpi-report']); ?>
                <div class="menu-group">
                    <button onclick="toggleGroup(this)" class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $kpiOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
                        <div class="flex items-center gap-3"><span class="material-icons-outlined">leaderboard</span><span>KPI</span></div>
                        <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $kpiOpen ? 'rotate-180' : '' ?>">expand_more</span>
                    </button>
                    <div class="accordion-items pl-4 mt-1 space-y-1 <?= $kpiOpen ? '' : 'hidden' ?>">
                        <a href="<?= BASE_URL ?>/modules/kpi/index.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">assignment_ind</span>Миний KPI
                        </a>
                        <?php if (hasPermission('kpi.approve') || hasPermission('kpi.evaluate') || hasPermission('kpi.view_all')): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi/team.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi-team') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">groups</span>Багийн KPI
                        </a>
                        <?php endif; ?>
                        <?php if (hasPermission('kpi.view_all')): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi/report.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi-report') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">summarize</span>Тайлан нэгтгэл
                        </a>
                        <?php endif; ?>
                        <?php if (hasPermission('kpi.manage_periods') || hasPermission('kpi.manage_evaluators')): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi/admin.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi-admin') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">tune</span>KPI тохиргоо
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                $myEmpIdForKpi2 = (int)($_SESSION['user_id'] ?? 0);
                $kpi2IsMgr = $myEmpIdForKpi2 ? kpi2IsManager(getDB(), $myEmpIdForKpi2) : false;
                $kpi2IsDir = $myEmpIdForKpi2 ? kpi2IsDirector(getDB(), $myEmpIdForKpi2) : false;
                $kpi2Open = inGroup(['kpi2','kpi2-team','kpi2-manager','kpi2-approvals','kpi2-admin']);
                ?>
                <div class="menu-group">
                    <button onclick="toggleGroup(this)" class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $kpi2Open ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
                        <div class="flex items-center gap-3"><span class="material-icons-outlined">insights</span><span>KPI2 <span class="text-[10px] opacity-70">(туршилт)</span></span></div>
                        <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $kpi2Open ? 'rotate-180' : '' ?>">expand_more</span>
                    </button>
                    <div class="accordion-items pl-4 mt-1 space-y-1 <?= $kpi2Open ? '' : 'hidden' ?>">
                        <a href="<?= BASE_URL ?>/modules/kpi2/index.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">assignment_ind</span>Миний KPI2
                        </a>
                        <?php if ($kpi2IsMgr || isHR() || isSuperAdmin()): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi2/manager.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-manager') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">edit_calendar</span>Хэлтсийн ажлаа төлөвлөх
                        </a>
                        <?php endif; ?>
                        <?php if ($kpi2IsDir || isHR() || isSuperAdmin()): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi2/approvals.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-approvals') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">fact_check</span>KPI батлах хүсэлтүүд
                        </a>
                        <?php endif; ?>
                        <?php if ($kpi2IsMgr || $kpi2IsDir || isHR() || isSuperAdmin()): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi2/team.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-team') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">groups</span>Багийн KPI2
                        </a>
                        <?php endif; ?>
                        <?php if (isHR() || isSuperAdmin()): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi2/admin.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-admin') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">tune</span>KPI2 тохиргоо
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (isSuperAdmin()): ?>
                <?php $adminOpen = inGroup(['org_units','employees']); ?>
                <div class="menu-group">
                    <button onclick="toggleGroup(this)" class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $adminOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
                        <div class="flex items-center gap-3"><span class="material-icons-outlined">admin_panel_settings</span><span>Удирдлага</span></div>
                        <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $adminOpen ? 'rotate-180' : '' ?>">expand_more</span>
                    </button>
                    <div class="accordion-items pl-4 mt-1 space-y-1 <?= $adminOpen ? '' : 'hidden' ?>">
                        <a href="<?= BASE_URL ?>/modules/org_units/index.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('org_units') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">account_tree</span>Алба, хэлтэс
                        </a>
                        <a href="<?= BASE_URL ?>/modules/employees/index.php" class="flex items-center gap-3 px-4 py-2.5 <?= ac('employees') ?> rounded-lg transition-colors text-sm">
                            <span class="material-icons-outlined text-base">groups</span>Ажилтны бүх бүртгэл
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </nav>
            <div class="p-4 border-t border-[#2a2f3b] flex-shrink-0">
                <div class="flex items-center gap-3 mb-3">
                    <?php if ($empPhoto && file_exists(UPLOAD_PATH . $empPhoto)): ?>
                        <img src="<?= UPLOAD_URL . htmlspecialchars($empPhoto) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-[#2a2f3b] flex-shrink-0" />
                    <?php else: ?>
                        <div class="w-9 h-9 rounded-full bg-[#f1592a] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                            <?= mb_substr($user['name'], 0, 1) ?>
                        </div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-[#f2f3f6] truncate"><?= htmlspecialchars($user['name']) ?></p>
                        <p class="text-xs text-[#8b93a1]"><?= htmlspecialchars($_SESSION['user_position'] ?? roleLabel($user['role'])) ?></p>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/logout.php" class="w-full flex items-center justify-center gap-2 py-2 bg-[#272c38] text-[#c9cdd6] hover:bg-[#313745] hover:text-white rounded-lg text-sm font-medium transition-colors">
                    <span class="material-icons-outlined" style="font-size:16px">logout</span> Гарах
                </a>
            </div>
        </aside>

        <main class="flex-1 flex flex-col min-w-0">
            <header
                class="h-16 bg-[#1e222c] lg:bg-white lg:dark:bg-[#1c212b] border-b border-[#2a2f3b] lg:border-slate-200 lg:dark:border-[#2a2f3b] flex items-center justify-between px-4 lg:px-8 sticky top-0 z-10">
                <div class="flex items-center gap-3 flex-1">
                    <!-- Hamburger (mobile) -->
                    <button onclick="openMobileSidebar()" class="lg:hidden p-2 text-white hover:bg-white/10 rounded-lg">
                        <span class="material-icons-outlined">menu</span>
                    </button>
                    <!-- Mobile лого (desktop-д нуусан) -->
                    <img src="<?= BASE_URL ?>/assets/icons/logo.png" alt="Buuruljuut"
                        class="lg:hidden h-8 object-contain" />
                    <!-- Desktop хайлт -->
                    <div class="relative flex-1 max-w-xs hidden lg:block">
                        <span class="material-icons-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-[#8b93a1] text-sm">search</span>
                        <input class="w-full bg-slate-100 dark:bg-[#272c38] dark:text-[#f2f3f6] border-none rounded-full py-2 pl-9 pr-4 text-sm outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="Хайлт хийх..." type="text" />
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Өдөр/шөнө горим сэлгэгч -->
                    <button id="themeToggle" type="button" onclick="toggleTheme()"
                        class="relative w-14 h-8 flex-shrink-0 rounded-full bg-white/15 lg:bg-slate-100 dark:bg-[#2a2f3b] lg:dark:bg-[#2a2f3b] transition-colors duration-300 flex items-center px-1"
                        aria-label="Өдөр/шөнө горим сэлгэх">
                        <span class="absolute left-1.5 flex items-center justify-center text-amber-400">
                            <span class="material-icons-outlined" style="font-size:14px">light_mode</span>
                        </span>
                        <span class="absolute right-1.5 flex items-center justify-center text-[#8b93a1] dark:text-[#ff8a5c]">
                            <span class="material-icons-outlined" style="font-size:14px">dark_mode</span>
                        </span>
                        <span class="relative z-10 w-6 h-6 rounded-full bg-white dark:bg-[#f1592a] shadow-sm transition-transform duration-300 dark:translate-x-6"></span>
                    </button>
                    <button class="p-2 text-slate-200 lg:text-slate-500 lg:dark:text-[#8b93a1] hover:bg-white/10 lg:hover:bg-slate-100 lg:dark:hover:bg-[#272c38] rounded-full relative">
                        <span class="material-icons-outlined">notifications</span>
                        <span class="absolute top-2 right-2 w-2 h-2 bg-[#f1592a] rounded-full border-2 border-[#1e222c] lg:border-white lg:dark:border-[#1c212b]"></span>
                    </button>
                    <div class="h-8 w-px bg-white/20 lg:bg-slate-200 lg:dark:bg-[#2a2f3b] mx-1"></div>
                    <div class="flex items-center gap-2">
                        <div class="text-right hidden sm:block">
                            <p class="text-sm font-semibold text-white lg:text-[#1c2430] lg:dark:text-[#f2f3f6]"><?= htmlspecialchars($user['name']) ?></p>
                            <p class="text-xs text-white/70 lg:text-[#8b93a1]"><?= htmlspecialchars($_SESSION['user_position'] ?? roleLabel($user['role'])) ?></p>
                        </div>
                        <?php if ($empPhoto && file_exists(UPLOAD_PATH . $empPhoto)): ?>
                            <img src="<?= UPLOAD_URL . htmlspecialchars($empPhoto) ?>"
                                class="w-9 h-9 rounded-full object-cover border-2 border-white/30 lg:border-[#ecedf0] lg:dark:border-[#2a2f3b] flex-shrink-0" />
                        <?php else: ?>
                            <div class="w-9 h-9 rounded-full bg-[#f1592a] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                <?= mb_substr($user['name'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>
            <div class="p-4 lg:p-8 space-y-4 lg:space-y-6">