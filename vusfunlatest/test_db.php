<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/config.php'; // adjust if your config path is different
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) {
    echo "DB ERROR: " . $mysqli->connect_error;
} else {
    echo "DB OK: Connected to " . DB_NAME;
    $mysqli->close();
}
