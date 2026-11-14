-- Database voor de fietsverhuur van jfbp.nl (MariaDB 10.5+ / MySQL 8, utf8mb4).
-- Eenmalig uitvoeren op een lege database.

CREATE TABLE gebruikers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  naam          VARCHAR(100)  NOT NULL,
  email         VARCHAR(190)  NOT NULL UNIQUE,
  telefoon      VARCHAR(30)   NULL,
  wachtwoord    VARCHAR(255)  NOT NULL,
  rol           ENUM('klant','beheerder') NOT NULL DEFAULT 'klant',
  aangemaakt_op DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fietsmaten met prijs en omschrijving.
CREATE TABLE maten (
  maat          VARCHAR(3)   NOT NULL PRIMARY KEY,
  omschrijving  VARCHAR(255) NOT NULL,
  prijs_per_dag DECIMAL(6,2) NOT NULL,
  volgorde      TINYINT      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Elke fiets heeft een uniek nummer (op het frame) en een unieke, niet te raden code in de barcode (QR).
CREATE TABLE fietsen (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nummer  VARCHAR(10)  NOT NULL UNIQUE,     -- bijv. M-01, L-07, XL-10
  maat    VARCHAR(3)   NOT NULL,
  code    CHAR(12)     NOT NULL UNIQUE,     -- inhoud van de barcode
  actief  TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = in onderhoud, niet verhuurbaar
  CONSTRAINT fk_fiets_maat FOREIGN KEY (maat) REFERENCES maten(maat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locaties (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  naam   VARCHAR(100) NOT NULL,
  adres  VARCHAR(255) NOT NULL,
  actief TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reserveringen (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gebruiker_id     INT UNSIGNED NOT NULL,
  huurder_naam     VARCHAR(100) NOT NULL,
  fiets_id         INT UNSIGNED NOT NULL,
  afhaal_locatie_id     INT UNSIGNED NOT NULL,
  afhaal_datum          DATE         NOT NULL,
  terugbreng_locatie_id INT UNSIGNED NOT NULL,
  terugbreng_datum      DATE         NOT NULL,  -- inclusief: de fiets is t/m deze dag verhuurd
  aantal_dagen     SMALLINT UNSIGNED NOT NULL,
  prijs_per_dag    DECIMAL(6,2) NOT NULL,       -- prijs vastgelegd op het moment van reserveren
  opgehaald_op     DATETIME     NULL,           -- eerste keer dat het slot met de barcode is geopend
  vertrek          VARCHAR(150) NULL,           -- fietstocht: vertrekplaats (optioneel)
  aankomst         VARCHAR(150) NULL,           -- fietstocht: aankomstplaats (optioneel)
  route_meters     INT UNSIGNED NULL,           -- afstand (BRouter)
  route_lijn       MEDIUMTEXT   NULL,           -- routelijn als JSON [[lat,lon],...] voor de kaart
  aangemaakt_op    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_gebruiker  FOREIGN KEY (gebruiker_id) REFERENCES gebruikers(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_fiets      FOREIGN KEY (fiets_id)     REFERENCES fietsen(id),
  CONSTRAINT fk_res_afhaal     FOREIGN KEY (afhaal_locatie_id)     REFERENCES locaties(id),
  CONSTRAINT fk_res_terugbreng FOREIGN KEY (terugbreng_locatie_id) REFERENCES locaties(id),
  CONSTRAINT chk_res_periode   CHECK (terugbreng_datum >= afhaal_datum),
  INDEX idx_fiets_periode (fiets_id, afhaal_datum, terugbreng_datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Elke scan van een barcode wordt vastgelegd, ook als het slot niet opengaat.
CREATE TABLE scans (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiets_id       INT UNSIGNED NULL,
  gebruiker_id   INT UNSIGNED NULL,
  reservering_id INT UNSIGNED NULL,
  resultaat      ENUM('bekeken','geopend','geen_reservering','onbekende_fiets','slot_fout') NOT NULL,
  ip             VARCHAR(45)  NOT NULL,
  tijd           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_scan_fiets       FOREIGN KEY (fiets_id)       REFERENCES fietsen(id),
  CONSTRAINT fk_scan_gebruiker   FOREIGN KEY (gebruiker_id)   REFERENCES gebruikers(id) ON DELETE SET NULL,
  CONSTRAINT fk_scan_reservering FOREIGN KEY (reservering_id) REFERENCES reserveringen(id) ON DELETE SET NULL,
  INDEX idx_scan_tijd (tijd),
  INDEX idx_scan_fiets (fiets_id, tijd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache voor plaatsnamen -> coördinaten (Nominatim vraagt om resultaten te bewaren).
CREATE TABLE geocache (
  zoekterm     VARCHAR(150) NOT NULL PRIMARY KEY,
  lat          DECIMAL(9,6) NULL,   -- NULL = niet gevonden
  lon          DECIMAL(9,6) NULL,
  naam         VARCHAR(255) NULL,
  opgehaald_op DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inlogpogingen (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip     VARCHAR(45) NOT NULL,
  tijd   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_tijd (ip, tijd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maten: omschrijving en prijzen zijn VOORBEELDEN, pas ze aan.
INSERT INTO maten (maat, omschrijving, prijs_per_dag, volgorde) VALUES
  ('M',  'Medium frame. Indicatie: lichaamslengte ca. 1,60 – 1,75 m.', 15.00, 1),
  ('L',  'Large frame. Indicatie: lichaamslengte ca. 1,75 – 1,85 m.',  15.00, 2),
  ('XL', 'Extra large frame. Indicatie: vanaf ca. 1,85 m.',            15.00, 3);

-- 30 fietsen: 10 x M, 10 x L, 10 x XL. De code is willekeurig per installatie,
-- zodat niemand de barcode van een andere fiets kan raden.
INSERT INTO fietsen (nummer, maat, code)
WITH RECURSIVE n AS (SELECT 1 AS i UNION ALL SELECT i + 1 FROM n WHERE i < 10)
SELECT CONCAT(m.maat, '-', LPAD(n.i, 2, '0')), m.maat, UPPER(LEFT(SHA2(CONCAT(UUID(), RAND(), m.maat, n.i), 256), 12))
  FROM maten m CROSS JOIN n
 ORDER BY m.volgorde, n.i;

-- Afhaal- en terugbrenglocaties. Adressen nog invullen.
INSERT INTO locaties (naam, adres) VALUES
  ('Amsterdam',  '[Adres volgt], Amsterdam'),
  ('Utrecht',    '[Adres volgt], Utrecht'),
  ('Groningen',  '[Adres volgt], Groningen'),
  ('Maastricht', '[Adres volgt], Maastricht');
