<?php
// TEMPORARY: show errors in browser (remove after debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// -----------------------
// Load configuration
// -----------------------
require_once __DIR__ . '/../config.php';

// -----------------------
// Try to load Dompdf (multiple possible locations)
// -----------------------
$dompdfLoaded = false;
$dompdfCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/dompdf/autoload.inc.php',
    __DIR__ . '/../dompdf/autoload.inc.php',
    __DIR__ . '/../vendor/dompdf/autoload.inc.php',
];

foreach ($dompdfCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        // check for class
        if (class_exists('\\Dompdf\\Dompdf') || class_exists('Dompdf')) {
            $dompdfLoaded = true;
            break;
        }
    }
}

// -----------------------
// PHPMailer loading — Composer preferred, fallback to local phpmailer/src
// -----------------------
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
        die('PHPMailer not found. Please install via composer or upload phpmailer/src/ to public_html/phpmailer/src/');
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// helper sanitize
function s($v) {
    return htmlspecialchars(trim((string)$v));
}

// ensure uploads dir exists (fallback if config didn't set it)
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', __DIR__ . '/uploads');
}
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0750, true);
}

// Allowed mime types (from config)
global $ALLOWED_MIME;

// -- READ & SANITIZE POST FIELDS --
$full_name = s($_POST['full_name'] ?? '');
$id_number = s($_POST['id_number'] ?? '');
$dob = s($_POST['dob'] ?? '');
$gender = s($_POST['gender'] ?? '');
$marital_status = s($_POST['marital_status'] ?? '');
$nationality = s($_POST['nationality'] ?? '');
$home_address = s($_POST['home_address'] ?? '');
$postal_address = s($_POST['postal_address'] ?? '');
$province = s($_POST['province'] ?? '');
$city = s($_POST['city'] ?? '');
$phone = s($_POST['phone'] ?? '');
$alt_phone = s($_POST['alt_phone'] ?? '');
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
$occupation = s($_POST['occupation'] ?? '');
$employer_name = s($_POST['employer_name'] ?? '');
$employer_contact = s($_POST['employer_contact'] ?? '');
$income_range = s($_POST['income_range'] ?? '');
$payment_method = s($_POST['payment_method'] ?? '');

// Plan fields
$plan_choice = s($_POST['plan_choice'] ?? '');
$plan_option = s($_POST['plan_option'] ?? '');
$cover_amount = s($_POST['cover_amount'] ?? '');
$premium_month = s($_POST['premium_month'] ?? '');
$preferred_payment_date = s($_POST['preferred_payment_date'] ?? '');
$joining_ack = isset($_POST['joining_ack']) ? '1' : '0';

// Beneficiaries (arrays)
$beneficiaries = [];
$bnames = $_POST['beneficiary_name'] ?? [];
$brelations = $_POST['beneficiary_relation'] ?? [];
$bids = $_POST['beneficiary_id'] ?? [];
$bphones = $_POST['beneficiary_phone'] ?? [];
$baddresses = $_POST['beneficiary_address'] ?? [];

for ($i=0; $i < max(1, count($bnames)); $i++) {
    $n = s($bnames[$i] ?? '');
    if ($n === '') continue;
    $beneficiaries[] = [
        'name' => $n,
        'relation' => s($brelations[$i] ?? ''),
        'id' => s($bids[$i] ?? ''),
        'phone' => s($bphones[$i] ?? ''),
        'address' => s($baddresses[$i] ?? '')
    ];
}

// Dependants (arrays)
$dependants = [];
$dnames = $_POST['dep_name'] ?? [];
$drelations = $_POST['dep_relation'] ?? [];
$dids = $_POST['dep_id'] ?? [];
$ddobs = $_POST['dep_dob'] ?? [];
$dgenders = $_POST['dep_gender'] ?? [];

for ($i=0; $i < count($dnames); $i++) {
    $n = s($dnames[$i] ?? '');
    if ($n === '') continue;
    $dependants[] = [
        'name' => $n,
        'relation' => s($drelations[$i] ?? ''),
        'id' => s($dids[$i] ?? ''),
        'dob' => s($ddobs[$i] ?? ''),
        'gender' => s($dgenders[$i] ?? '')
    ];
}

