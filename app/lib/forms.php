<?php
/** Helpers for the public form endpoints in /api. */
declare(strict_types=1);

final class FormError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/** Send the result as JSON (JavaScript) or as a styled page (no-JavaScript fallback). */
function respond(int $status, bool $ok, string $message, ?string $field = null, string $back = '../contact.html'): never
{
    http_response_code($status);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_filter(['ok' => $ok, 'message' => $message, 'field' => $field], static fn ($v) => $v !== null));
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $title = $ok ? 'Thank you.' : 'Please try again.';
    $actions = $ok
        ? '<a class="button" href="../index.html">Return to the homepage</a>'
        : '<a class="button" href="' . e($back) . '">Go back to the form</a><a class="button button-ghost" href="tel:+13363292695">Call 336-329-2695</a>';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' | Olive Sprigs Health Solutions</title><link rel="stylesheet" href="../style.css"></head>'
        . '<body><main class="wrap message-page"><div><img src="../assets/images/logo-mark.webp" alt="Olive Sprigs Health Solutions" width="120" height="118" style="margin:0 auto 28px;height:96px;width:auto">'
        . '<h1>' . e($title) . '</h1><p class="lead">' . e($message) . '</p><div class="button-row">' . $actions . '</div></div></main></body></html>';
    exit;
}

/** Common checks for every public POST endpoint. */
function guard_public_post(string $bucket): void
{
    send_security_headers();
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new FormError('Please use the form on our website.', 405);
    if (!same_origin_request()) throw new FormError('Please submit from our website.', 403);
    if (($_POST['website'] ?? '') !== '') throw new FormError('Your submission could not be accepted.', 400); // honeypot
    if (!rate_limit($bucket . ':' . client_ip_hash(), (int)config('form_rate_limit', 3), (int)config('form_rate_window', 600))) {
        throw new FormError('You’ve sent several requests in a short time. Please wait a few minutes, or call 336-329-2695.', 429);
    }
}

/** Read a trimmed text field with a length limit. */
function input(string $key, int $max, bool $required = false, string $label = 'This field'): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) throw new FormError('Please check your form details.', 422, $key);
    $value = trim(str_replace("\0", '', $value));
    if ($required && $value === '') throw new FormError("$label is required.", 422, $key);
    if (mb_strlen($value) > $max) throw new FormError("$label is too long (maximum $max characters).", 422, $key);
    return $value;
}

function input_name(string $key, string $label): string
{
    $value = input($key, 60, true, $label);
    if (preg_match('/[\r\n<>]/', $value)) throw new FormError("Please check your $label.", 422, $key);
    return $value;
}

function input_email(string $key = 'email'): string
{
    $value = input($key, 200, true, 'Email');
    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $value)) throw new FormError('Please enter a valid email address.', 422, $key);
    return strtolower($value);
}

function input_phone(string $key = 'phone'): string
{
    $value = input($key, 40, true, 'Phone');
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) < 10 || strlen($digits) > 15) throw new FormError('Please enter a full phone number, including the area code.', 422, $key);
    return $value;
}

function input_choice(string $key, array $allowed, bool $required = false, string $label = 'Option'): string
{
    $value = input($key, 120, $required, $label);
    if ($value !== '' && !in_array($value, $allowed, true)) throw new FormError("Please choose a $label from the list.", 422, $key);
    return $value;
}

/** Run an endpoint body, turning FormError into a friendly response. */
function handle_form(callable $body, string $back): never
{
    try {
        $body();
    } catch (FormError $e) {
        respond($e->status, false, $e->getMessage(), $e->field, $back);
    }
    exit;
}

function admin_link(string $type, int $id): string
{
    $base = rtrim((string)config('site_url'), '/');
    return $base !== '' ? "$base/admin/view.php?type=$type&id=$id" : "admin/view.php?type=$type&id=$id (sign in to your website admin)";
}
