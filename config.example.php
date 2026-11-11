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
];
