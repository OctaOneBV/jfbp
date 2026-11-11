<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = login_vereist();

$fietsen  = db()->query('SELECT id, naam, omschrijving, prijs_per_dag FROM fietsen WHERE actief = 1 ORDER BY id')->fetchAll();
$locaties = db()->query('SELECT id, naam, adres FROM locaties WHERE actief = 1 ORDER BY id')->fetchAll();

$vandaag = new DateTimeImmutable('today');
$laatste = $vandaag->modify('+' . MAX_DAGEN_VOORUIT . ' days');

$fouten = [];
$invoer = ['fiets' => 0, 'startdatum' => $vandaag->modify('+1 day')->format('Y-m-d'), 'duur' => 1, 'afhaal' => 0, 'terugbreng' => 0, 'vertrek' => '', 'aankomst' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $invoer['fiets']      = (int) ($_POST['fiets'] ?? 0);
    $invoer['startdatum'] = (string) ($_POST['startdatum'] ?? '');
    $invoer['duur']       = (int) ($_POST['duur'] ?? 0);
    $invoer['afhaal']     = (int) ($_POST['afhaal'] ?? 0);
    $invoer['terugbreng'] = (int) ($_POST['terugbreng'] ?? 0);
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
    $locatieIds = array_map('intval', array_column($locaties, 'id'));
    if (!in_array($invoer['afhaal'], $locatieIds, true)) {
        $fouten['afhaal'] = 'Kies een afhaallocatie.';
    }
    if (!in_array($invoer['terugbreng'], $locatieIds, true)) {
        $fouten['terugbreng'] = 'Kies een terugbrenglocatie.';
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
                $pdo->prepare('INSERT INTO reserveringen (gebruiker_id, fiets_id, afhaal_locatie_id, terugbreng_locatie_id, startdatum, einddatum, aantal_dagen, vertrek, aankomst) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$gebruiker['id'], $fiets['id'], $invoer['afhaal'], $invoer['terugbreng'], $start->format('Y-m-d'), $eind->format('Y-m-d'), $invoer['duur'],
                               $invoer['vertrek'] ?: null, $invoer['aankomst'] ?: null]);
                $id = (int) $pdo->lastInsertId();
                $pdo->commit();

                // Fietsroute buiten de transactie berekenen: een trage of mislukte routedienst mag de reservering niet blokkeren.
                if ($invoer['vertrek'] !== '' && ($route = bereken_fietsroute($invoer['vertrek'], $invoer['aankomst']))) {
                    route_opslaan($id, $route);
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
<p class="lead">Hallo <?= e($gebruiker['naam']) ?>, kies hieronder je fiets, de huurperiode en waar je hem ophaalt en terugbrengt.</p>

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
    <legend>3. Afhalen en terugbrengen</legend>
    <div class="rij">
      <label>Afhaallocatie
        <select name="afhaal" required>
          <option value="">Kies een locatie…</option>
          <?php foreach ($locaties as $l): ?>
            <option value="<?= (int) $l['id'] ?>" <?= $invoer['afhaal'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['naam']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fout($fouten, 'afhaal') ?>
      </label>
      <label>Terugbrenglocatie
        <select name="terugbreng" required>
          <option value="">Kies een locatie…</option>
          <?php foreach ($locaties as $l): ?>
            <option value="<?= (int) $l['id'] ?>" <?= $invoer['terugbreng'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['naam']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fout($fouten, 'terugbreng') ?>
      </label>
    </div>
    <p class="klein">Je mag de fiets op een andere locatie terugbrengen dan waar je hem ophaalt.</p>
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
