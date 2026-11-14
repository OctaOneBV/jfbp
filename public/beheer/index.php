<?php
require __DIR__ . '/../../src/bootstrap.php';

beheerder_vereist();

// Weektijdbalk: per fiets de verhuur van maandag t/m zondag.
$vandaag = new DateTimeImmutable('today');
$maandag = maandag_van(get_datum('week', $vandaag));
$zondag  = $maandag->modify('+6 days');
$dagen   = [];
for ($i = 0; $i < 7; $i++) {
    $dagen[] = $maandag->modify("+$i days");
}

$maatFilter = in_array($_GET['maat'] ?? '', array_column(maten(), 'maat'), true) ? $_GET['maat'] : '';
$alleenVerhuurd = !empty($_GET['verhuurd']);
$locNaam = array_column(locaties(), 'naam', 'id');

$st = db()->prepare(
    'SELECT r.id, r.fiets_id, r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, r.opgehaald_op,
            r.afhaal_locatie_id, r.terugbreng_locatie_id, g.email
       FROM reserveringen r JOIN gebruikers g ON g.id = r.gebruiker_id
      WHERE r.afhaal_datum <= ? AND r.terugbreng_datum >= ?
      ORDER BY r.afhaal_datum'
);
$st->execute([$zondag->format('Y-m-d'), $maandag->format('Y-m-d')]);
$perFiets = [];
foreach ($st as $r) {
    $perFiets[(int) $r['fiets_id']][] = $r;
}

$fietsen = fietsen_op_dag($maandag->format('Y-m-d'));
$fietsen = array_filter($fietsen, fn ($f) => ($maatFilter === '' || $f['maat'] === $maatFilter)
                                         && (!$alleenVerhuurd || isset($perFiets[(int) $f['id']])));

// Vrije fietsen per locatie per dag (onder de tijdbalk).
$vrij = [];
foreach ($dagen as $d) {
    foreach (vrij_per_locatie($d->format('Y-m-d')) as $loc => $perMaat) {
        $vrij[$loc][$d->format('Y-m-d')] = $maatFilter ? $perMaat[$maatFilter] : array_sum($perMaat);
    }
}
$verhuurdPerDag = [];
foreach ($dagen as $d) {
    $dag = $d->format('Y-m-d');
    $verhuurdPerDag[$dag] = 0;
    foreach ($perFiets as $fid => $lijst) {
        if (!isset($fietsen[$fid])) { continue; }
        foreach ($lijst as $r) {
            if ($r['afhaal_datum'] <= $dag && $r['terugbreng_datum'] >= $dag) { $verhuurdPerDag[$dag]++; }
        }
    }
}

function week_link(DateTimeImmutable $maandag, array $extra = []): string
{
    $q = array_filter(['week' => $maandag->format('Y-m-d'), 'maat' => $_GET['maat'] ?? '', 'verhuurd' => $_GET['verhuurd'] ?? ''] + $extra);
    return '?' . http_build_query($q);
}

beheer_kop('Tijdbalk', 'tijdbalk');
?>
<div class="balk-kop">
  <h1>Week <?= (int) $maandag->format('W') ?> <small><?= datum($maandag->format('Y-m-d')) ?> – <?= datum($zondag->format('Y-m-d')) ?></small></h1>
  <div class="weeknav">
    <a class="knop klein secundair" href="<?= e(week_link($maandag->modify('-7 days'))) ?>" aria-label="Vorige week">‹ Vorige</a>
    <a class="knop klein secundair" href="<?= e(week_link(maandag_van($vandaag))) ?>">Deze week</a>
    <a class="knop klein secundair" href="<?= e(week_link($maandag->modify('+7 days'))) ?>" aria-label="Volgende week">Volgende ›</a>
  </div>
</div>

