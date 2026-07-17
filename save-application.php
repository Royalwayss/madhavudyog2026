<?php 
header('Content-Type: application/json');

// ----- CONFIG -----
$csvFile     = __DIR__ . '/data/career-applications-56466363366954263.csv';
$uploadDir   = __DIR__ . '/uploads/resumes/';
$allowedExts = ['pdf', 'doc', 'docx'];
$maxFileSize = 5 * 1024 * 1024; // 5 MB

// ----- MAIL CONFIG -----
$fromEmail  = 'noreply@madhavudyog.com';   // <-- change to your sending address
$fromName   = 'Madhav Udyog Website';
$adminEmail = 'hr@madhavudyog.com';
      
$logoUrl    = 'https://www.madhavudyog.com/assets/img/madhav/header-logo.png';

// ----- RECAPTCHA CONFIG -----
$recaptchaSecret = '6LcKX1YtAAAAANuwh3hGADZ1qSibxPWyAq6-lVFJ'; // <-- from https://www.google.com/recaptcha/admin

// Public URL base for the resume link inside the email (adjust if this script
// lives in a different folder than /uploads/resumes/ on your live server)
$resumeUrlBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
               . $_SERVER['HTTP_HOST']
               . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
               . '/uploads/resumes/';

$response = ['status' => 'error', 'message' => 'Something went wrong. Please try again.'];

// ----- BASIC METHOD CHECK -----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode($response);
    exit;
}

// ----- COLLECT & SANITIZE INPUT -----
$fullName   = trim($_POST['full_name']   ?? '');
$email      = trim($_POST['email']       ?? '');
$mobile     = trim($_POST['mobile']      ?? '');
$position   = trim($_POST['position']    ?? '');
$experience = trim($_POST['experience']  ?? '');
$location   = trim($_POST['location']    ?? '');
$message    = trim($_POST['message']     ?? '');
$recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

// ----- SERVER-SIDE VALIDATION (never trust client JS alone) -----
$errors = [];

if ($recaptchaResponse === '') {
    $errors[] = 'Please verify that you are not a robot.';
} else {
    $verify = @file_get_contents(
        'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($recaptchaSecret)
        . '&response=' . urlencode($recaptchaResponse)
        . '&remoteip=' . urlencode($_SERVER['REMOTE_ADDR'] ?? '')
    );
    $verifyData = $verify ? json_decode($verify, true) : null;

    if (empty($verifyData['success'])) {
       // $errors[] = 'reCAPTCHA verification failed. Please try again.';
    }
}


if ($fullName === '' || mb_strlen($fullName) < 3) {
    $errors[] = 'Full name is required (min 3 characters).';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if ($mobile === '' || !preg_match('/^[0-9]{10}$/', $mobile)) {
    $errors[] = 'A valid 10-digit mobile number is required.';
}
if ($position === '') {
    $errors[] = 'Position is required.';
}
if ($experience === '') {
    $errors[] = 'Experience is required.';
}

if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'Resume file is required.';
} else {
    $ext = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        $errors[] = 'Resume must be a PDF, DOC, or DOCX file.';
    }
    if ($_FILES['resume']['size'] > $maxFileSize) {
        $errors[] = 'Resume file must be smaller than 5 MB.';
    }
}

if (!empty($errors)) {
    $response['message'] = implode(' ', $errors);
    echo json_encode($response);
    exit;
}

// ----- ENSURE DIRECTORIES EXIST -----
if (!is_dir(dirname($csvFile))) {
    mkdir(dirname($csvFile), 0755, true);
}
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ----- MOVE UPLOADED RESUME -----
$ext          = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
$safeName     = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($_FILES['resume']['name'], PATHINFO_FILENAME));
$resumeName   = date('Ymd_His') . '_' . $safeName . '.' . $ext;
$resumeTarget = $uploadDir . $resumeName;

