<?php
declare(strict_types=1);

// AMNAISYS website form handler — Microsoft 365 SMTP transport.
// All four localized Contact / Consultation forms post to this one handler.
const AMNAISYS_FORM_RECIPIENT = 'inquiries@amnaisys.com';
const AMNAISYS_FROM_ADDRESS = 'inquiries@amnaisys.com';
const AMNAISYS_FROM_NAME = 'AMNAISYS Website';
const AMNAISYS_SMTP_HOST = 'smtp.office365.com';
const AMNAISYS_SMTP_PORT = 587;
const AMNAISYS_SMTP_TIMEOUT = 20;
const AMNAISYS_SMTP_HELO = 'amnaisys.com';

function clean_line(string $value, int $max = 200): string {
    $value = trim(preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}
function clean_text(string $value, int $max = 3000): string {
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}
function fail_page(string $lang, int $status = 400): never {
    http_response_code($status);
    $ar = $lang === 'ar';
    $title = $ar ? 'تعذر إرسال الطلب' : 'Unable to send your request';
    $body = $ar
        ? 'تعذر إرسال النموذج حالياً. يرجى المحاولة مرة أخرى أو التواصل مباشرة عبر الهاتف أو واتساب.'
        : 'The form could not be sent at this time. Please try again or contact AMNAISYS directly by phone or WhatsApp.';
    $back = $ar ? '/ar/contact.html' : '/en/contact.html';
    $backLabel = $ar ? 'العودة إلى صفحة التواصل' : 'Return to Contact';
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="'.($ar?'ar':'en').'" dir="'.($ar?'rtl':'ltr').'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</title><style>body{font-family:system-ui,sans-serif;background:#faf7f1;color:#171717;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:680px;background:#fff;border:1px solid #e3ded3;border-radius:12px;padding:32px}a{color:#006c3c;font-weight:700}</style><div class="box"><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($body,ENT_QUOTES,'UTF-8').'</p><p><a href="'.$back.'">'.htmlspecialchars($backLabel,ENT_QUOTES,'UTF-8').'</a></p></div></html>';
    exit;
}

/**
 * Read one complete SMTP response, including RFC 5321 multiline replies.
 * Returns [statusCode, rawResponse].
 */
function smtp_read_response($socket): array {
    $response = '';
    $code = 0;
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $match)) {
            $code = (int)$match[1];
            if ($match[2] === ' ') {
                break;
            }
        }
    }
    return [$code, trim($response)];
}

/** Write the complete buffer to the SMTP socket, even if fwrite() returns a partial count. */
function smtp_write_all($socket, string $data): void {
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('SMTP write failed.');
        }
        $offset += $written;
    }
}

/** Send one SMTP command and require one of the expected response codes. */
function smtp_command($socket, string $command, array $expectedCodes): array {
    if ($command !== '') {
        smtp_write_all($socket, $command."\r\n");
    }
    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP command failed ('.$code.'): '.$response);
    }
    return [$code, $response];
}

/** RFC 5322 header encoding with a safe fallback when mbstring is unavailable. */
function encode_mail_header(string $value): string {
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }
    return '=?UTF-8?B?'.base64_encode($value).'?=';
}

/** Dot-stuff data lines as required by SMTP DATA framing. */
function smtp_dot_stuff(string $data): string {
    $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data) ?? $data;
    return preg_replace('/(?m)^\./', '..', $data) ?? $data;
}

/**
 * Send a UTF-8 plain-text message using Microsoft 365 STARTTLS on port 587.
 * This deployment is intentionally configured without SMTP username/password,
 * per the site's current Microsoft 365 / hosting configuration.
 */
