-- Database voor de fietsverhuur van jfbp.nl (MariaDB / MySQL, utf8mb4).
-- Eenmalig uitvoeren op een lege database.

CREATE TABLE gebruikers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  naam          VARCHAR(100)  NOT NULL,
  email         VARCHAR(190)  NOT NULL UNIQUE,
  telefoon      VARCHAR(30)   NULL,
  wachtwoord    VARCHAR(255)  NOT NULL,
  aangemaakt_op DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fietsen (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  naam         VARCHAR(100) NOT NULL,
  omschrijving VARCHAR(255) NOT NULL,
  prijs_per_dag DECIMAL(6,2) NOT NULL,
  actief       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locaties (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  naam   VARCHAR(100) NOT NULL,
  adres  VARCHAR(255) NOT NULL,
  actief TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reserveringen (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gebruiker_id  INT UNSIGNED NOT NULL,
  fiets_id      INT UNSIGNED NOT NULL,
  afhaal_locatie_id     INT UNSIGNED NOT NULL,
  terugbreng_locatie_id INT UNSIGNED NOT NULL,
  startdatum    DATE         NOT NULL,
  einddatum     DATE         NOT NULL,  -- exclusief: dag waarop de fiets terug moet zijn
  aantal_dagen  SMALLINT UNSIGNED NOT NULL,
  vertrek       VARCHAR(150) NULL,      -- fietstocht: vertrekplaats (optioneel)
  aankomst      VARCHAR(150) NULL,      -- fietstocht: aankomstplaats (optioneel)
  route_seconden INT UNSIGNED NULL,     -- geschatte fietsduur (BRouter)
  route_meters  INT UNSIGNED NULL,      -- afstand (BRouter)
  route_lijn    MEDIUMTEXT   NULL,      -- routelijn als JSON [[lat,lon],...] voor de kaart
  aangemaakt_op DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_gebruiker FOREIGN KEY (gebruiker_id) REFERENCES gebruikers(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_fiets     FOREIGN KEY (fiets_id)     REFERENCES fietsen(id),
  CONSTRAINT fk_res_afhaal     FOREIGN KEY (afhaal_locatie_id)     REFERENCES locaties(id),
  CONSTRAINT fk_res_terugbreng FOREIGN KEY (terugbreng_locatie_id) REFERENCES locaties(id),
  INDEX idx_fiets_periode (fiets_id, startdatum, einddatum)
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

-- Fietsen: voorbeeldgegevens, pas namen en prijzen aan.
INSERT INTO fietsen (naam, omschrijving, prijs_per_dag) VALUES
  ('Stadsfiets', 'Comfortabele fiets met 3 versnellingen, ideaal voor korte ritten.', 12.50),
  ('E-bike',     'Elektrische fiets met een actieradius van ca. 80 km.',              27.50),
  ('Bakfiets',   'Elektrische bakfiets voor boodschappen of kinderen.',               35.00);

-- Afhaal- en terugbrenglocaties. Adressen nog invullen.
INSERT INTO locaties (naam, adres) VALUES
  ('Utrecht',    '[Adres volgt], Utrecht'),
  ('Amsterdam',  '[Adres volgt], Amsterdam'),
  ('Maastricht', '[Adres volgt], Maastricht'),
  ('Antwerpen',  '[Adres volgt], Antwerpen (België)'),
  ('Groningen',  '[Adres volgt], Groningen');
