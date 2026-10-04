<?php
declare(strict_types=1);

// AMNAISYS website form handler — Microsoft 365 Authenticated SMTP transport.
// All four localized Contact / Consultation forms post to this one handler.
const AMNAISYS_FORM_RECIPIENT = 'inquiries@amnaisys.com';
const AMNAISYS_FROM_ADDRESS = 'inquiries@amnaisys.com';
const AMNAISYS_FROM_NAME = 'AMNAISYS Website';
const AMNAISYS_SMTP_HOST = 'smtp.office365.com';
const AMNAISYS_SMTP_PORT = 587;
const AMNAISYS_SMTP_TIMEOUT = 20;
const AMNAISYS_SMTP_HELO = 'amnaisys.com';

// SMTP Authentication Credentials
const AMNAISYS_SMTP_USER = 'inquiries@amnaisys.com';
const AMNAISYS_SMTP_PASS = 'zbvkccqccvxksngl'; // Replace with the mailbox/App password

function clean_line(string $value, int $max = 200): string {
    $value = trim(preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}
function clean_text(string $value, int $max = 3000): string {
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function post_string(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function utf8_length(string $value): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return preg_match_all('/./us', $value, $matches) ?: 0;
}

function normalized_phone_digits(string $value): string {
    $value = strtr($value, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    return preg_replace('/[^0-9]/', '', $value) ?? '';
}

function validation_fail(string $lang): never {
    $message = $lang === 'ar'
        ? 'يرجى مراجعة الحقول وإدخال معلومات صحيحة قبل إرسال النموذج.'
        : 'Please review the form and enter valid information before submitting.';
    fail_page($lang, 422, $message);
}

function form_guard_directory(): ?string {
    $suffix = substr(hash('sha256', AMNAISYS_SMTP_HELO), 0, 12);
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'amnaisys-form-guard-'.$suffix;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('AMNAISYS form guard directory could not be created.');
        return null;
    }
    return $dir;
}

function enforce_rate_limit(string $clientKey, string $lang): void {
    $dir = form_guard_directory();
    if ($dir === null) {
        return;
    }
    $path = $dir.DIRECTORY_SEPARATOR.'rate-'.hash('sha256', $clientKey).'.json';
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        error_log('AMNAISYS form rate-limit file could not be opened.');
        return;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return;
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $events = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($events)) {
            $events = [];
        }
        $now = time();
        $events = array_values(array_filter($events, static fn($ts): bool => is_int($ts) && $ts >= $now - 86400));
        $lastTenMinutes = array_filter($events, static fn($ts): bool => $ts >= $now - 600);
        if (count($lastTenMinutes) >= 5 || count($events) >= 20) {
            $message = $lang === 'ar'
                ? 'تم إرسال عدة طلبات خلال فترة قصيرة. يرجى الانتظار قليلاً ثم المحاولة مرة أخرى.'
                : 'Several requests were submitted in a short period. Please wait a little and try again.';
            flock($fp, LOCK_UN);
            fclose($fp);
            fail_page($lang, 429, $message);
        }
        $events[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($events));
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        if (is_resource($fp)) {
            @fclose($fp);
        }
    }
}

function reserve_duplicate_submission(string $fingerprint): bool {
    $dir = form_guard_directory();
    if ($dir === null) {
        return true;
    }
    $path = $dir.DIRECTORY_SEPARATOR.'duplicates.json';
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return true;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return true;
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $items = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($items)) {
            $items = [];
        }
        $now = time();
        foreach ($items as $hash => $ts) {
            if (!is_int($ts) || $ts < $now - 3600) {
                unset($items[$hash]);
            }
        }
        if (isset($items[$fingerprint]) && $items[$fingerprint] >= $now - 1800) {
            flock($fp, LOCK_UN);
            return false;
        }
        $items[$fingerprint] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($items));
        fflush($fp);
        flock($fp, LOCK_UN);
        return true;
    } finally {
        if (is_resource($fp)) {
            @fclose($fp);
        }
    }
}

function release_duplicate_submission(string $fingerprint): void {
    $dir = form_guard_directory();
    if ($dir === null) {
        return;
    }
    $path = $dir.DIRECTORY_SEPARATOR.'duplicates.json';
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return;
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $items = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($items)) {
            $items = [];
        }
        unset($items[$fingerprint]);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($items));
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        if (is_resource($fp)) {
            @fclose($fp);
        }
    }
}

