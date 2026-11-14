<?php
require __DIR__ . '/../src/bootstrap.php';

if (gebruiker()) {
    redirect(na_inloggen());
}

$fout = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    [$g, $fout] = controleer_inlog($email, (string) ($_POST['wachtwoord'] ?? ''));
    if ($g) {
        redirect(na_inloggen());
    }
}

kop('Inloggen');
?>
<div class="formulier">
  <h1>Inloggen</h1>
  <form method="post">
    <?= csrf_veld() ?>
    <label>E-mailadres
      <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
    </label>
    <label>Wachtwoord
      <input type="password" name="wachtwoord" autocomplete="current-password" required>
    </label>
    <?php if ($fout): ?><p class="fout" role="alert"><?= e($fout) ?></p><?php endif; ?>
    <button class="knop" type="submit">Inloggen</button>
  </form>
  <p class="klein">Nog geen account? <a href="/registreren.php">Registreer je hier</a>.</p>
</div>
<?php voet();
