<?php
require __DIR__ . '/../src/bootstrap.php';

$fietsen = db()->query('SELECT naam, omschrijving, prijs_per_dag FROM fietsen WHERE actief = 1 ORDER BY id')->fetchAll();

kop('Fiets huren');
?>
<section class="hero">
  <h1>Huur eenvoudig een fiets</h1>
  <p>Maak een account aan, kies een fiets, de huurperiode en een afhaallocatie. Klaar.</p>
  <?php if (gebruiker()): ?>
    <a class="knop" href="/reserveren.php">Fiets reserveren</a>
  <?php else: ?>
    <a class="knop" href="/registreren.php">Account aanmaken</a>
    <a class="knop secundair" href="/inloggen.php">Inloggen</a>
  <?php endif; ?>
</section>

<section>
  <h2>Onze fietsen</h2>
  <div class="grid">
    <?php foreach ($fietsen as $f): ?>
      <div class="kaart">
        <h3><?= e($f['naam']) ?></h3>
        <p><?= e($f['omschrijving']) ?></p>
        <p class="prijs"><?= euro($f['prijs_per_dag']) ?> per dag</p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section>
  <h2>Zo werkt het</h2>
  <ol class="stappen">
    <li><strong>Registreer</strong> een account.</li>
    <li><strong>Log in</strong> met je e-mailadres en wachtwoord.</li>
    <li><strong>Kies</strong> een fiets, de huurduur en een afhaallocatie.</li>
  </ol>
</section>
<?php voet();
