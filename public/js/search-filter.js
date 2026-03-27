// public/js/search-filter.js

// public/js/search-filter.js

function debounce(fn, wait) {
    var t;
    return function() {
        var ctx = this, args = arguments;
        clearTimeout(t);
        t = setTimeout(function() { fn.apply(ctx, args); }, wait);
    };
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.searchable-table-container').forEach(function(container) {
        var input = container.querySelector('.search-input');
        var table = container.querySelector('table');
        if (!input || !table) return;

        var noResultsRow = null;

        var runFilter = function() {
            var value = input.value.trim().toLowerCase();
            var rows = table.querySelectorAll('tbody tr');
            var visible = 0;
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                var ok = value === '' || text.indexOf(value) !== -1;
                row.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });

            if (visible === 0) {
                if (!noResultsRow) {
                    noResultsRow = document.createElement('tr');
                    noResultsRow.className = 'no-results';
                    var colCount = table.querySelectorAll('thead th').length || 1;
                    noResultsRow.innerHTML = '<td colspan="' + colCount + '" style="text-align:center;padding:1rem;color:#6b7280;">No results found</td>';
                    table.querySelector('tbody').appendChild(noResultsRow);
                }
            } else if (noResultsRow) {
                noResultsRow.remove();
                noResultsRow = null;
            }
        };

        input.addEventListener('input', debounce(runFilter, 250));
        // run initial filter in case input has a value
        runFilter();
    });
});
