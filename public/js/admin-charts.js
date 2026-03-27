
document.addEventListener('DOMContentLoaded', function() {
    // Bookings per month
    var bookingsEl = document.getElementById('bookingsPerMonthData');
    if (bookingsEl) {
        try {
            var bookingsData = JSON.parse(bookingsEl.textContent);
            var ctx1 = document.getElementById('bookingsPerMonthChart');
            if (ctx1 && bookingsData.labels && bookingsData.data) {
                new Chart(ctx1.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: bookingsData.labels,
                        datasets: [{
                            label: 'Bookings',
                            data: bookingsData.data,
                            backgroundColor: 'rgba(37,99,235,0.8)'
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }
        } catch (e) { console.error('bookings chart error', e); }
    }

    // Revenue per month
    var revenueEl = document.getElementById('revenuePerMonthData');
    if (revenueEl) {
        try {
            var revenueData = JSON.parse(revenueEl.textContent);
            var ctx2 = document.getElementById('revenuePerMonthChart');
            if (ctx2 && revenueData.labels && revenueData.data) {
                new Chart(ctx2.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: revenueData.labels,
                        datasets: [{
                            label: 'Revenue',
                            data: revenueData.data,
                            borderColor: 'rgba(255,107,53,0.9)',
                            backgroundColor: 'rgba(255,107,53,0.12)',
                            fill: true
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: true } } }
                });
            }
        } catch (e) { console.error('revenue chart error', e); }
    }
});
