<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = login_vereist();

$st = db()->prepare(
    'SELECT r.startdatum, r.einddatum, r.aantal_dagen, f.naam AS fiets, f.prijs_per_dag, l.naam AS locatie, l.adres
       FROM reserveringen r
       JOIN fietsen f  ON f.id = r.fiets_id
       JOIN locaties l ON l.id = r.locatie_id
      WHERE r.gebruiker_id = ?
      ORDER BY r.startdatum DESC'
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
      <thead><tr><th>Fiets</th><th>Ophalen</th><th>Terugbrengen</th><th>Duur</th><th>Afhaallocatie</th><th>Prijs</th></tr></thead>
      <tbody>
      <?php foreach ($reserveringen as $r): ?>
        <tr>
          <td><?= e($r['fiets']) ?></td>
          <td><?= datum($r['startdatum']) ?></td>
          <td><?= datum($r['einddatum']) ?></td>
          <td><?= e(HUURDUUR[(int) $r['aantal_dagen']] ?? $r['aantal_dagen'] . ' dagen') ?></td>
          <td><?= e($r['locatie']) ?><br><small><?= e($r['adres']) ?></small></td>
          <td><?= euro((float) $r['prijs_per_dag'] * (int) $r['aantal_dagen']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<p><a class="knop" href="/reserveren.php">Nieuwe reservering</a></p>
<?php voet();
