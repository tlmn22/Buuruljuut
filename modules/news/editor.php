<?php
$pageTitle  = 'Нийтлэл бичих';
$activePage = 'news';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
if (!newsCanPublish()) { header('Location: ' . BASE_URL . '/denied.php'); exit; }
$pdo = getDB();
$me  = (int)$_SESSION['user_id'];

$id = intval($_GET['id'] ?? 0);
$post = null; $postUnits = [];
if ($id) {
    $post = newsLoadPost($pdo, $id);
    if (!newsCanEdit($post, $me)) { header('Location: ' . BASE_URL . '/denied.php'); exit; }
    $u = $pdo->prepare("SELECT org_unit_id FROM news_post_units WHERE post_id = ?");
    $u->execute([$id]);
    $postUnits = array_map('intval', $u->fetchAll(PDO::FETCH_COLUMN));
    $pageTitle = 'Нийтлэл засах';
}

// Нэгжийн мод (сонгоход)
$units = $pdo->query("SELECT id, parent_id, unit_type, name FROM org_units WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
$byParent = [];
foreach ($units as $un) $byParent[(int)$un['parent_id']][] = $un;
$flat = [];
$walk = function ($parentId, $depth) use (&$walk, &$flat, $byParent) {
    foreach ($byParent[$parentId] ?? [] as $un) { $flat[] = $un + ['depth' => $depth]; $walk((int)$un['id'], $depth + 1); }
};
$walk(0, 0);

$mailReady = newsMailListReady();
$isPublished = $post && $post['status'] === 'published';
$scheduledValue = ($post && $post['published_at'] && strtotime($post['published_at']) > time()) ? date('Y-m-d\TH:i', strtotime($post['published_at'])) : '';

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$inp = 'w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<style>
#editor { min-height: 320px; font-size: 15px; }
.ql-toolbar.ql-snow, .ql-container.ql-snow { border-color: #e2e8f0; }
.ql-toolbar.ql-snow { border-radius: 10px 10px 0 0; }
.ql-container.ql-snow { border-radius: 0 0 10px 10px; }
html.dark .ql-toolbar.ql-snow, html.dark .ql-container.ql-snow { border-color: #2a2f3b; background: #272c38; }
html.dark .ql-snow .ql-stroke { stroke: #c9cdd6; } html.dark .ql-snow .ql-fill { fill: #c9cdd6; }
html.dark .ql-snow .ql-picker { color: #c9cdd6; } html.dark .ql-editor.ql-blank::before { color: #5a6172; }
</style>

<div class="max-w-5xl w-full mx-auto space-y-6">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a><span class="mx-2">/</span>
            <a href="<?= BASE_URL ?>/modules/news/index.php" class="hover:text-[#f1592a]">Мэдээ мэдээлэл</a><span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium"><?= $post ? 'Засах' : 'Шинэ нийтлэл' ?></span>
        </nav>
        <h1 class="text-3xl font-bold"><?= $post ? 'Нийтлэл засах' : 'Нийтлэл бичих' ?></h1>
    </div>

    <form id="postForm" class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6 items-start">
        <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-6 space-y-5">
            <div>
                <label class="block text-sm font-semibold mb-1.5">Гарчиг <span class="text-red-500">*</span></label>
                <input id="title" type="text" maxlength="255" required class="<?= $inp ?> text-base font-semibold" value="<?= htmlspecialchars($post['title'] ?? '') ?>" placeholder="Мэдээний гарчиг">
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5">Нүүр зураг</label>
                <div id="coverBox" class="relative rounded-xl border-2 border-dashed border-slate-200 dark:border-[#2a2f3b] h-48 flex items-center justify-center cursor-pointer overflow-hidden hover:border-[#f1592a]/50" onclick="$('#cover').click()">
                    <img id="coverPreview" src="<?= !empty($post['cover_image']) ? NEWS_UPLOAD_URL . htmlspecialchars($post['cover_image']) : '' ?>" class="absolute inset-0 w-full h-full object-cover <?= empty($post['cover_image']) ? 'hidden' : '' ?>" alt="">
                    <span id="coverHint" class="text-sm text-slate-400 flex flex-col items-center gap-1 <?= !empty($post['cover_image']) ? 'hidden' : '' ?>"><span class="material-icons-outlined" style="font-size:32px">add_photo_alternate</span>Зураг сонгох (JPG, PNG, WEBP, 5MB хүртэл)</span>
                    <button type="button" id="coverRemove" onclick="event.stopPropagation(); removeCover()" class="absolute top-2 right-2 p-1.5 rounded-lg bg-black/50 text-white hover:bg-black/70 <?= empty($post['cover_image']) ? 'hidden' : '' ?>" title="Зураг хасах"><span class="material-icons-outlined" style="font-size:18px">close</span></button>
                </div>
                <input id="cover" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="previewCover(this)">
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5">Агуулга <span class="text-red-500">*</span></label>
                <div id="editor"><?= $post['body'] ?? '' ?></div>
            </div>
        </div>

        <aside class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-5 space-y-5 lg:sticky lg:top-24">
            <div>
                <label class="block text-sm font-semibold mb-1.5">Ангилал <span class="text-red-500">*</span></label>
                <select id="category" class="<?= $inp ?>">
                    <?php foreach (NEWS_CATEGORIES as $key => $c): ?>
                        <option value="<?= $key ?>" <?= ($post['category'] ?? 'notice') === $key ? 'selected' : '' ?>><?= $c['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <span class="block text-sm font-semibold mb-1.5">Хэнд харагдах</span>
                <label class="flex items-center gap-2 text-sm py-1"><input type="radio" name="audience" value="all" <?= ($post['audience'] ?? 'all') === 'all' ? 'checked' : '' ?>> Бүх ажилтан</label>
                <label class="flex items-center gap-2 text-sm py-1"><input type="radio" name="audience" value="units" <?= ($post['audience'] ?? '') === 'units' ? 'checked' : '' ?>> Сонгосон нэгжүүд</label>
                <div id="unitPicker" class="mt-2 max-h-60 overflow-y-auto border border-slate-200 dark:border-[#2a2f3b] rounded-lg p-2 space-y-0.5 <?= ($post['audience'] ?? 'all') === 'units' ? '' : 'hidden' ?>">
                    <?php foreach ($flat as $un): ?>
                        <label class="flex items-start gap-2 text-xs py-1 rounded hover:bg-slate-50 dark:hover:bg-[#272c38]" style="padding-left:<?= 4 + $un['depth'] * 14 ?>px">
                            <input type="checkbox" class="unit-cb mt-0.5" value="<?= (int)$un['id'] ?>" <?= in_array((int)$un['id'], $postUnits, true) ? 'checked' : '' ?>>
                            <span class="<?= $un['depth'] === 0 ? 'font-semibold' : '' ?>"><?= htmlspecialchars($un['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1">Нэгж сонгоход түүний доорх бүх нэгж хамрагдана.</p>
            </div>
            <div class="space-y-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" id="is_pinned" <?= !empty($post['is_pinned']) ? 'checked' : '' ?>> Дээр бэхлэх</label>
                <label class="flex items-center gap-2"><input type="checkbox" id="allow_comments" <?= ($post['allow_comments'] ?? 1) ? 'checked' : '' ?>> Сэтгэгдэл зөвшөөрөх</label>
                <?php if ($mailReady): ?>
                <label class="flex items-start gap-2" id="emailOpt"><input type="checkbox" id="notify_email" class="mt-1" <?= !empty($post['notify_email']) ? 'checked' : '' ?> <?= !empty($post['notified_at']) ? 'disabled' : '' ?>>
                    <span>Бүх ажилтанд имэйлээр мэдэгдэх<br><span class="text-xs text-slate-400"><?= !empty($post['notified_at']) ? 'Мэдэгдэл аль хэдийн явсан' : 'Зөвхөн "Бүх ажилтан" үед' ?></span></span></label>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5">Нийтлэх цаг <span class="font-normal text-slate-400">(заавал биш)</span></label>
                <input id="published_at" type="datetime-local" class="<?= $inp ?>" value="<?= htmlspecialchars($scheduledValue) ?>">
                <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1">Хоосон бол "Нийтлэх" дарахад шууд нийтлэгдэнэ.</p>
            </div>
            <div class="flex flex-col gap-2 pt-1">
                <button type="button" onclick="savePost('published')" class="w-full bg-[#f1592a] text-white py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors"><?= $isPublished ? 'Хадгалах' : 'Нийтлэх' ?></button>
                <button type="button" onclick="savePost('draft')" class="w-full border border-slate-200 dark:border-[#2a2f3b] py-2.5 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-[#272c38] transition-colors"><?= $isPublished ? 'Ноорог болгох' : 'Ноорог хадгалах' ?></button>
            </div>
        </aside>
    </form>
</div>

<script>
var quill = new Quill('#editor', {
    theme: 'snow',
    placeholder: 'Мэдээний агуулгаа энд бичнэ үү...',
    modules: { toolbar: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline', 'strike'], [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'link'], ['clean']] }
});
var removeCoverFlag = false;
function previewCover(input) {
    if (!input.files || !input.files[0]) return;
    var r = new FileReader();
    r.onload = function (e) { $('#coverPreview').attr('src', e.target.result).removeClass('hidden'); $('#coverHint').addClass('hidden'); $('#coverRemove').removeClass('hidden'); };
    r.readAsDataURL(input.files[0]);
    removeCoverFlag = false;
}
function removeCover() {
    $('#cover').val(''); $('#coverPreview').attr('src', '').addClass('hidden'); $('#coverHint').removeClass('hidden'); $('#coverRemove').addClass('hidden');
    removeCoverFlag = true;
}
$('input[name=audience]').on('change', function () {
    var units = this.value === 'units';
    $('#unitPicker').toggleClass('hidden', !units);
    $('#emailOpt').toggleClass('opacity-50', units).find('input').prop('disabled', units || <?= !empty($post['notified_at']) ? 'true' : 'false' ?>);
}).filter(':checked').trigger('change');

function savePost(status) {
    var fd = new FormData();
    fd.append('action', 'save_post');
    fd.append('id', '<?= $id ?: '' ?>');
    fd.append('title', $('#title').val().trim());
    fd.append('category', $('#category').val());
    fd.append('body', quill.getText().trim() ? quill.root.innerHTML : '');
    fd.append('audience', $('input[name=audience]:checked').val());
    fd.append('unit_ids', JSON.stringify($('.unit-cb:checked').map(function () { return +this.value; }).get()));
    fd.append('is_pinned', $('#is_pinned').is(':checked') ? 1 : 0);
    fd.append('allow_comments', $('#allow_comments').is(':checked') ? 1 : 0);
    fd.append('notify_email', $('#notify_email').is(':checked:enabled') ? 1 : 0);
    fd.append('published_at', $('#published_at').val());
    fd.append('status', status);
    if ($('#cover')[0].files[0]) fd.append('cover', $('#cover')[0].files[0]);
    if (removeCoverFlag) fd.append('remove_cover', 1);
    $.ajax({ url: '<?= BASE_URL ?>/modules/news/ajax.php', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) setTimeout(function () { location.href = '<?= BASE_URL ?>/modules/news/post.php?id=' + r.id; }, 600);
        },
        error: function () { showToast('Хадгалахад алдаа гарлаа.', 'error'); }
    });
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
