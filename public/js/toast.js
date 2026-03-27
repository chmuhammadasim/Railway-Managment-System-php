// public/js/toast.js
document.addEventListener('DOMContentLoaded', function() {
    function ensureContainer() {
        var container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'position-fixed top-0 end-0 p-3';
            container.style.zIndex = 1080;
            document.body.appendChild(container);
        }
        return container;
    }

    window.showToast = function(type, message, title, delay) {
        var container = ensureContainer();
        var toastEl = document.createElement('div');
        var bgClass = (type === 'success') ? 'text-bg-success' : (type === 'danger' ? 'text-bg-danger' : 'text-bg-info');
        toastEl.className = 'toast align-items-center ' + bgClass + ' border-0';
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');

        var inner = document.createElement('div');
        inner.className = 'd-flex';

        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message || '';
        inner.appendChild(body);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-close btn-close-white me-2 m-auto';
        btn.setAttribute('data-bs-dismiss', 'toast');
        btn.setAttribute('aria-label', 'Close');
        inner.appendChild(btn);

        toastEl.appendChild(inner);
        container.appendChild(toastEl);

        var bsToast = new bootstrap.Toast(toastEl, { delay: (delay || 5000) });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
    };

    // If server provided toast data, show it
    if (window.__server_toast) {
        var t = window.__server_toast;
        window.showToast(t.type || 'info', t.message || '', t.title || '', t.delay || 5000);
    }
});