function fail_page(string $lang, int $status = 400, ?string $bodyOverride = null): never {
    http_response_code($status);
    $ar = $lang === 'ar';
    $title = $ar ? 'تعذر إرسال الطلب' : 'Unable to send your request';
    $body = $bodyOverride ?? ($ar
        ? 'تعذر إرسال النموذج حالياً. يرجى المحاولة مرة أخرى أو التواصل مباشرة عبر الهاتف أو واتساب.'
        : 'The form could not be sent at this time. Please try again or contact AMNAISYS directly by phone or WhatsApp.');
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
 * Send a UTF-8 plain-text message using Microsoft 365 STARTTLS on port 587 with AUTH LOGIN.
 */
function send_via_microsoft365_smtp(string $to, string $replyTo, string $replyName, string $fromName, string $subject, string $body): bool {
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

        // RFC 4954 SMTP Authentication via AUTH LOGIN
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode(AMNAISYS_SMTP_USER), [334]);
        smtp_command($socket, base64_encode(AMNAISYS_SMTP_PASS), [235]);

        smtp_command($socket, 'MAIL FROM:<'.AMNAISYS_FROM_ADDRESS.'>', [250]);
        smtp_command($socket, 'RCPT TO:<'.$to.'>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $encodedSubject = encode_mail_header($subject);
        $encodedFromName = encode_mail_header($fromName);
        $encodedReplyName = encode_mail_header($replyName);
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
            'From: '.$encodedFromName.' <'.AMNAISYS_FROM_ADDRESS.'>',
            'To: <'.$to.'>',
            'Reply-To: '.$encodedReplyName.' <'.$replyTo.'>',
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

$lang = post_string('language') === 'ar' ? 'ar' : 'en';
$formTypeRaw = post_string('form_type');
if (!in_array($formTypeRaw, ['contact', 'consultation'], true)) {
    validation_fail($lang);
}
$formType = $formTypeRaw;
$returnTo = post_string('return_to');
$allowedReturn = ['/en/thank-you.html', '/ar/thank-you.html'];
if (!in_array($returnTo, $allowedReturn, true)) {
    $returnTo = $lang === 'ar' ? '/ar/thank-you.html' : '/en/thank-you.html';
}

// Honeypot: successful-looking redirect prevents basic bots from learning the rule.
if (trim(post_string('website')) !== '') {
    header('Location: '.$returnTo, true, 303);
    exit;
}

$clientIp = clean_line((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 80);
enforce_rate_limit($clientIp !== '' ? $clientIp : 'unknown', $lang);

// Optional low-friction timing signal populated by JavaScript. No-JS submissions remain supported.
$formStartedRaw = post_string('form_started');
if ($formStartedRaw !== '' && ctype_digit($formStartedRaw)) {
    $elapsedMs = (int)round(microtime(true) * 1000) - (int)$formStartedRaw;
    if ($elapsedMs >= 0 && $elapsedMs < 1200) {
        $message = $lang === 'ar'
            ? 'تم إرسال النموذج بسرعة غير معتادة. يرجى مراجعة البيانات ثم المحاولة مرة أخرى.'
            : 'The form was submitted unusually quickly. Please review the details and try again.';
        fail_page($lang, 429, $message);
    }
}

// Validate raw values before truncating/normalizing them so oversized direct POSTs are rejected, not silently clipped.
$nameRaw = trim(post_string('name'));
$companyRaw = trim(post_string('company'));
$emailRaw = trim(post_string('email'));
$phoneRaw = trim(post_string('phone'));
$messageRaw = trim(post_string('message'));

$nameLength = utf8_length($nameRaw);
$companyLength = utf8_length($companyRaw);
$emailLength = utf8_length($emailRaw);
$phoneLength = utf8_length($phoneRaw);
$messageLength = utf8_length($messageRaw);

if ($nameLength < 2 || $nameLength > 100 || !preg_match('/\p{L}/u', $nameRaw) || !preg_match('/^[\p{L}\p{M} .\'’\-]+$/u', $nameRaw)) {
    validation_fail($lang);
}
if ($companyLength < 2 || $companyLength > 150 || !preg_match('/[\p{L}\p{N}]/u', $companyRaw)) {
    validation_fail($lang);
}
if (
    $emailLength < 3 ||
    $emailLength > 254 ||
    filter_var($emailRaw, FILTER_VALIDATE_EMAIL) === false ||
    !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $emailRaw)
) {
    validation_fail($lang);
}
if ($phoneLength < 7 || $phoneLength > 25 || !preg_match('/^\+?[0-9٠-٩ ]+$/u', $phoneRaw)) {
    validation_fail($lang);
}
$phoneDigits = normalized_phone_digits($phoneRaw);
if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
    validation_fail($lang);
}
if ($messageLength < 20 || $messageLength > 1000) {
    validation_fail($lang);
}

$contactServices = [
    'AI Automation', 'AI Agents', 'Cybersecurity', 'Cloud & Infrastructure', 'Managed IT Services',
    'Software Engineering', 'DevOps & DevSecOps', 'Systems Integration & APIs', 'Data Engineering',
    'Network & Infrastructure', 'IT Consulting & Digital Transformation', 'Other Requirement / Not Yet Decided',
];
$consultationServices = [
    'ai-automation', 'ai-agents', 'cybersecurity', 'cloud-infrastructure', 'managed-it', 'software-engineering',
    'devops-devsecops', 'systems-integration', 'data-engineering', 'network-infrastructure',
    'it-consulting-digital-transformation', 'other-requirement',
];
$optionalSelects = [
    'industry' => ['', 'industrial-manufacturing', 'logistics-supply-chain', 'construction-real-estate', 'professional-services', 'retail-ecommerce', 'hospitality'],
    'companySize' => ['', '1–20', '21–50', '51–200', '201–500', '501+'],
    'projectStage' => ['', 'exploring', 'defined', 'ready-to-scope', 'improvement', 'urgent-security'],
    'budgetRange' => ['', 'not-decided', 'under-50k', '50k-150k', '150k-500k', '500k-plus'],
    'preferredContactMethod' => ['', 'email', 'phone', 'whatsapp'],
];

$requiredServiceKey = $formType === 'consultation' ? 'serviceInterest' : 'service';
$requiredServiceRaw = post_string($requiredServiceKey);
$allowedRequiredServices = $formType === 'consultation' ? $consultationServices : $contactServices;
if (!in_array($requiredServiceRaw, $allowedRequiredServices, true)) {
    validation_fail($lang);
}

if ($formType === 'consultation') {
    foreach ($optionalSelects as $key => $allowedValues) {
        $value = post_string($key);
        if (!in_array($value, $allowedValues, true)) {
            validation_fail($lang);
        }
    }
}

$name = clean_line($nameRaw, 100);
$company = clean_line($companyRaw, 150);
$email = clean_line($emailRaw, 254);
$phone = clean_line($phoneRaw, 25);
$message = clean_text($messageRaw, 1000);
$requiredService = clean_line($requiredServiceRaw, 240);

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
    $value = post_string($key);
    if ($value !== '') {
        $details[] = $label.': '.clean_line($value, 240);
    }
}

