<?php
$pageTitle = 'OneFit Admin';
$activePage = 'onefit-admin';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
requireLogin();

if (!isHR() && !isSuperAdmin()) {
    echo '<p class="p-8 text-red-500">Эрх хүрэлцэхгүй.</p>';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php';
    exit;
}

$pdo = getDB();

// Бүртгэлүүдийн жагсаалт
$periods = $pdo->query("
    SELECT o.*, COUNT(f.id) AS reg_count
    FROM onefitoid o
    LEFT JOIN onefit f ON f.ofId = o.id
    GROUP BY o.id
    ORDER BY o.id DESC
")->fetchAll();

// Харах бүртгэл
$viewId = (int)($_GET['view'] ?? 0);
$viewPeriod = null;
$regs = [];
if ($viewId) {
    $viewPeriod = $pdo->prepare("SELECT * FROM onefitoid WHERE id=? LIMIT 1");
    $viewPeriod->execute([$viewId]);
    $viewPeriod = $viewPeriod->fetch();

    if ($viewPeriod) {
        $regs = $pdo->prepare("
            SELECT f.*, e.last_name, e.first_name, e.position,
                   ou.name AS div_name
            FROM onefit f
            LEFT JOIN employees e ON e.employee_code = f.EmployeeNumber
            LEFT JOIN org_units ou ON ou.id = e.org_unit_id
            WHERE f.ofId = ?
            ORDER BY f.Date ASC
        ");
        $regs->execute([$viewId]);
        $regs = $regs->fetchAll();
    }
}
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
  <div>
    <div class="flex items-center gap-3 mb-1">
      <img src="https://www.onefit.mn/themes/onefit/img/onefit_plain.png" alt="OneFit" class="h-7 object-contain"/>
      <h1 class="text-2xl font-bold text-slate-800">Admin</h1>
    </div>
    <p class="text-sm text-slate-400 mt-0.5">Сарын бүртгэлийг удирдах</p>
  </div>
  <button onclick="openCreateModal()"
    class="flex items-center gap-2 px-4 py-2.5 bg-[#00c9a7] text-white rounded-xl text-sm font-semibold hover:bg-[#00b396] transition-colors">
    <span class="material-icons-outlined text-sm">add</span> Шинэ бүртгэл нээх
  </button>
</div>

<div class="grid grid-cols-1 <?= $viewPeriod ? 'lg:grid-cols-2' : '' ?> gap-6">

  <!-- Бүртгэлийн жагсаалт -->
  <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100">
      <h3 class="font-semibold text-slate-800">Бүртгэлүүд</h3>
    </div>
    <table class="w-full text-sm">
      <thead class="bg-slate-50 border-b border-slate-200">
        <tr>
          <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500">Тайлбар</th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Эхлэх</th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Дуусах</th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Бүртгэл</th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Төлөв</th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($periods as $p): ?>
        <tr class="hover:bg-slate-50 <?= $viewId == $p['id'] ? 'bg-[#00c9a7]/5' : '' ?>">
          <td class="px-5 py-3 font-medium text-slate-700"><?= htmlspecialchars($p['Name']) ?></td>
          <td class="px-4 py-3 text-center text-xs text-slate-500"><?= $p['StartDate'] ?></td>
          <td class="px-4 py-3 text-center text-xs text-slate-500"><?= $p['EndDate'] ?></td>
          <td class="px-4 py-3 text-center font-semibold text-slate-700"><?= $p['reg_count'] ?></td>
          <td class="px-4 py-3 text-center">
            <?php if ($p['Status']): ?>
              <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Идэвхтэй</span>
            <?php else: ?>
              <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-500">Дууссан</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center">
            <div class="flex items-center justify-center gap-1">
              <a href="?view=<?= $p['id'] ?>"
                class="p-1.5 rounded-lg <?= $viewId == $p['id'] ? 'bg-[#00c9a7] text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?> transition-colors"
                title="Харах">
                <span class="material-icons-outlined text-sm">visibility</span>
              </a>
              <?php if ($p['Status']): ?>
              <button onclick="closePeriod(<?= $p['id'] ?>)"
                class="p-1.5 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-100 transition-colors" title="Хаах">
                <span class="material-icons-outlined text-sm">lock</span>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($viewPeriod): ?>
  <!-- Бүртгүүлсэн ажилтнуудын жагсаалт -->
  <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
      <div>
        <h3 class="font-semibold text-slate-800"><?= htmlspecialchars($viewPeriod['Name']) ?></h3>
        <p class="text-xs text-slate-400 mt-0.5">Нийт <?= count($regs) ?> ажилтан бүртгүүлсэн</p>
      </div>
      <a href="ajax.php?action=export&id=<?= $viewId ?>"
        class="flex items-center gap-1.5 px-3 py-2 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition-colors">
        <span class="material-icons-outlined text-sm">download</span> Excel
      </a>
    </div>
    <?php if (empty($regs)): ?>
      <div class="py-12 text-center text-slate-400">
        <span class="material-icons-outlined text-4xl block mb-2">person_off</span>
        <p class="text-sm">Бүртгүүлсэн ажилтан байхгүй</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200 sticky top-0">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">Ажилтан</th>
              <th class="px-3 py-3 text-center text-xs font-semibold text-slate-500">Утас</th>
              <th class="px-3 py-3 text-center text-xs font-semibold text-slate-500">Хоног</th>
              <th class="px-3 py-3 text-center text-xs font-semibold text-slate-500">Суутгал</th>
              <th class="px-3 py-3 text-center text-xs font-semibold text-slate-500">Огноо</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($regs as $r): ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <p class="font-medium text-slate-800">
                  <?= $r['last_name'] ? htmlspecialchars($r['last_name'].' '.$r['first_name']) : htmlspecialchars($r['EmployeeNumber']) ?>
                </p>
                <p class="text-xs text-slate-400"><?= htmlspecialchars($r['EmployeeNumber']) ?> · <?= htmlspecialchars($r['div_name'] ?? '—') ?></p>
              </td>
              <td class="px-3 py-3 text-center text-slate-600 text-xs"><?= htmlspecialchars($r['PhoneNumber']) ?></td>
              <td class="px-3 py-3 text-center">
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?= $r['Day'] == 2 ? 'bg-violet-100 text-violet-700' : 'bg-blue-100 text-blue-700' ?>">
                  <?= $r['Day'] == 2 ? '60' : '30' ?> хоног
                </span>
              </td>
              <td class="px-3 py-3 text-center">
                <span class="text-xs <?= $r['Salary'] ? 'text-emerald-600' : 'text-rose-500' ?> font-medium">
                  <?= $r['Salary'] ? 'Тийм' : 'Үгүй' ?>
                </span>
              </td>
              <td class="px-3 py-3 text-center text-xs text-slate-400"><?= htmlspecialchars($r['Date']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<!-- Шинэ бүртгэл нээх modal -->
<div id="createModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
  <div class="bg-white rounded-2xl shadow-xl p-6 w-96">
    <h3 class="font-bold text-slate-800 mb-4">Шинэ бүртгэл нээх</h3>
    <div class="space-y-3">
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Нэр</label>
        <input id="newName" type="text" placeholder="2026 оны 5-р сарын бүртгэл"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#00c9a7]/30"/>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Эхлэх огноо</label>
          <input id="newStart" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#00c9a7]/30"/>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Дуусах огноо</label>
          <input id="newEnd" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#00c9a7]/30"/>
        </div>
      </div>
    </div>
    <div class="flex gap-2 justify-end mt-5">
      <button onclick="$('#createModal').addClass('hidden')" class="px-4 py-2 text-sm text-slate-500 hover:text-slate-700">Болих</button>
      <button onclick="createPeriod()" class="px-4 py-2 bg-[#00c9a7] text-white rounded-lg text-sm font-semibold hover:bg-[#00b396]">Нээх</button>
    </div>
  </div>
</div>

<script>
const AJAX = '<?= BASE_URL ?>/modules/onefit/ajax.php';

function openCreateModal() { $('#createModal').removeClass('hidden'); }
$('#createModal').on('click', function(e) { if (e.target === this) $(this).addClass('hidden'); });

function createPeriod() {
  const name  = $('#newName').val().trim();
  const start = $('#newStart').val();
  const end   = $('#newEnd').val();
  if (!name || !start || !end) { showToast('Бүх талбарыг бөглөнө үү', 'error'); return; }
  $.post(AJAX, { action: 'create_period', name, start, end }, function(r) {
    showToast(r.message, r.success ? 'success' : 'error');
    if (r.success) setTimeout(() => location.reload(), 800);
  }, 'json');
}

function closePeriod(id) {
  if (!confirm('Энэ бүртгэлийг хаахдаа итгэлтэй байна уу?')) return;
  $.post(AJAX, { action: 'close_period', id }, function(r) {
    showToast(r.message, r.success ? 'success' : 'error');
    if (r.success) setTimeout(() => location.reload(), 800);
  }, 'json');
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
