<?php
$pageTitle  = 'Ажилтан';
$activePage = 'employees';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
requireRole('superadmin');
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();

$rolesList = $pdo->query("SELECT id, code, name FROM roles WHERE is_active=1 ORDER BY id")->fetchAll();

$units = $pdo->query("SELECT id, name, parent_id FROM org_units ORDER BY parent_id IS NULL DESC, sort_order, name")->fetchAll();
$byParent = [];
foreach ($units as $u) { $byParent[$u['parent_id'] ?? 0][] = $u; }
$flatUnits = [];
function collectFlatU(array $byParent, ?int $parentId, int $depth, array &$out): void {
    foreach ($byParent[$parentId ?? 0] ?? [] as $u) {
        $out[] = ['id' => $u['id'], 'name' => $u['name'], 'depth' => $depth];
        collectFlatU($byParent, $u['id'], $depth + 1, $out);
    }
}
collectFlatU($byParent, null, 0, $flatUnits);

$list = $pdo->query("
    SELECT e.*, ou.name as unit_name,
           u.id as user_id, u.username, u.is_active as user_active,
           GROUP_CONCAT(DISTINCT r.code ORDER BY r.id) as role_codes,
           GROUP_CONCAT(DISTINCT r.name ORDER BY r.id SEPARATOR ', ') as role_names
    FROM employees e
    LEFT JOIN org_units ou ON ou.id = e.org_unit_id
    LEFT JOIN users u ON u.employee_id = e.id
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    GROUP BY e.id
    ORDER BY e.last_name, e.first_name
")->fetchAll();
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Ажилтан</span>
        </nav>
        <h1 class="text-3xl font-bold">Ажилтан</h1>
    </div>
    <button onclick="openModal()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium flex items-center gap-2 hover:bg-[#c33e12] transition-colors shadow-lg shadow-orange-100">
        <span class="material-icons-outlined text-sm">person_add</span> Ажилтан нэмэх
    </button>
</div>

<!-- Filter -->
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-4 flex flex-wrap items-center gap-3">
    <span class="material-icons-outlined text-slate-400 dark:text-[#8b93a1]">filter_list</span>
    <select id="filterUnit" onchange="filterTable()"
            class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
        <option value="">-- Бүх нэгж --</option>
        <?php foreach ($flatUnits as $f): ?>
            <option value="<?= $f['id'] ?>"><?= str_repeat('— ', $f['depth']) . htmlspecialchars($f['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="filterActive" onchange="filterTable()" class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
        <option value="">-- Бүх төлөв --</option>
        <option value="1">Идэвхтэй</option>
        <option value="0">Идэвхгүй</option>
    </select>
    <input type="text" id="filterSearch" onkeyup="filterTable()" placeholder="Нэр, код хайх..."
           class="border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30 w-44"/>
    <span class="text-sm text-slate-400 dark:text-[#8b93a1] ml-auto">Нийт: <strong id="rowCount"><?= count($list) ?></strong> ажилтан</span>
</div>

<!-- Table -->
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 dark:bg-[#14171f] border-b border-slate-200 dark:border-[#2a2f3b]">
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider w-12">#</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Ажилтан</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Код</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Албан тушаал</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Нэгж</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Утас</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Нэвтрэлт</th>
                    <th class="text-left px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Төлөв</th>
                    <th class="text-right px-6 py-4 font-semibold text-slate-500 dark:text-[#8b93a1] uppercase text-xs tracking-wider">Үйлдэл</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($list)): ?>
                <tr><td colspan="9" class="text-center py-16 text-slate-400 dark:text-[#5a6172]">
                    <span class="material-icons-outlined block mb-3" style="font-size:48px">groups</span>
                    Ажилтан байхгүй байна
                </td></tr>
            <?php else: foreach ($list as $i => $row): ?>
                <tr class="border-b border-slate-100 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors table-row"
                    id="row-<?= $row['id'] ?>"
                    data-unit="<?= $row['org_unit_id'] ?>"
                    data-active="<?= $row['is_active'] ?>"
                    data-name="<?= strtolower(htmlspecialchars($row['last_name'] . ' ' . $row['first_name'] . ' ' . $row['employee_code'])) ?>">
                    <td class="px-6 py-4 text-slate-400 dark:text-[#5a6172]"><?= $i + 1 ?></td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <?php if ($row['photo'] && file_exists(UPLOAD_PATH . $row['photo'])): ?>
                                <img src="<?= UPLOAD_URL . htmlspecialchars($row['photo']) ?>"
                                     class="w-10 h-10 rounded-full object-cover border-2 border-slate-200 dark:border-[#2a2f3b] flex-shrink-0"/>
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#f1592a] to-[#ff8a5c] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                    <?= mb_substr($row['last_name'], 0, 1) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <p class="font-semibold"><?= htmlspecialchars($row['last_name'] . '. ' . $row['first_name']) ?></p>
                                <p class="text-xs text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($row['register_number']) ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="font-mono text-xs bg-slate-100 dark:bg-[#272c38] text-slate-600 dark:text-[#c9cdd6] px-2 py-1 rounded-lg">
                            <?= htmlspecialchars($row['employee_code']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-slate-600 dark:text-[#c9cdd6]"><?= htmlspecialchars($row['position'] ?? '—') ?></td>
                    <td class="px-6 py-4 text-slate-600 dark:text-[#c9cdd6]"><?= htmlspecialchars($row['unit_name'] ?? '—') ?></td>
                    <td class="px-6 py-4 text-slate-600 dark:text-[#c9cdd6]"><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                    <td class="px-6 py-4">
                        <?php if ($row['user_id']): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-blue-50 dark:bg-[#272c38] text-[#f1592a] rounded-full text-xs font-medium">
                                <span class="material-icons-outlined" style="font-size:13px">verified_user</span>
                                <?= htmlspecialchars($row['role_names'] ?: 'Эрхгүй') ?>
                            </span>
                        <?php else: ?>
                            <span class="text-xs text-slate-400 dark:text-[#5a6172] italic">Бүртгэлгүй</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($row['is_active']): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-400 rounded-full text-xs font-medium"><span class="w-1.5 h-1.5 bg-green-500 rounded-full inline-block"></span> Идэвхтэй</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400 rounded-full text-xs font-medium"><span class="w-1.5 h-1.5 bg-red-500 rounded-full inline-block"></span> Идэвхгүй</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-right space-x-1">
                        <button onclick='editRow(<?= json_encode($row) ?>)'
                                class="inline-flex p-2 text-[#f1592a] hover:bg-orange-50 dark:hover:bg-[#272c38] rounded-lg transition-colors">
                            <span class="material-icons-outlined" style="font-size:18px">edit</span>
                        </button>
                        <button onclick="confirmDelete('<?= BASE_URL ?>/modules/employees/ajax.php', <?= $row['id'] ?>, function(){ $('#row-<?= $row['id'] ?>').fadeOut(300); updateCount(); })"
                                class="inline-flex p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition-colors">
                            <span class="material-icons-outlined" style="font-size:18px">delete</span>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL -->
<div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(15,23,42,0.45)">
    <div class="bg-white dark:bg-[#1c212b] rounded-2xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 bg-white dark:bg-[#1c212b] px-8 pt-8 pb-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between z-10">
            <h2 class="text-xl font-bold" id="modalTitle">Ажилтан нэмэх</h2>
            <button onclick="closeModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-[#272c38] rounded-lg transition-colors">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>
        <form id="empForm" enctype="multipart/form-data" class="px-8 py-6 space-y-5">
            <input type="hidden" id="empId" value=""/>
            <input type="hidden" id="empUserId" value=""/>

            <!-- Зураг upload -->
            <div class="flex items-center gap-6">
                <div class="relative">
                    <div id="photoPreview"
                         class="w-20 h-20 rounded-full bg-gradient-to-br from-[#f1592a] to-[#ff8a5c] flex items-center justify-center text-white text-2xl font-bold overflow-hidden border-4 border-slate-200 dark:border-[#2a2f3b] cursor-pointer"
                         onclick="$('#photoInput').click()">
                        <span class="material-icons-outlined">person</span>
                    </div>
                    <div class="absolute bottom-0 right-0 w-6 h-6 bg-[#f1592a] rounded-full flex items-center justify-center cursor-pointer"
                         onclick="$('#photoInput').click()">
                        <span class="material-icons-outlined text-white" style="font-size:14px">edit</span>
                    </div>
                </div>
                <div>
                    <p class="text-sm font-medium mb-1">Зураг оруулах</p>
                    <p class="text-xs text-slate-400 dark:text-[#8b93a1]">JPG, PNG • Дээд тал нь 2MB</p>
                    <input type="file" id="photoInput" name="photo" accept="image/*" class="hidden" onchange="previewPhoto(this)"/>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Овог <span class="text-red-500">*</span></label>
                    <input type="text" id="empLastName" required
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="Овог"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Нэр <span class="text-red-500">*</span></label>
                    <input type="text" id="empFirstName" required
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="Нэр"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Ажилтны код <span class="text-red-500">*</span></label>
                    <input type="text" id="empCode" required onkeyup="$('#loginUsername').text($(this).val() || '—')"
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="100001"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Регистрийн дугаар <span class="text-red-500">*</span></label>
                    <input type="text" id="empRegister" required
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="АА00000000"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Утасны дугаар</label>
                    <input type="text" id="empPhone"
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="99999999"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">И-мэйл</label>
                    <input type="email" id="empEmail"
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="name@example.com"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Ажилд орсон огноо</label>
                    <input type="date" id="empHireDate"
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Гэрээний төрөл</label>
                    <select id="empContractType" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
                        <option value="">-- Сонгох --</option>
                        <option value="Permanent">Байнгын</option>
                        <option value="Temporary">Түр</option>
                        <option value="Probation">Туршилтын</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Албан тушаал</label>
                    <input type="text" id="empPosition"
                           class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="Жишээ: Ахлах инженер"/>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Нэгж</label>
                    <select id="empUnit" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
                        <option value="">-- Сонгох --</option>
                        <?php foreach ($flatUnits as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= str_repeat('— ', $f['depth']) . htmlspecialchars($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <input type="checkbox" id="empActive" checked class="w-4 h-4 rounded cursor-pointer"/>
                <label for="empActive" class="text-sm font-medium cursor-pointer">Идэвхтэй</label>
            </div>

            <!-- Нэвтрэх эрх -->
            <div class="border-t border-slate-100 dark:border-[#2a2f3b] pt-4">
                <div class="flex items-center gap-3 mb-3">
                    <input type="checkbox" id="empCreateLogin" class="w-4 h-4 rounded cursor-pointer" onchange="$('#loginFields').toggle(this.checked)"/>
                    <label for="empCreateLogin" class="text-sm font-medium cursor-pointer">Нэвтрэх эрх (<span id="loginUsername">—</span>)</label>
                </div>
                <div id="loginFields" class="grid grid-cols-2 gap-4" style="display:none">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">
                            Нууц үг <span id="passHint" class="text-xs text-slate-400 dark:text-[#8b93a1]"></span>
                        </label>
                        <input type="password" id="empPassword"
                               class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30" placeholder="Хамгийн багадаа 6 тэмдэгт"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Эрхийн түвшин</label>
                        <select id="empRole" class="w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
                            <?php foreach ($rolesList as $r): ?>
                                <option value="<?= $r['id'] ?>" data-code="<?= htmlspecialchars($r['code']) ?>" <?= $r['code'] === 'employee' ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex gap-3 pt-2 border-t border-slate-100 dark:border-[#2a2f3b]">
                <button type="button" onclick="closeModal()"
                        class="flex-1 py-2.5 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38] transition-colors">
                    Болих
                </button>
                <button type="submit"
                        class="flex-1 py-2.5 bg-[#f1592a] text-white rounded-lg font-medium hover:bg-[#c33e12] transition-colors">
                    Хадгалах
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    $('#modal').css('display','flex');
    $('#modalTitle').text('Ажилтан нэмэх');
    $('#empId,#empUserId').val('');
    $('#empLastName,#empFirstName,#empCode,#empRegister,#empPhone,#empEmail,#empPosition,#empPassword').val('');
    $('#empHireDate').val('');
    $('#empUnit,#empContractType').val('');
    $('#empActive').prop('checked', true);
    $('#empCreateLogin').prop('checked', false).prop('disabled', false);
    $('#loginFields').hide();
    $('#loginUsername').text('—');
    $('#passHint').text('');
    $('#empRole').val($('#empRole option[data-code="employee"]').val());
    resetPhotoPreview();
}
function closeModal() { $('#modal').hide(); }

function resetPhotoPreview() {
    $('#photoPreview').html('<span class="material-icons-outlined">person</span>');
    $('#photoInput').val('');
}

function previewPhoto(input) {
    if(input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#photoPreview').html(`<img src="${e.target.result}" class="w-full h-full object-cover"/>`);
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function editRow(row) {
    openModal();
    $('#modalTitle').text('Ажилтан засах');
    $('#empId').val(row.id);
    $('#empLastName').val(row.last_name);
    $('#empFirstName').val(row.first_name);
    $('#empCode').val(row.employee_code);
    $('#empRegister').val(row.register_number);
    $('#empPhone').val(row.phone || '');
    $('#empEmail').val(row.email || '');
    $('#empPosition').val(row.position || '');
    $('#empHireDate').val(row.hire_date || '');
    $('#empContractType').val(row.contract_type || '');
    $('#empUnit').val(row.org_unit_id || '');
    $('#empActive').prop('checked', row.is_active == 1);
    $('#loginUsername').text(row.employee_code);

    if (row.photo) {
        $('#photoPreview').html(`<img src="<?= UPLOAD_URL ?>${row.photo}" class="w-full h-full object-cover"/>`);
    }

    if (row.user_id) {
        $('#empUserId').val(row.user_id);
        $('#empCreateLogin').prop('checked', true).prop('disabled', true); // байгаа бүртгэлийг унтраах боломжгүй, устгахдаа ашиглана
        $('#loginFields').show();
        $('#passHint').text('(хоосон үлдээвэл өөрчлөгдөхгүй)');
        const firstRole = (row.role_codes || '').split(',')[0];
        if (firstRole) $('#empRole').val($(`#empRole option[data-code="${firstRole}"]`).val());
    } else {
        $('#empCreateLogin').prop('checked', false).prop('disabled', false);
        $('#loginFields').hide();
        $('#passHint').text('');
    }
}

$('#empForm').on('submit', function(e) {
    e.preventDefault();
    const id = $('#empId').val();
    const formData = new FormData();
    formData.append('action',          id ? 'update' : 'create');
    formData.append('id',              id);
    formData.append('last_name',       $('#empLastName').val());
    formData.append('first_name',      $('#empFirstName').val());
    formData.append('employee_code',   $('#empCode').val());
    formData.append('register_number', $('#empRegister').val());
    formData.append('phone',           $('#empPhone').val());
    formData.append('email',           $('#empEmail').val());
    formData.append('position',        $('#empPosition').val());
    formData.append('hire_date',       $('#empHireDate').val());
    formData.append('contract_type',   $('#empContractType').val());
    formData.append('org_unit_id',     $('#empUnit').val());
    formData.append('is_active',       $('#empActive').is(':checked') ? 1 : 0);

    formData.append('create_login',    $('#empCreateLogin').is(':checked') ? 1 : 0);
    formData.append('user_id',         $('#empUserId').val());
    formData.append('password',        $('#empPassword').val());
    formData.append('role_id',         $('#empRole').val());

    const photo = $('#photoInput')[0].files[0];
    if(photo) formData.append('photo', photo);

    $.ajax({
        url:         '<?= BASE_URL ?>/modules/employees/ajax.php',
        type:        'POST',
        data:        formData,
        processData: false,
        contentType: false,
        success: function(r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if(r.success) { closeModal(); setTimeout(()=>location.reload(), 600); }
        },
        dataType: 'json'
    });
});

function filterTable() {
    const search = $('#filterSearch').val().toLowerCase();
    const unit    = $('#filterUnit').val();
    const active  = $('#filterActive').val();
    let count = 0;
    $('.table-row').each(function() {
        const ms = !search || $(this).data('name').includes(search);
        const mu = !unit   || $(this).data('unit') == unit;
        const ma = active === '' || $(this).data('active') == active;
        if(ms && mu && ma) { $(this).show(); count++; }
        else { $(this).hide(); }
    });
    $('#rowCount').text(count);
}
function updateCount() { $('#rowCount').text($('.table-row:visible').length); }

$('#modal').on('click', function(e) { if($(e.target).is('#modal')) closeModal(); });
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
