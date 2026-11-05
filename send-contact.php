<?php
// Verschickt Anfragen aus dem Kontaktformular (kontakt.html) per E-Mail an Sophie.
// Ersetzt den externen Dienst FormSubmit.co.

// ---- Einstellungen ----------------------------------------------------------
// Empfänger der Anfragen
$recipient = 'webmaster@trauredenbysophie.de';
// Absenderadresse: muss eine Adresse der eigenen Domain sein, sonst verwirft
// Strato bzw. der Empfänger die Mail. Die Anfrage-Person steht im Reply-To,
// damit "Antworten" direkt an sie geht.
$sender = 'webmaster@trauredenbysophie.de';
$senderName = 'Traureden by Sophie';
$thanksPage = 'thanks.html';
$allowedOrigin = 'trauredenbysophie.de';
// -----------------------------------------------------------------------------

mb_internal_encoding('UTF-8');

function showError($text) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nachricht nicht gesendet - Traureden by Sophie</title>
    <style>
        body { font-family: 'Inter', Arial, sans-serif; background: #FDFBF8; color: #44403c; margin: 0; }
        .box { max-width: 36rem; margin: 15vh auto; background: #fff; padding: 2.5rem; border-radius: .5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,.1); text-align: center; }
        h1 { font-family: 'Epunda Slab', Georgia, serif; color: #292524; }
        a.btn { display: inline-block; margin-top: 1.5rem; background: #E8DFD8; color: #292524; font-weight: 600; padding: .75rem 2rem; border-radius: .5rem; text-decoration: none; }
        a.btn:hover { background: #D4C4B7; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Das hat leider nicht geklappt</h1>
        <p>{$text}</p>
        <a class="btn" href="javascript:history.back()">Zurück zum Formular</a>
    </div>
</body>
</html>
HTML;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: kontakt.html');
    exit;
}

// Grobe Missbrauchsbremse: nur Anfragen von der eigenen Seite akzeptieren
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '' && strpos($referer, $allowedOrigin) === false) {
    showError('Die Anfrage kam nicht von unserer Seite.');
}

// Honeypot: dieses Feld ist für Menschen unsichtbar. Ist es ausgefüllt, war es
// ein Bot. Dann tun wir so, als hätte alles geklappt.
if (trim($_POST['website'] ?? '') !== '') {
    header('Location: ' . $thanksPage);
    exit;
}

function field($key, $maxLength) {
    $value = trim((string)($_POST[$key] ?? ''));
    return mb_substr($value, 0, $maxLength);
}

// Zeilenumbrüche entfernen, damit niemand zusätzliche Mail-Header einschleusen kann
function singleLine($value) {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $value));
}

$name = singleLine(field('name', 200));
$email = singleLine(field('email', 200));
$phone = singleLine(field('phone', 100));
$eventDate = singleLine(field('event-date', 20));
$message = field('message', 10000);

if ($name === '' || $email === '' || $phone === '' || $message === '') {
    showError('Bitte füllt Name, E-Mail, Telefonnummer und Nachricht aus.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    showError('Die E-Mail-Adresse sieht nicht gültig aus. Bitte prüft sie noch einmal.');
}

// Datum kommt als JJJJ-MM-TT aus dem Datepicker, in der Mail lieber TT.MM.JJJJ
$eventDateText = 'nicht angegeben';
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $eventDate, $m)) {
    $eventDateText = "$m[3].$m[2].$m[1]";
} elseif ($eventDate !== '') {
    $eventDateText = $eventDate;
}

$subject = 'Neue Anfrage über trauredenbysophie.de von ' . $name;

$body = "Neue Anfrage über das Kontaktformular\n"
      . "=====================================\n\n"
      . "Name:     $name\n"
      . "E-Mail:   $email\n"
      . "Telefon:  $phone\n"
      . "Datum:    $eventDateText\n\n"
      . "Nachricht:\n"
      . "$message\n";

$headers = [
    'From' => mb_encode_mimeheader($senderName, 'UTF-8', 'B') . " <$sender>",
    'Reply-To' => mb_encode_mimeheader($name, 'UTF-8', 'B') . " <$email>",
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding' => '8bit',
    'X-Mailer' => 'trauredenbysophie.de Kontaktformular',
];

$sent = mail(
    $recipient,
    mb_encode_mimeheader($subject, 'UTF-8', 'B'),
    $body,
    $headers,
    '-f' . $sender
);

if (!$sent) {
    error_log('send-contact: mail() fehlgeschlagen');
    showError('Eure Nachricht konnte gerade nicht verschickt werden. Bitte versucht es gleich noch einmal oder schreibt direkt an ' . $recipient . '.');
}

header('Location: ' . $thanksPage);
exit;
