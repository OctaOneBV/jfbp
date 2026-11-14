<?php
require __DIR__ . '/../src/bootstrap.php';

if (gebruiker()) {
    redirect(na_inloggen());
}

const MAX_POGINGEN = 10;   // mislukte pogingen per IP-adres...
const BLOKKEER_MINUTEN = 15; // ...binnen dit aantal minuten

$fout = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $wachtwoord = (string) ($_POST['wachtwoord'] ?? '');
    $ip = client_ip();

    $st = db()->prepare('SELECT COUNT(*) FROM inlogpogingen WHERE ip = ? AND tijd > NOW() - INTERVAL ? MINUTE');
    $st->execute([$ip, BLOKKEER_MINUTEN]);
    if ((int) $st->fetchColumn() >= MAX_POGINGEN) {
        $fout = 'Te veel mislukte pogingen. Probeer het over ' . BLOKKEER_MINUTEN . ' minuten opnieuw.';
    } else {
        $st = db()->prepare('SELECT id, wachtwoord FROM gebruikers WHERE email = ?');
        $st->execute([$email]);
        $g = $st->fetch();
        // Ook zonder gebruiker een hash controleren, zodat de reactietijd niets verraadt.
        $hash = $g['wachtwoord'] ?? '$2y$12$Tn6Lo2.we.GVu9Sr1OgMI.ACbyGuBaKRU36JBl7JKZVWwprA5ZlXq';
        if (password_verify($wachtwoord, $hash) && $g) {
            if (password_needs_rehash($g['wachtwoord'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE gebruikers SET wachtwoord = ? WHERE id = ?')
                    ->execute([password_hash($wachtwoord, PASSWORD_DEFAULT), $g['id']]);
            }
            db()->prepare('DELETE FROM inlogpogingen WHERE ip = ?')->execute([$ip]);
            session_regenerate_id(true);
            $_SESSION['gebruiker_id'] = (int) $g['id'];
            redirect(na_inloggen());
        }
        db()->prepare('INSERT INTO inlogpogingen (ip) VALUES (?)')->execute([$ip]);
        db()->exec('DELETE FROM inlogpogingen WHERE tijd < NOW() - INTERVAL 1 DAY');
        $fout = 'Onjuist e-mailadres of wachtwoord.';
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
