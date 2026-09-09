// KPI System - Main JS

// AJAX helper - JSON POST
function ajaxPost(url, data, callback) {
    $.ajax({
        url: url,
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(res) { callback(res); },
        error: function() { callback({ success: false, message: 'Сервертэй холбогдоход алдаа гарлаа.' }); }
    });
}

// Show toast notification
function showToast(message, type = 'success') {
    const bg = type === 'success' ? 'bg-success' : 'bg-danger';
    const html = `
        <div class="toast align-items-center text-white ${bg} border-0 show position-fixed bottom-0 end-0 m-3" style="z-index:9999">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;
    $('body').append(html);
    setTimeout(() => $('.toast').last().remove(), 3000);
}

// Confirm delete
function confirmDelete(url, id, callback) {
    if (confirm('Устгахдаа итгэлтэй байна уу?')) {
        ajaxPost(url, { id: id, action: 'delete' }, function(res) {
            showToast(res.message, res.success ? 'success' : 'error');
            if (res.success && callback) callback();
        });
    }
}

// DataTable default init (if needed later)
$(document).ready(function() {
    // dismiss modals on success if needed
});