// Next of kin
$nok_name = s($_POST['nok_name'] ?? '');
$nok_relation = s($_POST['nok_relation'] ?? '');
$nok_phone = s($_POST['nok_phone'] ?? '');
$nok_address = s($_POST['nok_address'] ?? '');

// Marketing & other
$signature = s($_POST['signature'] ?? '');
$application_date = s($_POST['application_date'] ?? date('Y-m-d'));
$heard_about = s($_POST['heard_about'] ?? '');
$marketing_ok = s($_POST['marketing_ok'] ?? '');
$notes = s($_POST['notes'] ?? '');

// Basic required validation
if (!$full_name || !$id_number || !$email || !$phone || !$plan_choice || !$signature) {
    die('Missing required fields. Please go back and complete all required fields.');
}

// Generate reference number
$ref = 'VFS' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

// --- Handle uploads ---
$uploaded_files = []; // each item: ['field','path','orig']

function handle_single_file($filekey, $ref, $ALLOWED_MIME) {
    if (empty($_FILES[$filekey]) || $_FILES[$filekey]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$filekey];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;
    if (defined('MAX_FILE_SIZE') && $f['size'] > MAX_FILE_SIZE) return null;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    if (!is_array($ALLOWED_MIME)) return null;
    if (!array_key_exists($mime, $ALLOWED_MIME)) return null;
    $ext = $ALLOWED_MIME[$mime];
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
    $newname = $ref . '_' . $filekey . '_' . $safe . '.' . $ext;
    $dest = rtrim(UPLOAD_DIR, '/') . '/' . $newname;
    if (move_uploaded_file($f['tmp_name'], $dest)) {
        return ['field' => $filekey, 'path' => $dest, 'orig' => $f['name']];
    }
    return null;
}

function handle_multiple_files($filekey, $ref, $ALLOWED_MIME) {
    $result = [];
    if (empty($_FILES[$filekey])) return $result;
    $files = $_FILES[$filekey];
    for ($i=0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if (defined('MAX_FILE_SIZE') && $files['size'][$i] > MAX_FILE_SIZE) continue;
        $tmp = $files['tmp_name'][$i];
        $orig = $files['name'][$i];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        if (!is_array($ALLOWED_MIME)) continue;
        if (!array_key_exists($mime, $ALLOWED_MIME)) continue;
        $ext = $ALLOWED_MIME[$mime];
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
        $newname = $ref . '_' . $filekey . '_' . $i . '_' . $safe . '.' . $ext;
        $dest = rtrim(UPLOAD_DIR, '/') . '/' . $newname;
        if (move_uploaded_file($tmp, $dest)) {
            $result[] = ['field' => $filekey, 'path' => $dest, 'orig' => $orig];
        }
    }
    return $result;
}

$f = handle_single_file('applicant_id', $ref, $ALLOWED_MIME);
if ($f) $uploaded_files[] = $f;

$bs = handle_multiple_files('beneficiaries_files', $ref, $ALLOWED_MIME);
if ($bs) $uploaded_files = array_merge($uploaded_files, $bs);

$f2 = handle_single_file('proof_residence', $ref, $ALLOWED_MIME);
if ($f2) $uploaded_files[] = $f2;

$f3 = handle_single_file('payslip', $ref, $ALLOWED_MIME);
if ($f3) $uploaded_files[] = $f3;

// attachments JSON
$attachments_json = json_encode($uploaded_files);

// beneficiaries / dependants JSON
$beneficiaries_json = json_encode($beneficiaries);
$dependants_json = json_encode($dependants);

