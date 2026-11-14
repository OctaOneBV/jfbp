<?php
require __DIR__ . '/../../src/bootstrap.php';

$beheerder = beheerder_vereist();
$locNaam = array_column(locaties(), 'naam', 'id');
$vandaag = new DateTimeImmutable('today');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $fietsId = (int) ($_POST['fiets'] ?? 0);
    $st = db()->prepare('SELECT id, nummer FROM fietsen WHERE id = ?');
    $st->execute([$fietsId]);
    $fiets = $st->fetch();
    if (!$fiets) {
        flash('Onbekende fiets.', 'fout');
    } elseif (($_POST['actie'] ?? '') === 'onderhoud') {
        db()->prepare('UPDATE fietsen SET actief = 1 - actief WHERE id = ?')->execute([$fietsId]);
        flash('Status van fiets ' . $fiets['nummer'] . ' is aangepast.');
    } elseif (($_POST['actie'] ?? '') === 'verplaatsen') {
        $datum = geldige_datum((string) ($_POST['datum'] ?? ''));
        $naar = (int) ($_POST['naar'] ?? 0);
        if (!$datum || !isset($locNaam[$naar])) {
            flash('Kies een geldige datum en locatie.', 'fout');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM fietsen WHERE id = ? FOR UPDATE')->execute([$fietsId]);
            if ($probleem = verplaatsing_probleem($fietsId, $datum->format('Y-m-d'), $naar)) {
                $pdo->rollBack();
                flash('Verplaatsen van ' . $fiets['nummer'] . ' kan niet: ' . $probleem, 'fout');
            } else {
                $pdo->prepare('INSERT INTO verplaatsingen (fiets_id, datum, naar_locatie_id, door_gebruiker_id) VALUES (?, ?, ?, ?)')
                    ->execute([$fietsId, $datum->format('Y-m-d'), $naar, $beheerder['id']]);
                $pdo->commit();
                flash('Fiets ' . $fiets['nummer'] . ' staat vanaf ' . datum($datum->format('Y-m-d')) . ' in ' . $locNaam[$naar] . '.');
            }
        }
    }
    redirect('/beheer/fietsen.php');
}

$fietsen = fietsen_op_dag($vandaag->format('Y-m-d'));
$volgende = [];
foreach (db()->query(
    "SELECT r.fiets_id, r.huurder_naam, r.afhaal_datum, r.afhaal_locatie_id, r.id
       FROM reserveringen r
      WHERE r.afhaal_datum > CURDATE()
        AND r.afhaal_datum = (SELECT MIN(r2.afhaal_datum) FROM reserveringen r2 WHERE r2.fiets_id = r.fiets_id AND r2.afhaal_datum > CURDATE())"
) as $r) {
    $volgende[(int) $r['fiets_id']] = $r;
}
$verplaatsingen = db()->query(
    'SELECT v.datum, v.naar_locatie_id, v.aangemaakt_op, f.nummer, g.naam FROM verplaatsingen v JOIN fietsen f ON f.id = v.fiets_id
       LEFT JOIN gebruikers g ON g.id = v.door_gebruiker_id ORDER BY v.id DESC LIMIT 20'
)->fetchAll();
$scans = db()->query(
    'SELECT s.tijd, s.resultaat, s.reservering_id, f.nummer, g.naam
       FROM scans s LEFT JOIN fietsen f ON f.id = s.fiets_id LEFT JOIN gebruikers g ON g.id = s.gebruiker_id
      ORDER BY s.id DESC LIMIT 50'
)->fetchAll();

const RESULTAAT = [
    'bekeken' => 'Gescand', 'geopend' => 'Slot geopend', 'geen_reservering' => 'Geweigerd: niet gereserveerd',
    'onbekende_fiets' => 'Onbekende code', 'slot_fout' => 'Slot reageerde niet',
];

beheer_kop('Fietsen', 'fietsen');
?>
<div class="balk-kop">
  <h1>Fietsen <small>vandaag <?= datum($vandaag->format('Y-m-d')) ?></small></h1>
  <a class="knop klein" href="/beheer/labels.php">Barcodelabels printen</a>
</div>

