<?php
$pageTitle  = 'Дүрэм журам удирдах';
$activePage = 'regulations-admin';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
if (!hasPermission('regulations.manage')) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();

$categories = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM regulations r WHERE r.category_id=c.id) AS reg_count FROM regulation_categories c ORDER BY c.sort_order, c.name")->fetchAll();
$employees  = $pdo->query("SELECT id, employee_code, last_name, first_name, position, org_unit_id FROM employees WHERE is_active=1 ORDER BY last_name")->fetchAll();

$unitRows = $pdo->query("SELECT id, parent_id, name FROM org_units ORDER BY sort_order, name")->fetchAll();
$unitsByParent = [];
foreach ($unitRows as $u) $unitsByParent[$u['parent_id'] ? (int)$u['parent_id'] : 0][] = $u;
$unitsById = regulationsOrgUnitsById($pdo);

$stmt = $pdo->query("
    SELECT r.*, c.name AS category_name
    FROM regulations r
    LEFT JOIN regulation_categories c ON c.id = r.category_id
    ORDER BY r.created_at DESC
");
$regs = $stmt->fetchAll();
$orgUnitMap = regulationsOrgUnitMap($pdo);

// ─── Тухайн бичлэгийг уншсан тоог тооцно (хүснэгтэд харуулах) — бүх ажилтан бүх журмыг харах эрхтэй ───
$readCounts = [];
foreach ($pdo->query("SELECT regulation_id, COUNT(*) AS cnt FROM regulation_reads GROUP BY regulation_id")->fetchAll() as $rc) {
    $readCounts[(int)$rc['regulation_id']] = (int)$rc['cnt'];
}
$totalActiveEmployees = count($employees);

// ─── Тайлан харах (нэг журмын дэлгэрэнгүй унших мэдээлэл — бүх ажилтан) ───
$viewId = intval($_GET['view'] ?? 0);
$viewReg = null;
$viewRows = [];
if ($viewId) {
    foreach ($regs as $r) { if ((int)$r['id'] === $viewId) { $viewReg = $r; break; } }
    if ($viewReg) {
        $readStmt = $pdo->prepare("SELECT employee_id, read_at FROM regulation_reads WHERE regulation_id=?");
        $readStmt->execute([$viewId]);
        $readByEmp = [];
        foreach ($readStmt->fetchAll() as $rr) $readByEmp[(int)$rr['employee_id']] = $rr['read_at'];

        foreach ($employees as $e) {
            $viewRows[] = [
                'employee' => $e,
                'read_at'  => $readByEmp[(int)$e['id']] ?? null,
            ];
        }
        usort($viewRows, fn($a, $b) => ($b['read_at'] !== null) <=> ($a['read_at'] !== null));
    }
}

// ─── Хариуцсан ажилтнууд (regulations.manage шууд олгогдсон) — зөвхөн superadmin ───
$managers = [];
if (isSuperAdmin()) {
    $managers = $pdo->query("
        SELECT e.id, e.employee_code, e.last_name, e.first_name, e.position
        FROM user_permissions up
        JOIN permissions p ON p.id = up.permission_id AND p.code = 'regulations.manage'
        JOIN users u ON u.id = up.user_id
        JOIN employees e ON e.id = u.employee_id
        ORDER BY e.last_name
    ")->fetchAll();
}

function renderOrgUnitCheckboxes(array $unitsByParent, int $parentId, int $depth): void {
    foreach ($unitsByParent[$parentId] ?? [] as $u) {
        ?>
        <label class="flex items-center gap-2 py-1 hover:bg-slate-50 dark:hover:bg-[#20242e] rounded px-1.5 cursor-pointer" style="padding-left: <?= 6 + $depth * 18 ?>px">
            <input type="checkbox" class="org-unit-cb w-3.5 h-3.5 rounded" value="<?= $u['id'] ?>">
            <span class="text-sm"><?= htmlspecialchars($u['name']) ?></span>
        </label>
        <?php
        renderOrgUnitCheckboxes($unitsByParent, (int)$u['id'], $depth + 1);
    }
}
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <a href="<?= BASE_URL ?>/modules/regulations/index.php" class="hover:text-[#f1592a]">Дүрэм журам</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Удирдах</span>
        </nav>
        <h1 class="text-3xl font-bold">Дүрэм журам удирдах</h1>
    </div>
    <button onclick="openRegModal()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12] transition-colors shadow-lg shadow-orange-100 dark:shadow-none">
        <span class="material-icons-outlined text-sm">upload_file</span> Дүрэм журам нэмэх
    </button>
</div>

<!-- Ангилал удирдах -->
<div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-4">
    <div class="flex items-center justify-between mb-3">
        <h3 class="font-bold text-sm">Ангилал</h3>
        <button onclick="addCategory()" class="text-xs text-[#f1592a] font-medium hover:underline flex items-center gap-1">
            <span class="material-icons-outlined" style="font-size:14px">add</span> Шинэ ангилал
        </button>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php if (empty($categories)): ?>
            <span class="text-sm text-slate-400 dark:text-[#5a6172]">Ангилал үүсгээгүй байна.</span>
        <?php endif; ?>
        <?php foreach ($categories as $c): ?>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-purple-50 dark:bg-[#272c38] text-purple-600 dark:text-[#c9a8ff] rounded-full text-xs font-medium" id="cat-<?= $c['id'] ?>">
                <?= htmlspecialchars($c['name']) ?> <span class="opacity-60">(<?= $c['reg_count'] ?>)</span>
                <button onclick="renameCategory(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')" class="hover:text-[#f1592a]"><span class="material-icons-outlined" style="font-size:13px">edit</span></button>
                <button onclick="deleteCategory(<?= $c['id'] ?>)" class="hover:text-red-500"><span class="material-icons-outlined" style="font-size:13px">close</span></button>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<?php if (isSuperAdmin()): ?>
<!-- Хариуцсан ажилтан томилох -->
<div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-4">
    <h3 class="font-bold text-sm mb-3">Хариуцсан ажилтнууд <span class="text-slate-400 dark:text-[#5a6172] font-normal">(дүрэм журам удирдах эрхтэй)</span></h3>
    <div class="flex flex-wrap gap-2 mb-3">
        <?php if (empty($managers)): ?>
            <span class="text-sm text-slate-400 dark:text-[#5a6172]">Тусад нь томилогдсон ажилтан байхгүй (superadmin, HR эрхтэй хүмүүс аль хэдийн удирдах боломжтой).</span>
        <?php endif; ?>
        <?php foreach ($managers as $m): ?>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-50 dark:bg-[#16283a] text-[#1d4e7a] dark:text-[#8ec5f0] rounded-full text-xs font-medium">
                <?= htmlspecialchars($m['last_name'] . '. ' . $m['first_name']) ?>
                <button onclick="revokeManager(<?= $m['id'] ?>)" class="hover:text-red-500"><span class="material-icons-outlined" style="font-size:13px">close</span></button>
            </span>
        <?php endforeach; ?>
    </div>
    <div class="flex items-center gap-2">
        <select id="newManagerEmp" class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm flex-1 max-w-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
            <option value="">-- Ажилтан сонгох --</option>
            <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['last_name'] . '. ' . $e['first_name'] . ($e['position'] ? ' — ' . $e['position'] : '')) ?></option>
            <?php endforeach; ?>
        </select>
        <button onclick="grantManager()" class="px-4 py-2 bg-[#1e222c] text-white rounded-lg text-xs font-medium hover:bg-[#2a2f3b]">Томилох</button>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 <?= $viewReg ? 'xl:grid-cols-2' : '' ?> gap-6">

    <!-- Дүрэм журмын жагсаалт -->
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-[#2a2f3b]"><h3 class="font-bold">Баримтууд</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b]">
                        <th class="text-left px-4 py-3 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs">Гарчиг</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs">Ангилал</th>
                        <th class="text-center px-4 py-3 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs">Уншсан</th>
                        <th class="text-center px-4 py-3 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs">Төлөв</th>
                        <th class="text-right px-4 py-3 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs">Үйлдэл</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
                <?php if (empty($regs)): ?>
                    <tr><td colspan="5" class="text-center py-16 text-slate-400 dark:text-[#5a6172]">Дүрэм журам бүртгэгдээгүй байна</td></tr>
                <?php else: foreach ($regs as $r):
                    $read = $readCounts[(int)$r['id']] ?? 0;
                ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors <?= $viewId == $r['id'] ? 'bg-orange-50/50 dark:bg-[#272c38]' : '' ?>">
                        <td class="px-4 py-3">
                            <p class="font-medium"><?= htmlspecialchars($r['title']) ?></p>
                            <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= $r['approved_date'] ? htmlspecialchars($r['approved_date']) : 'Батлагдсан огноогүй' ?><?= $r['require_ack'] ? ' · Заавал танилцах' : '' ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($r['category_name']): ?>
                                <span class="inline-flex items-center px-2.5 py-1 bg-purple-50 dark:bg-[#272c38] text-purple-600 dark:text-[#c9a8ff] rounded-full text-xs font-medium"><?= htmlspecialchars($r['category_name']) ?></span>
                            <?php else: ?><span class="text-slate-400 dark:text-[#5a6172]">—</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center font-medium"><?= $read ?>/<?= $totalActiveEmployees ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($r['is_active']): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400 rounded-full text-xs font-medium">Идэвхтэй</span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400 rounded-full text-xs font-medium">Идэвхгүй</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                            <a href="?view=<?= $r['id'] ?>" class="inline-flex p-2 <?= $viewId == $r['id'] ? 'bg-[#f1592a] text-white' : 'text-slate-500 dark:text-[#8b93a1] hover:bg-slate-100 dark:hover:bg-[#272c38]' ?> rounded-lg transition-colors" title="Тайлан">
                                <span class="material-icons-outlined" style="font-size:18px">fact_check</span>
                            </a>
                            <button onclick='editReg(<?= json_encode($r) ?>, <?= json_encode($orgUnitMap[(int)$r['id']] ?? []) ?>)' class="inline-flex p-2 text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38] rounded-lg transition-colors" title="Засах">
                                <span class="material-icons-outlined" style="font-size:18px">edit</span>
                            </button>
                            <button onclick="confirmDelete('<?= BASE_URL ?>/modules/regulations/ajax.php', <?= $r['id'] ?>, function(){ location.reload(); })" class="inline-flex p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition-colors" title="Устгах">
                                <span class="material-icons-outlined" style="font-size:18px">delete</span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($viewReg): ?>
    <!-- Уншсан/уншаагүй тайлан -->
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 class="font-bold truncate"><?= htmlspecialchars($viewReg['title']) ?></h3>
                <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-0.5">
                    Нийт <?= count($viewRows) ?> ажилтан (бүх компани) харах боломжтой
                    <?php $viewUnitIds = $orgUnitMap[$viewId] ?? []; if ($viewUnitIds): ?>
                        · Хамаарах нэгж: <?= htmlspecialchars(implode(', ', array_map(fn($uid) => $unitsById[$uid]['name'] ?? '', $viewUnitIds))) ?>
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?= BASE_URL ?>/modules/regulations/export_reads.php?id=<?= $viewId ?>"
               class="flex-shrink-0 flex items-center gap-1.5 px-3 py-2 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition-colors">
                <span class="material-icons-outlined text-sm">download</span> Excel
            </a>
        </div>
        <?php if (empty($viewRows)): ?>
            <div class="py-12 text-center text-slate-400 dark:text-[#5a6172]">
                <span class="material-icons-outlined text-4xl block mb-2">person_off</span>
                <p class="text-sm">Хамаарах ажилтан алга</p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b] sticky top-0">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-[#8b93a1]">Ажилтан</th>
                        <th class="px-3 py-3 text-center text-xs font-semibold text-slate-500 dark:text-[#8b93a1]">Төлөв</th>
                        <th class="px-3 py-3 text-center text-xs font-semibold text-slate-500 dark:text-[#8b93a1]">Огноо</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
                    <?php foreach ($viewRows as $row): $e = $row['employee']; ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e]">
                        <td class="px-4 py-3">
                            <p class="font-medium"><?= htmlspecialchars($e['last_name'] . '. ' . $e['first_name']) ?></p>
                            <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($e['employee_code']) ?> · <?= htmlspecialchars($e['position'] ?? '') ?></p>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <?php if ($row['read_at']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400 rounded-full text-xs font-medium">Үзсэн</span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400 rounded-full text-xs font-medium">Үзээгүй</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-center text-xs text-slate-400 dark:text-[#8b93a1]"><?= $row['read_at'] ? htmlspecialchars($row['read_at']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Дүрэм журам нэмэх/засах MODAL -->
<div id="regModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-lg font-bold" id="regModalTitle">Дүрэм журам нэмэх</h2>
            <button onclick="closeRegModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg transition-colors">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>
        <form id="regForm" class="space-y-4">
            <input type="hidden" id="regId" value=""/>
            <div>
                <label class="block text-sm font-medium mb-1.5">Гарчиг <span class="text-red-500">*</span></label>
                <input type="text" id="regTitle" required
                       class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"/>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Ангилал</label>
                    <select id="regCategory" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
                        <option value="">-- Сонгох --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Батлагдсан огноо</label>
                    <input type="date" id="regApproved" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"/>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Тайлбар</label>
                <textarea id="regDesc" rows="2" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">PDF файл <span id="fileRequiredMark" class="text-red-500">*</span></label>
                <input type="file" id="regFile" accept="application/pdf"
                       class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2 text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-[#f1592a] file:text-white file:text-xs"/>
                <p id="currentFileLabel" class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1"></p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Хамаарах газар/хэлтэс/алба</label>
                <p class="text-xs text-slate-400 dark:text-[#5a6172] mb-2">Юу ч сонгохгүй бол бүх компанид хамаарна.</p>
                <div id="orgUnitTreeWrap" class="border border-slate-200 dark:border-[#2a2f3b] rounded-lg p-2 max-h-52 overflow-y-auto">
                    <?php renderOrgUnitCheckboxes($unitsByParent, 0, 0); ?>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <input type="checkbox" id="regRequireAck" class="w-4 h-4 rounded cursor-pointer"/>
                <label for="regRequireAck" class="text-sm font-medium cursor-pointer">Заавал уншиж танилцах</label>
            </div>
            <div id="regActiveWrap" class="hidden items-center gap-3">
                <input type="checkbox" id="regActive" checked class="w-4 h-4 rounded cursor-pointer"/>
                <label for="regActive" class="text-sm font-medium cursor-pointer">Идэвхтэй</label>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeRegModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<script>
const AJAX = '<?= BASE_URL ?>/modules/regulations/ajax.php';

// ─── Ангилал ─────────────────────────────────────────────
function addCategory() {
    const name = prompt('Шинэ ангиллын нэр:');
    if (!name) return;
    $.post(AJAX, { action: 'category_create', name }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}
function renameCategory(id, oldName) {
    const name = prompt('Ангиллын нэр:', oldName);
    if (!name || name === oldName) return;
    $.post(AJAX, { action: 'category_update', id, name }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}
function deleteCategory(id) {
    if (!confirm('Энэ ангиллыг устгах уу? (Дотор нь байгаа баримтууд ангилалгүй болно)')) return;
    $.post(AJAX, { action: 'category_delete', id }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}

// ─── Хариуцсан ажилтан ──────────────────────────────────
function grantManager() {
    const employee_id = $('#newManagerEmp').val();
    if (!employee_id) { showToast('Ажилтан сонгоно уу', 'error'); return; }
    $.post(AJAX, { action: 'grant_manager', employee_id }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}
function revokeManager(employee_id) {
    if (!confirm('Энэ ажилтны эрхийг цуцлах уу?')) return;
    $.post(AJAX, { action: 'revoke_manager', employee_id }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(() => location.reload(), 500);
    }, 'json');
}

// ─── Дүрэм журам modal ──────────────────────────────────
function openRegModal() {
    $('#regModal').css('display', 'flex');
    $('#regModalTitle').text('Дүрэм журам нэмэх');
    $('#regId,#regTitle,#regDesc,#regApproved').val('');
    $('#regCategory').val('');
    $('#regRequireAck').prop('checked', false);
    $('.org-unit-cb').prop('checked', false);
    $('#regFile').val('').prop('required', true);
    $('#fileRequiredMark').show();
    $('#currentFileLabel').text('');
    $('#regActiveWrap').addClass('hidden').removeClass('flex');
    setTimeout(() => $('#regTitle').focus(), 100);
}
function closeRegModal() { $('#regModal').hide(); }

function editReg(row, orgUnitIds) {
    openRegModal();
    $('#regModalTitle').text('Дүрэм журам засах');
    $('#regId').val(row.id);
    $('#regTitle').val(row.title);
    $('#regDesc').val(row.description || '');
    $('#regApproved').val(row.approved_date || '');
    $('#regCategory').val(row.category_id || '');
    $('#regRequireAck').prop('checked', row.require_ack == 1);
    $('#regFile').prop('required', false);
    $('#fileRequiredMark').hide();
    $('#currentFileLabel').text(row.file_original_name ? 'Одоогийн файл: ' + row.file_original_name + ' (шинээр сонгоогүй бол өөрчлөгдөхгүй)' : '');
    $('#regActiveWrap').removeClass('hidden').addClass('flex');
    $('#regActive').prop('checked', row.is_active == 1);

    $('.org-unit-cb').each(function () {
        $(this).prop('checked', (orgUnitIds || []).includes(parseInt($(this).val())));
    });
}

$('#regForm').on('submit', function (e) {
    e.preventDefault();
    const id = $('#regId').val();
    const fd = new FormData();
    fd.append('action', id ? 'update' : 'create');
    if (id) fd.append('id', id);
    fd.append('title', $('#regTitle').val());
    fd.append('category_id', $('#regCategory').val());
    fd.append('approved_date', $('#regApproved').val());
    fd.append('description', $('#regDesc').val());
    fd.append('require_ack', $('#regRequireAck').is(':checked') ? 1 : 0);
    fd.append('is_active', $('#regActive').is(':checked') ? 1 : 0);
    $('.org-unit-cb:checked').each(function () { fd.append('org_unit_ids[]', $(this).val()); });
    const fileInput = $('#regFile')[0];
    if (fileInput.files.length) fd.append('file', fileInput.files[0]);

    $.ajax({
        url: AJAX, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { closeRegModal(); setTimeout(() => location.reload(), 600); }
        },
        error: function () { showToast('Алдаа гарлаа.', 'error'); }
    });
});

$('#regModal').on('click', function (e) { if ($(e.target).is('#regModal')) closeRegModal(); });
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
