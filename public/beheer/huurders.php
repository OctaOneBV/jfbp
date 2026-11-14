<?php
require __DIR__ . '/../../src/bootstrap.php';

beheerder_vereist();

$locNaam = array_column(locaties(), 'naam', 'id');

// ---------- detail van één reservering ----------
if (isset($_GET['id'])) {
    $st = db()->prepare(
        'SELECT r.*, f.nummer, f.maat, g.naam AS account_naam, g.email, g.telefoon
           FROM reserveringen r JOIN fietsen f ON f.id = r.fiets_id JOIN gebruikers g ON g.id = r.gebruiker_id
          WHERE r.id = ?'
    );
    $st->execute([(int) $_GET['id']]);
    $r = $st->fetch();
    if (!$r) {
        http_response_code(404);
        beheer_kop('Niet gevonden', 'huurders');
        echo '<h1>Reservering niet gevonden</h1><p><a href="/beheer/huurders.php">Terug naar huurders</a></p>';
        beheer_voet();
        exit;
    }
    $scans = db()->prepare('SELECT tijd, resultaat, ip FROM scans WHERE reservering_id = ? ORDER BY id');
    $scans->execute([$r['id']]);
    $scans = $scans->fetchAll();
    $andere = db()->prepare('SELECT r.id, r.afhaal_datum, r.terugbreng_datum, f.nummer FROM reserveringen r JOIN fietsen f ON f.id = r.fiets_id
                              WHERE r.gebruiker_id = ? AND r.id <> ? ORDER BY r.afhaal_datum DESC LIMIT 20');
    $andere->execute([$r['gebruiker_id'], $r['id']]);
    $andere = $andere->fetchAll();

    beheer_kop('Reservering #' . $r['id'], 'huurders');
    ?>
    <p><a href="/beheer/huurders.php">‹ Terug naar huurders</a></p>
    <h1>Reservering #<?= (int) $r['id'] ?></h1>
    <div class="grid samenvatting">
      <div class="kaart"><h3>Huurder</h3><p><?= e($r['huurder_naam']) ?></p></div>
      <div class="kaart"><h3>Account</h3><p><?= e($r['account_naam']) ?><br><small><?= e($r['email']) ?><?= $r['telefoon'] ? '<br>' . e($r['telefoon']) : '' ?></small></p></div>
      <div class="kaart"><h3>Fiets</h3><p><strong><?= e($r['nummer']) ?></strong> <small>maat <?= e($r['maat']) ?></small></p></div>
      <div class="kaart"><h3>Afhalen</h3><p><?= e($locNaam[$r['afhaal_locatie_id']] ?? '?') ?>, <?= datum($r['afhaal_datum']) ?></p></div>
      <div class="kaart"><h3>Terugbrengen</h3><p><?= e($locNaam[$r['terugbreng_locatie_id']] ?? '?') ?>, <?= datum($r['terugbreng_datum']) ?></p></div>
      <div class="kaart"><h3>Bedrag</h3><p class="prijs"><?= euro((float) $r['prijs_per_dag'] * (int) $r['aantal_dagen']) ?></p><p><small><?= (int) $r['aantal_dagen'] ?> dagen × <?= euro($r['prijs_per_dag']) ?></small></p></div>
    </div>
    <p>Geplaatst op <?= e((new DateTimeImmutable($r['aangemaakt_op']))->format('d-m-Y H:i')) ?>
       · <?= $r['opgehaald_op'] ? 'Opgehaald op ' . e((new DateTimeImmutable($r['opgehaald_op']))->format('d-m-Y H:i')) : 'Nog niet opgehaald' ?>
       <?php if ($r['vertrek']): ?> · Fietstocht <?= e($r['vertrek']) ?> → <?= e($r['aankomst']) ?><?= $r['route_meters'] ? ' (' . afstand_tekst((int) $r['route_meters']) . ')' : '' ?><?php endif; ?>
       · <a href="/beheer/?week=<?= e($r['afhaal_datum']) ?>">In tijdbalk</a></p>

    <h2>Scans</h2>
    <?php if (!$scans): ?><p class="lead">Geen scans bij deze reservering.</p><?php else: ?>
      <div class="tabel"><table><thead><tr><th>Tijd</th><th>Resultaat</th><th>IP-adres</th></tr></thead><tbody>
      <?php foreach ($scans as $s): ?><tr><td><?= e((new DateTimeImmutable($s['tijd']))->format('d-m-Y H:i:s')) ?></td><td><?= e($s['resultaat']) ?></td><td><?= e($s['ip']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>

    <?php if ($andere): ?>
      <h2>Andere reserveringen van dit account</h2>
      <div class="tabel"><table><thead><tr><th>Periode</th><th>Fiets</th><th></th></tr></thead><tbody>
      <?php foreach ($andere as $a): ?><tr><td><?= datum($a['afhaal_datum']) ?> – <?= datum($a['terugbreng_datum']) ?></td><td><?= e($a['nummer']) ?></td><td><a href="?id=<?= (int) $a['id'] ?>">Bekijken</a></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <?php
    beheer_voet();
    exit;
}

// ---------- zoeken ----------
$vandaag = new DateTimeImmutable('today');
$filter = [
    'van'     => get_datum('van', $vandaag->modify('first day of this month')),
    'tot'     => get_datum('tot', $vandaag->modify('last day of this month')),
    'zoek'    => trim((string) ($_GET['zoek'] ?? '')),
    'locatie' => (int) ($_GET['locatie'] ?? 0),
    'maat'    => in_array($_GET['maat'] ?? '', array_column(maten(), 'maat'), true) ? $_GET['maat'] : '',
];
if ($filter['tot'] < $filter['van']) {
    [$filter['van'], $filter['tot']] = [$filter['tot'], $filter['van']];
}

$waar = ['r.afhaal_datum <= ?', 'r.terugbreng_datum >= ?'];
$params = [$filter['tot']->format('Y-m-d'), $filter['van']->format('Y-m-d')];
if ($filter['zoek'] !== '') {
    $waar[] = '(r.huurder_naam LIKE ? OR g.naam LIKE ? OR g.email LIKE ? OR f.nummer LIKE ?)';
    $like = '%' . addcslashes($filter['zoek'], '%_\\') . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($filter['locatie']) {
    $waar[] = '(r.afhaal_locatie_id = ? OR r.terugbreng_locatie_id = ?)';
    array_push($params, $filter['locatie'], $filter['locatie']);
}
if ($filter['maat'] !== '') {
    $waar[] = 'f.maat = ?';
    $params[] = $filter['maat'];
}
$st = db()->prepare(
    'SELECT r.id, r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, r.aantal_dagen, r.prijs_per_dag, r.opgehaald_op,
            r.afhaal_locatie_id, r.terugbreng_locatie_id, f.nummer, f.maat, g.naam AS account_naam, g.email, g.telefoon
       FROM reserveringen r JOIN fietsen f ON f.id = r.fiets_id JOIN gebruikers g ON g.id = r.gebruiker_id
      WHERE ' . implode(' AND ', $waar) . '
      ORDER BY r.afhaal_datum, r.id
      LIMIT 5001'
);
$st->execute($params);
$rijen = $st->fetchAll();

// ---------- CSV-export (Excel-vriendelijk: puntkomma, UTF-8 met BOM) ----------
if (($_GET['export'] ?? '') === 'csv') {
    $cel = function (string $v): string {
        // Voorkom dat Excel een cel als formule uitvoert.
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="huurders_' . $filter['van']->format('Ymd') . '-' . $filter['tot']->format('Ymd') . '.csv"');
    $uit = fopen('php://output', 'w');
    fwrite($uit, "\xEF\xBB\xBF");
    fputcsv($uit, ['Reservering', 'Huurder', 'Accountnaam', 'E-mail', 'Telefoon', 'Fiets', 'Maat', 'Afhaalplaats', 'Afhaaldatum',
                   'Terugbrengplaats', 'Terugbrengdatum', 'Dagen', 'Bedrag', 'Opgehaald'], ';', '"', '');
    foreach (array_slice($rijen, 0, 5000) as $r) {
        fputcsv($uit, array_map($cel, [
            (string) $r['id'], $r['huurder_naam'], $r['account_naam'], $r['email'], (string) $r['telefoon'], $r['nummer'], $r['maat'],
            $locNaam[$r['afhaal_locatie_id']] ?? '', datum($r['afhaal_datum']), $locNaam[$r['terugbreng_locatie_id']] ?? '',
            datum($r['terugbreng_datum']), (string) $r['aantal_dagen'],
            number_format((float) $r['prijs_per_dag'] * (int) $r['aantal_dagen'], 2, ',', ''),
            $r['opgehaald_op'] ? (new DateTimeImmutable($r['opgehaald_op']))->format('d-m-Y H:i') : '',
        ]), ';', '"', '');
    }
    exit;
}

$teVeel = count($rijen) > 500;
$tonen = array_slice($rijen, 0, 500);
$query = array_filter(['van' => $filter['van']->format('Y-m-d'), 'tot' => $filter['tot']->format('Y-m-d'),
                       'zoek' => $filter['zoek'], 'locatie' => $filter['locatie'] ?: '', 'maat' => $filter['maat']]);

beheer_kop('Huurders', 'huurders');
?>
<h1>Huurders</h1>
<p class="lead">Zoek welke huurders in een periode een fiets hadden. Een reservering telt mee als die (deels) in de periode valt.</p>

<form method="get" class="filterbalk">
  <label>Van <input type="date" name="van" value="<?= $filter['van']->format('Y-m-d') ?>"></label>
  <label>Tot en met <input type="date" name="tot" value="<?= $filter['tot']->format('Y-m-d') ?>"></label>
  <label>Zoeken <input name="zoek" value="<?= e($filter['zoek']) ?>" placeholder="naam, e-mail of fiets"></label>
  <label>Locatie
    <select name="locatie"><option value="">Alle</option>
      <?php foreach (locaties() as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $filter['locatie'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['naam']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Maat
    <select name="maat"><option value="">Alle</option>
      <?php foreach (maten() as $m): ?><option value="<?= e($m['maat']) ?>" <?= $filter['maat'] === $m['maat'] ? 'selected' : '' ?>><?= e($m['maat']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <button class="knop klein" type="submit">Zoeken</button>
</form>

<p><?= count($rijen) > 5000 ? 'Meer dan 5000' : count($rijen) ?> reservering<?= count($rijen) === 1 ? '' : 'en' ?> gevonden
  <?php if ($rijen): ?>· <a href="?<?= e(http_build_query($query + ['export' => 'csv'])) ?>">Download als CSV (Excel)</a><?php endif; ?>
  <?php if ($teVeel): ?>· Hieronder de eerste 500; de CSV bevat er maximaal 5000.<?php endif; ?></p>

<?php if ($tonen): ?>
<div class="tabel">
  <table>
    <thead><tr><th>Huurder</th><th>Account</th><th>Fiets</th><th>Afhalen</th><th>Terugbrengen</th><th>Dagen</th><th>Bedrag</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tonen as $r):
        $status = $r['opgehaald_op'] ? 'Opgehaald' : ($r['afhaal_datum'] > $vandaag->format('Y-m-d') ? 'Gepland' : 'Niet opgehaald'); ?>
      <tr>
        <td><strong><?= e($r['huurder_naam']) ?></strong></td>
        <td><?= e($r['account_naam']) ?><br><small><?= e($r['email']) ?></small></td>
        <td><?= e($r['nummer']) ?></td>
        <td><?= e($locNaam[$r['afhaal_locatie_id']] ?? '?') ?><br><small><?= datum($r['afhaal_datum']) ?></small></td>
        <td><?= e($locNaam[$r['terugbreng_locatie_id']] ?? '?') ?><br><small><?= datum($r['terugbreng_datum']) ?></small></td>
        <td><?= (int) $r['aantal_dagen'] ?></td>
        <td><?= euro((float) $r['prijs_per_dag'] * (int) $r['aantal_dagen']) ?></td>
        <td><?= $status ?></td>
        <td><a href="?id=<?= (int) $r['id'] ?>">Details</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php beheer_voet();
