<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = login_vereist();

$st = db()->prepare(
    'SELECT r.id, r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, r.aantal_dagen, r.prijs_per_dag, r.vertrek, r.aankomst, r.route_meters,
            f.nummer, la.naam AS afhaal, lt.naam AS terugbreng
       FROM reserveringen r
       JOIN fietsen f  ON f.id = r.fiets_id
       JOIN locaties la ON la.id = r.afhaal_locatie_id
       JOIN locaties lt ON lt.id = r.terugbreng_locatie_id
      WHERE r.gebruiker_id = ?
      ORDER BY r.afhaal_datum DESC'
);
$st->execute([$gebruiker['id']]);
$reserveringen = $st->fetchAll();

kop('Mijn reserveringen');
?>
<h1>Mijn reserveringen</h1>
<?php if (!$reserveringen): ?>
  <p class="lead">Je hebt nog geen reserveringen.</p>
<?php else: ?>
  <div class="tabel">
    <table>
      <thead><tr><th>Fiets</th><th>Huurder</th><th>Afhalen</th><th>Terugbrengen</th><th>Prijs</th><th>Fietstocht</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($reserveringen as $r): ?>
        <tr>
          <td><strong><?= e($r['nummer']) ?></strong></td>
          <td><?= e($r['huurder_naam']) ?></td>
          <td><?= e($r['afhaal']) ?><br><small><?= datum($r['afhaal_datum']) ?></small></td>
          <td><?= e($r['terugbreng']) ?><br><small><?= datum($r['terugbreng_datum']) ?></small></td>
          <td><?= euro((float) $r['prijs_per_dag'] * (int) $r['aantal_dagen']) ?><br><small><?= (int) $r['aantal_dagen'] ?> d.</small></td>
          <td><?php if ($r['vertrek'] !== null): ?><?= e($r['vertrek']) ?> → <?= e($r['aankomst']) ?><?= $r['route_meters'] !== null ? '<br><small>' . afstand_tekst((int) $r['route_meters']) . ', ' . duur_tekst(fietstijd_seconden((int) $r['route_meters'])) . '</small>' : '' ?><?php else: ?>–<?php endif; ?></td>
          <td><a href="/reservering.php?id=<?= (int) $r['id'] ?>">Bekijken</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<p><a class="knop" href="/reserveren.php">Nieuwe reservering</a></p>
<?php voet();
