<?php
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}
csrf_controleren();

$_SESSION = [];
session_regenerate_id(true);
flash('Je bent uitgelogd.', 'info');
redirect('/');
