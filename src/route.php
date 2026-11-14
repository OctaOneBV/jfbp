<?php
declare(strict_types=1);

// Fietsroute met open-source diensten (geen Google):
//  - Plaatsnaam -> coördinaten: Nominatim (OpenStreetMap). Resultaten worden in de tabel geocache bewaard.
//    Gebruiksvoorwaarden: https://operations.osmfoundation.org/policies/nominatim/ (o.a. max. 1 verzoek/s).
//  - Route en afstand: BRouter (fietsrouteplanner op OpenStreetMap-data), profiel 'trekking'.
//    De fietstijd rekenen we zelf uit met een gemiddelde snelheid (GEMIDDELDE_SNELHEID_KMH, 15 km/u).
//    https://brouter.de  — voor productie bij voorkeur een eigen BRouter-server (zie README).
//  - Kaart: Leaflet met OpenStreetMap-tegels (in de browser, zie public/assets/kaart.js).
// Alle adressen staan in de config en zijn dus naar eigen servers om te zetten.

function http_get_json(string $url): ?array
{
    global $config;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => $config['route_user_agent'],
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: nl'],
    ]);
    $antwoord = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fout = curl_error($ch);
    curl_close($ch);
    if ($antwoord === false || $status !== 200) {
        error_log('Routedienst: HTTP ' . $status . ' ' . $fout . ' ' . preg_replace('/\?.*/', '', $url));
        return null;
    }
    $data = json_decode((string) $antwoord, true);
    return is_array($data) ? $data : null;
}

/** @return array{lat:float, lon:float}|null */
function zoek_plaats(string $plaats): ?array
{
    global $config;
    $sleutel = mb_strtolower(trim($plaats));
    if ($sleutel === '') {
        return null;
    }
    $st = db()->prepare('SELECT lat, lon FROM geocache WHERE zoekterm = ? AND opgehaald_op > NOW() - INTERVAL 90 DAY');
    $st->execute([$sleutel]);
    if ($rij = $st->fetch()) {
        return $rij['lat'] === null ? null : ['lat' => (float) $rij['lat'], 'lon' => (float) $rij['lon']];
    }

    // Nominatim staat maximaal 1 verzoek per seconde toe.
    static $laatste = 0.0;
    $wacht = 1.05 - (microtime(true) - $laatste);
    if ($wacht > 0) {
        usleep((int) ($wacht * 1_000_000));
    }
    $laatste = microtime(true);

    $data = http_get_json($config['geocoder_url'] . '?' . http_build_query([
        'q' => $plaats, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'nl,be',
    ]));
    if ($data === null) {
        return null; // dienst onbereikbaar: niet cachen, later opnieuw proberen
    }
    $hit = $data[0] ?? null;
    $lat = isset($hit['lat']) ? (float) $hit['lat'] : null;
    $lon = isset($hit['lon']) ? (float) $hit['lon'] : null;
    db()->prepare('REPLACE INTO geocache (zoekterm, lat, lon, naam) VALUES (?, ?, ?, ?)')
        ->execute([$sleutel, $lat, $lon, isset($hit['display_name']) ? mb_substr($hit['display_name'], 0, 255) : null]);
    return $lat === null ? null : ['lat' => $lat, 'lon' => $lon];
}

/**
 * @return array{meters:int, lijn:array<int, array{0:float,1:float}>}|null
 */
function bereken_fietsroute(string $van, string $naar): ?array
{
    global $config;
    $a = zoek_plaats($van);
    $b = zoek_plaats($naar);
    if (!$a || !$b) {
        return null;
    }
    $data = http_get_json($config['routing_url'] . '?' . http_build_query([
        'lonlats'        => sprintf('%.6F,%.6F|%.6F,%.6F', $a['lon'], $a['lat'], $b['lon'], $b['lat']),
        'profile'        => 'trekking',
        'alternativeidx' => 0,
        'format'         => 'geojson',
    ]));
    $f = $data['features'][0] ?? null;
    if (!$f || !isset($f['properties']['track-length'], $f['geometry']['coordinates'])) {
        return null;
    }
    // GeoJSON is [lon, lat, hoogte]; voor Leaflet [lat, lon]. Maximaal ~1500 punten om de pagina licht te houden.
    $punten = $f['geometry']['coordinates'];
    $stap = max(1, (int) ceil(count($punten) / 1500));
    $lijn = [];
    foreach ($punten as $i => $p) {
        if ($i % $stap === 0 || $i === count($punten) - 1) {
            $lijn[] = [round((float) $p[1], 5), round((float) $p[0], 5)];
        }
    }
    return [
        'meters'   => (int) $f['properties']['track-length'],
        'lijn'     => $lijn,
    ];
}

function route_opslaan(int $reserveringId, array $route): void
{
    db()->prepare('UPDATE reserveringen SET route_meters = ?, route_lijn = ? WHERE id = ?')
        ->execute([$route['meters'], json_encode($route['lijn']), $reserveringId]);
}

/** Fietstijd in seconden bij de gemiddelde snelheid uit de instellingen (15 km/u). */
function fietstijd_seconden(int $meters): int
{
    return (int) round($meters / (GEMIDDELDE_SNELHEID_KMH * 1000 / 3600));
}

function osm_route_link(string $van, string $naar): string
{
    // Route openen op openstreetmap.org (fietsrouting), werkt zonder account.
    return 'https://www.openstreetmap.org/directions?' . http_build_query([
        'engine' => 'graphhopper_bicycle', 'from' => $van, 'to' => $naar,
    ]);
}

function duur_tekst(int $seconden): string
{
    $min = (int) round($seconden / 60);
    if ($min < 60) {
        return $min . ' min';
    }
    $uur = intdiv($min, 60);
    $rest = $min % 60;
    return $uur . ' uur' . ($rest ? ' ' . $rest . ' min' : '');
}

function afstand_tekst(int $meters): string
{
    return $meters < 1000 ? $meters . ' m' : number_format($meters / 1000, 1, ',', '.') . ' km';
}
