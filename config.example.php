<?php
// Kopieer naar config.local.php (buiten de webroot, staat in .gitignore) of zet het pad
// in de omgevingsvariabele JFBP_CONFIG. Zet dit bestand NOOIT in git of in een chat.
return [
    'db_dsn'  => 'mysql:host=localhost;dbname=jfbp;charset=utf8mb4',
    'db_user' => 'jfbp',
    'db_pass' => 'VUL-HIER-HET-WACHTWOORD-IN',
    // Google Maps (optioneel). Zie README voor het beperken van de sleutels.
    'google_routes_key' => '',
    'google_embed_key'  => '',
];
