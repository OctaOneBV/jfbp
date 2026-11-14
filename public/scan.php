<?php
require __DIR__ . '/../src/bootstrap.php';

// De barcode (QR) op elke fiets bevat https://jfbp.nl/scan.php?c=<code>.
// Scannen met de camera van de telefoon opent deze pagina. Het slot gaat alleen open als de fiets
// vandaag gereserveerd is voor de ingelogde gebruiker. Elke scan wordt vastgelegd in de tabel scans.

$gebruiker = login_vereist();

$code = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) ($_POST['c'] ?? $_GET['c'] ?? '')));
$code = substr($code, 0, 12);

$fiets = null;
if ($code !== '') {
    $st = db()->prepare('SELECT id, nummer, maat, code, actief FROM fietsen WHERE code = ?');
    $st->execute([$code]);
    $fiets = $st->fetch() ?: null;
}

/** Reservering van deze gebruiker voor deze fiets die vandaag loopt. */
function reservering_vandaag(int $fietsId, int $gebruikerId): ?array
{
    $st = db()->prepare(
        'SELECT r.id, r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, r.opgehaald_op, lt.naam AS terugbreng
           FROM reserveringen r JOIN locaties lt ON lt.id = r.terugbreng_locatie_id
          WHERE r.fiets_id = ? AND r.gebruiker_id = ? AND CURDATE() BETWEEN r.afhaal_datum AND r.terugbreng_datum
          LIMIT 1'
    );
    $st->execute([$fietsId, $gebruikerId]);
    return $st->fetch() ?: null;
}

$reservering = $fiets ? reservering_vandaag((int) $fiets['id'], (int) $gebruiker['id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    if (!$fiets) {
        log_scan(null, (int) $gebruiker['id'], null, 'onbekende_fiets');
        flash('Deze barcode hoort niet bij een van onze fietsen.', 'fout');
        redirect('/scan.php');
    }
    if (!$reservering) {
        log_scan((int) $fiets['id'], (int) $gebruiker['id'], null, 'geen_reservering');
        flash('Het slot blijft dicht: fiets ' . $fiets['nummer'] . ' is vandaag niet voor jou gereserveerd.', 'fout');
        $_SESSION['scan_al_gelogd'] = $code;
        redirect('/scan.php?c=' . rawurlencode($code));
    }
    $resultaat = slot_openen($fiets);
    if ($resultaat['ok']) {
        log_scan((int) $fiets['id'], (int) $gebruiker['id'], (int) $reservering['id'], 'geopend');
        db()->prepare('UPDATE reserveringen SET opgehaald_op = COALESCE(opgehaald_op, NOW()) WHERE id = ?')->execute([$reservering['id']]);
        flash('Het slot van fiets ' . $fiets['nummer'] . ' is open. Goede rit!' . ($resultaat['melding'] ? ' (' . $resultaat['melding'] . ')' : ''));
    } else {
        log_scan((int) $fiets['id'], (int) $gebruiker['id'], (int) $reservering['id'], 'slot_fout');
        flash($resultaat['melding'], 'fout');
    }
    $_SESSION['scan_al_gelogd'] = $code;
    redirect('/scan.php?c=' . rawurlencode($code));
}

// GET: scan vastleggen (niet nogmaals na onze eigen doorverwijzing) en tonen wat er kan.
$alGelogd = ($_SESSION['scan_al_gelogd'] ?? '') === $code;
unset($_SESSION['scan_al_gelogd']);
if ($code !== '' && !$alGelogd) {
    log_scan($fiets ? (int) $fiets['id'] : null, (int) $gebruiker['id'], $reservering ? (int) $reservering['id'] : null,
             $fiets ? 'bekeken' : 'onbekende_fiets');
}

// Heeft de gebruiker vandaag wel een andere fiets? Dan noemen we dat nummer.
$eigenFiets = null;
if ($fiets && !$reservering) {
    $st = db()->prepare('SELECT f.nummer FROM reserveringen r JOIN fietsen f ON f.id = r.fiets_id
                          WHERE r.gebruiker_id = ? AND CURDATE() BETWEEN r.afhaal_datum AND r.terugbreng_datum LIMIT 1');
    $st->execute([$gebruiker['id']]);
    $eigenFiets = $st->fetchColumn() ?: null;
}

// Beheerders zien wie de fiets vandaag huurt.
$huidig = null;
if ($fiets && $gebruiker['rol'] === 'beheerder') {
    $st = db()->prepare('SELECT r.huurder_naam, r.afhaal_datum, r.terugbreng_datum FROM reserveringen r
                          WHERE r.fiets_id = ? AND r.terugbreng_datum >= CURDATE() ORDER BY r.afhaal_datum LIMIT 1');
    $st->execute([$fiets['id']]);
    $huidig = $st->fetch() ?: null;
}

kop('Fiets openen');
?>
<h1>Fiets openen</h1>

<?php if ($code === ''): ?>
  <p class="lead">Scan de barcode op het frame van je fiets met de camera van je telefoon. Je komt dan vanzelf op deze pagina.
    Lukt scannen niet? Vul dan de code onder de barcode in.</p>
  <form method="get" class="formulier">
    <label>Code van de fiets
      <input name="c" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="bijv. 2E855460CBE8" required>
    </label>
    <button class="knop" type="submit">Verder</button>
  </form>

<?php elseif (!$fiets): ?>
  <p class="melding fout">Deze barcode hoort niet bij een van onze fietsen. Controleer de code of neem contact met ons op.</p>
  <p><a class="knop secundair" href="/scan.php">Opnieuw proberen</a></p>

<?php else: ?>
  <div class="scan-fiets">
    <p class="fietsnummer groot"><?= e($fiets['nummer']) ?></p>
    <p>Maat <?= e($fiets['maat']) ?></p>

    <?php if ($reservering): ?>
      <p>Gereserveerd voor <strong><?= e($reservering['huurder_naam']) ?></strong>,
         t/m <?= datum($reservering['terugbreng_datum']) ?>. Terugbrengen in <?= e($reservering['terugbreng']) ?>.</p>
      <form method="post">
        <?= csrf_veld() ?>
        <input type="hidden" name="c" value="<?= e($code) ?>">
        <button class="knop groot" type="submit">Slot openen</button>
      </form>
    <?php else: ?>
      <p class="melding fout">Het slot blijft dicht: deze fiets is vandaag niet voor jou gereserveerd.</p>
      <?php if ($eigenFiets): ?>
        <p>Jouw fiets voor vandaag is <strong class="fietsnummer"><?= e($eigenFiets) ?></strong>.</p>
      <?php else: ?>
        <p>Je hebt vandaag geen fiets gereserveerd. <a href="/reserveren.php">Reserveer een fiets</a>.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($gebruiker['rol'] === 'beheerder'): ?>
    <div class="kaart beheer-info">
      <h3>Beheer</h3>
      <p>Code <?= e($fiets['code']) ?><?= $fiets['actief'] ? '' : ' · <strong>in onderhoud</strong>' ?></p>
      <p><?= $huidig ? 'Eerstvolgende/huidige verhuur: ' . e($huidig['huurder_naam']) . ', ' . datum($huidig['afhaal_datum']) . ' t/m ' . datum($huidig['terugbreng_datum'])
                     : 'Geen lopende of komende verhuur.' ?></p>
      <p><a href="/beheer.php">Naar het fietsoverzicht</a></p>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php voet();
