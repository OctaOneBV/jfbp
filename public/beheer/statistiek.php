<?php
require __DIR__ . '/../../src/bootstrap.php';

beheerder_vereist();

// Statistiek: in welke periodes worden veel fietsen verhuurd?
// Maat: verhuurde fietsdagen (één fiets één dag verhuurd = 1) en bezetting = fietsdagen / (aantal fietsen × dagen).
$vandaag = new DateTimeImmutable('today');
$van = get_datum('van', $vandaag->modify('first day of this month')->modify('-11 months'));
$tot = get_datum('tot', $vandaag->modify('last day of this month')->modify('+2 months')->modify('last day of this month'));
if ($tot < $van) { [$van, $tot] = [$tot, $van]; }
if ($van->diff($tot)->days > 3 * 366) { $tot = $van->modify('+3 years'); }
$groep = ($_GET['groep'] ?? 'maand') === 'week' ? 'week' : 'maand';

$aantalFietsen = (int) db()->query('SELECT COUNT(*) FROM fietsen')->fetchColumn();
$locNaam = array_column(locaties(), 'naam', 'id');

$st = db()->prepare(
    'SELECT r.afhaal_datum, r.terugbreng_datum, r.afhaal_locatie_id, f.maat
       FROM reserveringen r JOIN fietsen f ON f.id = r.fiets_id
      WHERE r.afhaal_datum <= ? AND r.terugbreng_datum >= ?'
);
$st->execute([$tot->format('Y-m-d'), $van->format('Y-m-d')]);
$reserveringen = $st->fetchAll();

// Verhuurde fietsen per dag.
$perDag = [];
for ($d = $van; $d <= $tot; $d = $d->modify('+1 day')) {
    $perDag[$d->format('Y-m-d')] = 0;
}
$fietsdagenPerMaat = array_fill_keys(array_column(maten(), 'maat'), 0);
$afhalenPerLocatie = array_fill_keys(array_keys($locNaam), 0);
$nieuwPerDag = [];
foreach ($reserveringen as $r) {
    $a = max($r['afhaal_datum'], $van->format('Y-m-d'));
    $b = min($r['terugbreng_datum'], $tot->format('Y-m-d'));
    for ($d = new DateTimeImmutable($a); $d->format('Y-m-d') <= $b; $d = $d->modify('+1 day')) {
        $perDag[$d->format('Y-m-d')]++;
        $fietsdagenPerMaat[$r['maat']]++;
    }
    if (isset($perDag[$r['afhaal_datum']])) {
        $nieuwPerDag[$r['afhaal_datum']] = ($nieuwPerDag[$r['afhaal_datum']] ?? 0) + 1;
        $afhalenPerLocatie[(int) $r['afhaal_locatie_id']] = ($afhalenPerLocatie[(int) $r['afhaal_locatie_id']] ?? 0) + 1;
    }
}

// Groeperen per week of maand.
$buckets = [];
foreach ($perDag as $dag => $n) {
    $d = new DateTimeImmutable($dag);
    if ($groep === 'week') {
        $sleutel = $d->format('o-\WW');
        $label = 'wk ' . (int) $d->format('W');
        $lang = 'Week ' . (int) $d->format('W') . ' ' . $d->format('o');
    } else {
        $sleutel = $d->format('Y-m');
        $label = MAANDEN[(int) $d->format('n')] . ($d->format('n') === '1' || !$buckets ? ' ' . $d->format('y') : '');
        $lang = MAANDEN[(int) $d->format('n')] . ' ' . $d->format('Y');
    }
    $buckets[$sleutel] ??= ['label' => $label, 'lang' => $lang, 'fietsdagen' => 0, 'dagen' => 0, 'nieuw' => 0, 'gepland' => true, 'piek' => 0];
    $buckets[$sleutel]['fietsdagen'] += $n;
    $buckets[$sleutel]['dagen']++;
    $buckets[$sleutel]['nieuw'] += $nieuwPerDag[$dag] ?? 0;
    $buckets[$sleutel]['piek'] = max($buckets[$sleutel]['piek'], $n);
    if ($d <= $vandaag) { $buckets[$sleutel]['gepland'] = false; }
}
foreach ($buckets as &$b) {
    $b['bezetting'] = $aantalFietsen ? 100 * $b['fietsdagen'] / ($aantalFietsen * $b['dagen']) : 0;
}
unset($b);

