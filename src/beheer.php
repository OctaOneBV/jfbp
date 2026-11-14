<?php
declare(strict_types=1);

// Opmaak van het beheerportaal (/beheer/).

const BEHEER_MENU = [
    'tijdbalk'       => ['/beheer/', 'Tijdbalk'],
    'beschikbaarheid'=> ['/beheer/beschikbaarheid.php', 'Beschikbaarheid'],
    'huurders'       => ['/beheer/huurders.php', 'Huurders'],
    'statistiek'     => ['/beheer/statistiek.php', 'Statistiek'],
    'sloten'         => ['/beheer/sloten.php', 'Sloten'],
    'fietsen'        => ['/beheer/fietsen.php', 'Fietsen'],
];

function beheer_kop(string $titel, string $actief, string $extra = ''): void
{
    global $config;
    $g = gebruiker();
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titel) ?> · Beheer · <?= e($config['sitenaam']) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/beheer.css">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<?php if ($extra === 'labels'): ?>
<script src="/assets/qrcode/qrcode.min.js" defer></script>
<script src="/assets/labels.js" defer></script>
<?php endif; ?>
</head>
<body class="beheer">
<header class="beheer-kop">
  <div class="wrap breed">
    <a class="logo" href="/beheer/">JFBP <span>Beheer</span></a>
    <?php if ($g && $g['rol'] === 'beheerder'): ?>
      <nav aria-label="Beheer">
        <?php foreach (BEHEER_MENU as $sleutel => [$url, $label]): ?>
          <a href="<?= $url ?>" <?= $sleutel === $actief ? 'aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
        <span class="wie"><?= e($g['naam']) ?></span>
        <a href="/" class="naar-site">Website</a>
        <form method="post" action="/uitloggen.php" class="inline"><?= csrf_veld() ?><button class="link">Uitloggen</button></form>
      </nav>
    <?php endif; ?>
  </div>
</header>
<main class="wrap breed inhoud">
<?php
    if ($f = flash()) {
        echo '<p class="melding ' . e($f['soort']) . '" role="status">' . e($f['tekst']) . '</p>';
    }
}

function beheer_voet(): void
{
    ?>
</main>
</body>
</html>
<?php
}

/** Korte locatienaam voor in krappe cellen (eerste 3 letters). */
function kort(string $naam): string
{
    return mb_substr($naam, 0, 3);
}

/** Datum uit GET met standaardwaarde. */
function get_datum(string $sleutel, DateTimeImmutable $standaard): DateTimeImmutable
{
    return geldige_datum((string) ($_GET[$sleutel] ?? '')) ?? $standaard;
}
