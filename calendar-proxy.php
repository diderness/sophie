<?php
// Liest den privaten Google-Kalender-Feed serverseitig aus und liefert
// nur die belegten Tage (keine Titel/Details) als JSON an das Frontend.
// Der geheime Kalenderlink bleibt dadurch serverseitig verborgen.

header('Content-Type: application/json; charset=utf-8');

$icalUrl = 'https://calendar.google.com/calendar/ical/70741a063095350b1d01c468a45559afe7d36ac49730ce26d402ebd2a8ccaad9%40group.calendar.google.com/private-504bae892134f838cbee132193b29ffd/basic.ics';
$cacheFile = __DIR__ . '/cache/booked-dates-cache.json';
$cacheTtl = 900; // 15 Minuten

function respond($bookedDates) {
    echo json_encode(['bookedDates' => array_values($bookedDates)]);
    exit;
}

function readCache($cacheFile) {
    if (!file_exists($cacheFile)) {
        return null;
    }
    $cached = json_decode(file_get_contents($cacheFile), true);
    return is_array($cached) ? $cached : null;
}

function fetchIcal($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($data !== false && $httpCode === 200) ? $data : null;
    }
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $data = @file_get_contents($url, false, $context);
    return $data !== false ? $data : null;
}

function unfoldIcs($raw) {
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    // RFC 5545: fortgesetzte Zeilen beginnen mit einem Leerzeichen/Tab
    return preg_replace("/\n[ \t]/", '', $raw);
}

function normalizeIcsDate($value) {
    $value = trim($value);
    $datePart = substr($value, 0, 8);
    if (strlen($datePart) !== 8) {
        return null;
    }
    return substr($datePart, 0, 4) . '-' . substr($datePart, 4, 2) . '-' . substr($datePart, 6, 2);
}

function parseBookedDates($ics) {
    $ics = unfoldIcs($ics);
    $lines = explode("\n", $ics);

    $dates = [];
    $inEvent = false;
    $dtStart = null;
    $dtStartIsDate = false;
    $dtEnd = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === 'BEGIN:VEVENT') {
            $inEvent = true;
            $dtStart = null;
            $dtEnd = null;
            $dtStartIsDate = false;
            continue;
        }

        if ($line === 'END:VEVENT') {
            if ($dtStart !== null) {
                try {
                    $current = new DateTime($dtStart);
                    $endDate = new DateTime($dtEnd ?: $dtStart);
                    if ($dtStartIsDate && $dtEnd !== null) {
                        // DTEND ist bei ganztägigen Terminen exklusiv
                        $endDate->modify('-1 day');
                    }
                    if (!$dtStartIsDate) {
                        // Terminierter Termin: nur den Starttag als belegt markieren
                        $endDate = clone $current;
                    }
                    $guard = 0;
                    while ($current <= $endDate && $guard < 730) {
                        $dates[$current->format('Y-m-d')] = true;
                        $current->modify('+1 day');
                        $guard++;
                    }
                } catch (Exception $e) {
                    // Ungültiges Datum im Feed ignorieren
                }
            }
            $inEvent = false;
            continue;
        }

        if (!$inEvent) {
            continue;
        }

        if (strpos($line, 'DTSTART') === 0) {
            $parts = explode(':', $line, 2);
            $meta = $parts[0];
            $value = $parts[1] ?? '';
            $dtStartIsDate = strpos($meta, 'VALUE=DATE') !== false && strpos($meta, 'VALUE=DATE-TIME') === false;
            $dtStart = normalizeIcsDate($value);
        } elseif (strpos($line, 'DTEND') === 0) {
            $parts = explode(':', $line, 2);
            $value = $parts[1] ?? '';
            $dtEnd = normalizeIcsDate($value);
        }
    }

    return array_keys($dates);
}

// Frischen Cache direkt ausliefern (per ?refresh=1 erzwingbar überspringen)
$forceRefresh = isset($_GET['refresh']);
if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = readCache($cacheFile);
    if ($cached !== null) {
        respond($cached);
    }
}

$raw = fetchIcal($icalUrl);

if ($raw === null) {
    // Google nicht erreichbar: auf alten Cache zurückfallen statt Fehler zu zeigen
    $cached = readCache($cacheFile);
    respond($cached ?? []);
}

$bookedDates = parseBookedDates($raw);

$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
@file_put_contents($cacheFile, json_encode($bookedDates));

respond($bookedDates);
