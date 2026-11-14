<?php
declare(strict_types=1);

// Fietsen, locaties en beschikbaarheid.
//
// Een reservering bezet een fiets van afhaal_datum t/m terugbreng_datum (beide inclusief).
// Waar een fiets staat, volgt uit gebeurtenissen:
//   - beginsituatie: fietsen.thuis_locatie_id;
//   - na een verhuur staat de fiets vanaf de dag ná de terugbrengdatum op de terugbrenglocatie;
//   - een verplaatsing door de beheerder zet de fiets vanaf 'datum' op 'naar_locatie_id'.
// Een fiets kan alleen worden verhuurd als hij op de afhaaldag op de afhaallocatie staat, en als de
// eerstvolgende reservering daarna op de terugbrenglocatie begint (of er eerst een verplaatsing is).

function maten(): array
{
    static $maten = null;
    return $maten ??= db()->query('SELECT maat, omschrijving, prijs_per_dag FROM maten ORDER BY volgorde')->fetchAll();
}

function locaties(): array
{
    static $locaties = null;
    return $locaties ??= db()->query('SELECT id, naam, adres FROM locaties WHERE actief = 1 ORDER BY naam')->fetchAll();
}

/** Locatie van alle fietsen op een dag: [fiets_id => locatie_id]. */
function locaties_op_dag(string $dag): array
{
    $plek = array_map('intval', array_column(db()->query('SELECT id, thuis_locatie_id FROM fietsen')->fetchAll(), 'thuis_locatie_id', 'id'));
    $st = db()->prepare(
        'SELECT fiets_id, loc FROM (
            SELECT fiets_id, DATE_ADD(terugbreng_datum, INTERVAL 1 DAY) AS vanaf, terugbreng_locatie_id AS loc, 0 AS volg, id FROM reserveringen
            UNION ALL
            SELECT fiets_id, datum, naar_locatie_id, 1, id FROM verplaatsingen
         ) e WHERE vanaf <= ? ORDER BY vanaf, volg, id'
    );
    $st->execute([$dag]);
    foreach ($st as $e) {
        $plek[(int) $e['fiets_id']] = (int) $e['loc'];
    }
    return $plek;
}

/**
 * Alle fietsen met hun situatie op een dag. Per fiets: id, nummer, maat, actief, code,
 * locatie_id (waar hij staat of vandaan kwam) en verhuur (de lopende reservering of null).
 */
function fietsen_op_dag(string $dag): array
{
    $plek = locaties_op_dag($dag);
    $st = db()->prepare(
        'SELECT r.id, r.fiets_id, r.huurder_naam, r.afhaal_datum, r.terugbreng_datum, r.afhaal_locatie_id, r.terugbreng_locatie_id
           FROM reserveringen r WHERE ? BETWEEN r.afhaal_datum AND r.terugbreng_datum'
    );
    $st->execute([$dag]);
    $verhuur = [];
    foreach ($st as $r) {
        $verhuur[(int) $r['fiets_id']] = $r;
    }
    $uit = [];
    foreach (db()->query('SELECT f.id, f.nummer, f.maat, f.actief, f.code FROM fietsen f JOIN maten m ON m.maat = f.maat ORDER BY m.volgorde, f.nummer') as $f) {
        $id = (int) $f['id'];
        $f['locatie_id'] = $verhuur[$id]['afhaal_locatie_id'] ?? $plek[$id];
        $f['verhuur'] = $verhuur[$id] ?? null;
        $uit[$id] = $f;
    }
    return $uit;
}

/** Aantal vrije (actieve, niet verhuurde) fietsen per locatie en maat op een dag: [locatie_id => [maat => n]]. */
function vrij_per_locatie(string $dag): array
{
    $uit = [];
    foreach (locaties() as $l) {
        foreach (maten() as $m) {
            $uit[(int) $l['id']][$m['maat']] = 0;
        }
    }
    foreach (fietsen_op_dag($dag) as $f) {
        if ($f['actief'] && !$f['verhuur'] && isset($uit[(int) $f['locatie_id']])) {
            $uit[(int) $f['locatie_id']][$f['maat']]++;
        }
    }
    return $uit;
}

function fiets_locatie_op(int $fietsId, string $dag): int
{
    return locaties_op_dag($dag)[$fietsId];
}

/**
 * Kan deze fiets van $van t/m $tot verhuurd worden met afhalen op $afhaal en terugbrengen op $terug?
 * Controleert overlap, de plek op de afhaaldag, geplande verplaatsingen en de aansluiting op de volgende reservering.
 */
