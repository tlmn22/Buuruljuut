</div><!-- end page content -->
</main>
</div><!-- end flex -->
<script>
// Mobile sidebar
function openMobileSidebar() {
    document.getElementById('mobileSidebar').classList.add('open');
    document.getElementById('mobileOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeMobileSidebar() {
    document.getElementById('mobileSidebar').classList.remove('open');
    document.getElementById('mobileOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

// Өдөр/шөнө горим сэлгэх
function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
}

// Accordion toggle — button-аас parent .menu-group-г хайна (ID давхардлаас зайлсхийнэ)
function toggleGroup(btn) {
    const group = btn.closest('.menu-group');
    const items = group.querySelector('.accordion-items');
    const arrow = group.querySelector('.accordion-arrow');
    if (!items) return;
    const isHidden = items.classList.contains('hidden');
    items.classList.toggle('hidden', !isHidden);
    arrow.classList.toggle('rotate-180', isHidden);
}

// Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/buuruljuut/sw.js')
        .catch(() => {});
}

// Toast helper
function showToast(msg, type='success'){
    const bg = type==='success' ? '#22c55e' : '#ef4444';
    const el = $('<div>').css({position:'fixed',bottom:'24px',right:'24px',background:bg,color:'#fff',padding:'12px 20px',borderRadius:'12px',fontSize:'14px',fontWeight:500,zIndex:9999,boxShadow:'0 4px 20px rgba(0,0,0,0.15)'}).text(msg);
    $('body').append(el);
    setTimeout(()=>el.fadeOut(300,()=>el.remove()),3000);
}
// ─── 🔔 Мэдэгдэл: 60 секунд тутам шалгана, дарахад жагсаалт нээгдэнэ ───
var NOTIF_URL = '<?= BASE_URL ?>/ajax/notifications.php';
function notifEsc(s) { return $('<div>').text(s == null ? '' : s).html(); }
function renderNotif(r) {
    if (!r || !r.success) return;
    var $b = $('#notifBadge');
    $b.text(r.unread > 99 ? '99+' : r.unread).toggleClass('hidden', !r.unread);
    $('#notifList').html(r.items.length ? r.items.map(function (n) {
        return '<a href="' + notifEsc(n.link || '#') + '" data-id="' + n.id + '" class="notif-item flex gap-3 px-4 py-3 border-b border-slate-50 dark:border-[#232833] hover:bg-slate-50 dark:hover:bg-[#20242e] ' + (n.unread ? 'bg-orange-50/60 dark:bg-[#2a1810]/40' : '') + '">' +
            '<span class="w-2 h-2 mt-1.5 rounded-full flex-shrink-0 ' + (n.unread ? 'bg-[#f1592a]' : 'bg-transparent') + '"></span>' +
            '<span class="min-w-0 flex-1"><span class="block text-sm text-[#1c2430] dark:text-[#f2f3f6] leading-snug">' + notifEsc(n.title) + '</span>' +
            (n.body ? '<span class="block text-xs text-slate-500 dark:text-[#8b93a1] mt-0.5 line-clamp-2">' + notifEsc(n.body) + '</span>' : '') +
            '<span class="block text-[11px] text-slate-400 dark:text-[#5a6172] mt-1">' + (n.actor ? notifEsc(n.actor) + ' · ' : '') + notifEsc(n.time) + '</span></span></a>';
    }).join('') : '<p class="px-4 py-10 text-center text-sm text-slate-400 dark:text-[#5a6172]">Мэдэгдэл алга</p>');
}
function loadNotif() { $.getJSON(NOTIF_URL, { action: 'list' }, renderNotif); }
function toggleNotif(e) {
    e.stopPropagation();
    var $p = $('#notifPanel').toggleClass('hidden');
    if (!$p.hasClass('hidden')) loadNotif();
}
function readAllNotif() { $.post(NOTIF_URL, { action: 'read_all' }, loadNotif, 'json'); }
$(document).on('click', '.notif-item', function () {
    // Уншсан болгоод холбоос руу шилжинэ (sendBeacon — хуудас солигдох үед ч хүсэлт алдагдахгүй)
    var fd = new FormData(); fd.append('action', 'read'); fd.append('id', this.dataset.id);
    navigator.sendBeacon ? navigator.sendBeacon(NOTIF_URL, fd) : $.post(NOTIF_URL, { action: 'read', id: this.dataset.id });
});
$(document).on('click', function (e) { if (!$(e.target).closest('#notifWrap').length) $('#notifPanel').addClass('hidden'); });
if (document.getElementById('notifWrap')) setInterval(function () { if (!document.hidden) loadNotif(); }, 60000);

// Confirm delete helper
function confirmDelete(url, id, onSuccess){
    if(!confirm('Устгахдаа итгэлтэй байна уу?')) return;
    $.post(url, {id:id, action:'delete'}, function(r){
        showToast(r.message, r.success?'success':'error');
        if(r.success && onSuccess) onSuccess();
    },'json');
}
</script>
</body>
</html>
