<?php
$pageTitle = 'OneFit захиалга';
$activePage = 'onefit';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
requireLogin();

$pdo = getDB();
$me = currentUser();
$myCode = $me['code'] ?? '';

// Одоогийн ажилтны мэдээлэл
$empSt = $pdo->prepare("SELECT id, last_name, first_name, position, phone FROM employees WHERE employee_code=? LIMIT 1");
$empSt->execute([$myCode]);
$emp = $empSt->fetch();

// Идэвхтэй бүртгэл
$activePeriod = $pdo->query("SELECT * FROM onefitoid WHERE Status=1 ORDER BY id DESC LIMIT 1")->fetch();

// Ажилтан энэ бүртгэлд бүртгүүлсэн эсэх
$myReg = null;
if ($activePeriod) {
  $regSt = $pdo->prepare("SELECT * FROM onefit WHERE ofId=? AND EmployeeNumber=? LIMIT 1");
  $regSt->execute([$activePeriod['id'], $myCode]);
  $myReg = $regSt->fetch();
}

// Бүх бүртгэлийн түүх (миний)
$historySt = $pdo->prepare("
    SELECT f.*, o.Name AS period_name
    FROM onefit f
    JOIN onefitoid o ON o.id = f.ofId
    WHERE f.EmployeeNumber = ?
    ORDER BY f.id DESC
");
$historySt->execute([$myCode]);
$history = $historySt->fetchAll();

$today = date('Y-m-d');
$regOpen = $activePeriod && $today >= $activePeriod['StartDate'] && $today <= $activePeriod['EndDate'];
$regNotStarted = $activePeriod && $today < $activePeriod['StartDate'];
?>

<div class="max-w-2xl mx-auto">

  <!-- Header — mobile-д нуусан -->
  <div class="hidden lg:flex items-center gap-4 mb-6">
    <div class="w-14 h-14 rounded-2xl overflow-hidden flex-shrink-0 bg-white border border-slate-200 flex items-center justify-center p-1">
      <img src="https://www.onefit.mn/themes/onefit/img/onefit_plain.png" alt="OneFit" class="w-full h-full object-contain" />
    </div>
    <div>
      <h1 class="text-2xl font-bold text-slate-800">OneFit захиалга</h1>
    </div>
  </div>

  <?php if (!$activePeriod): ?>
    <!-- Идэвхтэй бүртгэл байхгүй -->
    <div class="bg-white rounded-2xl border border-slate-200 p-10 text-center">
      <span class="material-icons-outlined text-5xl text-slate-300 block mb-3">event_busy</span>
      <p class="text-slate-500 font-medium">Одоогоор нээлттэй бүртгэл байхгүй байна</p>
      <p class="text-sm text-slate-400 mt-1">HR нээлттэй болгосны дараа захиалах боломжтой</p>
    </div>

  <?php else: ?>
    <!-- Идэвхтэй бүртгэлийн карт -->
    <div class="bg-gradient-to-br from-[#0d1f3c] to-[#1a3a6b] rounded-2xl p-4 lg:p-6 text-white mb-4 shadow-lg">
      <div class="flex items-start justify-between mb-4">
        <div>
          <p class="text-[#00c9a7] text-xs font-semibold uppercase tracking-widest mb-1">OneFit</p>
          <h2 class="text-2xl font-bold"><?= htmlspecialchars($activePeriod['Name']) ?></h2>
        </div>
        <div class="w-12 h-12 rounded-xl overflow-hidden bg-white/10 flex items-center justify-center p-1.5">
          <img src="https://www.onefit.mn/themes/onefit/img/onefit_plain.png" alt="OneFit"
            class="w-full h-full object-contain" />
        </div>
      </div>
      <div class="flex items-center gap-4 text-sm">
        <div class="flex items-center gap-1.5 text-white/70">
          <span class="material-icons-outlined text-sm">calendar_today</span>
          Бүртгэл дуусах: <span class="text-white font-medium ml-1"><?= $activePeriod['EndDate'] ?></span>
        </div>
        <?php if ($myReg): ?>
          <span class="ml-auto bg-[#00c9a7] text-[#0d1f3c] text-xs font-bold px-3 py-1 rounded-full">✓ Бүртгүүлсэн</span>
        <?php elseif ($regOpen): ?>
          <span class="ml-auto bg-amber-400 text-amber-900 text-xs font-bold px-3 py-1 rounded-full">Бүртгэл нээлттэй</span>
        <?php else: ?>
          <?php if ($regNotStarted): ?>
            <span class="ml-auto bg-blue-400 text-blue-900 text-xs font-bold px-3 py-1 rounded-full">Эхлэх:
              <?= $activePeriod['StartDate'] ?></span>
          <?php else: ?>
            <span class="ml-auto bg-white/20 text-white/70 text-xs font-bold px-3 py-1 rounded-full">Бүртгэл дууссан</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($myReg): ?>
      <!-- Бүртгүүлсэн мэдээлэл -->
      <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-5 mb-5">
        <div class="flex items-center gap-3 mb-3">
          <span class="material-icons-outlined text-emerald-600">check_circle</span>
          <p class="font-semibold text-emerald-800">Та амжилттай бүртгүүлсэн байна</p>
        </div>
        <div class="grid grid-cols-2 gap-3 text-sm">
          <div class="bg-white rounded-xl p-3">
            <p class="text-slate-400 text-xs mb-1">Утасны дугаар</p>
            <p class="font-semibold text-slate-800"><?= htmlspecialchars($myReg['PhoneNumber']) ?></p>
          </div>
          <div class="bg-white rounded-xl p-3">
            <p class="text-slate-400 text-xs mb-1">Эрх ашиглах хоног</p>
            <p class="font-semibold text-slate-800">
              <?= $myReg['Day'] == 2 ? '60 хоног' : '30 хоног' ?>
            </p>
          </div>
          <div class="bg-white rounded-xl p-3">
            <p class="text-slate-400 text-xs mb-1">Цалингаас суутгал</p>
            <p class="font-semibold text-slate-800"><?= $myReg['Salary'] ? 'Зөвшөөрсөн' : 'Зөвшөөрөөгүй' ?></p>
          </div>
          <div class="bg-white rounded-xl p-3">
            <p class="text-slate-400 text-xs mb-1">Бүртгүүлсэн огноо</p>
            <p class="font-semibold text-slate-800"><?= htmlspecialchars($myReg['Date']) ?></p>
          </div>
        </div>
        <button onclick="cancelReg()"
          class="mt-4 w-full py-2.5 border border-rose-200 text-rose-500 rounded-xl text-sm font-medium hover:bg-rose-50 transition-colors">
          Бүртгэлээс гарах
        </button>
      </div>

    <?php elseif ($regOpen): ?>
      <!-- Бүртгүүлэх форм -->
      <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5">
        <h3 class="font-bold text-slate-800 mb-1">OneFit-д бүртгүүлэх</h3>
        <p class="text-xs text-slate-400 mb-5">Мэдээллээ оруулаад баталгаажуулна уу</p>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">OneFit бүртгэлтэй утасны дугаар</label>
            <input type="tel" id="phoneInput" placeholder="99xxxxxx"
              value="<?= htmlspecialchars($emp['phone'] ?? '') ?>"
              class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[#00c9a7]/30 focus:border-[#00c9a7]" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">
              Төлбөр ухайн сарын 5-нь цалингаас суутгахыг анхаарна уу!!!
            </label>
          </div>
        </div>

        <button onclick="submitReg()"
          class="mt-5 w-full py-3 bg-[#00c9a7] hover:bg-[#00b396] text-white rounded-xl font-semibold text-sm transition-colors">
          Баталгаажуулах
        </button>

        <div class="mt-4 p-4 bg-amber-50 rounded-xl text-xs text-amber-800 space-y-1">
          <p class="font-semibold mb-1.5">Бүртгэл үүсгэхэд анхаарах зүйлс!</p>
          <p>• Та 50 кредитийг ашиглах хугацааг 30 эсхүл 60 хоногоор сонгох боломжтой.</p>
          <p>• Сарын нийт төлбөр 180,000 төгрөг байна. Байгууллагаас 60%, ажилтнаас 40%, төлнө.</p>
          <p>• Та сүүлийн 3 сар тасралтгүй 90%-иас дээш идэвхтэй ашиглалттай бол байгууллагаас 80%, ажилтнаас 20%-ийн төлбөр
            төлнө.</p>
        </div>
      </div>

    <?php elseif ($regNotStarted): ?>
      <div class="bg-slate-50 border border-slate-200 rounded-2xl p-6 text-center mb-5">
        <span class="material-icons-outlined text-4xl text-blue-300 block mb-2">schedule</span>
        <p class="text-slate-600 text-sm font-medium">Бүртгэл эхлээгүй байна</p>
        <p class="text-slate-400 text-xs mt-1"><?= $activePeriod['StartDate'] ?> -ноос эхэлнэ</p>
      </div>
    <?php else: ?>
      <div class="bg-slate-50 border border-slate-200 rounded-2xl p-6 text-center mb-5">
        <span class="material-icons-outlined text-4xl text-slate-300 block mb-2">lock_clock</span>
        <p class="text-slate-500 text-sm">Бүртгэлийн хугацаа дууссан байна</p>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <!-- HR: Гараас нэмэх -->
  <?php if ((isHR() || isSuperAdmin()) && $activePeriod): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6">
      <h3 class="font-semibold text-slate-800 mb-4 flex items-center gap-2">
        <span class="material-icons-outlined text-slate-400 text-lg">person_add</span>
        Ажилтан гараас нэмэх
      </h3>
      <div class="flex gap-3">
        <input type="text" id="adminEmpCode" placeholder="Ажилтны код"
          class="flex-1 border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[#00c9a7]/30 focus:border-[#00c9a7]" />
        <input type="tel" id="adminPhone" placeholder="Утасны дугаар"
          class="flex-1 border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-[#00c9a7]/30 focus:border-[#00c9a7]" />
        <button onclick="adminRegister()"
          class="px-5 py-2.5 bg-[#00c9a7] hover:bg-[#00b396] text-white rounded-xl text-sm font-semibold transition-colors whitespace-nowrap">
          Нэмэх
        </button>
      </div>
    </div>
  <?php endif; ?>

  <!-- Түүх -->
  <?php if (!empty($history)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="font-semibold text-slate-800">Миний бүртгэлийн түүх</h3>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-200">
          <tr>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500">Сар</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Хоног</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Огноо</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($history as $h): ?>
            <tr class="hover:bg-slate-50">
              <td class="px-5 py-3 font-medium text-slate-700"><?= htmlspecialchars($h['period_name']) ?></td>
              <td class="px-4 py-3 text-center text-slate-600"><?= $h['Day'] == 2 ? '60' : '30' ?> хоног</td>
              <td class="px-4 py-3 text-center text-slate-400 text-xs"><?= htmlspecialchars($h['Date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>

<script>
  const AJAX = '<?= BASE_URL ?>/modules/onefit/ajax.php';

  // Radio card visual update
  $(document).on('change', 'input[name="day"]', function () {
    $('.day-card').removeClass('border-[#00c9a7] bg-[#00c9a7]/5').addClass('border-slate-200');
    $(this).siblings('.day-card').addClass('border-[#00c9a7] bg-[#00c9a7]/5').removeClass('border-slate-200');
  });
  $(document).on('change', 'input[name="salary"]', function () {
    $('.salary-card').removeClass('border-[#00c9a7] bg-[#00c9a7]/5 text-[#00c9a7]').addClass('border-slate-200 text-slate-500');
    $(this).siblings('.salary-card').addClass('border-[#00c9a7] bg-[#00c9a7]/5 text-[#00c9a7]').removeClass('border-slate-200 text-slate-500');
  });

  function submitReg() {
    const phone = $('#phoneInput').val().trim();
    const day = $('input[name="day"]:checked').val();
    const salary = $('input[name="salary"]:checked').val();
    if (!phone) { showToast('Утасны дугаар оруулна уу', 'error'); return; }
    if (phone.length < 8) { showToast('Утасны дугаар буруу байна', 'error'); return; }
    $.post(AJAX, { action: 'register', phone, day, salary }, function (r) {
      showToast(r.message, r.success ? 'success' : 'error');
      if (r.success) setTimeout(() => location.reload(), 1000);
    }, 'json');
  }

  function adminRegister() {
    const emp_code = $('#adminEmpCode').val().trim();
    const phone = $('#adminPhone').val().trim();
    if (!emp_code) { showToast('Ажилтны код оруулна уу', 'error'); return; }
    if (!phone) { showToast('Утасны дугаар оруулна уу', 'error'); return; }
    $.post(AJAX, { action: 'admin_register', emp_code, phone }, function (r) {
      showToast(r.message, r.success ? 'success' : 'error');
      if (r.success) { $('#adminEmpCode').val(''); $('#adminPhone').val(''); }
    }, 'json');
  }

  function cancelReg() {
    if (!confirm('Бүртгэлээс гарахдаа итгэлтэй байна уу?')) return;
    $.post(AJAX, { action: 'cancel' }, function (r) {
      showToast(r.message, r.success ? 'success' : 'error');
      if (r.success) setTimeout(() => location.reload(), 1000);
    }, 'json');
  }
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>