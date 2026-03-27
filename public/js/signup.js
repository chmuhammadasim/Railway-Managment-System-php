// public/js/signup.js
document.addEventListener('DOMContentLoaded', function() {
    function qs(sel, ctx) { return (ctx||document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx||document).querySelectorAll(sel)); }

    // Password show/hide
    qsa('.password-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var input = document.getElementById(targetId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                this.textContent = 'Hide';
            } else {
                input.type = 'password';
                this.textContent = 'Show';
            }
            input.focus();
        });
    });

    // Password strength
    var pwd = qs('#password');
    var pwdBar = qs('.strength-meter .bar');
    var pwdText = qs('.strength-text');
    var confirm = qs('#confirm_password');
    var matchText = qs('.match-text');

    function scorePassword(password) {
        var score = 0;
        if (!password) return score;
        // length
        if (password.length >= 6) score += 1;
        if (password.length >= 10) score += 1;
        // variety
        if (/[a-z]/.test(password)) score += 1;
        if (/[A-Z]/.test(password)) score += 1;
        if (/[0-9]/.test(password)) score += 1;
        if (/[^A-Za-z0-9]/.test(password)) score += 1;
        return score; // 0-6
    }

    function updateStrength() {
        if (!pwd) return;
        var s = scorePassword(pwd.value);
        var pct = Math.min(100, Math.round((s / 6) * 100));
        if (pwdBar) pwdBar.style.width = pct + '%';
        if (pwdText) {
            var label = 'Very weak';
            if (s >= 5) label = 'Strong';
            else if (s >= 3) label = 'Medium';
            else if (s >= 1) label = 'Weak';
            pwdText.textContent = label;
        }
    }

    if (pwd) {
        pwd.addEventListener('input', function() {
            updateStrength();
            if (confirm) {
                if (confirm.value) {
                    matchText.textContent = (pwd.value === confirm.value) ? 'Passwords match' : 'Passwords do not match';
                    matchText.classList.toggle('text-success', pwd.value === confirm.value);
                    matchText.classList.toggle('text-danger', pwd.value !== confirm.value);
                } else {
                    matchText.textContent = '';
                    matchText.classList.remove('text-success','text-danger');
                }
            }
        });
        updateStrength();
    }

    if (confirm) {
        confirm.addEventListener('input', function() {
            if (!pwd) return;
            matchText.textContent = (pwd.value === confirm.value) ? 'Passwords match' : 'Passwords do not match';
            matchText.classList.toggle('text-success', pwd.value === confirm.value);
            matchText.classList.toggle('text-danger', pwd.value !== confirm.value);
        });
    }

    // Small enhancement: remove server-sent alerts after a few seconds
    var alerts = qsa('.alert');
    alerts.forEach(function(a){ setTimeout(function(){ a.style.transition = 'opacity 400ms'; a.style.opacity = 0; setTimeout(()=>a.remove(),450); }, 5000); });
});
