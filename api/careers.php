<?php
/** POST /api/careers.php — job application from careers.html, with an optional résumé (PDF, DOC, DOCX). */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/forms.php';

const RESUME_TYPES = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
];

/** Validate the upload and move it into private storage. Returns [stored name, original name, mime, size] or null. */
function store_resume(): ?array
{
    $file = $_FILES['resume'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (!is_array($file) || is_array($file['name'])) throw new FormError('Please attach one file.', 422, 'resume');
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > (int)config('upload_max_bytes')) {
        throw new FormError('Your résumé is larger than 5 MB. Please attach a smaller file.', 413, 'resume');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new FormError('Your résumé did not upload. Please try again.', 422, 'resume');

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(RESUME_TYPES[$ext]) || !in_array($mime, RESUME_TYPES[$ext], true)) {
        throw new FormError('Please attach your résumé as a PDF or Word document (.pdf, .doc, .docx).', 415, 'resume');
    }
    // A .docx is a zip; make sure it really is a Word document.
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $opened = $zip->open($file['tmp_name']) === true;
        $isWord = $opened && $zip->locateName('word/document.xml') !== false;
        if ($opened) $zip->close();
        if (!$isWord) throw new FormError('That file does not look like a Word document.', 415, 'resume');
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], storage_path('uploads/' . $stored))) {
        throw new RuntimeException('Could not save the uploaded résumé. Check storage/uploads permissions.');
    }
    @chmod(storage_path('uploads/' . $stored), 0640);
    $original = preg_replace('/[^\w .()-]/u', '_', mb_substr(basename((string)$file['name']), 0, 120));
    $mimes = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    return [$stored, $original, $mimes[$ext], (int)$file['size']];
}

handle_form(static function (): void {
    // Requests larger than post_max_size arrive with empty $_POST; explain instead of failing silently.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new FormError('Your résumé is too large to upload. Please attach a file under 5 MB.', 413, 'resume');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > (int)config('upload_max_bytes') + 50000) {
        throw new FormError('Your résumé is larger than 5 MB. Please attach a smaller file.', 413, 'resume');
    }
    guard_public_post('careers');

    $data = [
        'first_name'   => input_name('first_name', 'first name'),
        'last_name'    => input_name('last_name', 'last name'),
        'email'        => input_email(),
        'phone'        => input_phone(),
        'position'     => input_choice('position', POSITIONS, true, 'position'),
        'county'       => input_choice('county', COUNTIES, false, 'county'),
        'availability' => input_choice('availability', AVAILABILITY, false, 'availability'),
        'experience'   => input('experience', 2000, false, 'Experience'),
        'message'      => input('message', 2000, false, 'Your message'),
    ];
    $resume = store_resume();

    $stamp = now();
    q('INSERT INTO applications (created_at, updated_at, first_name, last_name, email, phone, position, county, availability,
         experience, message, resume_file, resume_name, resume_mime, resume_size, ip_hash)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$stamp, $stamp, ...array_values($data), ...($resume ?? [null, null, null, null]), client_ip_hash()]);
    $id = (int)db()->lastInsertId();
    audit('application', $id, 'received', $resume ? 'Submitted with a résumé' : 'Submitted without a résumé');

    $name = $data['first_name'] . ' ' . $data['last_name'];
    $body = "A new job application arrived from the website.\n\n"
        . "Name:          $name\nEmail:         {$data['email']}\nPhone:         {$data['phone']}\n"
        . "Position:      {$data['position']}\n"
        . 'County:        ' . ($data['county'] ?: 'Not given') . "\n"
        . 'Availability:  ' . ($data['availability'] ?: 'Not given') . "\n"
        . 'Résumé:        ' . ($resume ? 'Attached in the admin dashboard' : 'Not provided') . "\n\n"
        . "Experience:\n" . ($data['experience'] ?: '(none)') . "\n\n"
        . "Message:\n" . ($data['message'] ?: '(none)') . "\n\n"
        . 'Review this application: ' . admin_link('application', $id) . "\n";
    $sent = send_mail((string)config('careers_to'), "New job application — $name, {$data['position']} (#$id)", $body, $data['email']);
    q('UPDATE applications SET email_sent = ? WHERE id = ?', [$sent ? 1 : 0, $id]);
    if (!$sent) app_log("Notification email failed for application #$id (saved in the database).");

    respond(200, true, 'Thank you for applying. Our team will review your application and contact you if there is a good fit.', null, '../careers.html');
}, '../careers.html');
