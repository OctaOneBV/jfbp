<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = login_vereist();

$maten    = maten();
$locaties = locaties();

$vandaag = new DateTimeImmutable('today');
$laatste = $vandaag->modify('+' . MAX_DAGEN_VOORUIT . ' days');

$fouten = [];
$invoer = [
    'huurder_naam' => $gebruiker['naam'], 'maat' => '',
    'afhaal' => 0, 'afhaal_datum' => $vandaag->modify('+1 day')->format('Y-m-d'),
    'terugbreng' => 0, 'terugbreng_datum' => $vandaag->modify('+2 days')->format('Y-m-d'),
    'vertrek' => '', 'aankomst' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $tekst = fn (string $k) => trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? '')));
    $invoer['huurder_naam']     = $tekst('huurder_naam');
    $invoer['maat']             = (string) ($_POST['maat'] ?? '');
    $invoer['afhaal']           = (int) ($_POST['afhaal'] ?? 0);
    $invoer['afhaal_datum']     = (string) ($_POST['afhaal_datum'] ?? '');
    $invoer['terugbreng']       = (int) ($_POST['terugbreng'] ?? 0);
    $invoer['terugbreng_datum'] = (string) ($_POST['terugbreng_datum'] ?? '');
    $invoer['vertrek']          = $tekst('vertrek');
    $invoer['aankomst']         = $tekst('aankomst');

    if ($invoer['huurder_naam'] === '' || mb_strlen($invoer['huurder_naam']) > 100) {
        $fouten['huurder_naam'] = 'Vul de naam van de huurder in (maximaal 100 tekens).';
    }
    $maat = null;
    foreach ($maten as $m) {
        if ($m['maat'] === $invoer['maat']) { $maat = $m; }
    }
    if (!$maat) {
        $fouten['maat'] = 'Kies een maat.';
    }
    $locatieIds = array_map('intval', array_column($locaties, 'id'));
    if (!in_array($invoer['afhaal'], $locatieIds, true)) {
        $fouten['afhaal'] = 'Kies een afhaallocatie.';
    }
    if (!in_array($invoer['terugbreng'], $locatieIds, true)) {
        $fouten['terugbreng'] = 'Kies een terugbrenglocatie.';
    }
    $van = geldige_datum($invoer['afhaal_datum']);
    $tot = geldige_datum($invoer['terugbreng_datum']);
    if (!$van) {
        $fouten['afhaal_datum'] = 'Kies een geldige afhaaldatum.';
    } elseif ($van < $vandaag || $van > $laatste) {
        $fouten['afhaal_datum'] = 'Kies een afhaaldatum tussen vandaag en ' . $laatste->format('d-m-Y') . '.';
    }
    if (!$tot) {
        $fouten['terugbreng_datum'] = 'Kies een geldige terugbrengdatum.';
    } elseif ($van && $tot < $van) {
        $fouten['terugbreng_datum'] = 'De terugbrengdatum kan niet vóór de afhaaldatum liggen.';
    } elseif ($van && aantal_dagen($van->format('Y-m-d'), $tot->format('Y-m-d')) > MAX_HUURDAGEN) {
        $fouten['terugbreng_datum'] = 'Je kunt een fiets maximaal ' . MAX_HUURDAGEN . ' dagen huren.';
    }
    foreach (['vertrek', 'aankomst'] as $veld) {
        if (mb_strlen($invoer[$veld]) > 150) {
            $fouten[$veld] = 'Maximaal 150 tekens.';
        }
    }
    if (($invoer['vertrek'] === '') !== ($invoer['aankomst'] === '')) {
        $fouten['aankomst'] = 'Vul zowel een vertrek- als een aankomstplaats in, of laat beide leeg.';
    }

    if (!$fouten) {
        $vanS = $van->format('Y-m-d');
        $totS = $tot->format('Y-m-d');
        $dagen = aantal_dagen($vanS, $totS);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $fiets = kies_vrije_fiets($maat['maat'], $vanS, $totS, $invoer['afhaal'], $invoer['terugbreng']);
            if (!$fiets) {
                $pdo->rollBack();
                $plaats = array_column($locaties, 'naam', 'id')[$invoer['afhaal']];
                $fouten['maat'] = 'Er is op ' . datum($vanS) . ' in ' . $plaats . ' geen fiets in maat ' . $maat['maat'] . ' beschikbaar voor deze periode. Kies een andere datum, plaats of maat.';
            } else {
                $pdo->prepare(
                    'INSERT INTO reserveringen (gebruiker_id, huurder_naam, fiets_id, afhaal_locatie_id, afhaal_datum,
                                                terugbreng_locatie_id, terugbreng_datum, aantal_dagen, prijs_per_dag, vertrek, aankomst)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $gebruiker['id'], $invoer['huurder_naam'], $fiets['id'], $invoer['afhaal'], $vanS,
                    $invoer['terugbreng'], $totS, $dagen, $maat['prijs_per_dag'],
                    $invoer['vertrek'] ?: null, $invoer['aankomst'] ?: null,
                ]);
                $id = (int) $pdo->lastInsertId();
                $pdo->commit();

                // Route buiten de transactie: een trage of mislukte routedienst mag de reservering niet blokkeren.
                if ($invoer['vertrek'] !== '' && ($route = bereken_fietsroute($invoer['vertrek'], $invoer['aankomst']))) {
                    route_opslaan($id, $route);
                }
                flash('Je reservering is geplaatst. Je fiets is ' . $fiets['nummer'] . '.');
                redirect('/reservering.php?id=' . $id);
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ex;
        }
    }
}