// Gemiddelde bezetting per weekdag (alleen tot en met vandaag: gerealiseerd).
$wd = array_fill(1, 7, ['som' => 0, 'n' => 0]);
foreach ($perDag as $dag => $n) {
    if ($dag > $vandaag->format('Y-m-d')) { continue; }
    $w = (int) (new DateTimeImmutable($dag))->format('N');
    $wd[$w]['som'] += $n;
    $wd[$w]['n']++;
}

$totaalFietsdagen = array_sum($perDag);
$totaalDagen = count($perDag);
$drukste = $perDag;
arsort($drukste);
$drukste = array_slice(array_filter($drukste), 0, 10, true);

/**
 * Kolomdiagram als inline SVG (geen JavaScript nodig, past binnen de CSP).
 * $items: [['label' => .., 'waarde' => float, 'tip' => .., 'gepland' => bool], ...]
 */
function kolommen(array $items, float $max, string $eenheid, string $titel, int $labelElke = 1): string
{
    $b = 760; $h = 240; $links = 44; $onder = 26; $boven = 12;
    $plotB = $b - $links - 8; $plotH = $h - $onder - $boven;
    $n = max(1, count($items));
    $slot = $plotB / $n;
    $breedte = min(24, max(4, $slot - 2));
    $max = $max > 0 ? $max : 1;
    $stap = $eenheid === '%' ? 25 : max(1, (int) ceil($max / 4));
    $topWaarde = $eenheid === '%' ? 100 : $stap * 4;
    $y = fn (float $v) => $boven + $plotH - ($v / $topWaarde) * $plotH;

    static $teller = 0;
    $patroon = 'arcering-' . ++$teller;
    $svg = '<svg class="grafiek" viewBox="0 0 ' . $b . ' ' . $h . '" role="img" aria-label="' . e($titel) . '">';
    $svg .= '<defs><pattern id="' . $patroon . '" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" class="gepland-vlak"/><line x1="0" y1="0" x2="0" y2="6" class="gepland-lijn"/></pattern></defs>';
    for ($t = 0; $t <= $topWaarde; $t += $stap) {
        $yy = round($y($t), 1);
        $svg .= '<line class="raster" x1="' . $links . '" x2="' . ($b - 8) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $svg .= '<text class="as" x="' . ($links - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . $t . ($eenheid === '%' ? '%' : '') . '</text>';
    }
    $hoogsteIdx = array_search(max(array_column($items, 'waarde') ?: [0]), array_column($items, 'waarde'), true);
    foreach ($items as $i => $it) {
        $x = $links + $i * $slot + ($slot - $breedte) / 2;
        $top = $y(min($it['waarde'], $topWaarde));
        $hoogte = $boven + $plotH - $top;
        $r = min(4, $hoogte, $breedte / 2);
        $klasse = $it['gepland'] ? 'staaf gepland' : 'staaf';
        $svg .= '<g class="hover"><title>' . e($it['tip']) . '</title>';
        // Onzichtbaar, breder vlak als hover-doel.
        $svg .= '<rect class="doel" x="' . round($links + $i * $slot, 1) . '" y="' . $boven . '" width="' . round($slot, 1) . '" height="' . $plotH . '"/>';
        if ($hoogte > 0.5) {
            $x1 = round($x, 1); $x2 = round($x + $breedte, 1); $yb = $boven + $plotH; $yt = round($top, 1);
            $svg .= '<path class="' . $klasse . '"' . ($it['gepland'] ? ' fill="url(#' . $patroon . ')"' : '') . ' d="M' . $x1 . ' ' . $yb . 'V' . ($yt + $r) . 'Q' . $x1 . ' ' . $yt . ' ' . ($x1 + $r) . ' ' . $yt
                  . 'H' . ($x2 - $r) . 'Q' . $x2 . ' ' . $yt . ' ' . $x2 . ' ' . ($yt + $r) . 'V' . $yb . 'Z"/>';
        }
        $svg .= '</g>';
        if ($i === $hoogsteIdx && $it['waarde'] > 0) {
            $svg .= '<text class="waarde" x="' . round($x + $breedte / 2, 1) . '" y="' . round($top - 5, 1) . '" text-anchor="middle">'
                  . e(str_replace('.', ',', (string) round($it['waarde'], $eenheid === '%' ? 0 : 1)) . $eenheid) . '</text>';
        }
        if ($i % $labelElke === 0) {
            $svg .= '<text class="as" x="' . round($links + $i * $slot + $slot / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e($it['label']) . '</text>';
        }
    }
    $svg .= '<line class="basis" x1="' . $links . '" x2="' . ($b - 8) . '" y1="' . ($boven + $plotH) . '" y2="' . ($boven + $plotH) . '"/>';
    return $svg . '</svg>';
}

function pct(float $v): string { return number_format($v, 0, ',', '.') . '%'; }

beheer_kop('Statistiek', 'statistiek');
?>
<h1>Statistiek</h1>
<p class="lead">Wanneer worden er veel fietsen verhuurd? Bezetting = verhuurde fietsdagen gedeeld door <?= $aantalFietsen ?> fietsen × het aantal dagen.</p>

<form method="get" class="filterbalk">
  <label>Van <input type="date" name="van" value="<?= $van->format('Y-m-d') ?>"></label>
  <label>Tot en met <input type="date" name="tot" value="<?= $tot->format('Y-m-d') ?>"></label>
  <label>Per
    <select name="groep">
      <option value="maand" <?= $groep === 'maand' ? 'selected' : '' ?>>Maand</option>
      <option value="week" <?= $groep === 'week' ? 'selected' : '' ?>>Week</option>
    </select>
  </label>
  <button class="knop klein" type="submit">Toon</button>
</form>

<div class="tegels">
  <div class="tegel"><span class="getal"><?= pct($totaalDagen ? 100 * $totaalFietsdagen / ($aantalFietsen * $totaalDagen) : 0) ?></span><span>gemiddelde bezetting</span></div>
  <div class="tegel"><span class="getal"><?= number_format($totaalFietsdagen, 0, ',', '.') ?></span><span>verhuurde fietsdagen</span></div>
  <div class="tegel"><span class="getal"><?= number_format(array_sum($nieuwPerDag), 0, ',', '.') ?></span><span>verhuren gestart</span></div>
  <div class="tegel"><span class="getal"><?= $drukste ? reset($drukste) . ' / ' . $aantalFietsen : '–' ?></span><span>drukste dag<?= $drukste ? ': ' . datum(array_key_first($drukste)) : '' ?></span></div>
</div>

<h2>Bezetting per <?= $groep ?></h2>
<p class="legenda klein"><span class="sw"></span> gerealiseerd <span class="sw gepland"></span> gepland (reserveringen in de toekomst) · beweeg over een staaf voor de cijfers</p>
<?= kolommen(array_map(fn ($b) => [
        'label' => $b['label'], 'waarde' => $b['bezetting'], 'gepland' => $b['gepland'],
        'tip' => $b['lang'] . ': ' . pct($b['bezetting']) . ' bezet, ' . $b['fietsdagen'] . ' fietsdagen, ' . $b['nieuw'] . ' verhuren gestart, piek ' . $b['piek'] . ' fietsen' . ($b['gepland'] ? ' (gepland)' : ''),
    ], array_values($buckets)), 100, '%', 'Bezetting per ' . $groep, $groep === 'week' ? 4 : 1) ?>

<div class="twee-kolommen">
  <section>
    <h2>Per weekdag</h2>
    <p class="klein">Gemiddeld aantal verhuurde fietsen per dag, tot en met vandaag.</p>
    <?php $wdItems = [];
    foreach ($wd as $w => $x) {
        $gem = $x['n'] ? $x['som'] / $x['n'] : 0;
        $wdItems[] = ['label' => DAGNAMEN[$w], 'waarde' => $gem, 'gepland' => false,
                      'tip' => DAGNAMEN[$w] . ': gemiddeld ' . number_format($gem, 1, ',', '') . ' fietsen verhuurd (' . pct($aantalFietsen ? 100 * $gem / $aantalFietsen : 0) . ')'];
    }
    echo kolommen($wdItems, max(array_column($wdItems, 'waarde') ?: [0]), '', 'Gemiddeld verhuurde fietsen per weekdag'); ?>
  </section>
  <section>
    <h2>Per locatie en maat</h2>
    <div class="tabel">
      <table>
        <thead><tr><th>Afhaallocatie</th><th>Verhuren gestart</th></tr></thead>
        <tbody>
          <?php $maxLoc = max($afhalenPerLocatie ?: [0]) ?: 1;
          foreach ($afhalenPerLocatie as $loc => $n): ?>
            <tr><td><?= e($locNaam[$loc] ?? '?') ?></td><td><span class="balkje"><span class="balkje-vul b<?= (int) round(10 * $n / $maxLoc) ?>"></span></span> <?= $n ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="tabel">
      <table>
        <thead><tr><th>Maat</th><th>Fietsdagen</th><th>Bezetting</th></tr></thead>
        <tbody>
          <?php $perMaatAantal = array_map('intval', array_column(db()->query('SELECT maat, COUNT(*) n FROM fietsen GROUP BY maat')->fetchAll(), 'n', 'maat'));
          foreach ($fietsdagenPerMaat as $maat => $n): ?>
            <tr><td><?= e($maat) ?></td><td><?= $n ?></td><td><?= pct(($perMaatAantal[$maat] ?? 0) && $totaalDagen ? 100 * $n / ($perMaatAantal[$maat] * $totaalDagen) : 0) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<div class="twee-kolommen">
  <section>
    <h2>Drukste dagen</h2>
    <?php if ($drukste): ?>
    <div class="tabel">
      <table>
        <thead><tr><th>Datum</th><th>Verhuurd</th><th>Bezetting</th></tr></thead>
        <tbody>
          <?php foreach ($drukste as $dag => $n): ?>
            <tr><td><a href="/beheer/?week=<?= e($dag) ?>"><?= DAGNAMEN[(int) (new DateTimeImmutable($dag))->format('N')] ?> <?= datum($dag) ?></a></td><td><?= $n ?></td><td><?= pct(100 * $n / $aantalFietsen) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><p>Geen verhuur in deze periode.</p><?php endif; ?>
  </section>
  <section>
    <h2>Cijfers per <?= $groep ?></h2>
    <div class="tabel hoog">
      <table>
        <thead><tr><th><?= ucfirst($groep) ?></th><th>Bezetting</th><th>Fietsdagen</th><th>Gestart</th><th>Piek</th></tr></thead>
        <tbody>
          <?php foreach ($buckets as $b): ?>
            <tr class="<?= $b['gepland'] ? 'gepland-rij' : '' ?>"><td><?= e($b['lang']) ?><?= $b['gepland'] ? ' <small>(gepland)</small>' : '' ?></td><td><?= pct($b['bezetting']) ?></td><td><?= $b['fietsdagen'] ?></td><td><?= $b['nieuw'] ?></td><td><?= $b['piek'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<p class="klein">Capaciteit is gerekend met het huidige aantal fietsen (<?= $aantalFietsen ?>), inclusief fietsen in onderhoud.</p>
<?php beheer_voet();
