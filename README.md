# jfbp.nl – fietsverhuur

PHP-website waarop bezoekers een account aanmaken, inloggen en een fiets reserveren:
keuze uit 3 fietsen, een huurduur, een afhaal- en terugbrenglocatie en optioneel een fietstocht met route op de kaart.

- PHP 8.2+ met `pdo_mysql`, MariaDB/MySQL. Geen frameworks of Composer-packages.
- Webroot: `public/`. Code buiten de webroot: `src/`, `database/`, config.

## Opbouw

| Pad | Functie |
|---|---|
| `public/index.php` | Homepage met de fietsen |
| `public/registreren.php` | Account aanmaken |
| `public/inloggen.php` / `uitloggen.php` | Inloggen (met blokkade na 10 mislukte pogingen per IP in 15 min) |
| `public/reserveren.php` | Fiets, ophaaldatum, huurduur en afhaallocatie kiezen |
| `public/account.php` | Overzicht van eigen reserveringen |
| `public/reservering.php` | Details van één reservering, met fietsroute op de kaart en reistijd |
| `src/route.php` | Routekoppeling: Nominatim (plaatsnamen) en BRouter (fietsroute) |
| `public/assets/kaart.js` | Kaart met de route (Leaflet + OpenStreetMap) |
| `public/privacy.php` | Privacyverklaring (concept, nog invullen) |
| `src/bootstrap.php` | Config, database, sessie, CSRF, opmaak. Huurduur-opties: `HUURDUUR` |
| `database/schema.sql` | Tabellen + voorbeeldfietsen en -locaties |

Fietsen en afhaallocaties staan in de database (tabellen `fietsen` en `locaties`).
Een fiets kan niet dubbel verhuurd worden in overlappende periodes.

## Installatie (server)

1. Maak een lege MariaDB-database en -gebruiker aan, en voer `database/schema.sql` uit.
2. Zet de databasegegevens in `config.local.php` (zie `config.example.php`) buiten de uitrolmap,
   en verwijs ernaar met de omgevingsvariabele `JFBP_CONFIG`; of gebruik `JFBP_DB_DSN`,
   `JFBP_DB_USER` en `JFBP_DB_PASS`.
3. Webroot naar `public/` (Raziel-type *php*).
4. Route en kaart gebruiken standaard de openbare servers van OpenStreetMap (Nominatim, kaarttegels)
   en brouter.de. Die zijn bedoeld voor licht gebruik; lees de gebruiksvoorwaarden en zet bij meer
   verkeer eigen servers neer (zie *Fietstocht*). Stel in productie een herkenbare User-Agent met
   contactadres in via `JFBP_ROUTE_USER_AGENT` (vereist door Nominatim).

## Fietstocht

Bij het reserveren kan de klant een vertrek- en aankomstplaats invullen. Na het reserveren toont
`reservering.php` de fietsroute op een kaart, met de geschatte fietstijd en afstand.
Alles is open source, er wordt geen Google gebruikt:

| Onderdeel | Dienst | Standaard | Instelling |
|---|---|---|---|
| Plaatsnaam → coördinaten | Nominatim | nominatim.openstreetmap.org | `JFBP_GEOCODER_URL` |
| Route, afstand, tijd | BRouter (profiel *trekking*) | brouter.de | `JFBP_ROUTING_URL` |
| Kaart | Leaflet 1.9.4 (lokaal in `public/assets/leaflet`) + OSM-tegels | tile.openstreetmap.org | `tegel_url` in config |

- Coördinaten worden 90 dagen bewaard in `geocache`; route, tijd en lijn één keer per reservering.
- Nominatim staat maximaal 1 verzoek per seconde toe; de code wacht daar zelf op.
- Gebruiksvoorwaarden: https://operations.osmfoundation.org/policies/nominatim/ en
  https://operations.osmfoundation.org/policies/tiles/
- Voor productie met meer verkeer: draai BRouter zelf (Java, met de segmentbestanden voor NL/BE)
  en eventueel Nominatim, of kies een Europese aanbieder van kaarttegels.
