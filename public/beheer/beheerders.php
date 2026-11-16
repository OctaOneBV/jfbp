<?php
require __DIR__ . '/../../src/bootstrap.php';

$beheerder = beheerder_vereist();

// Beheerders: nieuw beheerdersaccount aanmaken, een bestaand klantaccount beheerder maken
// of beheerrechten weer intrekken. Je kunt je eigen rechten niet intrekken, zodat er altijd
// minstens één beheerder overblijft.

$fouten = [];
$invoer = ['naam' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $actie = (string) ($_POST['actie'] ?? '');

    if ($actie === 'nieuw') {
        $invoer['naam']  = trim((string) ($_POST['naam'] ?? ''));
        $invoer['email'] = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $wachtwoord = (string) ($_POST['wachtwoord'] ?? '');
        if ($invoer['naam'] === '' || mb_strlen($invoer['naam']) > 100) {
            $fouten['naam'] = 'Vul een naam in (maximaal 100 tekens).';
        }
        if (!filter_var($invoer['email'], FILTER_VALIDATE_EMAIL) || strlen($invoer['email']) > 190) {
            $fouten['email'] = 'Vul een geldig e-mailadres in.';
        }
        if (mb_strlen($wachtwoord) < 10) {
            $fouten['wachtwoord'] = 'Kies een wachtwoord van minimaal 10 tekens.';
        } elseif ($wachtwoord !== (string) ($_POST['wachtwoord2'] ?? '')) {
            $fouten['wachtwoord2'] = 'De wachtwoorden zijn niet gelijk.';
        }
        if (!$fouten) {
            try {
                db()->prepare("INSERT INTO gebruikers (naam, email, wachtwoord, rol) VALUES (?, ?, ?, 'beheerder')")
                    ->execute([$invoer['naam'], $invoer['email'], password_hash($wachtwoord, PASSWORD_DEFAULT)]);
                flash('Beheerdersaccount voor ' . $invoer['email'] . ' is aangemaakt.');
                redirect('/beheer/beheerders.php');
            } catch (PDOException $ex) {
                if ((int) ($ex->errorInfo[1] ?? 0) !== 1062) {
                    throw $ex;
                }
                $fouten['email'] = 'Er bestaat al een account met dit e-mailadres. Maak het hiernaast beheerder.';
            }
        }
    } elseif ($actie === 'bestaand') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $st = db()->prepare("UPDATE gebruikers SET rol = 'beheerder' WHERE email = ? AND rol = 'klant'");
        $st->execute([$email]);
        if ($st->rowCount()) {
            flash($email . ' is nu beheerder.');
        } else {
            $st = db()->prepare('SELECT rol FROM gebruikers WHERE email = ?');
            $st->execute([$email]);
            $rol = $st->fetchColumn();
            flash($rol === 'beheerder' ? $email . ' is al beheerder.' : 'Geen account gevonden met dit e-mailadres.', $rol ? 'info' : 'fout');
        }
        redirect('/beheer/beheerders.php');
    } elseif ($actie === 'intrekken') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $beheerder['id']) {
            flash('Je kunt je eigen beheerrechten niet intrekken.', 'fout');
        } else {
            $st = db()->prepare("UPDATE gebruikers SET rol = 'klant' WHERE id = ? AND rol = 'beheerder'");
            $st->execute([$id]);
            flash($st->rowCount() ? 'Beheerrechten zijn ingetrokken. Het account blijft bestaan als klantaccount.' : 'Onbekende beheerder.', $st->rowCount() ? 'ok' : 'fout');
        }
        redirect('/beheer/beheerders.php');
    }
}

$beheerders = db()->query(
    "SELECT id, naam, email, aangemaakt_op FROM gebruikers WHERE rol = 'beheerder' ORDER BY naam"
)->fetchAll();

beheer_kop('Beheerders', 'beheerders');
?>
<h1>Beheerders <small><?= count($beheerders) ?> account<?= count($beheerders) === 1 ? '' : 's' ?></small></h1>

<div class="tabel">
  <table class="smal">
    <thead><tr><th>Naam</th><th>E-mailadres</th><th>Sinds</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($beheerders as $b): ?>
      <tr>
        <td><?= e($b['naam']) ?><?= (int) $b['id'] === (int) $beheerder['id'] ? ' <small>(jij)</small>' : '' ?></td>
        <td><?= e($b['email']) ?></td>
        <td><?= datum(substr($b['aangemaakt_op'], 0, 10)) ?></td>
        <td>
          <?php if ((int) $b['id'] !== (int) $beheerder['id']): ?>
            <form method="post" class="inline"><?= csrf_veld() ?>
              <input type="hidden" name="actie" value="intrekken"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="link">Rechten intrekken</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="twee-kolommen">
  <section>
    <h2>Nieuw beheerdersaccount</h2>
    <form method="post" class="beheer-form" novalidate><?= csrf_veld() ?>
      <input type="hidden" name="actie" value="nieuw">
      <label>Naam
        <input name="naam" value="<?= e($invoer['naam']) ?>" required maxlength="100" autocomplete="off">
        <?= fout($fouten, 'naam') ?>
      </label>
      <label>E-mailadres
        <input type="email" name="email" value="<?= e($invoer['email']) ?>" required maxlength="190" autocomplete="off">
        <?= fout($fouten, 'email') ?>
      </label>
      <label>Startwachtwoord <small>(minimaal 10 tekens)</small>
        <input type="password" name="wachtwoord" required minlength="10" autocomplete="new-password">
        <?= fout($fouten, 'wachtwoord') ?>
      </label>
      <label>Herhaal wachtwoord
        <input type="password" name="wachtwoord2" required minlength="10" autocomplete="new-password">
        <?= fout($fouten, 'wachtwoord2') ?>
      </label>
      <button class="knop klein" type="submit">Account aanmaken</button>
    </form>
    <p class="klein">Geef het startwachtwoord persoonlijk of telefonisch door, niet per mail of chat.</p>
  </section>
  <section>
    <h2>Bestaand account beheerder maken</h2>
    <form method="post" class="beheer-form"><?= csrf_veld() ?>
      <input type="hidden" name="actie" value="bestaand">
      <label>E-mailadres van het account
        <input type="email" name="email" required maxlength="190" autocomplete="off">
      </label>
      <button class="knop klein" type="submit">Beheerder maken</button>
    </form>
    <p class="klein">Voor iemand die zich al als klant op de website heeft geregistreerd. Die logt daarna in met het eigen wachtwoord.</p>
  </section>
</div>
<?php beheer_voet();
