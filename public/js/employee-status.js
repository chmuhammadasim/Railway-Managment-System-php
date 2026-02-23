// public/js/employee-status.js

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.status-select').forEach(function(select) {
        select.addEventListener('change', function() {
            var routeId = this.dataset.routeId;
            var status = this.value;
            var row = this.closest('tr');
            fetch('update_train_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'route_id=' + encodeURIComponent(routeId) + '&status=' + encodeURIComponent(status)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    row.classList.add('status-updated');
                    setTimeout(() => row.classList.remove('status-updated'), 1200);
                } else {
                    alert('Failed to update status: ' + (data.message || 'Unknown error'));
                }
            });
        });
    });
});