<div class="tabel">
  <table>
    <thead><tr><th>Fiets</th><th>Maat</th><th>Locatie</th><th>Vandaag</th><th>Volgende verhuur</th><th>Verplaatsen</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($fietsen as $f): $v = $f['verhuur']; $n = $volgende[(int) $f['id']] ?? null; ?>
      <tr class="<?= $f['actief'] ? '' : 'onderhoud' ?>">
        <td><a href="/scan.php?c=<?= e($f['code']) ?>"><strong><?= e($f['nummer']) ?></strong></a><br><small class="code"><?= e($f['code']) ?></small></td>
        <td><?= e($f['maat']) ?></td>
        <td><?= $v ? '<small>onderweg</small>' : e($locNaam[$f['locatie_id']] ?? '?') ?></td>
        <td><?= $v ? '<a href="/beheer/huurders.php?id=' . (int) $v['id'] . '">' . e($v['huurder_naam']) . '</a><br><small>' . e($locNaam[$v['afhaal_locatie_id']]) . ' → ' . e($locNaam[$v['terugbreng_locatie_id']]) . ', t/m ' . datum($v['terugbreng_datum']) . '</small>' : 'Vrij' ?></td>
        <td><?= $n ? '<a href="/beheer/huurders.php?id=' . (int) $n['id'] . '">' . e($n['huurder_naam']) . '</a><br><small>' . datum($n['afhaal_datum']) . ', ' . e($locNaam[$n['afhaal_locatie_id']]) . '</small>' : '–' ?></td>
        <td>
          <form method="post" class="verplaats"><?= csrf_veld() ?>
            <input type="hidden" name="fiets" value="<?= (int) $f['id'] ?>"><input type="hidden" name="actie" value="verplaatsen">
            <input type="date" name="datum" value="<?= $vandaag->format('Y-m-d') ?>" aria-label="Datum verplaatsing <?= e($f['nummer']) ?>" required>
            <select name="naar" aria-label="Naar locatie" required>
              <?php foreach ($locNaam as $id => $naam): ?><option value="<?= (int) $id ?>"><?= e($naam) ?></option><?php endforeach; ?>
            </select>
            <button class="link">Verplaats</button>
          </form>
        </td>
        <td>
          <form method="post" class="inline"><?= csrf_veld() ?><input type="hidden" name="fiets" value="<?= (int) $f['id'] ?>"><input type="hidden" name="actie" value="onderhoud">
            <button class="link"><?= $f['actief'] ? 'Actief · naar onderhoud' : 'Onderhoud · activeren' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="klein">Een fiets in onderhoud wordt niet meer toegewezen aan nieuwe reserveringen; bestaande reserveringen blijven staan.
  Verplaatsen kan alleen als de fiets op die dag niet verhuurd is en een latere reservering daarna nog klopt.</p>

<div class="twee-kolommen">
  <section>
    <h2>Laatste verplaatsingen</h2>
    <?php if (!$verplaatsingen): ?><p>Nog geen verplaatsingen.</p><?php else: ?>
    <div class="tabel"><table><thead><tr><th>Fiets</th><th>Vanaf</th><th>Naar</th><th>Door</th></tr></thead><tbody>
      <?php foreach ($verplaatsingen as $v): ?><tr><td><?= e($v['nummer']) ?></td><td><?= datum($v['datum']) ?></td><td><?= e($locNaam[$v['naar_locatie_id']] ?? '?') ?></td><td><?= e($v['naam'] ?? '–') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </section>
  <section>
    <h2>Laatste scans</h2>
    <?php if (!$scans): ?><p>Nog geen scans.</p><?php else: ?>
    <div class="tabel hoog"><table><thead><tr><th>Tijd</th><th>Fiets</th><th>Gebruiker</th><th>Resultaat</th></tr></thead><tbody>
      <?php foreach ($scans as $s): ?>
        <tr>
          <td><?= e((new DateTimeImmutable($s['tijd']))->format('d-m H:i')) ?></td>
          <td><?= e($s['nummer'] ?? '–') ?></td>
          <td><?= e($s['naam'] ?? '–') ?></td>
          <td><?= $s['reservering_id'] ? '<a href="/beheer/huurders.php?id=' . (int) $s['reservering_id'] . '">' . e(RESULTAAT[$s['resultaat']] ?? $s['resultaat']) . '</a>' : e(RESULTAAT[$s['resultaat']] ?? $s['resultaat']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </section>
</div>
<?php beheer_voet();