function send_via_microsoft365_smtp(string $to, string $replyTo, string $subject, string $body): bool {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => AMNAISYS_SMTP_HOST,
            'SNI_enabled' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        'tcp://'.AMNAISYS_SMTP_HOST.':'.AMNAISYS_SMTP_PORT,
        $errno,
        $errstr,
        AMNAISYS_SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        error_log('AMNAISYS SMTP connection failed: '.$errno.' '.$errstr);
        return false;
    }

    stream_set_timeout($socket, AMNAISYS_SMTP_TIMEOUT);

    try {
        smtp_command($socket, '', [220]);
        smtp_command($socket, 'EHLO '.AMNAISYS_SMTP_HELO, [250]);
        smtp_command($socket, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to enable STARTTLS.');
        }

        // RFC 3207 requires EHLO again after STARTTLS establishes a new SMTP state.
        smtp_command($socket, 'EHLO '.AMNAISYS_SMTP_HELO, [250]);
        smtp_command($socket, 'MAIL FROM:<'.AMNAISYS_FROM_ADDRESS.'>', [250]);
        smtp_command($socket, 'RCPT TO:<'.$to.'>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $encodedSubject = encode_mail_header($subject);
        $encodedBody = quoted_printable_encode($body);
        $messageIdHost = preg_replace('/[^A-Za-z0-9.-]/', '', AMNAISYS_SMTP_HELO) ?: 'amnaisys.com';
        try {
            $messageToken = bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $messageToken = sha1(uniqid('', true));
        }

        $headers = [
            'Date: '.date(DATE_RFC2822),
            'Message-ID: <'.$messageToken.'@'.$messageIdHost.'>',
            'From: '.AMNAISYS_FROM_NAME.' <'.AMNAISYS_FROM_ADDRESS.'>',
            'To: <'.$to.'>',
            'Reply-To: <'.$replyTo.'>',
            'Subject: '.$encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'X-Mailer: AMNAISYS Website SMTP',
        ];

        $payload = implode("\r\n", $headers)."\r\n\r\n".$encodedBody;
        $payload = smtp_dot_stuff($payload);
        if (substr($payload, -2) !== "\r\n") {
            $payload .= "\r\n";
        }

        smtp_write_all($socket, $payload.".\r\n");
        smtp_command($socket, '', [250]);

        // QUIT is best-effort; successful DATA acceptance already means the message was queued.
        try {
            smtp_write_all($socket, "QUIT\r\n");
            @smtp_read_response($socket);
        } catch (Throwable $e) {
            // Message was already accepted; QUIT failure does not change delivery status.
        }
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        error_log('AMNAISYS SMTP send failed: '.$e->getMessage());
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            fclose($socket);
        }
        return false;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail_page('en', 405);
}

$lang = (($_POST['language'] ?? 'en') === 'ar') ? 'ar' : 'en';
$formType = in_array(($_POST['form_type'] ?? ''), ['contact', 'consultation'], true) ? (string)$_POST['form_type'] : 'contact';

// Honeypot: successful-looking redirect prevents bots from learning the rule.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    header('Location: '.($lang === 'ar' ? '/ar/thank-you.html' : '/en/thank-you.html'), true, 303);
    exit;
}

$name = clean_line((string)($_POST['name'] ?? ''), 120);
$company = clean_line((string)($_POST['company'] ?? ''), 160);
$emailRaw = trim((string)($_POST['email'] ?? ''));
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$phone = clean_line((string)($_POST['phone'] ?? ''), 40);
$message = clean_text((string)($_POST['message'] ?? ''), 3000);

$requiredServiceKey = $formType === 'consultation' ? 'serviceInterest' : 'service';
$requiredService = clean_line((string)($_POST[$requiredServiceKey] ?? ''), 240);
if ($name === '' || $company === '' || $email === '' || $phone === '' || $message === '' || $requiredService === '') {
    fail_page($lang, 422);
}

// Phone accepts Western/Arabic-Indic digits, spaces, and at most one leading +.
if (!preg_match('/^\+?[0-9٠-٩ ]+$/u', $phone) || !preg_match('/[0-9٠-٩]/u', $phone)) {
    fail_page($lang, 422);
}

$labels = [
    'service' => 'Service',
    'serviceInterest' => 'Service Interest',
    'industry' => 'Industry',
    'companySize' => 'Company Size',
    'projectStage' => 'Project Stage',
    'budgetRange' => 'Budget Range',
    'preferredContactMethod' => 'Preferred Contact Method',
];
$details = [];
foreach ($labels as $key => $label) {
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        $details[] = $label.': '.clean_line((string)$_POST[$key], 240);
    }
}

$subject = '[AMNAISYS Website] '.ucfirst($formType).' request — '.$company;
$host = clean_line((string)($_SERVER['HTTP_HOST'] ?? 'amnaisys.com'), 120);
$ip = clean_line((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 80);
$ua = clean_line((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 300);

$body = "AMNAISYS website submission\n\n".
        "Form: {$formType}\nLanguage: {$lang}\nName: {$name}\nCompany: {$company}\nEmail: {$email}\nPhone: {$phone}\n".
        (count($details) ? implode("\n", $details)."\n" : '').
        "\nMessage:\n{$message}\n\n---\nHost: {$host}\nIP: {$ip}\nUser-Agent: {$ua}\n";

$sent = send_via_microsoft365_smtp(AMNAISYS_FORM_RECIPIENT, $email, $subject, $body);
if (!$sent) {
    fail_page($lang, 500);
}

$returnTo = (string)($_POST['return_to'] ?? '');
$allowedReturn = ['/en/thank-you.html', '/ar/thank-you.html'];
if (!in_array($returnTo, $allowedReturn, true)) {
    $returnTo = $lang === 'ar' ? '/ar/thank-you.html' : '/en/thank-you.html';
}
header('Location: '.$returnTo, true, 303);
exit;
