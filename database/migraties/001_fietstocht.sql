-- Alleen nodig voor een database die al bestond vóór de fietstocht-functie.
ALTER TABLE reserveringen
  ADD COLUMN vertrek        VARCHAR(150) NULL AFTER aantal_dagen,
  ADD COLUMN aankomst       VARCHAR(150) NULL AFTER vertrek,
  ADD COLUMN route_seconden INT UNSIGNED NULL AFTER aankomst,
  ADD COLUMN route_meters   INT UNSIGNED NULL AFTER route_seconden;
