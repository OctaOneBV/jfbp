<?php
require __DIR__ . '/../../src/bootstrap.php';

$beheerder = beheerder_vereist();

// Sloten: overzicht van alle fietsen (horizontaal) per vestiging (verticaal).
//  rood  = slot dicht; klik om vrij te geven
//  groen = vrijgegeven: er staat een eenmalige openingscode klaar die naar het slot verzonden kan worden
// Na verzenden is de code gebruikt en wordt het hokje weer rood. Klik op groen = vrijgave intrekken.

const VRIJGAVE_GELDIG_UUR = 24;

$vandaag = date('Y-m-d');

/** Actieve vrijgaven per fiets_id. */
function actieve_vrijgaven(): array
{
    $uit = [];
    foreach (db()->query(
        "SELECT v.*, f.nummer, f.code AS barcode, l.naam AS locatie, g.naam AS door
           FROM slotvrijgaven v JOIN fietsen f ON f.id = v.fiets_id JOIN locaties l ON l.id = v.locatie_id
           LEFT JOIN gebruikers g ON g.id = v.aangemaakt_door
          WHERE v.status = 'klaar' AND v.geldig_tot > NOW() ORDER BY v.id"
    ) as $v) {
        $uit[(int) $v['fiets_id']] = $v;
    }
    return $uit;
}

/** Stuurt de code van een vrijgave naar het slot. */
function verzend(array $v, array $beheerder): array
{
    $fietsen = fietsen_op_dag(date('Y-m-d'));
    $f = $fietsen[(int) $v['fiets_id']] ?? null;
    if (!$f || $f['verhuur'] || (int) $f['locatie_id'] !== (int) $v['locatie_id']) {
        db()->prepare("UPDATE slotvrijgaven SET status = 'ingetrokken', afgehandeld_op = NOW(), afgehandeld_door = ? WHERE id = ?")
            ->execute([$beheerder['id'], $v['id']]);
        return [false, $v['nummer'] . ': de fiets staat niet meer op ' . $v['locatie'] . '; vrijgave ingetrokken.'];
    }
    $resultaat = slot_openen(['nummer' => $v['nummer'], 'code' => $v['barcode']], $v['code']);
    if (!$resultaat['ok']) {
        return [false, $v['nummer'] . ': ' . $resultaat['melding'] . ' De vrijgave blijft staan, probeer het opnieuw.'];
    }
    db()->prepare("UPDATE slotvrijgaven SET status = 'verzonden', afgehandeld_op = NOW(), afgehandeld_door = ? WHERE id = ? AND status = 'klaar'")
        ->execute([$beheerder['id'], $v['id']]);
    return [true, $v['nummer'] . ' in ' . $v['locatie'] . ' is geopend.' . ($resultaat['melding'] ? ' (' . $resultaat['melding'] . ')' : '')];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_controleren();
    $actie = (string) ($_POST['actie'] ?? '');
    $pdo = db();

    if ($actie === 'wissel') {
        // Klik op een hokje: rood -> groen (vrijgeven) of groen -> rood (intrekken).
        $fietsId = (int) ($_POST['fiets'] ?? 0);
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id, nummer FROM fietsen WHERE id = ? FOR UPDATE');
        $st->execute([$fietsId]);
        $fiets = $st->fetch();
        $actief = actieve_vrijgaven()[$fietsId] ?? null;
        $f = fietsen_op_dag($vandaag)[$fietsId] ?? null;
        if (!$fiets || !$f) {
            $pdo->rollBack();
            flash('Onbekende fiets.', 'fout');
        } elseif ($actief) {
            $pdo->prepare("UPDATE slotvrijgaven SET status = 'ingetrokken', afgehandeld_op = NOW(), afgehandeld_door = ? WHERE id = ?")
                ->execute([$beheerder['id'], $actief['id']]);
            $pdo->commit();
            flash('Vrijgave van ' . $fiets['nummer'] . ' ingetrokken: het slot blijft dicht.');
        } elseif ($f['verhuur']) {
            $pdo->rollBack();
            flash($fiets['nummer'] . ' is verhuurd en staat niet op een vestiging.', 'fout');
        } else {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO slotvrijgaven (fiets_id, locatie_id, code, aangemaakt_door, geldig_tot) VALUES (?, ?, ?, ?, NOW() + INTERVAL ? HOUR)')
                ->execute([$fietsId, $f['locatie_id'], $code, $beheerder['id'], VRIJGAVE_GELDIG_UUR]);
            $pdo->commit();
            flash($fiets['nummer'] . ' is vrijgegeven. De openingscode staat klaar om naar het slot te verzenden.');
        }
    } elseif ($actie === 'verzenden' || $actie === 'alles') {
        $actief = actieve_vrijgaven();
        if ($actie === 'verzenden') {
            $id = (int) ($_POST['vrijgave'] ?? 0);
            $actief = array_filter($actief, fn ($v) => (int) $v['id'] === $id);
        }
        if (!$actief) {
            flash('Er staat niets klaar om te verzenden (misschien al verzonden of verlopen).', 'fout');
        } else {
            $goed = $fout = [];
            foreach ($actief as $v) {
                [$ok, $tekst] = verzend($v, $beheerder);
                if ($ok) { $goed[] = $tekst; } else { $fout[] = $tekst; }
            }
            flash(implode(' ', array_merge($goed, $fout)), $fout ? 'fout' : 'ok');
        }
    }
    redirect('/beheer/sloten.php');
}

