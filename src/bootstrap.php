<?php
declare(strict_types=1);

// ---------- configuratie ----------
// Volgorde: omgevingsvariabelen, daarna een configbestand buiten de webroot.
$configFile = getenv('JFBP_CONFIG') ?: dirname(__DIR__) . '/config.local.php';
$config = is_file($configFile) ? require $configFile : [];
$config += [
    'db_dsn'  => getenv('JFBP_DB_DSN') ?: 'mysql:host=localhost;dbname=jfbp;charset=utf8mb4',
    'db_user' => getenv('JFBP_DB_USER') ?: 'jfbp',
    'db_pass' => getenv('JFBP_DB_PASS') ?: '',
    'sitenaam' => 'JFBP Fietsverhuur',
    'google_routes_key' => getenv('JFBP_GOOGLE_ROUTES_KEY') ?: '',
    'google_embed_key'  => getenv('JFBP_GOOGLE_EMBED_KEY') ?: '',
    'google_routes_url' => 'https://routes.googleapis.com/directions/v2:computeRoutes',
];
require __DIR__ . '/route.php';

// Huurduur in dagen => label. Pas hier aan als de opties wijzigen.
const HUURDUUR = [1 => '1 dag', 2 => '2 dagen', 3 => '3 dagen', 7 => '1 week', 14 => '2 weken'];
const MAX_DAGEN_VOORUIT = 90;

date_default_timezone_set('Europe/Amsterdam');

// ---------- beveiligingsheaders ----------
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-src https://www.google.com; frame-ancestors 'none'; base-uri 'none'");
header('X-Frame-Options: DENY');

// ---------- sessie ----------
$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('jfbp_sessie');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

// ---------- database ----------
function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo === null) {
        $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ---------- hulpfuncties ----------
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $pad): never
{
    header('Location: ' . $pad, true, 303);
    exit;
}

function flash(?string $bericht = null, string $soort = 'ok'): ?array
{
    if ($bericht !== null) {
        $_SESSION['flash'] = ['tekst' => $bericht, 'soort' => $soort];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_veld(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_controleren(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ongeldig formulier. Ga terug en probeer het opnieuw.');
    }
}

function gebruiker(): ?array
{
    static $g = false;
    if ($g === false) {
        $g = null;
        if (!empty($_SESSION['gebruiker_id'])) {
            $st = db()->prepare('SELECT id, naam, email FROM gebruikers WHERE id = ?');
            $st->execute([$_SESSION['gebruiker_id']]);
            $g = $st->fetch() ?: null;
        }
    }
    return $g;
}

function login_vereist(): array
{
    $g = gebruiker();
    if (!$g) {
        flash('Log in om een fiets te reserveren.', 'info');
        redirect('/inloggen.php');
    }
    return $g;
}

function euro(float|string $bedrag): string
{
    return '€ ' . number_format((float) $bedrag, 2, ',', '.');
}

function datum(string $ymd): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d ? $d->format('d-m-Y') : $ymd;
}

function fout(array $fouten, string $veld): string
{
    return isset($fouten[$veld]) ? '<span class="fout">' . e($fouten[$veld]) . '</span>' : '';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// ---------- opmaak ----------
function kop(string $titel): void
{
    global $config;
    $g = gebruiker();
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($titel) ?> · <?= e($config['sitenaam']) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<header>
  <div class="wrap">
    <a class="logo" href="/">JFBP</a>
    <nav>
      <?php if ($g): ?>
        <a href="/reserveren.php">Reserveren</a>
        <a href="/account.php">Mijn reserveringen</a>
        <form method="post" action="/uitloggen.php" class="inline"><?= csrf_veld() ?><button class="link">Uitloggen</button></form>
      <?php else: ?>
        <a href="/inloggen.php">Inloggen</a>
        <a href="/registreren.php" class="knop klein">Registreren</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap inhoud">
<?php
    if ($f = flash()) {
        echo '<p class="melding ' . e($f['soort']) . '" role="status">' . e($f['tekst']) . '</p>';
    }
}

function voet(): void
{
    ?>
</main>
<footer>
  <div class="wrap kolommen">
    <div>
      <h4>JFBP Fietsverhuur</h4>
      <p>[Korte omschrijving: fietsen huren in de regio, eenvoudig online reserveren.]</p>
    </div>
    <div>
      <h4>Snel naar</h4>
      <ul>
        <li><a href="/reserveren.php">Fiets reserveren</a></li>
        <li><a href="/account.php">Mijn reserveringen</a></li>
        <li><a href="/privacy.php">Privacyverklaring</a></li>
      </ul>
    </div>
    <div>
      <h4>Contact</h4>
      <p>[Adres]<br>[Postcode Plaats]<br>[Telefoonnummer]<br>[E-mailadres]</p>
    </div>
  </div>
  <div class="onderbalk"><div class="wrap">&copy; <?= date('Y') ?> JFBP</div></div>
</footer>
</body>
</html>
<?php
}
