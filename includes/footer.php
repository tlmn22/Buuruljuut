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
