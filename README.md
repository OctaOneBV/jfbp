# jfbp.nl – fietsverhuur

PHP-website waarop bezoekers een account aanmaken, inloggen en een fiets reserveren:
keuze uit 3 fietsen, een huurduur en een afhaallocatie.

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

## Lokaal testen

```bash
php -S 127.0.0.1:8080 -t public
```
