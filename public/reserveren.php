<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = login_vereist();

$fietsen  = db()->query('SELECT id, naam, omschrijving, prijs_per_dag FROM fietsen WHERE actief = 1 ORDER BY id')->fetchAll();
$locaties = db()->query('SELECT id, naam, adres FROM locaties WHERE actief = 1 ORDER BY id')->fetchAll();

$vandaag = new DateTimeImmutable('today');
$laatste = $vandaag->modify('+' . MAX_DAGEN_VOORUIT . ' days');

$fouten = [];
$invoer = ['fiets' => 0, 'startdatum' => $vandaag->modify('+1 day')->format('Y-m-d'), 'duur' => 1, 'locatie' => 0, 'vertrek' => '', 'aankomst' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $invoer['fiets']      = (int) ($_POST['fiets'] ?? 0);
    $invoer['startdatum'] = (string) ($_POST['startdatum'] ?? '');
    $invoer['duur']       = (int) ($_POST['duur'] ?? 0);
    $invoer['locatie']    = (int) ($_POST['locatie'] ?? 0);
    $invoer['vertrek']    = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['vertrek'] ?? '')));
    $invoer['aankomst']   = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['aankomst'] ?? '')));

    $fiets = null;
    foreach ($fietsen as $f) {
        if ((int) $f['id'] === $invoer['fiets']) { $fiets = $f; }
    }
    if (!$fiets) {
        $fouten['fiets'] = 'Kies een fiets.';
    }
    if (!isset(HUURDUUR[$invoer['duur']])) {
        $fouten['duur'] = 'Kies een huurduur.';
    }
    if (!in_array($invoer['locatie'], array_map('intval', array_column($locaties, 'id')), true)) {
        $fouten['locatie'] = 'Kies een afhaallocatie.';
    }
    foreach (['vertrek', 'aankomst'] as $veld) {
        if (mb_strlen($invoer[$veld]) > 150) {
            $fouten[$veld] = 'Maximaal 150 tekens.';
        }
    }
    if (($invoer['vertrek'] === '') !== ($invoer['aankomst'] === '')) {
        $fouten['aankomst'] = 'Vul zowel een vertrek- als een aankomstplaats in, of laat beide leeg.';
    }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $invoer['startdatum']);
    if (!$start || $start->format('Y-m-d') !== $invoer['startdatum']) {
        $fouten['startdatum'] = 'Kies een geldige datum.';
    } elseif ($start < $vandaag || $start > $laatste) {
        $fouten['startdatum'] = 'Kies een datum tussen vandaag en ' . $laatste->format('d-m-Y') . '.';
    }

    if (!$fouten) {
        $eind = $start->modify('+' . $invoer['duur'] . ' days');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Fietsrij vergrendelen, zodat twee gelijktijdige reserveringen elkaar niet kruisen.
            $pdo->prepare('SELECT id FROM fietsen WHERE id = ? FOR UPDATE')->execute([$fiets['id']]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM reserveringen WHERE fiets_id = ? AND startdatum < ? AND einddatum > ?');
            $st->execute([$fiets['id'], $eind->format('Y-m-d'), $start->format('Y-m-d')]);
            if ((int) $st->fetchColumn() > 0) {
                $fouten['startdatum'] = 'De ' . $fiets['naam'] . ' is in (een deel van) deze periode al verhuurd. Kies een andere datum of fiets.';
                $pdo->rollBack();
            } else {
                $pdo->prepare('INSERT INTO reserveringen (gebruiker_id, fiets_id, locatie_id, startdatum, einddatum, aantal_dagen, vertrek, aankomst) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$gebruiker['id'], $fiets['id'], $invoer['locatie'], $start->format('Y-m-d'), $eind->format('Y-m-d'), $invoer['duur'],
                               $invoer['vertrek'] ?: null, $invoer['aankomst'] ?: null]);
                $id = (int) $pdo->lastInsertId();
                $pdo->commit();

                // Fietsroute buiten de transactie berekenen: een trage of mislukte Google-aanroep mag de reservering niet blokkeren.
                if ($invoer['vertrek'] !== '' && ($route = bereken_fietsroute($invoer['vertrek'], $invoer['aankomst']))) {
                    $pdo->prepare('UPDATE reserveringen SET route_seconden = ?, route_meters = ? WHERE id = ?')
                        ->execute([$route['seconden'], $route['meters'], $id]);
                }
                flash('Je reservering is geplaatst.');
                redirect('/reservering.php?id=' . $id);
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ex;
        }
    }
}

kop('Fiets reserveren');
?>
<h1>Fiets reserveren</h1>
<p class="lead">Hallo <?= e($gebruiker['naam']) ?>, kies hieronder je fiets, de huurperiode en waar je hem ophaalt.</p>

<form method="post" class="reserveren">
  <?= csrf_veld() ?>

  <fieldset>
    <legend>1. Kies een fiets</legend>
    <div class="grid keuzes">
      <?php foreach ($fietsen as $f): ?>
        <label class="kaart keuze">
          <input type="radio" name="fiets" value="<?= (int) $f['id'] ?>" <?= $invoer['fiets'] === (int) $f['id'] ? 'checked' : '' ?> required>
          <span class="titel"><?= e($f['naam']) ?></span>
          <span><?= e($f['omschrijving']) ?></span>
          <span class="prijs"><?= euro($f['prijs_per_dag']) ?> per dag</span>
        </label>
      <?php endforeach; ?>
    </div>
    <?= fout($fouten, 'fiets') ?>
  </fieldset>

  <fieldset>
    <legend>2. Kies de huurperiode</legend>
    <div class="rij">
      <label>Ophaaldatum
        <input type="date" name="startdatum" value="<?= e($invoer['startdatum']) ?>"
               min="<?= $vandaag->format('Y-m-d') ?>" max="<?= $laatste->format('Y-m-d') ?>" required>
      </label>
      <label>Huurduur
        <select name="duur" required>
          <?php foreach (HUURDUUR as $dagen => $label): ?>
            <option value="<?= $dagen ?>" <?= $invoer['duur'] === $dagen ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <?= fout($fouten, 'startdatum') ?><?= fout($fouten, 'duur') ?>
  </fieldset>

  <fieldset>
    <legend>3. Kies een afhaallocatie</legend>
    <div class="lijst">
      <?php foreach ($locaties as $l): ?>
        <label class="keuze-regel">
          <input type="radio" name="locatie" value="<?= (int) $l['id'] ?>" <?= $invoer['locatie'] === (int) $l['id'] ? 'checked' : '' ?> required>
          <span><strong><?= e($l['naam']) ?></strong><br><small><?= e($l['adres']) ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?= fout($fouten, 'locatie') ?>
  </fieldset>

  <fieldset>
    <legend>4. Je fietstocht <small>(optioneel)</small></legend>
    <p class="lead">Vul in waar je vertrekt en waar je naartoe fietst. Na het reserveren tonen we de fietsroute op de kaart en hoe lang de tocht ongeveer duurt.</p>
    <div class="rij">
      <label>Vertrekplaats
        <input name="vertrek" value="<?= e($invoer['vertrek']) ?>" maxlength="150" placeholder="bijv. Beesd" autocomplete="off">
        <?= fout($fouten, 'vertrek') ?>
      </label>
      <label>Aankomstplaats
        <input name="aankomst" value="<?= e($invoer['aankomst']) ?>" maxlength="150" placeholder="bijv. Culemborg" autocomplete="off">
        <?= fout($fouten, 'aankomst') ?>
      </label>
    </div>
  </fieldset>

  <button class="knop" type="submit">Reservering plaatsen</button>
</form>
<?php voet();
