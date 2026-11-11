<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = login_vereist();

$st = db()->prepare(
    'SELECT r.id, r.startdatum, r.einddatum, r.aantal_dagen, r.vertrek, r.aankomst, r.route_seconden, r.route_meters,
            f.naam AS fiets, f.prijs_per_dag, l.naam AS locatie, l.adres
       FROM reserveringen r
       JOIN fietsen f  ON f.id = r.fiets_id
       JOIN locaties l ON l.id = r.locatie_id
      WHERE r.id = ? AND r.gebruiker_id = ?'
);
$st->execute([(int) ($_GET['id'] ?? 0), $gebruiker['id']]);
$r = $st->fetch();
if (!$r) {
    http_response_code(404);
    kop('Niet gevonden');
    echo '<h1>Reservering niet gevonden</h1><p><a href="/account.php">Terug naar mijn reserveringen</a></p>';
    voet();
    exit;
}

$heeftTocht = $r['vertrek'] !== null && $r['aankomst'] !== null;

// Route nog niet bekend (bijv. Google was eerder onbereikbaar)? Dan nu nog één keer proberen.
if ($heeftTocht && $r['route_seconden'] === null && ($route = bereken_fietsroute($r['vertrek'], $r['aankomst']))) {
    db()->prepare('UPDATE reserveringen SET route_seconden = ?, route_meters = ? WHERE id = ?')
        ->execute([$route['seconden'], $route['meters'], $r['id']]);
    $r['route_seconden'] = $route['seconden'];
    $r['route_meters'] = $route['meters'];
}

kop('Reservering');
?>
<h1>Je reservering</h1>

<div class="grid samenvatting">
  <div class="kaart"><h3>Fiets</h3><p><?= e($r['fiets']) ?></p></div>
  <div class="kaart"><h3>Periode</h3><p><?= datum($r['startdatum']) ?> t/m <?= datum((new DateTimeImmutable($r['einddatum']))->modify('-1 day')->format('Y-m-d')) ?><br><small><?= e(HUURDUUR[(int) $r['aantal_dagen']] ?? $r['aantal_dagen'] . ' dagen') ?>, terugbrengen op <?= datum($r['einddatum']) ?></small></p></div>
  <div class="kaart"><h3>Afhalen</h3><p><?= e($r['locatie']) ?><br><small><?= e($r['adres']) ?></small></p></div>
  <div class="kaart"><h3>Prijs</h3><p class="prijs"><?= euro((float) $r['prijs_per_dag'] * (int) $r['aantal_dagen']) ?></p></div>
</div>

<?php if ($heeftTocht): ?>
  <h2>Je fietstocht</h2>
  <div class="tocht">
    <div class="tocht-info">
      <p class="van-naar"><strong><?= e($r['vertrek']) ?></strong> <span aria-hidden="true">→</span> <strong><?= e($r['aankomst']) ?></strong></p>
      <?php if ($r['route_seconden'] !== null): ?>
        <div class="tocht-cijfers">
          <div><span class="getal"><?= duur_tekst((int) $r['route_seconden']) ?></span><span>fietsen</span></div>
          <div><span class="getal"><?= afstand_tekst((int) $r['route_meters']) ?></span><span>afstand</span></div>
        </div>
        <p class="klein">Reistijd volgens Google Maps voor een gewone fiets, zonder pauzes. Met een e-bike ben je meestal sneller.</p>
      <?php else: ?>
        <p class="melding info">De reistijd kon niet worden berekend. Controleer of de plaatsnamen kloppen, of bekijk de route via de link hieronder.</p>
      <?php endif; ?>
      <a class="knop secundair" href="<?= e(google_maps_link($r['vertrek'], $r['aankomst'])) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
    </div>
    <?php if ($kaart = kaart_url($r['vertrek'], $r['aankomst'])): ?>
      <iframe class="kaart-frame" src="<?= e($kaart) ?>" title="Fietsroute van <?= e($r['vertrek']) ?> naar <?= e($r['aankomst']) ?>"
              loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
    <?php endif; ?>
  </div>
<?php endif; ?>

<p><a class="knop" href="/account.php">Naar mijn reserveringen</a></p>
<?php voet();
