<div class="p-5 flex items-center justify-center border-b border-[#2a2f3b]">
    <img src="<?= BASE_URL ?>/assets/icons/logo.png" alt="Buuruljuut"
        class="h-12 w-48 object-contain" />
</div>
<nav class="flex-1 px-4 space-y-1 overflow-y-auto py-4">
    <!-- Dashboard -->
    <a href="<?= BASE_URL ?>/dashboard.php"
        class="flex items-center gap-3 px-4 py-3 <?= ac('dashboard') ?> rounded-lg transition-colors">
        <span class="material-icons-outlined">home</span>Миний самбар
    </a>
    <?php $regOpen = inGroup(['regulations','regulations-admin']); ?>
    <div class="menu-group">
        <button onclick="toggleGroup(this)"
            class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $regOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
            <div class="flex items-center gap-3">
                <span class="material-icons-outlined">menu_book</span>
                <span>Дүрэм журам</span>
            </div>
            <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $regOpen ? 'rotate-180' : '' ?>">expand_more</span>
        </button>
        <div class="accordion-items pl-4 mt-1 space-y-1 <?= $regOpen ? '' : 'hidden' ?>">
            <a href="<?= BASE_URL ?>/modules/regulations/index.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('regulations') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">library_books</span>Жагсаалт
            </a>
            <?php if (hasPermission('regulations.manage')): ?>
            <a href="<?= BASE_URL ?>/modules/regulations/admin.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('regulations-admin') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">admin_panel_settings</span>Удирдах
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Benefits групп -->
    <?php $benefitsOpen = inGroup(['onefit', 'onefit-admin']); ?>
    <div class="menu-group">
        <button onclick="toggleGroup(this)"
            class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $benefitsOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
            <div class="flex items-center gap-3">
                <span class="material-icons-outlined">card_giftcard</span>
                <span>Benefits</span>
            </div>
            <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $benefitsOpen ? 'rotate-180' : '' ?>">expand_more</span>
        </button>
        <div class="accordion-items pl-4 mt-1 space-y-1 <?= $benefitsOpen ? '' : 'hidden' ?>">
            <a href="<?= BASE_URL ?>/modules/onefit/index.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('onefit') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">fitness_center</span>OneFit
            </a>
            <?php if(isHR() || isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/onefit/admin.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('onefit-admin') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">admin_panel_settings</span>OneFit Admin
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Self Service групп -->
    <?php $selfOpen = inGroup(['self-service']); ?>
    <div class="menu-group">
        <button onclick="toggleGroup(this)"
            class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $selfOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
            <div class="flex items-center gap-3">
                <span class="material-icons-outlined">manage_accounts</span>
                <span>Self Service</span>
            </div>
            <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $selfOpen ? 'rotate-180' : '' ?>">expand_more</span>
        </button>
        <div class="accordion-items pl-4 mt-1 space-y-1 <?= $selfOpen ? '' : 'hidden' ?>">
            <div class="flex items-center gap-3 px-4 py-2.5 text-[#5a6172] rounded-lg text-sm cursor-default">
                <span class="material-icons-outlined text-base">schedule</span>Удахгүй...
            </div>
        </div>
    </div>

    <!-- KPI групп -->
    <?php $kpiOpen = inGroup(['kpi', 'kpi-team', 'kpi-admin', 'kpi-report']); ?>
    <div class="menu-group">
        <button onclick="toggleGroup(this)"
            class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $kpiOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
            <div class="flex items-center gap-3">
                <span class="material-icons-outlined">leaderboard</span>
                <span>KPI</span>
            </div>
            <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $kpiOpen ? 'rotate-180' : '' ?>">expand_more</span>
        </button>
        <div class="accordion-items pl-4 mt-1 space-y-1 <?= $kpiOpen ? '' : 'hidden' ?>">
            <a href="<?= BASE_URL ?>/modules/kpi/index.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">assignment_ind</span>Миний KPI
            </a>
            <?php if (hasPermission('kpi.approve') || hasPermission('kpi.evaluate') || hasPermission('kpi.view_all')): ?>
            <a href="<?= BASE_URL ?>/modules/kpi/team.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi-team') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">groups</span>Газар,Хэлтэс 
            </a>
            <?php endif; ?>
            <?php if (hasPermission('kpi.view_all')): ?>
            <a href="<?= BASE_URL ?>/modules/kpi/report.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi-report') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">summarize</span>Тайлан нэгтгэл
            </a>
            <?php endif; ?>
            <?php if (hasPermission('kpi.manage_periods') || hasPermission('kpi.manage_evaluators')): ?>
            <a href="<?= BASE_URL ?>/modules/kpi/admin.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi-admin') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">tune</span>KPI тохиргоо
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI2 групп (туршилтын) -->
    <?php
    $myEmpIdForKpi2 = (int)($_SESSION['user_id'] ?? 0);
    $kpi2IsMgr = $myEmpIdForKpi2 ? kpi2IsManager(getDB(), $myEmpIdForKpi2) : false;
    $kpi2IsDir = $myEmpIdForKpi2 ? kpi2IsDirector(getDB(), $myEmpIdForKpi2) : false;
    $kpi2Open = inGroup(['kpi2', 'kpi2-team', 'kpi2-manager', 'kpi2-approvals', 'kpi2-admin']);
    ?>
    <div class="menu-group">
        <button onclick="toggleGroup(this)"
            class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $kpi2Open ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
            <div class="flex items-center gap-3">
                <span class="material-icons-outlined">insights</span>
                <span>KPI2 <span class="text-[10px] opacity-70">(туршилт)</span></span>
            </div>
            <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $kpi2Open ? 'rotate-180' : '' ?>">expand_more</span>
        </button>
        <div class="accordion-items pl-4 mt-1 space-y-1 <?= $kpi2Open ? '' : 'hidden' ?>">
            <a href="<?= BASE_URL ?>/modules/kpi2/index.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">assignment_ind</span>Миний KPI2
            </a>
            <?php if ($kpi2IsMgr || isHR() || isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/kpi2/manager.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-manager') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">edit_calendar</span>Хэлтсийн ажлаа төлөвлөх
            </a>
            <?php endif; ?>
            <?php if ($kpi2IsDir || isHR() || isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/kpi2/approvals.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-approvals') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">fact_check</span>KPI батлах хүсэлтүүд
            </a>
            <?php endif; ?>
            <?php if ($kpi2IsMgr || $kpi2IsDir || isHR() || isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/kpi2/team.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-team') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">groups</span>Багийн KPI2
            </a>
            <?php endif; ?>
            <?php if (isHR() || isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/kpi2/admin.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('kpi2-admin') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">tune</span>KPI2 тохиргоо
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Удирдлага групп (зөвхөн superadmin) -->
    <?php if (isSuperAdmin()): ?>
    <?php $adminOpen = inGroup(['org_units', 'employees']); ?>
    <div class="menu-group">
        <button onclick="toggleGroup(this)"
            class="w-full flex items-center justify-between px-4 py-3 rounded-lg transition-colors <?= $adminOpen ? 'bg-[#f1592a] text-white font-semibold' : 'text-[#a7adba] hover:bg-[#2a2f3b] hover:text-white' ?>">
            <div class="flex items-center gap-3">
                <span class="material-icons-outlined">admin_panel_settings</span>
                <span>Удирдлага</span>
            </div>
            <span class="material-icons-outlined accordion-arrow text-sm transition-transform duration-200 <?= $adminOpen ? 'rotate-180' : '' ?>">expand_more</span>
        </button>
        <div class="accordion-items pl-4 mt-1 space-y-1 <?= $adminOpen ? '' : 'hidden' ?>">
            <a href="<?= BASE_URL ?>/modules/org_units/index.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('org_units') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">account_tree</span>Алба, хэлтэс
            </a>
            <a href="<?= BASE_URL ?>/modules/employees/index.php"
                class="flex items-center gap-3 px-4 py-2.5 <?= ac('employees') ?> rounded-lg transition-colors text-sm">
                <span class="material-icons-outlined text-base">groups</span>Ажилтны бүх бүртгэл
            </a>
        </div>
    </div>
    <?php endif; ?>
</nav>

<div class="p-4 border-t border-[#2a2f3b]">
    <div class="flex items-center gap-3 mb-3">
        <?php if ($empPhoto && file_exists(UPLOAD_PATH . $empPhoto)): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($empPhoto) ?>"
                class="w-9 h-9 rounded-full object-cover border-2 border-[#2a2f3b] flex-shrink-0" />
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
    <a href="<?= BASE_URL ?>/logout.php"
        class="w-full flex items-center justify-center gap-2 py-2 bg-[#272c38] text-[#c9cdd6] hover:bg-[#313745] hover:text-white rounded-lg text-sm font-medium transition-colors">
        <span class="material-icons-outlined" style="font-size:16px">logout</span> Гарах
    </a>
</div>
