
document.addEventListener('DOMContentLoaded', function() {
    // Bookings per month (example data, replace with PHP-generated JSON)
    var bookingsData = JSON.parse(document.getElementById('bookingsPerMonthData').textContent);
    var ctx1 = document.getElementById('bookingsPerMonthChart').getContext('2d');
    new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: bookingsData.labels,
            datasets: [{
                label: 'Bookings',
                data: bookingsData.data,
                backgroundColor: 'rgba(26,95,122,0.7)'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } }
        }
    });

    // Revenue per month (example data, replace with PHP-generated JSON)
    var revenueData = JSON.parse(document.getElementById('revenuePerMonthData').textContent);
    var ctx2 = document.getElementById('revenuePerMonthChart').getContext('2d');
    new Chart(ctx2, {
        type: 'line',
        data: {
            labels: revenueData.labels,
            datasets: [{
                label: 'Revenue',
                data: revenueData.data,
                borderColor: 'rgba(255,107,53,0.9)',
                backgroundColor: 'rgba(255,107,53,0.1)',
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: true } }
        }
    });
});
document.addEventListener('DOMContentLoaded', function() {
    // Bookings per month (example data, replace with PHP-generated JSON)
    var bookingsData = JSON.parse(document.getElementById('bookingsPerMonthData').textContent);
    var ctx1 = document.getElementById('bookingsPerMonthChart').getContext('2d');
    new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: bookingsData.labels,
            datasets: [{
                label: 'Bookings',
                data: bookingsData.data,
                backgroundColor: 'rgba(26,95,122,0.7)'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } }
        }
    });

    // Revenue per month (example data, replace with PHP-generated JSON)
    var revenueData = JSON.parse(document.getElementById('revenuePerMonthData').textContent);
    var ctx2 = document.getElementById('revenuePerMonthChart').getContext('2d');
    new Chart(ctx2, {
        type: 'line',
        data: {
            labels: revenueData.labels,
            datasets: [{
                label: 'Revenue',
                data: revenueData.data,
                borderColor: 'rgba(255,107,53,0.9)',
                backgroundColor: 'rgba(255,107,53,0.1)',
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: true } }
        }
    });
});