// Na een mislukte poging: toon per maat hoeveel fietsen er in de gekozen periode vrij zijn.
$vrij = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vanG = geldige_datum($invoer['afhaal_datum']);
    $totG = geldige_datum($invoer['terugbreng_datum']);
    if ($vanG && $totG && $totG >= $vanG && $invoer['afhaal'] && $invoer['terugbreng']) {
        foreach ($maten as $m) {
            $vrij[$m['maat']] = count(geschikte_fietsen($m['maat'], $vanG->format('Y-m-d'), $totG->format('Y-m-d'), $invoer['afhaal'], $invoer['terugbreng']));
        }
    }
}

// Overzicht: vrije fietsen per afhaalplaats per dag, eventueel voor één maat.
$overzichtMaat = in_array($_GET['maat'] ?? '', array_column($maten, 'maat'), true) ? $_GET['maat'] : '';
$overzicht = [];
for ($i = 0; $i < 14; $i++) {
    $dag = $vandaag->modify("+$i days")->format('Y-m-d');
    foreach (vrij_per_locatie($dag) as $loc => $perMaat) {
        $overzicht[$dag][$loc] = $overzichtMaat ? $perMaat[$overzichtMaat] : array_sum($perMaat);
    }
}

kop('Fiets reserveren');
?>
<h1>Fiets reserveren</h1>
<p class="lead">Hallo <?= e($gebruiker['naam']) ?>, vul hieronder in wie huurt, waar en wanneer je de fiets ophaalt en terugbrengt, en welke maat je wilt.</p>

