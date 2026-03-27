// public/js/form-validation.js
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form').forEach(function(form) {
        // Skip forms that explicitly opt out
        if (form.hasAttribute('data-no-validate')) return;

        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                form.classList.add('was-validated');
                var firstInvalid = form.querySelector(':invalid');
                if (firstInvalid) firstInvalid.focus();
            }
        }, false);
    });
});