// Verlopen vrijgaven netjes afsluiten.
db()->exec("UPDATE slotvrijgaven SET status = 'ingetrokken', afgehandeld_op = geldig_tot WHERE status = 'klaar' AND geldig_tot <= NOW()");

$fietsen = fietsen_op_dag($vandaag);
$vrijgaven = actieve_vrijgaven();
$locaties = locaties();
$perMaat = [];
foreach ($fietsen as $f) { $perMaat[$f['maat']][] = $f; }

$recent = db()->query(
    "SELECT v.status, v.afgehandeld_op, v.aangemaakt_op, f.nummer, l.naam AS locatie, g.naam AS door
       FROM slotvrijgaven v JOIN fietsen f ON f.id = v.fiets_id JOIN locaties l ON l.id = v.locatie_id
       LEFT JOIN gebruikers g ON g.id = v.afgehandeld_door
      WHERE v.status <> 'klaar' ORDER BY v.afgehandeld_op DESC, v.id DESC LIMIT 20"
)->fetchAll();

beheer_kop('Sloten', 'sloten');
?>
<div class="balk-kop">
  <h1>Sloten <small><?= count($vrijgaven) ?> vrijgegeven</small></h1>
  <?php if ($vrijgaven): ?>
    <form method="post" class="inline"><?= csrf_veld() ?><input type="hidden" name="actie" value="alles">
      <button class="knop klein" type="submit">Alle groene verzenden (<?= count($vrijgaven) ?>)</button>
    </form>
  <?php endif; ?>
</div>
<p class="legenda klein">
  <span class="sl-voorbeeld dicht"></span> slot dicht, klik om vrij te geven
  <span class="sl-voorbeeld open"></span> vrijgegeven, code klaar om te verzenden (klik = intrekken)
  <span class="sl-voorbeeld weg"></span> verhuurd (onderweg)
</p>

