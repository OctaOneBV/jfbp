<?php
// Kopieer naar config.local.php (buiten de webroot, staat in .gitignore) of zet het pad
// in de omgevingsvariabele JFBP_CONFIG. Zet dit bestand NOOIT in git of in een chat.
return [
    'db_dsn'  => 'mysql:host=localhost;dbname=jfbp;charset=utf8mb4',
    'db_user' => 'jfbp',
    'db_pass' => 'VUL-HIER-HET-WACHTWOORD-IN',
    // Route en kaart (optioneel aanpassen, zie README > Fietstocht):
    // 'geocoder_url'     => 'https://nominatim.openstreetmap.org/search',
    // 'routing_url'      => 'https://brouter.de/brouter',
    // 'route_user_agent' => 'fietsverhuur-website/1.0 (contact: [e-mailadres])',
    // 'tegel_url'        => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    // Adres dat in de barcodes op de fietsen komt (pas aan vóór het printen van de labels):
    // 'basis_url'        => 'https://jfbp.nl',
    // Fietsslot, zie src/slot.php. 'simulatie' opent niets echt; 'webhook' roept de slotdienst aan:
    // 'slot_modus'         => 'simulatie',
    // 'slot_webhook_url'   => 'https://…',
    // 'slot_webhook_token' => 'ZET-HIER-DE-SLEUTEL-VAN-DE-SLOTLEVERANCIER',
];
