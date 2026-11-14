<?php
require __DIR__ . '/../src/bootstrap.php';

$gebruiker = login_vereist();

$st = db()->prepare(
    'SELECT r.id, r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, r.aantal_dagen, r.prijs_per_dag, r.opgehaald_op,
            r.vertrek, r.aankomst, r.route_meters, r.route_lijn,
            f.nummer, f.maat, la.naam AS afhaal, la.adres AS afhaal_adres, lt.naam AS terugbreng, lt.adres AS terugbreng_adres
       FROM reserveringen r
       JOIN fietsen f  ON f.id = r.fiets_id
       JOIN locaties la ON la.id = r.afhaal_locatie_id
       JOIN locaties lt ON lt.id = r.terugbreng_locatie_id
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

// Route nog niet bekend (bijv. routedienst was eerder onbereikbaar)? Dan nu nog één keer proberen.
if ($heeftTocht && $r['route_meters'] === null && ($route = bereken_fietsroute($r['vertrek'], $r['aankomst']))) {
    route_opslaan((int) $r['id'], $route);
    $r['route_meters'] = $route['meters'];
    $r['route_lijn'] = json_encode($route['lijn']);
}
$heeftKaart = $heeftTocht && $r['route_lijn'] !== null;

kop('Reservering', $heeftKaart ? 'kaart' : '');
?>
<h1>Je reservering</h1>

<div class="grid samenvatting">
  <div class="kaart"><h3>Huurder</h3><p><?= e($r['huurder_naam']) ?></p></div>
  <div class="kaart"><h3>Fiets</h3><p><strong class="fietsnummer"><?= e($r['nummer']) ?></strong><br><small>Maat <?= e($r['maat']) ?></small></p></div>
  <div class="kaart"><h3>Afhalen</h3><p><?= e($r['afhaal']) ?>, <?= datum($r['afhaal_datum']) ?><br><small><?= e($r['afhaal_adres']) ?></small></p></div>
  <div class="kaart"><h3>Terugbrengen</h3><p><?= e($r['terugbreng']) ?>, <?= datum($r['terugbreng_datum']) ?><br><small><?= e($r['terugbreng_adres']) ?></small></p></div>
  <div class="kaart"><h3>Prijs</h3><p class="prijs"><?= euro((float) $r['prijs_per_dag'] * (int) $r['aantal_dagen']) ?></p><p><small><?= (int) $r['aantal_dagen'] ?> <?= (int) $r['aantal_dagen'] === 1 ? 'dag' : 'dagen' ?> × <?= euro($r['prijs_per_dag']) ?></small></p></div>
</div>

<div class="melding info slot-uitleg">
  <?php if ($r['opgehaald_op']): ?>
    Opgehaald op <?= e((new DateTimeImmutable($r['opgehaald_op']))->format('d-m-Y \o\m H:i')) ?>.
  <?php else: ?>
    Op de afhaaldag scan je met je telefoon de barcode op fiets <strong><?= e($r['nummer']) ?></strong>. Het slot gaat dan open.
  <?php endif; ?>
  Het slot kan alleen worden geopend van <?= datum($r['afhaal_datum']) ?> t/m <?= datum($r['terugbreng_datum']) ?>, en alleen met jouw account.
</div>

<?php if ($heeftTocht): ?>
  <h2>Je fietstocht</h2>
  <div class="tocht">
    <div class="tocht-info">
      <p class="van-naar"><strong><?= e($r['vertrek']) ?></strong> <span aria-hidden="true">→</span> <strong><?= e($r['aankomst']) ?></strong></p>
      <?php if ($r['route_meters'] !== null): ?>
        <div class="tocht-cijfers">
          <div><span class="getal"><?= duur_tekst(fietstijd_seconden((int) $r['route_meters'])) ?></span><span>fietsen</span></div>
          <div><span class="getal"><?= afstand_tekst((int) $r['route_meters']) ?></span><span>afstand</span></div>
        </div>
        <p class="klein">Fietstijd bij een gemiddelde snelheid van <?= GEMIDDELDE_SNELHEID_KMH ?> km/u, zonder pauzes. Route berekend met de open-source routeplanner BRouter op basis van OpenStreetMap.</p>
      <?php else: ?>
        <p class="melding info">De route kon niet worden berekend. Controleer of de plaatsnamen kloppen, of bekijk de route via de link hieronder.</p>
      <?php endif; ?>
      <a class="knop secundair" href="<?= e(osm_route_link($r['vertrek'], $r['aankomst'])) ?>" target="_blank" rel="noopener">Open in OpenStreetMap</a>
    </div>
    <?php if ($heeftKaart): ?>
      <div id="kaart" class="kaart-frame" data-tegels="<?= e($config['tegel_url']) ?>"
           role="img" aria-label="Kaart met de fietsroute van <?= e($r['vertrek']) ?> naar <?= e($r['aankomst']) ?>"></div>
      <script type="application/json" id="routedata"><?= json_encode(
          ['van' => $r['vertrek'], 'naar' => $r['aankomst'], 'lijn' => json_decode($r['route_lijn'], true)],
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
      ) ?></script>
    <?php endif; ?>
  </div>
<?php endif; ?>

<p><a class="knop" href="/account.php">Naar mijn reserveringen</a></p>
<?php voet();