function fiets_past(int $fietsId, string $van, string $tot, int $afhaal, int $terug): bool
{
    $pdo = db();
    $st = $pdo->prepare('SELECT 1 FROM reserveringen WHERE fiets_id = ? AND afhaal_datum <= ? AND terugbreng_datum >= ? LIMIT 1');
    $st->execute([$fietsId, $tot, $van]);
    if ($st->fetchColumn()) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM verplaatsingen WHERE fiets_id = ? AND datum BETWEEN ? AND ? LIMIT 1');
    $st->execute([$fietsId, $van, $tot]);
    if ($st->fetchColumn()) {
        return false;
    }
    if (fiets_locatie_op($fietsId, $van) !== $afhaal) {
        return false;
    }
    // Volgende reservering na deze periode moet beginnen waar deze eindigt, tenzij er tussendoor een verplaatsing is.
    $st = $pdo->prepare('SELECT afhaal_datum, afhaal_locatie_id FROM reserveringen WHERE fiets_id = ? AND afhaal_datum > ? ORDER BY afhaal_datum LIMIT 1');
    $st->execute([$fietsId, $tot]);
    $volgende = $st->fetch();
    if ($volgende && (int) $volgende['afhaal_locatie_id'] !== $terug) {
        $st = $pdo->prepare('SELECT 1 FROM verplaatsingen WHERE fiets_id = ? AND datum > ? AND datum <= ? LIMIT 1');
        $st->execute([$fietsId, $tot, $volgende['afhaal_datum']]);
        if (!$st->fetchColumn()) {
            return false;
        }
    }
    return true;
}

/** Fietsen van een maat die passen bij de gevraagde verhuur. Met $vergrendel binnen een transactie aanroepen. */
function geschikte_fietsen(string $maat, string $van, string $tot, int $afhaal, int $terug, bool $vergrendel = false): array
{
    $pdo = db();
    if ($vergrendel) {
        // Alle fietsen van deze maat vergrendelen, zodat gelijktijdige reserveringen op elkaar wachten.
        $pdo->prepare('SELECT id FROM fietsen WHERE maat = ? FOR UPDATE')->execute([$maat]);
    }
    $st = $pdo->prepare('SELECT id, nummer, maat FROM fietsen WHERE maat = ? AND actief = 1 ORDER BY id');
    $st->execute([$maat]);
    return array_values(array_filter($st->fetchAll(), fn ($f) => fiets_past((int) $f['id'], $van, $tot, $afhaal, $terug)));
}

function kies_vrije_fiets(string $maat, string $van, string $tot, int $afhaal, int $terug): ?array
{
    return geschikte_fietsen($maat, $van, $tot, $afhaal, $terug, true)[0] ?? null;
}

/**
 * Controleert een verplaatsing door de beheerder. Geeft een foutmelding terug, of null als het kan.
 */
function verplaatsing_probleem(int $fietsId, string $datum, int $naar): ?string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT huurder_naam FROM reserveringen WHERE fiets_id = ? AND ? BETWEEN afhaal_datum AND terugbreng_datum');
    $st->execute([$fietsId, $datum]);
    if ($naam = $st->fetchColumn()) {
        return 'De fiets is op die dag verhuurd (aan ' . $naam . ').';
    }
    if (fiets_locatie_op($fietsId, $datum) === $naar) {
        return 'De fiets staat op die dag al op die locatie.';
    }
    $st = $pdo->prepare(
        'SELECT r.afhaal_datum, r.afhaal_locatie_id, l.naam FROM reserveringen r JOIN locaties l ON l.id = r.afhaal_locatie_id
          WHERE r.fiets_id = ? AND r.afhaal_datum > ? ORDER BY r.afhaal_datum LIMIT 1'
    );
    $st->execute([$fietsId, $datum]);
    $volgende = $st->fetch();
    if ($volgende && (int) $volgende['afhaal_locatie_id'] !== $naar) {
        $st = $pdo->prepare('SELECT 1 FROM verplaatsingen WHERE fiets_id = ? AND datum > ? AND datum <= ? LIMIT 1');
        $st->execute([$fietsId, $datum, $volgende['afhaal_datum']]);
        if (!$st->fetchColumn()) {
            return 'De fiets wordt op ' . datum($volgende['afhaal_datum']) . ' in ' . $volgende['naam'] . ' opgehaald voor een reservering.';
        }
    }
    return null;
}

function aantal_dagen(string $van, string $tot): int
{
    return (int) (new DateTimeImmutable($van))->diff(new DateTimeImmutable($tot))->days + 1;
}

function log_scan(?int $fietsId, ?int $gebruikerId, ?int $reserveringId, string $resultaat): void
{
    db()->prepare('INSERT INTO scans (fiets_id, gebruiker_id, reservering_id, resultaat, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([$fietsId, $gebruikerId, $reserveringId, $resultaat, client_ip()]);
}

function scan_url(string $code): string
{
    global $config;
    return rtrim($config['basis_url'], '/') . '/scan.php?c=' . rawurlencode($code);
}

/** Maandag van de week waarin $datum valt. */
function maandag_van(DateTimeImmutable $d): DateTimeImmutable
{
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days');
}

function geldige_datum(string $s): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s ? $d : null;
}

const DAGNAMEN = [1 => 'ma', 2 => 'di', 3 => 'wo', 4 => 'do', 5 => 'vr', 6 => 'za', 7 => 'zo'];
const MAANDEN = [1 => 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