$formLabel = $formType === 'consultation' ? 'Consultation Request' : 'Contact Inquiry';
$subject = '[AMNAISYS] '.$formLabel.' — '.$name.' — '.$company;
$fromDisplayName = $name.' via '.AMNAISYS_FROM_NAME;
$host = clean_line((string)($_SERVER['HTTP_HOST'] ?? 'amnaisys.com'), 120);
$ua = clean_line((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 300);

$body = "AMNAISYS website submission\n\n".
        "Form: {$formType}\nLanguage: {$lang}\nName: {$name}\nCompany: {$company}\nEmail: {$email}\nPhone: {$phone}\n".
        (count($details) ? implode("\n", $details)."\n" : '').
        "\nMessage:\n{$message}\n\n---\nHost: {$host}\nIP: {$clientIp}\nUser-Agent: {$ua}\n";

$normalizedMessage = preg_replace('/\s+/u', ' ', $message) ?? $message;
$duplicateFingerprint = hash('sha256', strtolower($email).'|'.$phoneDigits.'|'.$requiredService.'|'.$normalizedMessage);
if (!reserve_duplicate_submission($duplicateFingerprint)) {
    // Duplicate within 30 minutes: acknowledge without generating another Microsoft 365 message.
    header('Location: '.$returnTo, true, 303);
    exit;
}

$sent = send_via_microsoft365_smtp(
    AMNAISYS_FORM_RECIPIENT,
    $email,
    $name,
    $fromDisplayName,
    $subject,
    $body
);
if (!$sent) {
    release_duplicate_submission($duplicateFingerprint);
    fail_page($lang, 500);
}

header('Location: '.$returnTo, true, 303);
exit;
