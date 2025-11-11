<?php
// TEMPORARY: show errors in browser (remove after debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ✅ Load configuration
require_once __DIR__ . '/../config.php';

// PHPMailer loading — Composer preferred, fallback to local phpmailer/src
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
use PHPMailer\PHPMailer\Exception;

// helper sanitize
function s($v) {
    return htmlspecialchars(trim($v));
}

// ensure uploads dir exists
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
    // skip empties
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

// Basic required validation (adjust as you like)
if (!$full_name || !$id_number || !$email || !$phone || !$plan_choice || !$signature) {
    die('Missing required fields. Please go back and complete all required fields.');
}

// Generate reference number
$ref = 'VFS' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

// --- Handle uploads ---
// We'll process known inputs: applicant_id (single), beneficiaries_files[] (multiple), proof_residence, payslip
$uploaded_files = []; // each item: ['field','path','orig']

function handle_single_file($filekey, $ref, $ALLOWED_MIME) {
    if (empty($_FILES[$filekey]) || $_FILES[$filekey]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$filekey];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;
    if ($f['size'] > MAX_FILE_SIZE) return null;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    global $ALLOWED_MIME;
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
        if ($files['size'][$i] > MAX_FILE_SIZE) continue;
        $tmp = $files['tmp_name'][$i];
        $orig = $files['name'][$i];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
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

// process files
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
    // proceed with email but log DB failure
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

// LOGO: update this URL if your logo is elsewhere or provide a local logo at ../assets/logo.png
$logo_url = 'https://vusanafuneralservices.co.za/assets/icon.png';
$local_logo_path = __DIR__ . '/../assets/icon.png'; // recommended local path, optional

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

    // Decide how to reference the logo in the email: embed local if exists, otherwise use remote URL
    $logo_img_src = $logo_url;
    $embeddedLogoCid = null;
    if (file_exists($local_logo_path) && is_readable($local_logo_path)) {
        // embed local logo so it displays in clients that allow inline images
        $embeddedLogoCid = 'vfslogo_' . md5($ref);
        $mail->addEmbeddedImage($local_logo_path, $embeddedLogoCid, 'logo.png');
        $logo_img_src = 'cid:' . $embeddedLogoCid;
    } else {
        // use remote URL (ensure the URL is HTTPS and accessible)
        $logo_img_src = $logo_url;
    }

    // Build HTML email body (printer-friendly)
    $htmlBody = '
    <html>
    <head>
      <meta charset="utf-8" />
      <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 20px; color: #333; }
        .container { background: #fff; padding: 20px 25px; margin: auto; border-radius: 8px; max-width: 900px; box-shadow: 0 0 8px rgba(0,0,0,0.06); }
        .header { display:flex; align-items: center; gap: 15px; }
        .logo { max-height: 60px; }
        h1 { font-size: 18px; margin: 0; color: #c21f24; }
        p.lead { margin: 6px 0 12px 0; color:#555; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #e9ecef; text-align:left; vertical-align: top; font-size: 14px; }
        th { background: #f4f4f4; width: 220px; color: #333; font-weight: 600; }
        .section { margin-top: 18px; }
        .small { font-size: 12px; color: #777; }
        .attachments ul { margin: 6px 0 0 18px; padding: 0; }
        @media print {
          body { background: #fff; padding: 0; }
          .container { box-shadow: none; border: none; }
          .logo { max-height: 50px; }
        }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="header">
          <img src="' . $logo_img_src . '" alt="Vusana Funeral Services" class="logo" />
          <div>
            <h1>New Funeral Application Received</h1>
            <p class="lead">Reference: <strong>' . $ref . '</strong></p>
          </div>
        </div>

        <div class="section">
          <h3 style="margin-bottom:6px;">Applicant Details</h3>
          <table>
            <tr><th>Full Name</th><td>' . $full_name . '</td></tr>
            <tr><th>ID Number</th><td>' . $id_number . '</td></tr>
            <tr><th>Date of Birth</th><td>' . $dob . '</td></tr>
            <tr><th>Gender</th><td>' . $gender . '</td></tr>
            <tr><th>Marital Status</th><td>' . $marital_status . '</td></tr>
            <tr><th>Nationality</th><td>' . $nationality . '</td></tr>
            <tr><th>Home Address</th><td>' . $home_address . '</td></tr>
            <tr><th>Postal Address</th><td>' . $postal_address . '</td></tr>
            <tr><th>Province</th><td>' . $province . '</td></tr>
            <tr><th>City</th><td>' . $city . '</td></tr>
            <tr><th>Phone</th><td>' . $phone . '</td></tr>
            <tr><th>Alt Phone</th><td>' . $alt_phone . '</td></tr>
            <tr><th>Email</th><td>' . $email . '</td></tr>
          </table>
        </div>

        <div class="section">
          <h3 style="margin-bottom:6px;">Employment & Income</h3>
          <table>
            <tr><th>Occupation</th><td>' . $occupation . '</td></tr>
            <tr><th>Employer</th><td>' . $employer_name . '</td></tr>
            <tr><th>Employer Contact</th><td>' . $employer_contact . '</td></tr>
            <tr><th>Income Range</th><td>' . $income_range . '</td></tr>
            <tr><th>Payment Method</th><td>' . $payment_method . '</td></tr>
          </table>
        </div>

        <div class="section">
          <h3 style="margin-bottom:6px;">Plan Details</h3>
          <table>
            <tr><th>Plan Choice</th><td>' . $plan_choice . '</td></tr>
            <tr><th>Option</th><td>' . $plan_option . '</td></tr>
            <tr><th>Cover Amount</th><td>' . $cover_amount . '</td></tr>
            <tr><th>Premium / Month</th><td>' . $premium_month . '</td></tr>
            <tr><th>Preferred Payment Date</th><td>' . $preferred_payment_date . '</td></tr>
            <tr><th>Joining Fee Acknowledged</th><td>' . ($joining_ack ? 'Yes' : 'No') . '</td></tr>
          </table>
        </div>
    ';

    // Beneficiaries section
    if (!empty($beneficiaries)) {
        $htmlBody .= '<div class="section"><h3 style="margin-bottom:6px;">Beneficiaries</h3>';
        foreach ($beneficiaries as $b) {
            $htmlBody .= '
            <table>
              <tr><th>Name</th><td>' . $b['name'] . '</td></tr>
              <tr><th>Relation</th><td>' . $b['relation'] . '</td></tr>
              <tr><th>ID</th><td>' . $b['id'] . '</td></tr>
              <tr><th>Phone</th><td>' . $b['phone'] . '</td></tr>
              <tr><th>Address</th><td>' . $b['address'] . '</td></tr>
            </table><br />';
        }
        $htmlBody .= '</div>';
    }

    // Dependants section
    if (!empty($dependants)) {
        $htmlBody .= '<div class="section"><h3 style="margin-bottom:6px;">Dependants</h3>';
        foreach ($dependants as $d) {
            $htmlBody .= '
            <table>
              <tr><th>Name</th><td>' . $d['name'] . '</td></tr>
              <tr><th>Relation</th><td>' . $d['relation'] . '</td></tr>
              <tr><th>ID</th><td>' . $d['id'] . '</td></tr>
              <tr><th>DOB</th><td>' . $d['dob'] . '</td></tr>
              <tr><th>Gender</th><td>' . $d['gender'] . '</td></tr>
            </table><br />';
        }
        $htmlBody .= '</div>';
    }

    $htmlBody .= '
        <div class="section">
          <h3 style="margin-bottom:6px;">Next of Kin</h3>
          <table>
            <tr><th>Name</th><td>' . $nok_name . '</td></tr>
            <tr><th>Relation</th><td>' . $nok_relation . '</td></tr>
            <tr><th>Phone</th><td>' . $nok_phone . '</td></tr>
            <tr><th>Address</th><td>' . $nok_address . '</td></tr>
          </table>
        </div>

        <div class="section">
          <h3 style="margin-bottom:6px;">Other Details</h3>
          <table>
            <tr><th>Signature</th><td>' . $signature . '</td></tr>
            <tr><th>Application Date</th><td>' . $application_date . '</td></tr>
            <tr><th>Heard About</th><td>' . $heard_about . '</td></tr>
            <tr><th>Marketing OK</th><td>' . $marketing_ok . '</td></tr>
            <tr><th>Notes</th><td>' . nl2br($notes) . '</td></tr>
          </table>
        </div>

        <div class="section attachments">
          <h3 style="margin-bottom:6px;">Attachments</h3>
          <ul>';
    foreach ($uploaded_files as $u) {
        $htmlBody .= '<li>' . htmlspecialchars($u['orig']) . '</li>';
    }
    $htmlBody .= '
          </ul>
        </div>

        <p class="small">This email was automatically generated by Vusana Funeral Services system.</p>
      </div>
    </body>
    </html>
    ';

    // Plain text fallback
    $plainBody = "New Funeral Application Received\n\n";
    $plainBody .= "Reference Number: {$ref}\n\n";
    $plainBody .= "Applicant Details:\n";
    $plainBody .= "Full Name: {$full_name}\nID: {$id_number}\nDOB: {$dob}\nGender: {$gender}\nMarital Status: {$marital_status}\nNationality: {$nationality}\n";
    $plainBody .= "Home Address: {$home_address}\nPostal Address: {$postal_address}\nProvince: {$province}\nCity: {$city}\nPhone: {$phone}\nAlt Phone: {$alt_phone}\nEmail: {$email}\n\n";
    $plainBody .= "Employment & Income:\nOccupation: {$occupation}\nEmployer: {$employer_name}\nEmployer contact: {$employer_contact}\nIncome range: {$income_range}\nPayment method: {$payment_method}\n\n";
    $plainBody .= "Plan:\nChoice: {$plan_choice}\nOption: {$plan_option}\nCover amount: {$cover_amount}\nPremium per month: {$premium_month}\nPreferred payment date: {$preferred_payment_date}\nJoining fee acknowledged: " . ($joining_ack ? 'Yes' : 'No') . "\n\n";
    if (!empty($beneficiaries)) {
        $plainBody .= "Beneficiaries:\n";
        foreach ($beneficiaries as $b) {
            $plainBody .= "- {$b['name']} | {$b['relation']} | ID: {$b['id']} | Phone: {$b['phone']} | Addr: {$b['address']}\n";
        }
    }
    if (!empty($dependants)) {
        $plainBody .= "\nDependants:\n";
        foreach ($dependants as $d) {
            $plainBody .= "- {$d['name']} | {$d['relation']} | ID: {$d['id']} | DOB: {$d['dob']} | Gender: {$d['gender']}\n";
        }
    }
    $plainBody .= "\nNext of Kin:\nName: {$nok_name}\nRelation: {$nok_relation}\nPhone: {$nok_phone}\nAddress: {$nok_address}\n\n";
    $plainBody .= "Signature: {$signature}\nApplication date: {$application_date}\nHeard about: {$heard_about}\nMarketing ok: {$marketing_ok}\nNotes: {$notes}\n\n";
    $plainBody .= "Attachments:\n";
    foreach ($uploaded_files as $u) {
        $plainBody .= "- {$u['orig']} (stored: {$u['path']})\n";
    }

    // Attach HTML and plain text
    $mail->isHTML(true);
    $mail->Body = $htmlBody;
    $mail->AltBody = $plainBody;

    // Attach files
    foreach ($uploaded_files as $u) {
        if (file_exists($u['path'])) {
            $mail->addAttachment($u['path'], $u['orig']);
        }
    }

    $mail->send();
} catch (Exception $e) {
    error_log("Admin email failed: " . $mail->ErrorInfo);
    // continue
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

    // embed logo into applicant email if available
    $appLogoCid = null;
    if (file_exists($local_logo_path) && is_readable($local_logo_path)) {
        $appLogoCid = 'vfslogo_app_' . md5($ref);
        $mail2->addEmbeddedImage($local_logo_path, $appLogoCid, 'logo.png');
        $app_logo_src = 'cid:' . $appLogoCid;
    } else {
        $app_logo_src = $logo_url;
    }

    // HTML message for the client (with logo)
    $htmlMessage = "
        <html>
        <body style='font-family: Arial, sans-serif; color:#333; background-color:#f8f8f8; padding:20px;'>
            <div style='max-width:600px; margin:auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.1);'>
                <div style='text-align:center; margin-bottom:15px;'>
                    <img src='{$app_logo_src}' alt='Vusana Funeral Services' style='max-height:60px;' />
                </div>
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
    $mail2->send();
} catch (Exception $e) {
    error_log('Applicant email failed: ' . $mail2->ErrorInfo);
}

// store ref in session and redirect to thankyou
$_SESSION['vfs_ref'] = $ref;
header('Location: thankyou.php');
exit;
