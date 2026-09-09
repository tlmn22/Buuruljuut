<?php
$pageTitle  = 'Хяналтын самбар';
$activePage = 'dashboard';
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();
$companies = $pdo->query("SELECT COUNT(*) FROM companies WHERE is_active=1")->fetchColumn();
$depts     = $pdo->query("SELECT COUNT(*) FROM departments WHERE is_active=1")->fetchColumn();
$divisions = $pdo->query("SELECT COUNT(*) FROM divisions WHERE is_active=1")->fetchColumn();
$employees = $pdo->query("SELECT COUNT(*) FROM employees WHERE is_active=1")->fetchColumn();
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 mb-2">
            <span>Нүүр</span><span class="mx-2">/</span>
            <span class="text-[#F16225] font-medium">Хяналтын самбар</span>
        </nav>
        <div class="flex items-center gap-4">
            <h1 class="text-3xl font-bold">Хяналтын самбар</h1>
            <span class="px-3 py-1 bg-blue-50 text-[#F16225] text-sm font-semibold rounded-full border border-blue-100"><?= date('Y') ?> оны систем</span>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
    <a href="<?= BASE_URL ?>/modules/companies/index.php" class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div class="w-12 h-12 bg-blue-100 text-[#F16225] rounded-xl flex items-center justify-center flex-shrink-0">
            <span class="material-icons-outlined">business</span>
        </div>
        <div><p class="text-sm text-slate-500 mb-0.5">Компани</p><p class="text-3xl font-bold"><?= $companies ?></p></div>
    </a>
    <a href="<?= BASE_URL ?>/modules/departments/index.php" class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center flex-shrink-0">
            <span class="material-icons-outlined">account_tree</span>
        </div>
        <div><p class="text-sm text-slate-500 mb-0.5">Алба</p><p class="text-3xl font-bold"><?= $depts ?></p></div>
    </a>
    <a href="<?= BASE_URL ?>/modules/divisions/index.php" class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center flex-shrink-0">
            <span class="material-icons-outlined">device_hub</span>
        </div>
        <div><p class="text-sm text-slate-500 mb-0.5">Хэлтэс</p><p class="text-3xl font-bold"><?= $divisions ?></p></div>
    </a>
    <a href="<?= BASE_URL ?>/modules/employees/index.php" class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div class="w-12 h-12 bg-green-100 text-green-600 rounded-xl flex items-center justify-center flex-shrink-0">
            <span class="material-icons-outlined">groups</span>
        </div>
        <div><p class="text-sm text-slate-500 mb-0.5">Ажилтан</p><p class="text-3xl font-bold"><?= $employees ?></p></div>
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-8">
        <h3 class="text-xl font-bold mb-6">Байгууллагын бүтэц</h3>
        <div class="space-y-1">
        <?php
        $tree = $pdo->query("SELECT c.name as company, d.name as dept, v.name as div_name FROM companies c LEFT JOIN departments d ON d.company_id=c.id LEFT JOIN divisions v ON v.department_id=d.id WHERE c.is_active=1 ORDER BY c.name,d.name,v.name LIMIT 12")->fetchAll();
        if(empty($tree)): ?>
            <p class="text-slate-400 text-sm">Мэдээлэл байхгүй байна.</p>
        <?php else: foreach($tree as $t): ?>
            <div class="flex items-center gap-2 text-sm text-slate-600 py-2 border-b border-slate-50 last:border-0">
                <span class="material-icons-outlined text-blue-300" style="font-size:15px">business</span>
                <span class="font-medium"><?= htmlspecialchars($t['company']) ?></span>
                <?php if($t['dept']): ?><span class="text-slate-300">›</span><span><?= htmlspecialchars($t['dept']) ?></span><?php endif; ?>
                <?php if($t['div_name']): ?><span class="text-slate-300">›</span><span class="text-slate-400"><?= htmlspecialchars($t['div_name']) ?></span><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-8">
        <h3 class="text-xl font-bold mb-6">Хурдан үйлдэл</h3>
        <div class="grid grid-cols-2 gap-3">
            <a href="<?= BASE_URL ?>/modules/companies/index.php" class="p-5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors text-center">
                <span class="material-icons-outlined text-[#F16225] block mb-2" style="font-size:28px">business</span>
                <span class="text-sm font-medium">Компани</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/departments/index.php" class="p-5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors text-center">
                <span class="material-icons-outlined text-purple-500 block mb-2" style="font-size:28px">account_tree</span>
                <span class="text-sm font-medium">Алба</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/divisions/index.php" class="p-5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors text-center">
                <span class="material-icons-outlined text-amber-500 block mb-2" style="font-size:28px">device_hub</span>
                <span class="text-sm font-medium">Хэлтэс</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/employees/index.php" class="p-5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors text-center">
                <span class="material-icons-outlined text-green-500 block mb-2" style="font-size:28px">groups</span>
                <span class="text-sm font-medium">Ажилтан</span>
            </a>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
