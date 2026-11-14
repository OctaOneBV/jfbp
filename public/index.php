<?php
require __DIR__ . '/../src/bootstrap.php';

$maten = maten();
$vrij = [];
foreach (vrij_per_locatie(date('Y-m-d')) as $perMaat) {
    foreach ($perMaat as $maat => $n) { $vrij[$maat] = ($vrij[$maat] ?? 0) + $n; }
}
$locaties = db()->query('SELECT naam FROM locaties WHERE actief = 1 ORDER BY naam')->fetchAll(PDO::FETCH_COLUMN);

kop('Fiets huren');
?>
<section class="hero">
  <h1>Huur eenvoudig een fiets</h1>
  <p>Haal je fiets op in <?= e(implode(', ', array_slice($locaties, 0, -1)) . ' of ' . end($locaties)) ?>, en breng hem terug waar het jou uitkomt. Het slot open je met je telefoon.</p>
  <?php if (gebruiker()): ?>
    <a class="knop" href="/reserveren.php">Fiets reserveren</a>
  <?php else: ?>
    <a class="knop" href="/registreren.php">Account aanmaken</a>
    <a class="knop secundair" href="/inloggen.php">Inloggen</a>
  <?php endif; ?>
</section>

<section class="band">
  <h2>Kies je maat</h2>
  <div class="grid">
    <?php foreach ($maten as $m): ?>
      <div class="kaart">
        <h3>Maat <?= e($m['maat']) ?></h3>
        <p><?= e($m['omschrijving']) ?></p>
        <p class="prijs"><?= euro($m['prijs_per_dag']) ?> per dag</p>
        <p><small><?= (int) ($vrij[$m['maat']] ?? 0) ?> vandaag beschikbaar</small></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section>
  <h2>Zo werkt het</h2>
  <ol class="stappen">
    <li><strong>Registreer</strong> een account en log in.</li>
    <li><strong>Reserveer</strong> je maat, de afhaal- en terugbrengplaats en de data.</li>
    <li><strong>Scan</strong> op de afhaaldag de barcode op je fiets: het slot gaat open.</li>
  </ol>
</section>
<?php voet();
