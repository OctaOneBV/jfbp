# jfbp.nl – fietsverhuur

PHP-website waarop klanten een account aanmaken, inloggen en een fiets huren:
naam van de huurder, afhaalplaats en -datum, terugbrengplaats en -datum, maat M, L of XL,
en optioneel een fietstocht met route op de kaart. Het slot op de fiets gaat open door met de
telefoon de barcode (QR) op de fiets te scannen, maar alleen als die fiets op dat moment voor die klant gereserveerd is.

- PHP 8.2+ met `pdo_mysql` en `curl`, MariaDB 10.5+/MySQL 8. Geen frameworks of Composer-packages.
- Webroot: `public/`. Code buiten de webroot: `src/`, `database/`, config.

## Opbouw

| Pad | Functie |
|---|---|
| `public/index.php` | Homepage met de maten en beschikbaarheid vandaag |
| `public/registreren.php` | Account aanmaken |
| `public/inloggen.php` / `uitloggen.php` | Inloggen (blokkade na 10 mislukte pogingen per IP in 15 min) |
| `public/reserveren.php` | Huurder, afhalen, terugbrengen, maat, fietstocht; tabel met beschikbaarheid komende 14 dagen |
| `public/account.php` | Overzicht van eigen reserveringen |
| `public/reservering.php` | Details van één reservering: toegewezen fietsnummer, route op de kaart en fietstijd |
| `public/scan.php` | Pagina achter de barcode: controleert de reservering en opent het slot |
| `public/beheer.php` | Beheerders: status van alle 30 fietsen, onderhoud aan/uit, laatste 50 scans |
| `public/labels.php` | Beheerders: printbare QR-labels voor alle fietsen |
| `src/fietsen.php` | Beschikbaarheid, toewijzen van een vrije fiets, scanlog |
| `src/slot.php` | Koppeling met het fietsslot (nu: simulatie of algemene webhook) |
| `src/route.php` | Nominatim (plaatsnamen) en BRouter (fietsroute); fietstijd bij 15 km/u |
| `src/bootstrap.php` | Config, database, sessie, CSRF, rollen, opmaak. Constanten `MAX_HUURDAGEN`, `GEMIDDELDE_SNELHEID_KMH` |
| `database/schema.sql` | Tabellen, maten, 30 fietsen met willekeurige barcodes, 4 locaties |

### Fietsen en beschikbaarheid

- 30 fietsen: M-01 t/m M-10, L-01 t/m L-10, XL-01 t/m XL-10 (tabel `fietsen`). Elke fiets heeft
  een willekeurige code van 12 tekens; `schema.sql` maakt die bij installatie aan.
- De klant kiest een maat; de site wijst bij het reserveren een vrije fiets van die maat toe
  (bij voorkeur een fiets die het laatst op de afhaalplaats is teruggebracht).
- Een reservering bezet de fiets van de afhaaldatum **t/m** de terugbrengdatum. Overlappende
  reserveringen voor dezelfde fiets zijn niet mogelijk (vergrendeld in een transactie).
- Let op: de site houdt niet bij **waar** een fiets fysiek staat. Een fiets die in Amsterdam is
  teruggebracht kan aan iemand in Maastricht worden toegewezen; het herverdelen van fietsen is werk voor de beheerder.

### Barcode en slot

- De QR-code op de fiets bevat `<basis_url>/scan.php?c=<code>`. Scannen met de gewone camera van de
  telefoon opent die pagina; niet ingelogd → eerst inloggen, daarna terug naar de fiets.
- Het slot gaat alleen open (POST met CSRF-token) als de ingelogde klant een reservering heeft voor
  **deze** fiets die **vandaag** loopt. Elke scan komt in de tabel `scans`, ook geweigerde.
- **Het fysieke slot is nog niet gekoppeld.** `src/slot.php` staat standaard op `simulatie`.
  Zodra bekend is welk (smart)slot er op de fietsen komt, wordt de functie `slot_openen()`
  aangepast op de API van die leverancier. De meegeleverde `webhook`-stand is een algemeen voorbeeld.
- Labels printen: log in als beheerder → Beheer → Barcodelabels printen. Zet eerst `basis_url` goed.
- Beheerder maken (eenmalig, in de database):
  `UPDATE gebruikers SET rol = 'beheerder' WHERE email = '…';`

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
`reservering.php` de fietsroute op een kaart, met de afstand en de fietstijd bij een gemiddelde
snelheid van 15 km/u (constante `GEMIDDELDE_SNELHEID_KMH`).
Alles is open source, er wordt geen Google gebruikt:

| Onderdeel | Dienst | Standaard | Instelling |
|---|---|---|---|
| Plaatsnaam → coördinaten | Nominatim | nominatim.openstreetmap.org | `JFBP_GEOCODER_URL` |
| Route en afstand | BRouter (profiel *trekking*) | brouter.de | `JFBP_ROUTING_URL` |
| Kaart | Leaflet 1.9.4 (lokaal in `public/assets/leaflet`) + OSM-tegels | tile.openstreetmap.org | `tegel_url` in config |

- Coördinaten worden 90 dagen bewaard in `geocache`; afstand en routelijn één keer per reservering.
- Nominatim staat maximaal 1 verzoek per seconde toe; de code wacht daar zelf op.
- Gebruiksvoorwaarden: https://operations.osmfoundation.org/policies/nominatim/ en
  https://operations.osmfoundation.org/policies/tiles/
- Voor productie met meer verkeer: draai BRouter zelf (Java, met de segmentbestanden voor NL/BE)
  en eventueel Nominatim, of kies een Europese aanbieder van kaarttegels.
