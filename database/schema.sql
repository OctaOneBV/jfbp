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
  locatie_id    INT UNSIGNED NOT NULL,
  startdatum    DATE         NOT NULL,
  einddatum     DATE         NOT NULL,  -- exclusief: dag waarop de fiets terug moet zijn
  aantal_dagen  SMALLINT UNSIGNED NOT NULL,
  aangemaakt_op DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_gebruiker FOREIGN KEY (gebruiker_id) REFERENCES gebruikers(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_fiets     FOREIGN KEY (fiets_id)     REFERENCES fietsen(id),
  CONSTRAINT fk_res_locatie   FOREIGN KEY (locatie_id)   REFERENCES locaties(id),
  INDEX idx_fiets_periode (fiets_id, startdatum, einddatum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inlogpogingen (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip     VARCHAR(45) NOT NULL,
  tijd   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_tijd (ip, tijd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voorbeeldgegevens: pas namen, prijzen en adressen aan.
INSERT INTO fietsen (naam, omschrijving, prijs_per_dag) VALUES
  ('Stadsfiets', 'Comfortabele fiets met 3 versnellingen, ideaal voor korte ritten.', 12.50),
  ('E-bike',     'Elektrische fiets met een actieradius van ca. 80 km.',              27.50),
  ('Bakfiets',   'Elektrische bakfiets voor boodschappen of kinderen.',               35.00);

INSERT INTO locaties (naam, adres) VALUES
  ('[Locatie 1]', '[Straat 1, 1234 AB Plaats]'),
  ('[Locatie 2]', '[Straat 2, 1234 AB Plaats]'),
  ('[Locatie 3]', '[Straat 3, 1234 AB Plaats]');