if (!@move_uploaded_file($_FILES['resume']['tmp_name'], $resumeTarget)) {
    error_log('save-application.php: failed to move uploaded resume to ' . $resumeTarget);
}

// ----- MAIL FUNCTION -----
function sendApplicationMail($toEmail, $fromEmail, $fromName, $logoUrl, $data, $isAdminCopy = false) {

    $subject =  'New Career Application from Madhav Udyog - ' . date('d-m-Y');
        


    $rows = '';
    foreach ([
        'Full Name'  => $data['full_name'],
        'Email'      => $data['email'],
        'Mobile'     => $data['mobile'],
        'Position'   => $data['position'],
        'Experience' => $data['experience'],
        'Location'   => $data['location'] ?: '-',
        'Message'    => nl2br(htmlspecialchars($data['message'] ?: '-')),
    ] as $label => $value) {
        $rows .= '<tr>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;font-weight:600;background:#f9fafb;width:160px;">' . htmlspecialchars($label) . '</td>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">' . $value . '</td>
                  </tr>';
    }

    $resumeRow = '<tr>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;font-weight:600;background:#f9fafb;">Resume</td>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">
                        <a href="' . htmlspecialchars($data['resume_url']) . '" style="color:#10b981;">Download Resume</a>
                    </td>
                  </tr>';

    $intro = $isAdminCopy
        ? '<p style="margin:0 0 16px;">A new career application has been submitted on the website.</p>'
        : '<p style="margin:0 0 16px;">Hi ' . htmlspecialchars($data['full_name']) . ',</p>
           <p style="margin:0 0 16px;">Thank you for applying to Madhav Udyog. We have received your application for the
           <strong>' . htmlspecialchars($data['position']) . '</strong> position and our team will review it shortly.</p>';

    $body = '
    <!DOCTYPE html>
    <html>
    <body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
            <tr>
                <td align="center">
                    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
                        <tr>
                            <td style="background:#1e2a4a;padding:20px 24px;text-align:center;">
                                <img src="' . htmlspecialchars($logoUrl) . '" alt="Madhav Udyog" style="max-height:50px;">
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:24px;">
                                ' . $intro . '
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                                    ' . $rows . $resumeRow . '
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:16px 24px;background:#f9fafb;text-align:center;font-size:12px;color:#6b7280;">
                                &copy; ' . date('Y') . ' Madhav Udyog. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";

    return @mail($toEmail, $subject, $body, $headers); 
}


$isNewFile = !file_exists($csvFile);

$fp = @fopen($csvFile, 'a');
if ($fp === false) {
    error_log('save-application.php: failed to open CSV file at ' . $csvFile);
} else {

    // Lock file while writing to avoid corruption on concurrent submissions
    if (flock($fp, LOCK_EX)) {

        if ($isNewFile) {
            fputcsv($fp, [
                'Submitted At',
                'Full Name',
                'Email',
                'Mobile',
                'Position',
                'Experience',
                'Location',
                'Message',
                'Resume File'
            ]);
        }

        fputcsv($fp, [
            date('Y-m-d H:i:s'),
            $fullName,
            $email,
            $mobile,
            $position,
            $experience,
            $location,
            $message,
            $resumeName
        ]);

        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// ----- SEND NOTIFICATION EMAILS -----
$mailData = [
    'full_name'  => $fullName,
    'email'      => $email,
    'mobile'     => $mobile,
    'position'   => $position,
    'experience' => $experience,
    'location'   => $location,
    'message'    => $message,
    'resume_url' => $resumeUrlBase . $resumeName,
];

// Notify HR/admin
 sendApplicationMail($adminEmail, $fromEmail, $fromName, $logoUrl, $mailData, true);

// Confirmation to the applicant
//sendApplicationMail($email, $fromEmail, $fromName, $logoUrl, $mailData, false);

$response['status']  = 'success';
$response['message'] = 'Thank you! Your application has been submitted successfully.';
echo json_encode($response);