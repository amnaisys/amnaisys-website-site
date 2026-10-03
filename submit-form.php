<?php
declare(strict_types=1);

// AMNAISYS cPanel/VPS form handler. Requires PHP mail() to be enabled by hosting.
const AMNAISYS_FORM_RECIPIENT = 'inquiries@amnaisys.com';
const AMNAISYS_FROM_ADDRESS = 'inquiries@amnaisys.com';

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
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8');
}
$host = clean_line((string)($_SERVER['HTTP_HOST'] ?? 'amnaisys.com'), 120);
$ip = clean_line((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 80);
$ua = clean_line((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 300);

$body = "AMNAISYS website submission\n\n".
        "Form: {$formType}\nLanguage: {$lang}\nName: {$name}\nCompany: {$company}\nEmail: {$email}\nPhone: {$phone}\n".
        (count($details) ? implode("\n", $details)."\n" : '').
        "\nMessage:\n{$message}\n\n---\nHost: {$host}\nIP: {$ip}\nUser-Agent: {$ua}\n";

$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: AMNAISYS Website <'.AMNAISYS_FROM_ADDRESS.'>',
    'Reply-To: '.$email,
    'X-Mailer: PHP/'.PHP_VERSION,
];

$sent = @mail(AMNAISYS_FORM_RECIPIENT, $subject, $body, implode("\r\n", $headers));
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
