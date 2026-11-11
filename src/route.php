<?php
declare(strict_types=1);

// Fietsroute via Google.
//  - Duur en afstand: Routes API (computeRoutes, travelMode BICYCLE), aangeroepen vanaf de server.
//    Sleutel: 'google_routes_key' (alleen Routes API, bij voorkeur beperkt tot het IP-adres van de server).
//  - Kaart: Maps Embed API (directions, mode=bicycling) in een iframe.
//    Sleutel: 'google_embed_key' (alleen Maps Embed API, beperkt tot de websites jfbp.nl/www.jfbp.nl).
// Documentatie: https://developers.google.com/maps/documentation/routes
//               https://developers.google.com/maps/documentation/embed

/**
 * @return array{seconden:int, meters:int}|null  null als er geen route is of de dienst niet beschikbaar is.
 */
function bereken_fietsroute(string $van, string $naar): ?array
{
    global $config;
    $sleutel = $config['google_routes_key'];
    if ($sleutel === '' || $van === '' || $naar === '') {
        return null;
    }

    $ch = curl_init($config['google_routes_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $sleutel,
            'X-Goog-FieldMask: routes.duration,routes.distanceMeters',
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'origin'       => ['address' => $van],
            'destination'  => ['address' => $naar],
            'travelMode'   => 'BICYCLE',
            'languageCode' => 'nl',
            'regionCode'   => 'NL',
        ], JSON_THROW_ON_ERROR),
    ]);
    $antwoord = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fout = curl_error($ch);
    curl_close($ch);

    if ($antwoord === false || $status !== 200) {
        error_log("Routes API: HTTP $status $fout " . substr((string) $antwoord, 0, 300));
        return null;
    }
    $data = json_decode((string) $antwoord, true);
    $route = $data['routes'][0] ?? null;
    // duration komt als string in seconden, bijv. "3471s"
    if (!$route || !isset($route['duration']) || !preg_match('/^(\d+)(?:\.\d+)?s$/', (string) $route['duration'], $m)) {
        return null;
    }
    return ['seconden' => (int) $m[1], 'meters' => (int) ($route['distanceMeters'] ?? 0)];
}

function kaart_url(string $van, string $naar): ?string
{
    global $config;
    if ($config['google_embed_key'] === '' || $van === '' || $naar === '') {
        return null;
    }
    return 'https://www.google.com/maps/embed/v1/directions?' . http_build_query([
        'key'         => $config['google_embed_key'],
        'origin'      => $van,
        'destination' => $naar,
        'mode'        => 'bicycling',
        'language'    => 'nl',
        'region'      => 'NL',
    ]);
}

function google_maps_link(string $van, string $naar): string
{
    // Gewone link naar Google Maps (werkt ook zonder sleutel).
    return 'https://www.google.com/maps/dir/?' . http_build_query([
        'api' => 1, 'origin' => $van, 'destination' => $naar, 'travelmode' => 'bicycling',
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
