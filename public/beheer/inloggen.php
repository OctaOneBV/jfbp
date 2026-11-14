<?php
require __DIR__ . '/../../src/bootstrap.php';

$g = gebruiker();
if ($g && $g['rol'] === 'beheerder') {
    redirect(na_inloggen('/beheer/'));
}

$fout = $g ? 'Je bent ingelogd als klant. Log in met een beheerdersaccount.' : '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    [$ok, $fout] = controleer_inlog($email, (string) ($_POST['wachtwoord'] ?? ''), 'beheerder');
    if ($ok) {
        redirect(na_inloggen('/beheer/'));
    }
}

beheer_kop('Inloggen', '');
?>
<div class="formulier">
  <h1>Beheerportaal</h1>
  <p class="klein">Alleen voor medewerkers van JFBP Fietsverhuur.</p>
  <form method="post">
    <?= csrf_veld() ?>
    <label>E-mailadres
      <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required>
    </label>
    <label>Wachtwoord
      <input type="password" name="wachtwoord" autocomplete="current-password" required>
    </label>
    <?php if ($fout): ?><p class="fout" role="alert"><?= e($fout) ?></p><?php endif; ?>
    <button class="knop" type="submit">Inloggen</button>
  </form>
</div>
<?php beheer_voet();