<form method="get" class="filterbalk">
  <label>Ga naar datum <input type="date" name="week" value="<?= $maandag->format('Y-m-d') ?>"></label>
  <label>Maat
    <select name="maat">
      <option value="">Alle maten</option>
      <?php foreach (maten() as $m): ?><option value="<?= e($m['maat']) ?>" <?= $maatFilter === $m['maat'] ? 'selected' : '' ?>><?= e($m['maat']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="vinkje"><input type="checkbox" name="verhuurd" value="1" <?= $alleenVerhuurd ? 'checked' : '' ?>> Alleen verhuurde fietsen</label>
  <button class="knop klein" type="submit">Toon</button>
</form>

<div class="tijdbalk-scroll">
  <div class="tijdbalk" role="table" aria-label="Verhuur per fiets in week <?= (int) $maandag->format('W') ?>">
    <div class="tb-rij tb-kop" role="row">
      <div class="tb-fiets" role="columnheader">Fiets</div>
      <?php foreach ($dagen as $d): ?>
        <div class="tb-dag <?= $d == $vandaag ? 'vandaag' : '' ?> <?= (int) $d->format('N') >= 6 ? 'weekend' : '' ?>" role="columnheader">
          <?= DAGNAMEN[(int) $d->format('N')] ?> <strong><?= $d->format('j') ?></strong> <?= MAANDEN[(int) $d->format('n')] ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php foreach ($fietsen as $f): ?>
      <div class="tb-rij <?= $f['actief'] ? '' : 'onderhoud' ?>" role="row">
        <div class="tb-fiets" role="rowheader">
          <strong><?= e($f['nummer']) ?></strong>
          <small title="<?= e($locNaam[$f['locatie_id']] ?? '?') ?>"><?= e(kort($locNaam[$f['locatie_id']] ?? '?')) ?><?= $f['actief'] ? '' : ' · ond.' ?></small>
        </div>
        <div class="tb-dagen" role="cell">
          <?php foreach ($dagen as $d): ?><span class="tb-vak <?= $d == $vandaag ? 'vandaag' : '' ?> <?= (int) $d->format('N') >= 6 ? 'weekend' : '' ?>"></span><?php endforeach; ?>
          <?php foreach ($perFiets[(int) $f['id']] ?? [] as $r):
              $van = max(new DateTimeImmutable($r['afhaal_datum']), $maandag);
              $tot = min(new DateTimeImmutable($r['terugbreng_datum']), $zondag);
              $start = (int) $maandag->diff($van)->days + 1;
              $lengte = (int) $van->diff($tot)->days + 1;
              $klassen = 'tb-blok s' . $start . ' l' . $lengte
                  . ($r['afhaal_datum'] < $maandag->format('Y-m-d') ? ' door-links' : '')
                  . ($r['terugbreng_datum'] > $zondag->format('Y-m-d') ? ' door-rechts' : '')
                  . ($r['opgehaald_op'] ? ' opgehaald' : '');
              $titel = $r['huurder_naam'] . ' (' . $r['email'] . ")\n" . datum($r['afhaal_datum']) . ' ' . $locNaam[$r['afhaal_locatie_id']]
                  . ' → ' . datum($r['terugbreng_datum']) . ' ' . $locNaam[$r['terugbreng_locatie_id']]; ?>
            <a class="<?= $klassen ?>" href="/beheer/huurders.php?id=<?= (int) $r['id'] ?>" title="<?= e($titel) ?>">
              <span class="naam"><?= e($r['huurder_naam']) ?></span>
              <span class="route"><?= e(kort($locNaam[$r['afhaal_locatie_id']])) ?> → <?= e(kort($locNaam[$r['terugbreng_locatie_id']])) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$fietsen): ?><p class="leeg">Geen fietsen voor deze selectie.</p><?php endif; ?>
  </div>
</div>
<p class="legenda klein">
  <span class="tb-voorbeeld"></span> gereserveerd
  <span class="tb-voorbeeld opgehaald"></span> opgehaald (slot geopend)
  · Locatie onder het fietsnummer = waar de fiets op maandag staat of vertrekt. Klik op een blok voor de details.
</p>

<h2>Beschikbaar per locatie<?= $maatFilter ? ' (maat ' . e($maatFilter) . ')' : '' ?></h2>
<div class="tabel">
  <table class="week-tabel">
    <thead><tr><th>Locatie</th><?php foreach ($dagen as $d): ?><th><?= DAGNAMEN[(int) $d->format('N')] ?> <?= $d->format('j-n') ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach (locaties() as $l): ?>
        <tr>
          <td><?= e($l['naam']) ?></td>
          <?php foreach ($dagen as $d): $n = $vrij[(int) $l['id']][$d->format('Y-m-d')] ?? 0; ?>
            <td class="<?= $n === 0 ? 'nul' : '' ?>"><a href="/beheer/beschikbaarheid.php?datum=<?= $d->format('Y-m-d') ?>"><?= $n ?></a></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <tr class="totaal">
        <td>Verhuurd</td>
        <?php foreach ($dagen as $d): ?><td><?= $verhuurdPerDag[$d->format('Y-m-d')] ?></td><?php endforeach; ?>
      </tr>
    </tbody>
  </table>
</div>
<?php beheer_voet();
