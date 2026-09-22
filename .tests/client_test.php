<?php

declare(strict_types=1);

require __DIR__ . '/../libs/NetztransparenzClient.php';

$failures = 0;
$checks = 0;

function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
        echo "FEHLER: $label — erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    } else {
        echo "OK: $label\n";
    }
}

// --- CSV-Parser ---
$csv = "Datum;Negativ\n2022-06-10 13:00;Ja\n2022-06-10 14:00;Ja\n2022-06-10 15:00;Nein\n";
$parsed = NTP_Client::parseNegativePreiseCsv($csv);
check('CSV-Parser: 3 Zeilen erkannt', count($parsed), 3);
check('CSV-Parser: "Ja" wird true', $parsed['2022-06-10 13:00'], true);
check('CSV-Parser: "Nein" wird false', $parsed['2022-06-10 15:00'], false);

// --- Token-Handling (gemockter HTTP-POST) ---
$postCalls = [];
$getCalls = [];
$fakePost = function (string $url, array $fields) use (&$postCalls) {
    $postCalls[] = [$url, $fields];
    return ['status' => 200, 'body' => json_encode(['access_token' => 'TOKEN-1', 'expires_in' => 3600])];
};
$fakeGet = function (string $url, array $headers) use (&$getCalls) {
    $getCalls[] = [$url, $headers];
    return ['status' => 200, 'body' => "Datum;Negativ\n2024-01-15 03:00;Ja\n2024-01-15 04:00;Nein\n"];
};

$client = new NTP_Client('mein-client-id', 'mein-secret', $fakePost, $fakeGet);
$token = $client->getAccessToken(1000);
check('getAccessToken liefert das Token aus der Antwort', $token, 'TOKEN-1');
check('getAccessToken ruft den korrekten Token-Endpunkt auf', $postCalls[0][0], 'https://identity.netztransparenz.de/users/connect/token');
check('getAccessToken sendet client_credentials-Grant', $postCalls[0][1]['grant_type'], 'client_credentials');

$client->getAccessToken(1500); // noch innerhalb der 1h Gültigkeit
check('getAccessToken cacht das Token innerhalb der Gültigkeit (kein zweiter POST)', count($postCalls), 1);

$client->getAccessToken(1000 + 3600 + 1); // nach Ablauf
check('getAccessToken holt nach Ablauf ein neues Token', count($postCalls), 2);

// --- fetchNegativePreise: Pfadsegmente, kein Query-String ---
$result = $client->fetchNegativePreise(4, new DateTimeImmutable('2024-01-15T00:00:00'), new DateTimeImmutable('2024-01-16T00:00:00'));
check('fetchNegativePreise liefert das geparste Ergebnis', $result, ['2024-01-15 03:00' => true, '2024-01-15 04:00' => false]);
check(
    'fetchNegativePreise baut die URL als Pfadsegmente logic/dateFrom/dateTo',
    end($getCalls)[0],
    'https://ds.netztransparenz.de/api/v1/data/NegativePreise/4/2024-01-15T00%3A00%3A00/2024-01-16T00%3A00%3A00'
);
check('fetchNegativePreise übergibt den Bearer-Token', end($getCalls)[1][0], 'Authorization: Bearer TOKEN-1');

foreach (NTP_Client::GUELTIGE_LOGIC_WERTE as $logic) {
    try {
        $client->fetchNegativePreise($logic, new DateTimeImmutable(), new DateTimeImmutable());
        check("fetchNegativePreise akzeptiert gültige Regelvariante $logic", true, true);
    } catch (\InvalidArgumentException $e) {
        check("fetchNegativePreise akzeptiert gültige Regelvariante $logic", false, true);
    }
}
try {
    $client->fetchNegativePreise(5, new DateTimeImmutable(), new DateTimeImmutable());
    check('fetchNegativePreise lehnt unbekannte Regelvariante ab', 'keine Ausnahme', 'InvalidArgumentException');
} catch (\InvalidArgumentException $e) {
    check('fetchNegativePreise lehnt unbekannte Regelvariante ab', 'InvalidArgumentException', 'InvalidArgumentException');
}

// --- fetchCurrentNegativePreise: kein logic-Segment ---
$currentResult = $client->fetchCurrentNegativePreise(new DateTimeImmutable('2024-01-15T00:00:00'), new DateTimeImmutable('2024-01-16T00:00:00'));
check('fetchCurrentNegativePreise liefert das geparste Ergebnis', $currentResult, ['2024-01-15 03:00' => true, '2024-01-15 04:00' => false]);
check(
    'fetchCurrentNegativePreise baut die URL ohne logic-Segment',
    end($getCalls)[0],
    'https://ds.netztransparenz.de/api/v1/data/NegativePreise/2024-01-15T00%3A00%3A00/2024-01-16T00%3A00%3A00'
);

// --- Fehlerfall ---
$failPost = fn($url, $fields) => ['status' => 401, 'body' => 'Unauthorized'];
$failClient = new NTP_Client('x', 'y', $failPost, $fakeGet);
try {
    $failClient->getAccessToken();
    check('getAccessToken wirft bei fehlgeschlagener Authentifizierung', 'keine Ausnahme', 'RuntimeException');
} catch (\RuntimeException $e) {
    check('getAccessToken wirft bei fehlgeschlagener Authentifizierung', 'RuntimeException', 'RuntimeException');
}

echo "\n$checks Prüfungen, $failures Fehler.\n";
exit($failures > 0 ? 1 : 0);
