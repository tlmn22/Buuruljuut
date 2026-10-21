<?php
$pageTitle  = 'Миний профайл';
$activePage = 'profile';
include __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/dashboard_data.php';

$profile = dashProfile(getDB(), (int)$user['id']);
$photoUrl = ($profile['photo'] && file_exists(UPLOAD_PATH . basename($profile['photo']))) ? UPLOAD_URL . basename($profile['photo']) : null;
$card = 'bg-white dark:bg-[#1c212b] border border-slate-200 dark:border-[#2a2f3b] rounded-xl shadow-sm';
$rows = [
    ['badge', 'Ажилтны код', $profile['employee_code'] ?? '—'],
    ['work', 'Албан тушаал', $profile['position'] ?: '—'],
    ['account_tree', 'Нэгж', trim(($profile['unit_name'] ?? '—') . (!empty($profile['root_name']) ? ' · ' . $profile['root_name'] : ''))],
    ['event', 'Ажилд орсон', $profile['hire_date'] ? date('Y.m.d', strtotime($profile['hire_date'])) . ($profile['tenure'] ? ' (' . $profile['tenure'] . ')' : '') : '—'],
    ['mail', 'Имэйл', $profile['email'] ?: '—'],
    ['supervisor_account', 'Шууд удирдлага', $profile['manager'] ? employeeName($profile['manager']['last_name'], $profile['manager']['first_name']) . ($profile['manager']['position'] ? ' · ' . $profile['manager']['position'] : '') : '—'],
];
?>