<form method="post" class="reserveren">
  <?= csrf_veld() ?>

  <fieldset>
    <legend>1. Huurder</legend>
    <div class="rij">
      <label>Naam van de huurder
        <input name="huurder_naam" value="<?= e($invoer['huurder_naam']) ?>" maxlength="100" required autocomplete="name">
        <?= fout($fouten, 'huurder_naam') ?>
      </label>
    </div>
  </fieldset>

  <fieldset>
    <legend>2. Afhalen</legend>
    <div class="rij">
      <label>Afhaalplaats
        <select name="afhaal" required>
          <option value="">Kies een plaats…</option>
          <?php foreach ($locaties as $l): ?>
            <option value="<?= (int) $l['id'] ?>" <?= $invoer['afhaal'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['naam']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fout($fouten, 'afhaal') ?>
      </label>
      <label>Afhaaldatum
        <input type="date" name="afhaal_datum" value="<?= e($invoer['afhaal_datum']) ?>"
               min="<?= $vandaag->format('Y-m-d') ?>" max="<?= $laatste->format('Y-m-d') ?>" required>
        <?= fout($fouten, 'afhaal_datum') ?>
      </label>
    </div>
  </fieldset>

  <fieldset>
    <legend>3. Terugbrengen</legend>
    <div class="rij">
      <label>Terugbrengplaats
        <select name="terugbreng" required>
          <option value="">Kies een plaats…</option>
          <?php foreach ($locaties as $l): ?>
            <option value="<?= (int) $l['id'] ?>" <?= $invoer['terugbreng'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['naam']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fout($fouten, 'terugbreng') ?>
      </label>
      <label>Terugbrengdatum
        <input type="date" name="terugbreng_datum" value="<?= e($invoer['terugbreng_datum']) ?>"
               min="<?= $vandaag->format('Y-m-d') ?>" max="<?= $laatste->modify('+' . MAX_HUURDAGEN . ' days')->format('Y-m-d') ?>" required>
        <?= fout($fouten, 'terugbreng_datum') ?>
      </label>
    </div>
    <p class="klein">Je mag de fiets in een andere plaats terugbrengen dan waar je hem ophaalt. De fiets is van de afhaaldatum tot en met de terugbrengdatum voor jou gereserveerd.</p>
  </fieldset>

  <fieldset>
    <legend>4. Kies een maat</legend>
    <div class="grid keuzes">
      <?php foreach ($maten as $m): $n = $vrij[$m['maat']] ?? null; ?>
        <label class="kaart keuze">
          <input type="radio" name="maat" value="<?= e($m['maat']) ?>" <?= $invoer['maat'] === $m['maat'] ? 'checked' : '' ?> required>
          <span class="titel">Maat <?= e($m['maat']) ?></span>
          <span><?= e($m['omschrijving']) ?></span>
          <span class="prijs"><?= euro($m['prijs_per_dag']) ?> per dag</span>
          <?php if ($n !== null): ?>
            <span class="beschikbaar <?= $n === 0 ? 'nul' : '' ?>"><?= $n ?> beschikbaar voor deze plaatsen en data</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
    <?= fout($fouten, 'maat') ?>
  </fieldset>

  <fieldset>
    <legend>5. Je fietstocht <small>(optioneel)</small></legend>
    <p class="lead">Vul in waar je vertrekt en waar je naartoe fietst. Na het reserveren tonen we de fietsroute op de kaart en hoe lang de tocht duurt bij gemiddeld <?= GEMIDDELDE_SNELHEID_KMH ?> km/u.</p>
    <div class="rij">
      <label>Vertrekplaats
        <input name="vertrek" value="<?= e($invoer['vertrek']) ?>" maxlength="150" placeholder="bijv. Utrecht" autocomplete="off">
        <?= fout($fouten, 'vertrek') ?>
      </label>
      <label>Aankomstplaats
        <input name="aankomst" value="<?= e($invoer['aankomst']) ?>" maxlength="150" placeholder="bijv. Amsterdam" autocomplete="off">
        <?= fout($fouten, 'aankomst') ?>
      </label>
    </div>
  </fieldset>

  <button class="knop" type="submit">Reservering plaatsen</button>
</form>

<h2 id="beschikbaarheid">Beschikbaarheid komende 14 dagen</h2>
<p class="lead">Aantal fietsen dat per dag klaarstaat op elke afhaalplaats.</p>
<p class="filter">
  <a href="?#beschikbaarheid" class="<?= $overzichtMaat === '' ? 'actief' : '' ?>">Alle maten</a>
  <?php foreach ($maten as $m): ?>
    <a href="?maat=<?= e($m['maat']) ?>#beschikbaarheid" class="<?= $overzichtMaat === $m['maat'] ? 'actief' : '' ?>">Maat <?= e($m['maat']) ?></a>
  <?php endforeach; ?>
</p>
<div class="tabel">
  <table class="beschikbaarheid">
    <thead><tr><th>Datum</th><?php foreach ($locaties as $l): ?><th><?= e($l['naam']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($overzicht as $dag => $rij): $d = new DateTimeImmutable($dag); ?>
      <tr>
        <td><?= DAGNAMEN[(int) $d->format('N')] ?> <?= datum($dag) ?></td>
        <?php foreach ($locaties as $l): $n = $rij[(int) $l['id']] ?? 0; ?><td class="<?= $n === 0 ? 'nul' : '' ?>"><?= $n ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php voet();
