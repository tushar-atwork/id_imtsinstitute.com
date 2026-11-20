<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui/layout.php';

if (Auth::user()) {
    header('Location: ' . ui_landing_url($crm, (int)Auth::user()['id']));
    exit;
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (Auth::login($crm, $username, $password)) {
        header('Location: ' . ui_landing_url($crm, (int)Auth::user()['id']));
        exit;
    }
    $error = 'Invalid username or password.';
}
ui_start(['title' => 'Sign in', 'body_class' => 'auth-page']);
?>
<div class="auth">
  <section class="auth-hero" aria-hidden="true">
    <a class="brand" tabindex="-1"><span class="brand-mark">IMTS</span><span class="label">Admission Recovery CRM</span></a>
    <div>
      <h2>Follow up, update, done.</h2>
      <p>Everything you need to recover pending admissions in one place: your records, follow-ups, call and WhatsApp, and status updates.</p>
      <ul>
        <li>✓ Your assigned records, always up to date from the ERP</li>
        <li>✓ Call, WhatsApp or email a student in one tap</li>
        <li>✓ Documents → Form Filled → Verification, tracked end to end</li>
      </ul>
    </div>
    <div class="small">© IMTS Institute</div>
  </section>
  <main class="auth-panel" id="main" tabindex="-1">
    <div class="auth-card">
      <h1>Sign in</h1>
      <p class="muted">Use the username and password given to you by your administrator.</p>
      <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong><?=ui_e($error)?></strong><div class="small">Check your username and password and try again. Passwords are case-sensitive. If you are still locked out, ask your administrator to reset it.</div></div></div>
      <?php endif; ?>
      <form method="post" autocomplete="on">
        <div class="field">
          <label for="username">Username</label>
          <input class="input" id="username" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?=ui_e($username)?>" <?=$error ? '' : 'autofocus'?> aria-invalid="<?=$error ? 'true' : 'false'?>">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password" data-caps-hint="caps-hint" <?=$error ? 'autofocus' : ''?> aria-invalid="<?=$error ? 'true' : 'false'?>">
            <button class="toggle" type="button" data-toggle-password="password" aria-label="Show password" aria-pressed="false"><?=ui_icon('eye')?></button>
          </div>
          <span class="caps-hint" id="caps-hint" hidden>Caps Lock is on.</span>
        </div>
        <button class="btn btn-primary" type="submit" data-loading>Sign in</button>
      </form>
    </div>
  </main>
</div>
</body>
</html>
