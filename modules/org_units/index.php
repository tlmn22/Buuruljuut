<?php
$pageTitle  = 'Алба, хэлтэс';
$activePage = 'org_units';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
requireRole('superadmin');
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();

$employees = $pdo->query("SELECT id, last_name, first_name, position FROM employees WHERE is_active=1 ORDER BY last_name")->fetchAll();

$units = $pdo->query("
    SELECT ou.*,
           e.last_name as mgr_last, e.first_name as mgr_first, e.position as mgr_pos,
           (SELECT COUNT(*) FROM employees em WHERE em.org_unit_id = ou.id) as emp_count
    FROM org_units ou
    LEFT JOIN employees e ON e.id = ou.manager_employee_id
    ORDER BY ou.parent_id IS NULL DESC, ou.sort_order, ou.name
")->fetchAll();

// parent_id -> children[] байдлаар мод үүсгэх
$byParent = [];
foreach ($units as $u) {
    $byParent[$u['parent_id'] ?? 0][] = $u;
}
$flatForSelect = []; // [{id, name, depth}] — dropdown-д indent хийхэд
function collectFlat(array $byParent, ?int $parentId, int $depth, array &$out): void {
    foreach ($byParent[$parentId ?? 0] ?? [] as $u) {
        $out[] = ['id' => $u['id'], 'name' => $u['name'], 'depth' => $depth];
        collectFlat($byParent, $u['id'], $depth + 1, $out);
    }
}
collectFlat($byParent, null, 0, $flatForSelect);

$totalUnits = count($units);
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Алба, хэлтэс</span>
        </nav>
        <h1 class="text-3xl font-bold">Алба, хэлтэс</h1>
    </div>
    <button onclick="openModal()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12] transition-colors shadow-lg shadow-orange-100">
        <span class="material-icons-outlined text-sm">add_circle</span> Нэгж нэмэх
    </button>
</div>

<div class="bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm p-4 flex items-center gap-3">
    <span class="material-icons-outlined text-slate-400 dark:text-[#8b93a1]">account_tree</span>
    <input type="text" id="filterSearch" onkeyup="filterTable()" placeholder="Нэгжийн нэрээр хайх..."
           class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"/>
    <span class="text-sm text-slate-400 dark:text-[#8b93a1] ml-auto">Нийт: <strong id="rowCount"><?= $totalUnits ?></strong> нэгж</span>
</div>

<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b]">
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Нэр</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Төрөл</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Удирдагч</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Ажилтан</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Төлөв</th>
                    <th class="text-right px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Үйлдэл</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($units)): ?>
                <tr><td colspan="6" class="text-center py-16 text-slate-400 dark:text-[#5a6172]">
                    <span class="material-icons-outlined block mb-3" style="font-size:48px">account_tree</span>Нэгж байхгүй байна
                </td></tr>
            <?php else: $renderRow = function (array $byParent, ?int $parentId, int $depth) use (&$renderRow) { ?>
                <?php foreach ($byParent[$parentId ?? 0] ?? [] as $row): ?>
                    <tr class="border-b border-slate-100 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors table-row"
                        id="row-<?= $row['id'] ?>"
                        data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>">
                        <td class="px-6 py-4 font-medium">
                            <div class="flex items-center gap-2" style="padding-left: <?= $depth * 24 ?>px">
                                <?php if ($depth > 0): ?><span class="text-slate-300 dark:text-[#3a4151]">└</span><?php endif; ?>
                                <span class="material-icons-outlined text-[#f1592a]" style="font-size:16px">
                                    <?= $depth === 0 ? 'business' : 'device_hub' ?>
                                </span>
                                <?= htmlspecialchars($row['name']) ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($row['unit_type']): ?>
                                <span class="inline-flex items-center px-2.5 py-1 bg-purple-50 dark:bg-[#272c38] text-purple-600 dark:text-[#c9a8ff] rounded-full text-xs font-medium"><?= htmlspecialchars($row['unit_type']) ?></span>
                            <?php else: ?><span class="text-slate-400 dark:text-[#5a6172]">—</span><?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($row['mgr_last']): ?>
                                <p class="text-sm font-medium"><?= htmlspecialchars($row['mgr_last'] . '. ' . $row['mgr_first']) ?></p>
                                <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($row['mgr_pos'] ?? '') ?></p>
                            <?php else: ?><span class="text-xs text-slate-400 dark:text-[#5a6172] italic">Тохируулаагүй</span><?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-slate-600 dark:text-[#c9cdd6]"><?= (int)$row['emp_count'] ?></td>
                        <td class="px-6 py-4">
                            <?php if ($row['is_active']): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400 rounded-full text-xs font-medium"><span class="w-1.5 h-1.5 bg-green-500 rounded-full inline-block"></span> Идэвхтэй</span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400 rounded-full text-xs font-medium"><span class="w-1.5 h-1.5 bg-red-500 rounded-full inline-block"></span> Идэвхгүй</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right space-x-1">
                            <button onclick='editRow(<?= json_encode($row) ?>)' class="inline-flex p-2 text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38] rounded-lg transition-colors">
                                <span class="material-icons-outlined" style="font-size:18px">edit</span>
                            </button>
                            <button onclick="confirmDelete('<?= BASE_URL ?>/modules/org_units/ajax.php', <?= $row['id'] ?>, function(){ $('#row-<?= $row['id'] ?>').fadeOut(300); updateCount(); })"
                                    class="inline-flex p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition-colors">
                                <span class="material-icons-outlined" style="font-size:18px">delete</span>
                            </button>
                        </td>
                    </tr>
                    <?php $renderRow($byParent, $row['id'], $depth + 1); ?>
                <?php endforeach; ?>
            <?php }; $renderRow($byParent, null, 0); endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL -->
<div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-md mx-4 p-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold" id="modalTitle">Нэгж нэмэх</h2>
            <button onclick="closeModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg transition-colors">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>
        <form id="unitForm" class="space-y-4">
            <input type="hidden" id="unitId" value=""/>
            <div>
                <label class="block text-sm font-medium mb-1.5">Нэр <span class="text-red-500">*</span></label>
                <input type="text" id="unitName" required
                       class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"
                       placeholder="Жишээ: Санхүүгийн хэлтэс"/>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Төрөл</label>
                <input type="text" id="unitType" list="unitTypeSuggestions"
                       class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"
                       placeholder="газар / хэлтэс / алба / хэсэг"/>
                <datalist id="unitTypeSuggestions">
                    <option value="газар"><option value="хэлтэс"><option value="алба"><option value="хэсэг">
                </datalist>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Харьяалагдах нэгж</label>
                <select id="unitParent" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
                    <option value="">-- Хамгийн дээд түвшин (эцэггүй) --</option>
                    <?php foreach ($flatForSelect as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= str_repeat('— ', $f['depth']) . htmlspecialchars($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Удирдагч</label>
                <select id="unitManager" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
                    <option value="">-- Сонгох (заавал биш) --</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['last_name'] . '. ' . $e['first_name'] . ($e['position'] ? ' — ' . $e['position'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Тайлбар</label>
                <textarea id="unitDesc" rows="2" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="Товч тайлбар..."></textarea>
            </div>
            <div class="flex items-center gap-3">
                <input type="checkbox" id="unitActive" checked class="w-4 h-4 rounded cursor-pointer"/>
                <label for="unitActive" class="text-sm font-medium cursor-pointer">Идэвхтэй</label>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
                <button type="submit" class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12]">Хадгалах</button>
            </div>
        </form>
    </div>
</div>

<script>
const allUnits = <?= json_encode(array_map(fn($u) => ['id' => $u['id'], 'parent_id' => $u['parent_id']], $units)) ?>;

function descendantIds(id) {
    let out = [], queue = [id];
    while (queue.length) {
        const cur = queue.shift();
        allUnits.filter(u => u.parent_id == cur).forEach(u => { out.push(u.id); queue.push(u.id); });
    }
    return out;
}

function openModal() {
    $('#modal').css('display', 'flex');
    $('#modalTitle').text('Нэгж нэмэх');
    $('#unitId,#unitName,#unitType,#unitDesc').val('');
    $('#unitParent,#unitManager').val('');
    $('#unitParent option').show();
    $('#unitActive').prop('checked', true);
    setTimeout(() => $('#unitName').focus(), 100);
}
function closeModal() { $('#modal').hide(); }

function editRow(row) {
    openModal();
    $('#modalTitle').text('Нэгж засах');
    $('#unitId').val(row.id);
    $('#unitName').val(row.name);
    $('#unitType').val(row.unit_type || '');
    $('#unitDesc').val(row.description || '');
    $('#unitActive').prop('checked', row.is_active == 1);
    $('#unitManager').val(row.manager_employee_id || '');

    // Өөрийгөө болон удам нэгжүүдээ эцэг сонголтоос хасах (мөчлөгөөс сэргийлэх)
    const blocked = new Set([row.id, ...descendantIds(row.id)]);
    $('#unitParent option').each(function () {
        const v = $(this).val();
        $(this).toggle(v === '' || !blocked.has(parseInt(v)));
    });
    $('#unitParent').val(row.parent_id || '');
}

$('#unitForm').on('submit', function (e) {
    e.preventDefault();
    const id = $('#unitId').val();
    $.post('<?= BASE_URL ?>/modules/org_units/ajax.php', {
        action: id ? 'update' : 'create', id,
        name: $('#unitName').val(),
        unit_type: $('#unitType').val(),
        parent_id: $('#unitParent').val(),
        manager_employee_id: $('#unitManager').val(),
        description: $('#unitDesc').val(),
        is_active: $('#unitActive').is(':checked') ? 1 : 0
    }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) { closeModal(); setTimeout(() => location.reload(), 600); }
    }, 'json');
});

function filterTable() {
    const s = $('#filterSearch').val().toLowerCase();
    let n = 0;
    $('.table-row').each(function () {
        const match = !s || $(this).data('name').includes(s);
        $(this).toggle(match);
        if (match) n++;
    });
    $('#rowCount').text(n);
}
function updateCount() { $('#rowCount').text($('.table-row:visible').length); }
$('#modal').on('click', function (e) { if ($(e.target).is('#modal')) closeModal(); });
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
