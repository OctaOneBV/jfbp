<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = beheerder_vereist();

// Fiets in of uit onderhoud zetten.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    db()->prepare('UPDATE fietsen SET actief = 1 - actief WHERE id = ?')->execute([(int) ($_POST['fiets'] ?? 0)]);
    flash('Status van de fiets is aangepast.');
    redirect('/beheer.php');
}

$fietsen = db()->query(
    "SELECT f.id, f.nummer, f.maat, f.code, f.actief,
            (SELECT CONCAT_WS(CHAR(31), r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, la.naam, lt.naam, IFNULL(r.opgehaald_op, ''))
               FROM reserveringen r JOIN locaties la ON la.id = r.afhaal_locatie_id JOIN locaties lt ON lt.id = r.terugbreng_locatie_id
              WHERE r.fiets_id = f.id AND CURDATE() BETWEEN r.afhaal_datum AND r.terugbreng_datum LIMIT 1) AS nu,
            (SELECT CONCAT_WS(CHAR(31), r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, la.naam)
               FROM reserveringen r JOIN locaties la ON la.id = r.afhaal_locatie_id
              WHERE r.fiets_id = f.id AND r.afhaal_datum > CURDATE() ORDER BY r.afhaal_datum LIMIT 1) AS volgende,
            (SELECT lt.naam FROM reserveringen r JOIN locaties lt ON lt.id = r.terugbreng_locatie_id
              WHERE r.fiets_id = f.id AND r.terugbreng_datum < CURDATE() ORDER BY r.terugbreng_datum DESC LIMIT 1) AS laatst_terug
       FROM fietsen f JOIN maten m ON m.maat = f.maat
      ORDER BY m.volgorde, f.nummer"
)->fetchAll();

$scans = db()->query(
    "SELECT s.tijd, s.resultaat, f.nummer, g.naam
       FROM scans s LEFT JOIN fietsen f ON f.id = s.fiets_id LEFT JOIN gebruikers g ON g.id = s.gebruiker_id
      ORDER BY s.id DESC LIMIT 50"
)->fetchAll();

const RESULTAAT = [
    'bekeken' => 'Gescand', 'geopend' => 'Slot geopend', 'geen_reservering' => 'Geweigerd: niet gereserveerd',
    'onbekende_fiets' => 'Onbekende code', 'slot_fout' => 'Slot reageerde niet',
];

$telling = ['verhuurd' => 0, 'vrij' => 0, 'onderhoud' => 0];
foreach ($fietsen as $f) {
    $telling[!$f['actief'] ? 'onderhoud' : ($f['nu'] ? 'verhuurd' : 'vrij')]++;
}

kop('Beheer');
?>
<h1>Beheer fietsen</h1>
<p class="lead">Vandaag (<?= date('d-m-Y') ?>): <?= $telling['verhuurd'] ?> verhuurd, <?= $telling['vrij'] ?> vrij, <?= $telling['onderhoud'] ?> in onderhoud.</p>
<p><a class="knop" href="/labels.php">Barcodelabels printen</a></p>

<div class="tabel">
  <table>
    <thead><tr><th>Fiets</th><th>Maat</th><th>Vandaag</th><th>Volgende verhuur</th><th>Laatst teruggebracht in</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($fietsen as $f):
        $nu = $f['nu'] ? explode("\x1F", $f['nu']) : null;
        $volg = $f['volgende'] ? explode("\x1F", $f['volgende']) : null; ?>
      <tr>
        <td><a href="/scan.php?c=<?= e($f['code']) ?>"><strong><?= e($f['nummer']) ?></strong></a><br><small class="code"><?= e($f['code']) ?></small></td>
        <td><?= e($f['maat']) ?></td>
        <td><?php if ($nu): ?><?= e($nu[0]) ?><br><small><?= e($nu[3]) ?> → <?= e($nu[4]) ?>, t/m <?= datum($nu[2]) ?><?= $nu[5] !== '' ? ' · opgehaald' : ' · nog niet opgehaald' ?></small><?php else: ?>Vrij<?php endif; ?></td>
        <td><?= $volg ? e($volg[0]) . '<br><small>' . datum($volg[1]) . ' t/m ' . datum($volg[2]) . ', ' . e($volg[3]) . '</small>' : '–' ?></td>
        <td><?= e($f['laatst_terug'] ?? '–') ?></td>
        <td>
          <form method="post" class="inline"><?= csrf_veld() ?><input type="hidden" name="fiets" value="<?= (int) $f['id'] ?>">
            <button class="link"><?= $f['actief'] ? 'Actief · in onderhoud zetten' : 'In onderhoud · activeren' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="klein">Een fiets in onderhoud wordt niet meer toegewezen aan nieuwe reserveringen. Bestaande reserveringen blijven staan; controleer die zelf.</p>

<h2>Laatste scans</h2>
<?php if (!$scans): ?>
  <p class="lead">Nog geen scans.</p>
<?php else: ?>
  <div class="tabel">
    <table>
      <thead><tr><th>Tijd</th><th>Fiets</th><th>Gebruiker</th><th>Resultaat</th></tr></thead>
      <tbody>
      <?php foreach ($scans as $s): ?>
        <tr>
          <td><?= e((new DateTimeImmutable($s['tijd']))->format('d-m-Y H:i:s')) ?></td>
          <td><?= e($s['nummer'] ?? '–') ?></td>
          <td><?= e($s['naam'] ?? '–') ?></td>
          <td><?= e(RESULTAAT[$s['resultaat']] ?? $s['resultaat']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php voet();