<div class="slotmatrix-scroll">
  <table class="slotmatrix">
    <thead>
      <tr>
        <th rowspan="2" class="sl-hoek">Vestiging</th>
        <?php foreach ($perMaat as $maat => $lijst): ?><th colspan="<?= count($lijst) ?>" class="sl-maat">Maat <?= e($maat) ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($perMaat as $lijst): foreach ($lijst as $i => $f): ?>
          <th class="sl-fiets <?= $i === 0 ? 'groepstart' : '' ?>" scope="col"><?= e($f['nummer']) ?></th>
        <?php endforeach; endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($locaties as $l): ?>
        <tr>
          <th scope="row" class="sl-loc"><?= e($l['naam']) ?></th>
          <?php foreach ($perMaat as $lijst): foreach ($lijst as $i => $f):
              $hier = (int) $f['locatie_id'] === (int) $l['id'];
              $groep = $i === 0 ? ' groepstart' : ''; ?>
            <?php if (!$hier): ?>
              <td class="sl-cel leeg<?= $groep ?>"></td>
            <?php elseif ($f['verhuur']): ?>
              <td class="sl-cel weg<?= $groep ?>" title="<?= e($f['nummer'] . ' is verhuurd aan ' . $f['verhuur']['huurder_naam'] . ' t/m ' . datum($f['verhuur']['terugbreng_datum'])) ?>">
                <span aria-hidden="true">↗</span><span class="sr">verhuurd</span>
              </td>
            <?php else: $v = $vrijgaven[(int) $f['id']] ?? null; ?>
              <td class="sl-cel<?= $groep ?>">
                <form method="post"><?= csrf_veld() ?><input type="hidden" name="actie" value="wissel"><input type="hidden" name="fiets" value="<?= (int) $f['id'] ?>">
                  <button type="submit" class="sl-knop <?= $v ? 'open' : 'dicht' ?><?= $f['actief'] ? '' : ' onderhoud' ?>"
                          title="<?= e($f['nummer'] . ' in ' . $l['naam'] . ($v ? ': vrijgegeven, code ' . $v['code'] . '. Klik om in te trekken.' : ': slot dicht. Klik om vrij te geven.') . ($f['actief'] ? '' : ' (in onderhoud)')) ?>"
                          aria-label="<?= e($f['nummer'] . ' in ' . $l['naam'] . ($v ? ', vrijgegeven' : ', dicht')) ?>">
                    <span aria-hidden="true"><?= $v ? '✓' : '' ?></span>
                  </button>
                </form>
              </td>
            <?php endif; ?>
          <?php endforeach; endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="twee-kolommen">
  <section>
    <h2>Klaar om te verzenden</h2>
    <?php if (!$vrijgaven): ?>
      <p class="klein">Niets vrijgegeven. Klik op een rood hokje om een fiets vrij te geven.</p>
    <?php else: ?>
      <div class="tabel"><table>
        <thead><tr><th>Fiets</th><th>Vestiging</th><th>Code</th><th>Geldig tot</th><th>Door</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($vrijgaven as $v): ?>
          <tr>
            <td><strong><?= e($v['nummer']) ?></strong></td>
            <td><?= e($v['locatie']) ?></td>
            <td class="code"><?= e($v['code']) ?></td>
            <td><?= e((new DateTimeImmutable($v['geldig_tot']))->format('d-m H:i')) ?></td>
            <td><?= e($v['door'] ?? '–') ?></td>
            <td class="acties">
              <form method="post" class="inline"><?= csrf_veld() ?><input type="hidden" name="actie" value="verzenden"><input type="hidden" name="vrijgave" value="<?= (int) $v['id'] ?>">
                <button class="knop klein" type="submit">Verzend naar slot</button></form>
              <form method="post" class="inline"><?= csrf_veld() ?><input type="hidden" name="actie" value="wissel"><input type="hidden" name="fiets" value="<?= (int) $v['fiets_id'] ?>">
                <button class="link" type="submit">Intrekken</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <p class="klein">Een code is eenmalig en <?= VRIJGAVE_GELDIG_UUR ?> uur geldig. Na het verzenden is hij gebruikt en wordt het hokje weer rood.</p>
  </section>
  <section>
    <h2>Afgehandeld</h2>
    <?php if (!$recent): ?><p class="klein">Nog niets afgehandeld.</p><?php else: ?>
      <div class="tabel hoog"><table>
        <thead><tr><th>Tijd</th><th>Fiets</th><th>Vestiging</th><th>Resultaat</th><th>Door</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><?= e((new DateTimeImmutable($r['afgehandeld_op'] ?? $r['aangemaakt_op']))->format('d-m H:i')) ?></td>
            <td><?= e($r['nummer']) ?></td>
            <td><?= e($r['locatie']) ?></td>
            <td><?= ['verzonden' => 'Geopend', 'ingetrokken' => 'Ingetrokken / verlopen', 'mislukt' => 'Mislukt'][$r['status']] ?? e($r['status']) ?></td>
            <td><?= e($r['door'] ?? '–') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>
</div>
<?php if (($config['slot_modus'] ?? '') === 'simulatie'): ?>
  <p class="melding info">Simulatie: er is nog geen echt slot gekoppeld. Verzenden wordt alleen vastgelegd.</p>
<?php endif; ?>
<?php beheer_voet();
