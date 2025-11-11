<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
// load config (adjust path if needed)
$configPaths = [__DIR__ . '/config.php', __DIR__ . '/config/config.php', __DIR__ . '/../config.php', __DIR__ . '/../config/config.php'];
foreach ($configPaths as $p) { if (file_exists($p)) { require_once $p; break; } }
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/phpmailer/src/Exception.php';
    require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/src/SMTP.php';
}
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "PHPMailer is available. OK.";
} else {
    echo "PHPMailer NOT found. Check paths.";
}
