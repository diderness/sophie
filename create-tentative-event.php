<?php
// Legt bei einer Formular-Anfrage automatisch einen VORLÄUFIGEN Termin im
// Google-Kalender an (Service-Account-Zugriff). Sophie muss die Anfrage
// noch bestätigen oder den Eintrag wieder löschen.

header('Content-Type: application/json; charset=utf-8');

$calendarId = '70741a063095350b1d01c468a45559afe7d36ac49730ce26d402ebd2a8ccaad9@group.calendar.google.com';
$serviceAccountFile = __DIR__ . '/secrets/trauredenbysophie-fafd5340cea0.json';
$allowedOrigin = 'trauredenbysophie.de';

function fail($message) {
    error_log('create-tentative-event: ' . $message);
    echo json_encode(['ok' => false]);
    exit;
}

function ok() {
    echo json_encode(['ok' => true]);
    exit;
}

// Grobe Missbrauchsbremse: nur Anfragen mit passendem Referer akzeptieren
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '' && strpos($referer, $allowedOrigin) === false) {
    fail('unexpected referer');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fail('invalid payload');
}

$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');
$eventDate = trim($input['event-date'] ?? '');
$message = trim($input['message'] ?? '');

if ($name === '' || $message === '') {
    fail('missing required fields');
}

// Ohne gewähltes Datum gibt es nichts zu blocken - kein Fehler, einfach nichts tun
if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    ok();
}

if (!file_exists($serviceAccountFile)) {
    fail('service account file missing');
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getAccessToken($serviceAccountFile) {
    $credentials = json_decode(file_get_contents($serviceAccountFile), true);
    if (!$credentials || !isset($credentials['client_email'], $credentials['private_key'], $credentials['token_uri'])) {
        return null;
    }

    $now = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64UrlEncode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.events',
        'aud' => $credentials['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
    ]));

    $signatureInput = $header . '.' . $claims;
    $signature = '';
    $signed = openssl_sign($signatureInput, $signature, $credentials['private_key'], 'sha256WithRSAEncryption');
    if (!$signed) {
        return null;
    }
    $jwt = $signatureInput . '.' . base64UrlEncode($signature);

    $ch = curl_init($credentials['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

$accessToken = getAccessToken($serviceAccountFile);
if (!$accessToken) {
    fail('could not obtain access token');
}

$endDate = (new DateTime($eventDate))->modify('+1 day')->format('Y-m-d');

$descriptionLines = [
    'Automatisch erstellte Anfrage über das Kontaktformular - bitte bestätigen oder löschen.',
    '',
    'Name: ' . $name,
];
if ($email !== '') $descriptionLines[] = 'E-Mail: ' . $email;
if ($phone !== '') $descriptionLines[] = 'Telefon: ' . $phone;
$descriptionLines[] = '';
$descriptionLines[] = 'Nachricht:';
$descriptionLines[] = $message;

$event = [
    'summary' => 'Anfrage (unbestätigt): ' . $name,
    'description' => implode("\n", $descriptionLines),
    'start' => ['date' => $eventDate],
    'end' => ['date' => $endDate],
    'colorId' => '5', // Banane/Gelb - optisch als vorläufig erkennbar
];

$ch = curl_init('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($event),
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    fail('calendar insert failed: ' . $response);
}

ok();