// --- Insert into DB ---
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) {
    error_log("DB connect error: " . $mysqli->connect_error);
} else {
    $sql = "INSERT INTO applications
    (ref, full_name, id_number, dob, gender, marital_status, nationality, home_address, postal_address, province, city, phone, alt_phone, email, occupation, employer_name, employer_contact, income_range, payment_method, plan_choice, plan_option, cover_amount, premium_month, preferred_payment_date, joining_ack, beneficiaries, dependants, nok_name, nok_relation, nok_phone, nok_address, attachments, signature, application_date, heard_about, marketing_ok, notes, status, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";

    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $status = 'received';
        $types = str_repeat('s', 38);
        $stmt->bind_param($types,
            $ref, $full_name, $id_number, $dob, $gender, $marital_status, $nationality, $home_address, $postal_address, $province, $city, $phone, $alt_phone, $email, $occupation, $employer_name, $employer_contact, $income_range, $payment_method, $plan_choice, $plan_option, $cover_amount, $premium_month, $preferred_payment_date, $joining_ack, $beneficiaries_json, $dependants_json, $nok_name, $nok_relation, $nok_phone, $nok_address, $attachments_json, $signature, $application_date, $heard_about, $marketing_ok, $notes, $status
        );
        $stmt->execute();
        if ($stmt->error) {
            error_log("DB insert error: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("DB prepare error: " . $mysqli->error);
    }
    $mysqli->close();
}

// --- EMAIL: send to admin with attachments ---
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
    $mail->addAddress(ADMIN_EMAIL);
    $mail->addReplyTo($email, $full_name);
    $mail->Subject = "New Funeral Application - {$full_name} - Ref #{$ref}";

    // Build readable body
    $body = "New Funeral Application Received\n\n";
    $body .= "Reference Number: {$ref}\n\n";
    $body .= "Applicant Details:\n";
    $body .= "Full Name: {$full_name}\nID: {$id_number}\nDOB: {$dob}\nGender: {$gender}\nMarital Status: {$marital_status}\nNationality: {$nationality}\n";
    $body .= "Home Address: {$home_address}\nPostal Address: {$postal_address}\nProvince: {$province}\nCity: {$city}\nPhone: {$phone}\nAlt Phone: {$alt_phone}\nEmail: {$email}\n\n";

    $body .= "Employment & Income:\nOccupation: {$occupation}\nEmployer: {$employer_name}\nEmployer contact: {$employer_contact}\nIncome range: {$income_range}\nPayment method: {$payment_method}\n\n";

    $body .= "Plan:\nChoice: {$plan_choice}\nOption: {$plan_option}\nCover amount: {$cover_amount}\nPremium per month: {$premium_month}\nPreferred payment date: {$preferred_payment_date}\nJoining fee acknowledged: " . ($joining_ack ? 'Yes' : 'No') . "\n\n";

    $body .= "Beneficiaries:\n";
    foreach ($beneficiaries as $b) {
        $body .= "- {$b['name']} | {$b['relation']} | ID: {$b['id']} | Phone: {$b['phone']} | Addr: {$b['address']}\n";
    }
    $body .= "\nDependants:\n";
    foreach ($dependants as $d) {
        $body .= "- {$d['name']} | {$d['relation']} | ID: {$d['id']} | DOB: {$d['dob']} | Gender: {$d['gender']}\n";
    }

    $body .= "\nNext of Kin:\nName: {$nok_name}\nRelation: {$nok_relation}\nPhone: {$nok_phone}\nAddress: {$nok_address}\n\n";
    $body .= "Signature: {$signature}\nApplication date: {$application_date}\nHeard about: {$heard_about}\nMarketing ok: {$marketing_ok}\nNotes: {$notes}\n\n";
    $body .= "Attachments:\n";
    foreach ($uploaded_files as $u) {
        $body .= "- {$u['orig']} (stored: {$u['path']})\n";
    }

    $mail->Body = $body;
    $mail->AltBody = $body;

    // Attach files
    foreach ($uploaded_files as $u) {
        if (file_exists($u['path'])) {
            $mail->addAttachment($u['path'], $u['orig']);
        }
    }

    // --- Make a PDF of the form (only if Dompdf loaded) ---
    $pdfPath = '';
    if ($dompdfLoaded && class_exists('\\Dompdf\\Dompdf')) {
        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new \Dompdf\Dompdf($options);

            // Build the HTML for the PDF (expand as needed)
            $pdfHtml = "
            <html>
              <body style='font-family: DejaVu Sans, Arial, sans-serif; font-size:12px;'>
                <h1 style='color:#c21f24;'>Vusana Funeral Services Application</h1>
                <p><strong>Reference:</strong> {$ref}</p>
                <h3>Applicant</h3>
                <p><strong>Full Name:</strong> {$full_name}<br>
                <strong>ID Number:</strong> {$id_number}<br>
                <strong>Plan:</strong> {$plan_choice}<br>
                <strong>Phone:</strong> {$phone}<br>
                <strong>Email:</strong> {$email}<br>
                <strong>Date:</strong> {$application_date}</p>
                <hr>
                <p style='font-size:11px;color:#666;text-align:center;'>Generated by Vusana Funeral Services system</p>
              </body>
            </html>
            ";

            $dompdf->loadHtml($pdfHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdfOutput = $dompdf->output();
            $pdfPath = rtrim(UPLOAD_DIR, '/') . '/' . $ref . '_application.pdf';
            file_put_contents($pdfPath, $pdfOutput);

            if ($pdfPath && file_exists($pdfPath)) {
                $mail->addAttachment($pdfPath, $ref . '_application.pdf');
            }
        } catch (\Exception $e) {
            error_log("PDF generation failed: " . $e->getMessage());
        }
    } else {
        error_log("Dompdf not loaded; skipping PDF generation.");
    }

    $mail->send();
} catch (\Exception $e) {
    error_log("Admin email failed: " . ($mail->ErrorInfo ?? '') . " / Exception: " . $e->getMessage());
}

// --- EMAIL: confirmation to applicant ---
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
    $mail2->addAddress($email, $full_name);
    $mail2->Subject = "Thank You for Applying - Vusana Funeral Services (Ref #{$ref})";

    // HTML message for the client
    $htmlMessage = "
        <html>
        <body style='font-family: Arial, sans-serif; color:#333; background-color:#f8f8f8; padding:20px;'>
            <div style='max-width:600px; margin:auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.1);'>
                <h2 style='color:#c21f24;'>Thank You for Your Application</h2>
                <p>Dear <strong>{$full_name}</strong>,</p>
                <p>We’ve received your application for the <strong>{$plan_choice}</strong> funeral plan.</p>
                <p>Your reference number is <strong style='color:#c21f24;'>{$ref}</strong>.</p>
                <p>We’ll send you an email shortly with your <strong>policy documents</strong> and <strong>banking details</strong>.</p>
                <p>If you have any questions, our team is available <strong>24/7</strong> to assist you.</p>
                <p style='margin-top:20px;'>Kind regards,<br>
                <strong>Vusana Funeral Services</strong><br>
                <a href='https://vusanafuneralservices.co.za' style='color:#c21f24; text-decoration:none;'>www.vusanafuneralservices.co.za</a><br>
                applications@vusanafuneralservices.co.za<br>
                010 123 4567</p>
            </div>
        </body>
        </html>
    ";

    // Plain text fallback
    $plainMessage = "Dear {$full_name},\n\n"
        . "Thank you for applying for the {$plan_choice} funeral plan.\n"
        . "Your reference number is {$ref}.\n\n"
        . "We will send your policy documents and banking details shortly.\n"
        . "Our team is available 24/7 if you have any questions.\n\n"
        . "Kind regards,\nVusana Funeral Services\nwww.vusanafuneralservices.co.za\napplications@vusanafuneralservices.co.za\n";

    $mail2->isHTML(true);
    $mail2->Body = $htmlMessage;
    $mail2->AltBody = $plainMessage;

    // Attach PDF to applicant if it exists
    if (!empty($pdfPath) && file_exists($pdfPath)) {
        $mail2->addAttachment($pdfPath, $ref . '_application.pdf');
    }

    $mail2->send();
} catch (\Exception $e) {
    error_log('Applicant email failed: ' . ($e->getMessage()));
}

// store ref in session and redirect to thankyou
$_SESSION['vfs_ref'] = $ref;
header('Location: thankyou.php');
exit;
