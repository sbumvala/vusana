<?php
// send_quick_request.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 1) include config (tries several locations)
$configPaths = [
    __DIR__ . '/config.php',
    __DIR__ . '/config/config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../config/config.php'
];
$configFound = false;
foreach ($configPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $configFound = true;
        break;
    }
}
if (!$configFound) {
    die('Configuration file not found.');
}

// 2) load PHPMailer (composer preferred, fallback to local)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    $pmBase = __DIR__ . '/phpmailer/src';
    if (file_exists($pmBase . '/PHPMailer.php')) {
        require_once $pmBase . '/Exception.php';
        require_once $pmBase . '/PHPMailer.php';
        require_once $pmBase . '/SMTP.php';
    } else {
        die('PHPMailer not found. Install via composer or upload phpmailer/src/');
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// helper sanitize
function s($v) { return htmlspecialchars(trim($v)); }

// collect POST
$name = s($_POST['name'] ?? '');
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
$phone = s($_POST['phone'] ?? '');
$service = s($_POST['service'] ?? '');
$contact_method = s($_POST['contact_method'] ?? '');
$contact_time = s($_POST['contact_time'] ?? '');
$urgent = isset($_POST['urgent']) ? 'Yes' : 'No';
$message = s($_POST['message'] ?? '');

// basic validation
if (!$name || !$email || !$phone) {
    die('Please provide name, email and phone.');
}

// generate reference
$ref = 'QRF' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid('', true)),0,6));

// handle optional attachment
$attachment_info = null;
if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['attachment']['tmp_name'];
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
    } else {
        $mime = mime_content_type($tmp);
    }

    $allowed = $ALLOWED_MIME ?? ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    if (array_key_exists($mime, $allowed)) {
        $ext = $allowed[$mime];
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($_FILES['attachment']['name'], PATHINFO_FILENAME));
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0750, true);
        $filename = $ref . '_' . $safe . '.' . $ext;
        $dest = rtrim(UPLOAD_DIR, '/') . '/' . $filename;
        if (move_uploaded_file($tmp, $dest)) {
            $attachment_info = ['path'=>$dest, 'name'=>$_FILES['attachment']['name']];
        }
    }
}

// Build admin email to info@vusanafuneralservices.co.za
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = SMTP_PORT;

    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress('info@vusanafuneralservices.co.za');
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = "Quick Request: {$service} - {$name} - Ref: {$ref}";

    $html = "
      <h3>Quick Request Received</h3>
      <p><strong>Reference:</strong> {$ref}</p>
      <p><strong>Name:</strong> {$name}<br>
      <strong>Email:</strong> {$email}<br>
      <strong>Phone:</strong> {$phone}</p>
      <p><strong>Service / Inquiry:</strong> {$service}<br>
      <strong>Preferred contact method:</strong> {$contact_method}<br>
      <strong>Preferred time:</strong> {$contact_time}<br>
      <strong>Urgent:</strong> {$urgent}</p>
      <p><strong>Message:</strong><br>" . nl2br($message) . "</p>
      <p>Received on: " . date('Y-m-d H:i:s') . "</p>
    ";
    $mail->Body = $html;
    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>','<br />'],"\n", $html));

    if ($attachment_info && file_exists($attachment_info['path'])) {
        $mail->addAttachment($attachment_info['path'], $attachment_info['name']);
    }

    $mail->send();
} catch (Exception $e) {
    error_log('Quick request admin email failed: ' . $mail->ErrorInfo);
}

// Send confirmation to client
try {
    $mail2 = new PHPMailer(true);
    $mail2->isSMTP();
    $mail2->Host = SMTP_HOST;
    $mail2->SMTPAuth = true;
    $mail2->Username = SMTP_USER;
    $mail2->Password = SMTP_PASS;
    $mail2->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail2->Port = SMTP_PORT;

    $mail2->setFrom(FROM_EMAIL, FROM_NAME);
    $mail2->addAddress($email, $name);
    $mail2->Subject = "We received your quick request - Ref {$ref}";

    $clientHtml = "
      <p>Dear <strong>{$name}</strong>,</p>
      <p>Thank you — we have received your quick request (Reference <strong>{$ref}</strong>).</p>
      <p>Our coordinator will call you using your preferred contact method ({$contact_method}) and time ({$contact_time}).</p>
      <p>If this is urgent, please call us directly: <strong>010 123 4567</strong>.</p>
      <p>Kind regards,<br>Vusana Funeral Services</p>
    ";

    $mail2->isHTML(true);
    $mail2->Body = $clientHtml;
    $mail2->AltBody = strip_tags($clientHtml);

    $mail2->send();
} catch (Exception $e) {
    error_log('Quick request client email failed: ' . $mail2->ErrorInfo);
}

// store ref & redirect to thank-you
$_SESSION['qrf_ref'] = $ref;
header('Location: thankyou_quick.php');
exit;
