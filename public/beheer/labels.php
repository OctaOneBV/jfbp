<?php
require __DIR__ . '/../../src/bootstrap.php';

beheerder_vereist();

$fietsen = db()->query('SELECT f.nummer, f.maat, f.code FROM fietsen f JOIN maten m ON m.maat = f.maat ORDER BY m.volgorde, f.nummer')->fetchAll();

beheer_kop('Barcodelabels', 'fietsen', 'labels');
?>
<div class="niet-printen">
  <h1>Barcodelabels</h1>
  <p class="lead">Eén label per fiets. De QR-code bevat het adres <code><?= e(scan_url('…')) ?></code>; scannen met een telefooncamera opent direct de juiste pagina.
    Print op stickervellen of plastificeer de labels en bevestig ze goed zichtbaar op het frame.</p>
  <p><button class="knop" type="button" id="printen">Printen</button> <a class="knop secundair" href="/beheer/fietsen.php">Terug naar fietsen</a></p>
</div>

<div class="labels">
  <?php foreach ($fietsen as $f): ?>
    <div class="label">
      <div class="qr" data-qr="<?= e(scan_url($f['code'])) ?>" role="img" aria-label="Barcode fiets <?= e($f['nummer']) ?>"></div>
      <div class="label-tekst">
        <strong><?= e($f['nummer']) ?></strong>
        <span>Maat <?= e($f['maat']) ?></span>
        <span class="code"><?= e($f['code']) ?></span>
        <span class="klein">Scan om het slot te openen</span>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php beheer_voet();
