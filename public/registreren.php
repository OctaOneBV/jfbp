<?php
require __DIR__ . '/../src/bootstrap.php';

if (gebruiker()) {
    redirect('/reserveren.php');
}

$fouten = [];
$invoer = ['naam' => '', 'email' => '', 'telefoon' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $invoer['naam']     = trim((string) ($_POST['naam'] ?? ''));
    $invoer['email']    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $invoer['telefoon'] = trim((string) ($_POST['telefoon'] ?? ''));
    $wachtwoord  = (string) ($_POST['wachtwoord'] ?? '');
    $herhaling   = (string) ($_POST['wachtwoord2'] ?? '');

    if ($invoer['naam'] === '' || mb_strlen($invoer['naam']) > 100) {
        $fouten['naam'] = 'Vul je naam in (maximaal 100 tekens).';
    }
    if (!filter_var($invoer['email'], FILTER_VALIDATE_EMAIL) || strlen($invoer['email']) > 190) {
        $fouten['email'] = 'Vul een geldig e-mailadres in.';
    }
    if ($invoer['telefoon'] !== '' && !preg_match('/^[0-9 +()-]{8,20}$/', $invoer['telefoon'])) {
        $fouten['telefoon'] = 'Vul een geldig telefoonnummer in, of laat het leeg.';
    }
    if (mb_strlen($wachtwoord) < 10) {
        $fouten['wachtwoord'] = 'Kies een wachtwoord van minimaal 10 tekens.';
    } elseif ($wachtwoord !== $herhaling) {
        $fouten['wachtwoord2'] = 'De wachtwoorden zijn niet gelijk.';
    }
    if (empty($_POST['privacy'])) {
        $fouten['privacy'] = 'Ga akkoord met de privacyverklaring om verder te gaan.';
    }

    if (!$fouten) {
        try {
            $st = db()->prepare('INSERT INTO gebruikers (naam, email, telefoon, wachtwoord) VALUES (?, ?, ?, ?)');
            $st->execute([
                $invoer['naam'],
                $invoer['email'],
                $invoer['telefoon'] !== '' ? $invoer['telefoon'] : null,
                password_hash($wachtwoord, PASSWORD_DEFAULT),
            ]);
            flash('Je account is aangemaakt. Log nu in om een fiets te reserveren.');
            redirect('/inloggen.php');
        } catch (PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) !== 1062) { // 1062 = dubbele sleutel (e-mail bestaat al)
                throw $ex;
            }
            $fouten['email'] = 'Er bestaat al een account met dit e-mailadres. Log in of gebruik een ander adres.';
        }
    }
}

kop('Registreren');
?>
<div class="formulier">
  <h1>Account aanmaken</h1>
  <form method="post" novalidate>
    <?= csrf_veld() ?>
    <label>Naam
      <input name="naam" value="<?= e($invoer['naam']) ?>" autocomplete="name" required maxlength="100">
      <?= fout($fouten, 'naam') ?>
    </label>
    <label>E-mailadres
      <input type="email" name="email" value="<?= e($invoer['email']) ?>" autocomplete="email" required maxlength="190">
      <?= fout($fouten, 'email') ?>
    </label>
    <label>Telefoonnummer <small>(optioneel)</small>
      <input type="tel" name="telefoon" value="<?= e($invoer['telefoon']) ?>" autocomplete="tel" maxlength="20">
      <?= fout($fouten, 'telefoon') ?>
    </label>
    <label>Wachtwoord <small>(minimaal 10 tekens)</small>
      <input type="password" name="wachtwoord" autocomplete="new-password" required minlength="10">
      <?= fout($fouten, 'wachtwoord') ?>
    </label>
    <label>Herhaal wachtwoord
      <input type="password" name="wachtwoord2" autocomplete="new-password" required minlength="10">
      <?= fout($fouten, 'wachtwoord2') ?>
    </label>
    <label class="vinkje">
      <input type="checkbox" name="privacy" value="1">
      <span>Ik ga akkoord met de <a href="/privacy.php" target="_blank">privacyverklaring</a>.</span>
    </label>
    <?= fout($fouten, 'privacy') ?>
    <button class="knop" type="submit">Registreren</button>
  </form>
  <p class="klein">Heb je al een account? <a href="/inloggen.php">Log in</a>.</p>
</div>
<?php voet();
