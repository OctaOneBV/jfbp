<?php
declare(strict_types=1);

// ---------- configuratie ----------
// Configbestand buiten de webroot. Eerste die bestaat wint: $JFBP_CONFIG, naast de code,
// of in de klantmap op de server (buiten de uitrolmap, dus niet overschreven bij een nieuwe versie).
$configFile = null;
foreach ([getenv('JFBP_CONFIG') ?: null, dirname(__DIR__) . '/config.local.php', '/srv/klanten/jfbp/config.local.php'] as $pad) {
    if ($pad && @is_file($pad)) { $configFile = $pad; break; }
}
$config = $configFile ? require $configFile : [];
$config += [
    'db_dsn'  => getenv('JFBP_DB_DSN') ?: 'mysql:host=localhost;dbname=jfbp;charset=utf8mb4',
    'db_user' => getenv('JFBP_DB_USER') ?: 'jfbp',
    'db_pass' => getenv('JFBP_DB_PASS') ?: '',
    'sitenaam' => 'JFBP Fietsverhuur',
    // Open-source route- en kaartdiensten (zie src/route.php). Voor productie bij voorkeur eigen servers.
    'geocoder_url'     => getenv('JFBP_GEOCODER_URL') ?: 'https://nominatim.openstreetmap.org/search',
    'routing_url'      => getenv('JFBP_ROUTING_URL') ?: 'https://brouter.de/brouter',
    'route_user_agent' => getenv('JFBP_ROUTE_USER_AGENT') ?: 'fietsverhuur-website/1.0',
    'tegel_url'        => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    // Adres van de site zoals het in de barcodes op de fietsen komt te staan.
    'basis_url'        => getenv('JFBP_BASIS_URL') ?: 'https://jfbp.nl',
    // Fietsslot (zie src/slot.php): 'simulatie' (alleen loggen) of 'webhook'.
    'slot_modus'         => getenv('JFBP_SLOT_MODUS') ?: 'simulatie',
    'slot_webhook_url'   => getenv('JFBP_SLOT_WEBHOOK_URL') ?: '',
    'slot_webhook_token' => getenv('JFBP_SLOT_WEBHOOK_TOKEN') ?: '',
];
require __DIR__ . '/route.php';
require __DIR__ . '/fietsen.php';
require __DIR__ . '/slot.php';
require __DIR__ . '/beheer.php';

const MAX_DAGEN_VOORUIT = 90;   // zo ver vooruit kan er gereserveerd worden
const MAX_HUURDAGEN = 30;       // langste huurperiode
const GEMIDDELDE_SNELHEID_KMH = 15; // voor de geschatte fietstijd van de route

date_default_timezone_set('Europe/Amsterdam');

// ---------- beveiligingsheaders ----------
$tegelHost = parse_url($config['tegel_url'], PHP_URL_SCHEME) . '://' . parse_url($config['tegel_url'], PHP_URL_HOST);
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: $tegelHost; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
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
            $st = db()->prepare('SELECT id, naam, email, rol FROM gebruikers WHERE id = ?');
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
        // Na het inloggen terug naar deze pagina (bijv. na het scannen van een fiets).
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $_SESSION['terug_naar'] = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        }
        flash('Log in om verder te gaan.', 'info');
        redirect('/inloggen.php');
    }
    return $g;
}

function beheerder_vereist(): array
{
    $g = gebruiker();
    if (!$g) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $_SESSION['terug_naar'] = (string) ($_SERVER['REQUEST_URI'] ?? '/beheer/');
        }
        redirect('/beheer/inloggen.php');
    }
    if ($g['rol'] !== 'beheerder') {
        http_response_code(403);
        exit('Geen toegang: dit deel is alleen voor beheerders.');
    }
    return $g;
}

/** Interne pagina waar de gebruiker na het inloggen heen moet. */
function na_inloggen(string $standaard = '/reserveren.php'): string
{
    $pad = (string) ($_SESSION['terug_naar'] ?? '');
    unset($_SESSION['terug_naar']);
    return preg_match('#^/(?![/\\\\])#', $pad) ? $pad : $standaard;
}

const MAX_POGINGEN = 10;     // mislukte inlogpogingen per IP-adres...
const BLOKKEER_MINUTEN = 15; // ...binnen dit aantal minuten

/**
 * Controleert e-mail en wachtwoord met blokkade na te veel mislukte pogingen.
 * @return array{0: ?array, 1: string} [gebruiker of null, foutmelding]
 */
function controleer_inlog(string $email, string $wachtwoord, ?string $vereisteRol = null): array
{
    $ip = client_ip();
    $st = db()->prepare('SELECT COUNT(*) FROM inlogpogingen WHERE ip = ? AND tijd > NOW() - INTERVAL ? MINUTE');
    $st->execute([$ip, BLOKKEER_MINUTEN]);
    if ((int) $st->fetchColumn() >= MAX_POGINGEN) {
        return [null, 'Te veel mislukte pogingen. Probeer het over ' . BLOKKEER_MINUTEN . ' minuten opnieuw.'];
    }
    $st = db()->prepare('SELECT id, wachtwoord, rol FROM gebruikers WHERE email = ?');
    $st->execute([$email]);
    $g = $st->fetch();
    // Ook zonder gebruiker een hash controleren, zodat de reactietijd niets verraadt.
    $hash = $g['wachtwoord'] ?? '$2y$12$Tn6Lo2.we.GVu9Sr1OgMI.ACbyGuBaKRU36JBl7JKZVWwprA5ZlXq';
    if (password_verify($wachtwoord, $hash) && $g && ($vereisteRol === null || $g['rol'] === $vereisteRol)) {
        if (password_needs_rehash($g['wachtwoord'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE gebruikers SET wachtwoord = ? WHERE id = ?')
                ->execute([password_hash($wachtwoord, PASSWORD_DEFAULT), $g['id']]);
        }
        db()->prepare('DELETE FROM inlogpogingen WHERE ip = ?')->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['gebruiker_id'] = (int) $g['id'];
        return [$g, ''];
    }
    db()->prepare('INSERT INTO inlogpogingen (ip) VALUES (?)')->execute([$ip]);
    db()->exec('DELETE FROM inlogpogingen WHERE tijd < NOW() - INTERVAL 1 DAY');
    return [null, $vereisteRol ? 'Onjuist e-mailadres of wachtwoord, of geen beheerdersaccount.' : 'Onjuist e-mailadres of wachtwoord.'];
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
function kop(string $titel, string $extra = ''): void
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
<?php if ($extra === 'kaart'): ?>
<link rel="stylesheet" href="/assets/leaflet/leaflet.css">
<script src="/assets/leaflet/leaflet.js" defer></script>
<script src="/assets/kaart.js" defer></script>
<?php elseif ($extra === 'labels'): ?>
<script src="/assets/qrcode/qrcode.min.js" defer></script>
<script src="/assets/labels.js" defer></script>
<?php endif; ?>
</head>
<body>
<header>
  <div class="wrap">
    <a class="logo" href="/">JFBP</a>
    <nav>
      <?php if ($g): ?>
        <a href="/reserveren.php">Reserveren</a>
        <a href="/account.php">Mijn reserveringen</a>
        <a href="/scan.php">Fiets openen</a>
        <?php if ($g['rol'] === 'beheerder'): ?><a href="/beheer/">Beheer</a><?php endif; ?>
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
