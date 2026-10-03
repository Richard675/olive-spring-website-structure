<?php
/** POST /api/contact.php — care request from contact.html. Saves to the database, then notifies the office. */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/forms.php';

handle_form(static function (): void {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) throw new FormError('Your message is too long.', 413);
    guard_public_post('contact');

    $data = [
        'first_name' => input_name('first_name', 'first name'),
        'last_name'  => input_name('last_name', 'last name'),
        'email'      => input_email(),
        'phone'      => input_phone(),
        'county'     => input_choice('county', COUNTIES, false, 'county'),
        'service'    => input_choice('service', SERVICES, false, 'service'),
        'message'    => input('message', 2000, false, 'Your message'),
    ];

    $stamp = now();
    q('INSERT INTO inquiries (created_at, updated_at, first_name, last_name, email, phone, county, service, message, ip_hash)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$stamp, $stamp, ...array_values($data), client_ip_hash()]);
    $id = (int)db()->lastInsertId();
    audit('inquiry', $id, 'received', 'Submitted from the website contact form');

    $name = $data['first_name'] . ' ' . $data['last_name'];
    $body = "A new care request arrived from the website.\n\n"
        . "Name:     $name\nEmail:    {$data['email']}\nPhone:    {$data['phone']}\n"
        . 'County:   ' . ($data['county'] ?: 'Not given') . "\n"
        . 'Service:  ' . ($data['service'] ?: 'Not sure yet') . "\n\n"
        . "Message:\n" . ($data['message'] ?: '(none)') . "\n\n"
        . 'Manage this request: ' . admin_link('inquiry', $id) . "\n";
    $sent = send_mail((string)config('notify_to'), "New care request — $name (#$id)", $body, $data['email']);
    q('UPDATE inquiries SET email_sent = ? WHERE id = ?', [$sent ? 1 : 0, $id]);
    if (!$sent) app_log("Notification email failed for inquiry #$id (saved in the database).");

    if (config('send_confirmation')) {
        send_mail($data['email'], 'We received your request — Olive Sprigs Health Solutions',
            "Hi {$data['first_name']},\n\nThank you for contacting Olive Sprigs Health Solutions. A member of our team will reach out soon.\n"
            . "If you need help sooner, please call 336-329-2695. For emergencies, call 911.\n\nOlive Sprigs Health Solutions\n500 South Main Street, Suite 300, Burlington, NC 27215");
    }

    // The request is safely stored even if email delivery failed, so the visitor sees success either way.
    respond(200, true, 'Your request has been sent. A member of our team will contact you soon.');
}, '../contact.html');
