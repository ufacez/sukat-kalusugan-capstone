<?php

require_once __DIR__ . '/../includes/auth_middleware.php';

start_secure_session();

$currentUser = current_user();

if ($currentUser !== null) {
    header('Location: ' . redirect_for_current_user($currentUser));
    exit;
}

$notice = trim((string)($_GET['notice'] ?? ''));
$prefillCode = trim((string)($_GET['code'] ?? ''));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sukat Kalusugan | Activate Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="icon" type="image/svg+xml" href="../assets/img/logo/logo_forlight.svg">
    <script>
    (function(){
    var t=localStorage.getItem("theme");
    if(t==="dark"||(!t&&window.matchMedia("(prefers-color-scheme:dark)").matches)){
    document.documentElement.setAttribute("data-theme","dark");
    }
    })();
    </script>
</head>

<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-hero" aria-hidden="true">
            <svg class="hero-pattern" viewBox="0 0 400 400" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,320 C80,300 120,340 200,260 C260,200 300,220 400,140" />
                <path d="M0,360 C90,330 140,370 220,300 C280,250 330,270 400,190" />
                <path d="M0,280 C70,260 110,300 190,220 C250,160 290,180 400,100" />
            </svg>

            <div class="auth-logo-group">
                <div class="mark-standalone" aria-hidden="true">
                    <img src="../assets/img/logo/logotext_forlight.svg" alt="Sukat Kalusugan" class="mark-standalone-img" data-logo-light="../assets/img/logo/logotext_forlight.svg" data-logo-dark="../assets/img/logo/logotext_fordark.svg">
                </div>

                <div class="mark-standalone-icon" aria-hidden="true">
                    <img src="../assets/img/logo/logo_forlight.svg" alt="" class="mark-icon-img" data-logo-light="../assets/img/logo/logo_forlight.svg" data-logo-dark="../assets/img/logo/logo_fordark.svg">
                </div>

                <div class="auth-tagline">
                   Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.
                </div>
            </div>
        </section>

        <section class="auth-card" aria-labelledby="activate-title">
            <div class="auth-card-header">
                <p class="eyebrow">Staff Activation</p>
                <h2 id="activate-title">Activate your account</h2>
                <p class="muted">Enter the 6-character code from your administrator and set your password.</p>
            </div>

            <div class="flash flash-notice" id="globalNotice" style="display:none;" role="status"></div>

            <?php if ($notice !== ''): ?>
                <div class="flash flash-notice" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form class="auth-form" id="activateForm" novalidate>
                <label class="field" for="activation_code">
                    <span>Activation code</span>
                    <input id="activation_code" name="code" type="text" maxlength="8" autocomplete="off" placeholder="e.g. AX7K2M" required
                        value="<?php echo htmlspecialchars($prefillCode, ENT_QUOTES, 'UTF-8'); ?>"
                        style="text-transform:uppercase;letter-spacing:0.15em;font-weight:700;font-size:1.1rem;">
                    <span class="admin-field-message"></span>
                </label>

                <label class="field" for="activate_password">
                    <span>Password</span>
                    <div class="password-field">
                        <input id="activate_password" name="password" type="password" autocomplete="new-password" placeholder="At least 8 characters" required>
                        <button class="toggle-password" type="button" data-toggle-password aria-label="Show password">
                            <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            <svg class="eye-closed" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                </label>

                <label class="field" for="activate_password_confirm">
                    <span>Confirm password</span>
                    <input id="activate_password_confirm" name="password_confirm" type="password" autocomplete="new-password" placeholder="Re-type the password" required>
                </label>

                <ul class="admin-pw-checklist" data-pw-checklist-for="activate_password" style="list-style:none;padding:0;margin:0 0 8px;">
                    <li data-pw-rule="length">At least 8 characters</li>
                    <li data-pw-rule="upper">One uppercase letter</li>
                    <li data-pw-rule="number">One number</li>
                </ul>

                <div class="form-message" id="formMessage" aria-live="polite"></div>

                <button class="auth-submit" type="submit" id="activateBtn">
                    <span class="button-label">Activate Account</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>

                <p style="text-align:center;margin-top:1rem;font-size:0.85rem;">
                    <a class="link" href="login.php">Back to sign in</a>
                </p>
            </form>
        </section>
    </main>

    <script>
    (function(){
        function swapLogos(isDark){
            document.querySelectorAll('[data-logo-light]').forEach(function(img){
                img.src = isDark ? img.getAttribute('data-logo-dark') : img.getAttribute('data-logo-light');
            });
        }
        swapLogos(document.documentElement.getAttribute('data-theme') === 'dark');

        var toggle = document.querySelector('[data-auth-theme-toggle]');
        if (!toggle) return;
        var current = document.documentElement.getAttribute('data-theme') || 'light';
        var isDark = current === 'dark';
        toggle.addEventListener('click', function(){
            isDark = !isDark;
            document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            swapLogos(isDark);
        });
    })();
    </script>
    <script>
    (function(){
        var pwInputs = document.querySelectorAll('[data-pw-checklist-for]');
        pwInputs.forEach(function(input){
            var list = input.closest('form, .auth-form, .admin-field, .field, div')
                ?.querySelector('[data-pw-checklist-for="' + input.getAttribute('data-pw-checklist-for') + '"]');
            if (!list) return;
            input.addEventListener('input', function(){
                var v = input.value;
                list.querySelectorAll('[data-pw-rule]').forEach(function(li){
                    var rule = li.getAttribute('data-pw-rule');
                    var ok = false;
                    if (rule === 'length') ok = v.length >= 8;
                    else if (rule === 'upper') ok = /[A-Z]/.test(v);
                    else if (rule === 'number') ok = /[0-9]/.test(v);
                    li.style.color = ok ? '#16a34a' : '#94a3b8';
                    li.style.textDecoration = ok ? 'line-through' : 'none';
                });
            });
        });

        document.querySelectorAll('[data-toggle-password]').forEach(function(btn){
            btn.addEventListener('click', function(){
                var input = btn.closest('.password-field')?.querySelector('input') || btn.previousElementSibling;
                if (!input) return;
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
            });
        });

        var form = document.getElementById('activateForm');
        var msg = document.getElementById('formMessage');
        var btn = document.getElementById('activateBtn');
        var notice = document.getElementById('globalNotice');

        if (form) {
            form.addEventListener('submit', function(e){
                e.preventDefault();
                msg.textContent = '';
                msg.className = 'form-message';
                if (notice) notice.style.display = 'none';

                var code = (document.getElementById('activation_code')?.value || '').trim().toUpperCase();
                var password = document.getElementById('activate_password')?.value || '';
                var pwConfirm = document.getElementById('activate_password_confirm')?.value || '';

                if (code.length < 6) {
                    msg.textContent = 'Please enter a valid 6-character code.';
                    msg.className = 'form-message';
                    return;
                }
                if (password.length < 8) {
                    msg.textContent = 'Password must be at least 8 characters.';
                    msg.className = 'form-message';
                    return;
                }
                if (!/[A-Z]/.test(password)) {
                    msg.textContent = 'Password must contain at least one uppercase letter.';
                    msg.className = 'form-message';
                    return;
                }
                if (!/[0-9]/.test(password)) {
                    msg.textContent = 'Password must contain at least one number.';
                    msg.className = 'form-message';
                    return;
                }
                if (password !== pwConfirm) {
                    msg.textContent = 'Passwords do not match.';
                    msg.className = 'form-message';
                    return;
                }

                btn.disabled = true;
                btn.classList.add('is-loading');

                fetch('../api/auth/activate.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({code: code, password: password, password_confirm: pwConfirm})
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    if (data.success) {
                        msg.textContent = data.message;
                        msg.className = 'form-message is-success';
                        setTimeout(function(){
                            window.location.href = data.redirect_url || 'login.php';
                        }, 1500);
                    } else {
                        msg.textContent = data.message;
                        msg.className = 'form-message';
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    msg.textContent = 'Network error. Please try again.';
                    msg.className = 'form-message';
                });
            });
        }
    })();
    </script>
</body>
</html>
