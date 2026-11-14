<?php
// Maakt een bestaand account beheerder (of weer klant). Alleen vanaf de commandoregel.
//   php bin/beheerder.php naam@voorbeeld.nl          -> beheerder
//   php bin/beheerder.php naam@voorbeeld.nl --klant  -> terug naar klant
// Het account moet eerst via de website zijn geregistreerd.
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/bootstrap.php';
$email = mb_strtolower(trim($argv[1] ?? ''));
if ($email === '') { fwrite(STDERR, "Gebruik: php bin/beheerder.php <e-mailadres> [--klant]\n"); exit(1); }
$rol = in_array('--klant', $argv, true) ? 'klant' : 'beheerder';
$st = db()->prepare('UPDATE gebruikers SET rol = ? WHERE email = ?');
$st->execute([$rol, $email]);
$bestaat = db()->prepare('SELECT COUNT(*) FROM gebruikers WHERE email = ?');
$bestaat->execute([$email]);
if (!$bestaat->fetchColumn()) { fwrite(STDERR, "Geen account met dit e-mailadres. Registreer eerst via de website.\n"); exit(1); }
echo "$email is nu $rol.\n";
