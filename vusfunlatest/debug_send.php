<?php
// debug_send.php — temporary. Remove after debugging.
ini_set('display_errors',1);
error_reporting(E_ALL);

echo "<h2>Vusana debug</h2>";

// locate config
$configPaths = [
    __DIR__ . '/config.php',
    __DIR__ . '/config/config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../config/config.php'
];
$found = false;
foreach ($configPaths as $p) {
    if (file_exists($p)) {
        echo "<p style='color:green'>Found config at: <code>$p</code></p>";
        require_once $p;
        $found = true;
        break;
    }
}
if (!$found) {
    echo "<p style='color:red'>Config NOT found. Looked at:</p><pre>" . htmlspecialchars(implode("\n",$configPaths)) . "</pre>";
    exit;
}

// PHPMailer / autoload check
$autoloads = [__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/phpmailer/src/PHPMailer.php'];
$autoloadFound = false;
foreach ($autoloads as $a) {
    if (file_exists($a)) {
        echo "<p style='color:green'>Found autoload or PHPMailer at: <code>$a</code></p>";
        $autoloadFound = true;
        break;
    }
}
if (!$autoloadFound) {
    echo "<p style='color:red'>PHPMailer or composer autoload not found. Expected vendor/autoload.php or phpmailer/src/</p>";
}

// DB test
if (defined('DB_HOST') && defined('DB_USER') && defined('DB_NAME')) {
    echo "<p>Testing DB connection to <strong>" . DB_NAME . "</strong> as <strong>" . DB_USER . "</strong>...</p>";
    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        echo "<p style='color:red'>DB connect failed: " . htmlspecialchars($mysqli->connect_error) . "</p>";
    } else {
        echo "<p style='color:green'>DB connected OK.</p>";
        $mysqli->close();
    }
} else {
    echo "<p style='color:orange'>DB constants not defined in config.php.</p>";
}

// uploads folder
$ups = [__DIR__ . '/uploads', __DIR__ . '/../storage/uploads', __DIR__ . '/storage/uploads'];
foreach ($ups as $u) {
    if (file_exists($u)) {
        echo "<p style='color:green'>Uploads folder exists: <code>$u</code> | Writable: " . (is_writable($u) ? "yes" : "<span style='color:red'>no</span>") . "</p>";
    }
}

// Show POST and FILES
echo "<h3>POST (first 200 chars)</h3>";
if (!empty($_POST)) echo "<pre>" . htmlspecialchars(print_r($_POST, true)) . "</pre>";
else echo "<p style='color:orange'>No POST data received.</p>";

echo "<h3>FILES</h3>";
if (!empty($_FILES)) echo "<pre>" . htmlspecialchars(print_r($_FILES, true)) . "</pre>";
else echo "<p style='color:orange'>No FILES data.</p>";

echo "<h3>End debug</h3>";
