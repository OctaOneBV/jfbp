<?php
require __DIR__ . '/../../src/bootstrap.php';

beheerder_vereist();

// Beschikbaarheid op een willekeurige dag, per locatie en maat.
$dag  = get_datum('datum', new DateTimeImmutable('today'));
$dagS = $dag->format('Y-m-d');
$maten = maten();
$fietsen = fietsen_op_dag($dagS);

$perLocatie = [];
foreach (locaties() as $l) {
    $perLocatie[(int) $l['id']] = ['naam' => $l['naam'], 'vrij' => array_fill_keys(array_column($maten, 'maat'), 0),
                                   'vrijeFietsen' => [], 'onderhoud' => [], 'ophalen' => 0, 'terug' => 0];
}
foreach ($fietsen as $f) {
    $loc = (int) $f['locatie_id'];
    if (!isset($perLocatie[$loc])) { continue; }
    if (!$f['actief'] && !$f['verhuur']) {
        $perLocatie[$loc]['onderhoud'][] = $f['nummer'];
    } elseif (!$f['verhuur']) {
        $perLocatie[$loc]['vrij'][$f['maat']]++;
        $perLocatie[$loc]['vrijeFietsen'][] = $f;
    }
}
// Verwacht ophalen en terugbrengen op deze dag.
$st = db()->prepare('SELECT afhaal_locatie_id AS loc, COUNT(*) AS n FROM reserveringen WHERE afhaal_datum = ? GROUP BY afhaal_locatie_id');
$st->execute([$dagS]);
foreach ($st as $r) { if (isset($perLocatie[(int) $r['loc']])) { $perLocatie[(int) $r['loc']]['ophalen'] = (int) $r['n']; } }
$st = db()->prepare('SELECT terugbreng_locatie_id AS loc, COUNT(*) AS n FROM reserveringen WHERE terugbreng_datum = ? GROUP BY terugbreng_locatie_id');
$st->execute([$dagS]);
foreach ($st as $r) { if (isset($perLocatie[(int) $r['loc']])) { $perLocatie[(int) $r['loc']]['terug'] = (int) $r['n']; } }

$verhuurd = count(array_filter($fietsen, fn ($f) => $f['verhuur']));

beheer_kop('Beschikbaarheid', 'beschikbaarheid');
?>
<div class="balk-kop">
  <h1>Beschikbaarheid <small><?= DAGNAMEN[(int) $dag->format('N')] ?> <?= datum($dagS) ?></small></h1>
  <div class="weeknav">
    <a class="knop klein secundair" href="?datum=<?= $dag->modify('-1 day')->format('Y-m-d') ?>">‹ Vorige dag</a>
    <a class="knop klein secundair" href="?">Vandaag</a>
    <a class="knop klein secundair" href="?datum=<?= $dag->modify('+1 day')->format('Y-m-d') ?>">Volgende dag ›</a>
  </div>
</div>
<form method="get" class="filterbalk">
  <label>Datum <input type="date" name="datum" value="<?= $dagS ?>"></label>
  <button class="knop klein" type="submit">Toon</button>
</form>

<p class="lead"><?= $verhuurd ?> van de <?= count($fietsen) ?> fietsen zijn op deze dag verhuurd.</p>

<div class="tabel">
  <table>
    <thead><tr><th>Locatie</th><?php foreach ($maten as $m): ?><th>Maat <?= e($m['maat']) ?></th><?php endforeach; ?><th>Totaal vrij</th><th>Ophalen</th><th>Terugbrengen</th><th>Onderhoud</th></tr></thead>
    <tbody>
    <?php $totaal = array_fill_keys(array_column($maten, 'maat'), 0);
    foreach ($perLocatie as $p):
        foreach ($p['vrij'] as $maat => $n) { $totaal[$maat] += $n; } ?>
      <tr>
        <td><strong><?= e($p['naam']) ?></strong></td>
        <?php foreach ($p['vrij'] as $n): ?><td class="<?= $n === 0 ? 'nul' : '' ?>"><?= $n ?></td><?php endforeach; ?>
        <td><strong><?= array_sum($p['vrij']) ?></strong></td>
        <td><?= $p['ophalen'] ?></td>
        <td><?= $p['terug'] ?></td>
        <td><?= count($p['onderhoud']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="totaal">
        <td>Totaal</td>
        <?php foreach ($totaal as $n): ?><td><?= $n ?></td><?php endforeach; ?>
        <td><?= array_sum($totaal) ?></td><td></td><td></td><td></td>
      </tr>
    </tbody>
  </table>
</div>
<p class="klein">"Ophalen" en "Terugbrengen" zijn de reserveringen die op deze dag op die locatie beginnen of eindigen.
  Een fiets die vandaag wordt teruggebracht, is vanaf morgen weer beschikbaar.</p>

<h2>Vrije fietsen per locatie</h2>
<div class="grid">
  <?php foreach ($perLocatie as $p): ?>
    <div class="kaart">
      <h3><?= e($p['naam']) ?></h3>
      <?php if ($p['vrijeFietsen']): ?>
        <p class="chips"><?php foreach ($p['vrijeFietsen'] as $f): ?><span class="chip m-<?= e(strtolower($f['maat'])) ?>"><?= e($f['nummer']) ?></span><?php endforeach; ?></p>
      <?php else: ?>
        <p>Geen vrije fietsen.</p>
      <?php endif; ?>
      <?php if ($p['onderhoud']): ?><p class="klein">In onderhoud: <?= e(implode(', ', $p['onderhoud'])) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php beheer_voet();