<div class="max-w-3xl w-full mx-auto space-y-6">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a><span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Миний профайл</span>
        </nav>
        <h1 class="text-3xl font-bold">Миний профайл</h1>
    </div>

    <!-- Зураг -->
    <div class="<?= $card ?> p-6 flex flex-col sm:flex-row items-center gap-6">
        <div class="relative flex-shrink-0">
            <div id="avatarBox" class="w-32 h-32 rounded-full overflow-hidden bg-[#f1592a] flex items-center justify-center text-white text-4xl font-bold ring-4 ring-slate-100 dark:ring-[#272c38]">
                <?php if ($photoUrl): ?><img id="avatarImg" src="<?= htmlspecialchars($photoUrl) ?>" class="w-full h-full object-cover" alt="">
                <?php else: ?><span id="avatarInitial"><?= htmlspecialchars(mb_substr($profile['last_name'] ?? '?', 0, 1)) ?></span><?php endif; ?>
            </div>
            <button type="button" onclick="$('#photoInput').click()" title="Зураг солих"
                    class="absolute bottom-1 right-1 w-9 h-9 rounded-full bg-white dark:bg-[#272c38] border border-slate-200 dark:border-[#2a2f3b] shadow flex items-center justify-center text-[#f1592a] hover:bg-orange-50">
                <span class="material-icons-outlined" style="font-size:18px">photo_camera</span>
            </button>
        </div>
        <div class="flex-1 text-center sm:text-left">
            <p class="text-xl font-bold"><?= htmlspecialchars(employeeName($profile['last_name'] ?? '', $profile['first_name'] ?? '')) ?></p>
            <p class="text-sm text-slate-500 dark:text-[#8b93a1] mt-0.5"><?= htmlspecialchars(($profile['last_name'] ?? '') . ' ' . ($profile['first_name'] ?? '')) ?></p>
            <div class="flex flex-wrap gap-2 mt-4 justify-center sm:justify-start" id="photoActions">
                <button type="button" onclick="$('#photoInput').click()" class="bg-[#f1592a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors">Зураг солих</button>
                <button type="button" id="removeBtn" onclick="removePhoto()" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38] <?= $photoUrl ? '' : 'hidden' ?>">Зураг устгах</button>
            </div>
            <div class="flex flex-wrap gap-2 mt-4 justify-center sm:justify-start hidden" id="previewActions">
                <button type="button" onclick="savePhoto()" id="saveBtn" class="bg-[#f1592a] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors">Хадгалах</button>
                <button type="button" onclick="cancelPreview()" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38]">Болих</button>
            </div>
            <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-3">JPG, PNG, WEBP. Зургийг төвөөс нь дөрвөлжин тайрч, 400×400 хэмжээтэй болгоно.</p>
            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="pickPhoto(this)">
        </div>
    </div>

    <!-- Утас -->
    <div class="<?= $card ?> p-6">
        <h2 class="font-semibold text-lg mb-4">Холбоо барих</h2>
        <label class="block text-sm font-semibold mb-1.5" for="phone">Утасны дугаар</label>
        <div class="flex gap-2">
            <input id="phone" type="tel" maxlength="12" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" placeholder="99112233"
                   class="flex-1 border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30">
            <button type="button" onclick="savePhone()" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-[#c33e12] transition-colors">Хадгалах</button>
        </div>
    </div>

    <!-- Мэдээлэл (HR системээс — засах боломжгүй) -->
    <div class="<?= $card ?> overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-[#2a2f3b] flex items-center justify-between gap-3">
            <h2 class="font-semibold text-lg">Ажлын мэдээлэл</h2>
            <span class="text-xs text-slate-400 dark:text-[#8b93a1]">Засвар оруулах бол HR-т хандана уу</span>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($rows as [$icon, $label, $value]): ?>
            <div class="flex items-center gap-4 px-6 py-3">
                <span class="material-icons-outlined text-slate-400 dark:text-[#8b93a1]" style="font-size:20px"><?= $icon ?></span>
                <span class="w-36 flex-shrink-0 text-sm text-slate-500 dark:text-[#8b93a1]"><?= $label ?></span>
                <span class="text-sm font-medium min-w-0 break-words"><?= htmlspecialchars($value) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="<?= $card ?> p-6 flex items-center justify-between gap-4">
        <div>
            <h2 class="font-semibold">Нууц үг</h2>
            <p class="text-sm text-slate-500 dark:text-[#8b93a1]">Нэвтрэх нууц үгээ солих</p>
        </div>
        <a href="<?= BASE_URL ?>/change_password.php" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 dark:border-[#2a2f3b] hover:bg-slate-50 dark:hover:bg-[#272c38] flex items-center gap-1.5"><span class="material-icons-outlined" style="font-size:18px">key</span>Нууц үг солих</a>
    </div>
</div>

<script>
var AJAX = '<?= BASE_URL ?>/ajax/update_profile.php', croppedBlob = null, originalHtml = $('#avatarBox').html();
var SIZE = 400;

// Сонгосон зургийг төвөөс нь дөрвөлжин тайрч 400×400 JPEG болгоно (сервер дээр GD хэрэггүй)
function pickPhoto(input) {
    var file = input.files && input.files[0];
    input.value = '';
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { showToast('Зөвхөн JPG, PNG, WEBP зураг сонгоно уу.', 'error'); return; }
    if (file.size > 15 * 1024 * 1024) { showToast('Зураг хэт том байна (15MB хүртэл).', 'error'); return; }
    var url = URL.createObjectURL(file), img = new Image();
    img.onload = function () {
        var side = Math.min(img.naturalWidth, img.naturalHeight);
        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = SIZE;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, SIZE, SIZE); // PNG-ийн тунгалаг хэсгийг цагаан болгоно
        ctx.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, SIZE, SIZE);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) {
            croppedBlob = blob;
            $('#avatarBox').html('<img src="' + canvas.toDataURL('image/jpeg', 0.88) + '" class="w-full h-full object-cover" alt="">');
            $('#photoActions').addClass('hidden'); $('#previewActions').removeClass('hidden');
        }, 'image/jpeg', 0.88);
    };
    img.onerror = function () { URL.revokeObjectURL(url); showToast('Зургийг уншиж чадсангүй.', 'error'); };
    img.src = url;
}
function cancelPreview() {
    croppedBlob = null;
    $('#avatarBox').html(originalHtml);
    $('#previewActions').addClass('hidden'); $('#photoActions').removeClass('hidden');
}
function savePhoto() {
    if (!croppedBlob) return;
    var fd = new FormData();
    fd.append('action', 'update_photo');
    fd.append('photo', croppedBlob, 'profile.jpg');
    $('#saveBtn').prop('disabled', true).text('Хадгалж байна...');
    $.ajax({ url: AJAX, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) setTimeout(function () { location.reload(); }, 600);
            else $('#saveBtn').prop('disabled', false).text('Хадгалах');
        },
        error: function () { showToast('Хадгалахад алдаа гарлаа.', 'error'); $('#saveBtn').prop('disabled', false).text('Хадгалах'); }
    });
}
function removePhoto() {
    if (!confirm('Профайл зургаа устгах уу?')) return;
    $.post(AJAX, { action: 'remove_photo' }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) setTimeout(function () { location.reload(); }, 600);
    }, 'json');
}
function savePhone() {
    $.post(AJAX, { action: 'update_phone', phone: $('#phone').val() }, function (r) {
        showToast(r.message, r.success ? 'success' : 'error');
    }, 'json');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
