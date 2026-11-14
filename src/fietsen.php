<?php
declare(strict_types=1);

// Beschikbaarheid van fietsen. Een reservering bezet een fiets van afhaal_datum t/m terugbreng_datum (beide inclusief).
// Twee periodes overlappen als: bestaande.afhaal_datum <= nieuwe.terugbreng AND bestaande.terugbreng_datum >= nieuwe.afhaal.

function maten(): array
{
    return db()->query('SELECT maat, omschrijving, prijs_per_dag FROM maten ORDER BY volgorde')->fetchAll();
}

/** Aantal vrije fietsen per maat voor een periode: ['M' => 7, 'L' => 10, 'XL' => 3]. */
function vrij_per_maat(string $van, string $tot): array
{
    $st = db()->prepare(
        'SELECT m.maat, COUNT(f.id) AS vrij
           FROM maten m
           LEFT JOIN fietsen f ON f.maat = m.maat AND f.actief = 1
                AND NOT EXISTS (SELECT 1 FROM reserveringen r
                                 WHERE r.fiets_id = f.id AND r.afhaal_datum <= ? AND r.terugbreng_datum >= ?)
          GROUP BY m.maat, m.volgorde
          ORDER BY m.volgorde'
    );
    $st->execute([$tot, $van]);
    return array_map('intval', array_column($st->fetchAll(), 'vrij', 'maat'));
}

/**
 * Beschikbaarheid per dag en maat voor een reeks dagen: ['2026-11-14' => ['M' => 10, ...], ...].
 */
function beschikbaarheid_per_dag(DateTimeImmutable $van, int $dagen): array
{
    $tot = $van->modify('+' . ($dagen - 1) . ' days');
    $totaal = array_map('intval', array_column(
        db()->query('SELECT maat, COUNT(*) AS n FROM fietsen WHERE actief = 1 GROUP BY maat')->fetchAll(), 'n', 'maat'));
    $st = db()->prepare(
        'SELECT f.maat, r.afhaal_datum, r.terugbreng_datum
           FROM reserveringen r JOIN fietsen f ON f.id = r.fiets_id
          WHERE f.actief = 1 AND r.afhaal_datum <= ? AND r.terugbreng_datum >= ?'
    );
    $st->execute([$tot->format('Y-m-d'), $van->format('Y-m-d')]);
    $bezet = $st->fetchAll();

    $maten = maten();
    $uit = [];
    for ($i = 0; $i < $dagen; $i++) {
        $dag = $van->modify("+$i days")->format('Y-m-d');
        $rij = [];
        foreach ($maten as $m) {
            $rij[$m['maat']] = $totaal[$m['maat']] ?? 0;
        }
        foreach ($bezet as $b) {
            if ($b['afhaal_datum'] <= $dag && $b['terugbreng_datum'] >= $dag) {
                $rij[$b['maat']]--;
            }
        }
        $uit[$dag] = $rij;
    }
    return $uit;
}

/**
 * Kiest een vrije fiets van de gevraagde maat en vergrendelt de fietsen van die maat tot het einde
 * van de lopende transactie, zodat twee gelijktijdige reserveringen niet dezelfde fiets krijgen.
 * Voorkeur: een fiets die bij zijn vorige verhuur is teruggebracht op de afhaallocatie.
 */
function kies_vrije_fiets(string $maat, string $van, string $tot, int $afhaalLocatie): ?array
{
    $pdo = db();
    $pdo->prepare('SELECT id FROM fietsen WHERE maat = ? FOR UPDATE')->execute([$maat]);
    $st = $pdo->prepare(
        'SELECT f.id, f.nummer, f.maat
           FROM fietsen f
          WHERE f.maat = ? AND f.actief = 1
            AND NOT EXISTS (SELECT 1 FROM reserveringen r
                             WHERE r.fiets_id = f.id AND r.afhaal_datum <= ? AND r.terugbreng_datum >= ?)
          ORDER BY COALESCE((SELECT r2.terugbreng_locatie_id FROM reserveringen r2
                              WHERE r2.fiets_id = f.id AND r2.terugbreng_datum < ?
                              ORDER BY r2.terugbreng_datum DESC LIMIT 1) = ?, 0) DESC,
                   f.id
          LIMIT 1'
    );
    $st->execute([$maat, $tot, $van, $van, $afhaalLocatie]);
    return $st->fetch() ?: null;
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
