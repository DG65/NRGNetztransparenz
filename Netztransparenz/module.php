<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/NetztransparenzClient.php';
require_once __DIR__ . '/../libs/NegativePriceCache.php';

/**
 * Zentraler, ratenbegrenzter Zugriff auf die Netztransparenz.de-WebAPI für den
 * gesamten NRG-Stack-Verbund (DVHub, Szenariorechner, künftig EMS). Grund für die
 * Zentralisierung: die API erlaubt nur 2 Anfragen/Sekunde/IP (2 h Sperre bei
 * Überschreitung) — mehrere unabhängig pollende Konsumenten von derselben Adresse
 * könnten sich gegenseitig aussperren. Siehe CLAUDE.md.
 */
class Netztransparenz extends IPSModule
{
    /** Mindestabstand zwischen zwei echten HTTP-Anfragen an die API (2/s-Limit, mit Sicherheitsabstand). */
    private const MIN_REQUEST_INTERVAL_MS = 600;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('ClientIdInput', '');
        $this->RegisterPropertyString('ClientSecretInput', '');
        $this->RegisterAttributeString('ClientId', '');
        $this->RegisterAttributeString('ClientSecret', '');
        $this->RegisterAttributeString('NegativePreiseCache', '{}');
        $this->RegisterAttributeInteger('NegativePreiseCacheUpdated', 0);
        $this->RegisterAttributeInteger('LastRequestAtMs', 0);
        $this->RegisterAttributeInteger('LastTestSuccess', 0);
        $this->RegisterAttributeString('LastTestError', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->takeOverCredentials()) {
            return; // ApplyChanges läuft durch takeOverCredentials() erneut
        }
        $this->SetStatus($this->hasCredentials() ? 102 : 201);
    }

    /**
     * Übernimmt einmalig eingegebene Zugangsdaten aus den Property-Eingabefeldern in
     * die Attribute und leert die Eingabefelder danach wieder — Muster identisch zu
     * MeterHubs Inexogy-Anbindung (Handshake, Secret landet nie dauerhaft in einer
     * sichtbaren Property). True, wenn etwas übernommen wurde (dann läuft ApplyChanges
     * über IPS_SetProperty/IPS_ApplyChanges erneut).
     */
    private function takeOverCredentials(): bool
    {
        $id = $this->ReadPropertyString('ClientIdInput');
        $secret = $this->ReadPropertyString('ClientSecretInput');
        if ($id === '' && $secret === '') {
            return false;
        }
        if ($id !== '') {
            $this->WriteAttributeString('ClientId', $id);
        }
        if ($secret !== '') {
            $this->WriteAttributeString('ClientSecret', $secret);
        }
        IPS_SetProperty($this->InstanceID, 'ClientIdInput', '');
        IPS_SetProperty($this->InstanceID, 'ClientSecretInput', '');
        @IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function hasCredentials(): bool
    {
        return $this->ReadAttributeString('ClientId') !== '' && $this->ReadAttributeString('ClientSecret') !== '';
    }

    /**
     * Ob die angegebene Stunde eine amtlich bestätigte negative Preis-Stunde ist
     * (EEG § 51: kein Vergütungsanspruch), nach der aktuell gültigen Regel. Bedient
     * sich aus dem Cache, wenn frisch genug — sonst wird EIN Fenster um den
     * angefragten Zeitpunkt nachgeladen (nicht bei jedem Aufruf einzeln).
     *
     * Fail-safe: liefert `false`, wenn die Abfrage fehlschlägt (kein Zugang, API nicht
     * erreichbar). Begründung: eine nicht verifizierte negative-Preis-Behauptung wird
     * nicht stillschweigend als Rechtsanspruch unterstellt — im Zweifel zählt die
     * Abregelung als kommerziell (Next), nicht als unbezahlbares EEG-Ereignis. Loggt
     * den Fehler, bricht aber nicht ab.
     */
    public function IsNegativePriceHour(int $unixTimestamp): bool
    {
        $now = time();
        $cache = json_decode($this->ReadAttributeString('NegativePreiseCache'), true) ?: [];
        $lastUpdated = $this->ReadAttributeInteger('NegativePreiseCacheUpdated');

        $cached = NTP_NegativePriceCache::lookup($cache, $unixTimestamp);
        if ($cached !== null && !NTP_NegativePriceCache::needsRefresh($lastUpdated, $now)) {
            return $cached;
        }

        if (!$this->hasCredentials()) {
            $this->LogMessage('Netztransparenz: keine Zugangsdaten hinterlegt.', KL_WARNING);
            return $cached ?? false;
        }

        try {
            $from = (new \DateTimeImmutable('@' . ($unixTimestamp - 2 * 24 * 3600)))->setTimezone(new \DateTimeZone('UTC'));
            $to = (new \DateTimeImmutable('@' . ($unixTimestamp + 24 * 3600)))->setTimezone(new \DateTimeZone('UTC'));
            $fresh = $this->client()->fetchCurrentNegativePreise($from, $to);
            $cache = NTP_NegativePriceCache::merge($cache, $fresh, $now);
            $this->WriteAttributeString('NegativePreiseCache', json_encode($cache));
            $this->WriteAttributeInteger('NegativePreiseCacheUpdated', $now);
            $this->WriteAttributeInteger('LastTestSuccess', $now);
            $this->WriteAttributeString('LastTestError', '');
            return NTP_NegativePriceCache::lookup($cache, $unixTimestamp) ?? false;
        } catch (\Throwable $e) {
            $this->WriteAttributeString('LastTestError', $e->getMessage());
            $this->LogMessage('Netztransparenz: ' . $e->getMessage(), KL_WARNING);
            return $cached ?? false;
        }
    }

    /**
     * Historische Regelvariante gezielt abfragen (für rückwirkende Auswertungen über
     * mehrere Jahre, z. B. Szenariorechner) — bewusst NICHT gecacht wie
     * IsNegativePriceHour(), da hier beliebige, potenziell weit auseinanderliegende
     * Zeiträume abgefragt werden, für die ein Stunden-Cache wenig bringt.
     *
     * @return string JSON [ 'YYYY-MM-DD HH:00' => bool ]
     */
    public function GetHistoricalNegativePreise(int $logic, int $fromTimestamp, int $toTimestamp): string
    {
        if (!$this->hasCredentials()) {
            return json_encode(['error' => 'keine Zugangsdaten hinterlegt']);
        }
        try {
            $from = (new \DateTimeImmutable('@' . $fromTimestamp))->setTimezone(new \DateTimeZone('UTC'));
            $to = (new \DateTimeImmutable('@' . $toTimestamp))->setTimezone(new \DateTimeZone('UTC'));
            $result = $this->client()->fetchNegativePreise($logic, $from, $to);
            return json_encode($result);
        } catch (\Throwable $e) {
            $this->LogMessage('Netztransparenz: ' . $e->getMessage(), KL_WARNING);
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /** @return string JSON {"connected": bool, "lastSuccess": int, "lastError": string} */
    public function GetConnectionState(): string
    {
        return json_encode([
            'connected' => $this->hasCredentials() && $this->ReadAttributeString('LastTestError') === '',
            'lastSuccess' => $this->ReadAttributeInteger('LastTestSuccess'),
            'lastError' => $this->ReadAttributeString('LastTestError'),
        ]);
    }

    private function client(): NTP_Client
    {
        return new NTP_Client(
            $this->ReadAttributeString('ClientId'),
            $this->ReadAttributeString('ClientSecret'),
            fn(string $url, array $fields) => $this->httpPost($url, $fields),
            fn(string $url, array $headers) => $this->httpGet($url, $headers)
        );
    }

    /** Erzwingt den Mindestabstand zwischen zwei echten HTTP-Anfragen (2/s-API-Limit). */
    private function throttle(): void
    {
        $lastMs = $this->ReadAttributeInteger('LastRequestAtMs');
        $nowMs = (int) (microtime(true) * 1000);
        $waitMs = self::MIN_REQUEST_INTERVAL_MS - ($nowMs - $lastMs);
        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }
        $this->WriteAttributeInteger('LastRequestAtMs', (int) (microtime(true) * 1000));
    }

    private function httpPost(string $url, array $fields): array
    {
        $this->throttle();
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($fields),
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = $this->statusFromResponseHeaders($http_response_header ?? []);
        return ['status' => $status, 'body' => $body === false ? '' : $body];
    }

    private function httpGet(string $url, array $headers): array
    {
        $this->throttle();
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = $this->statusFromResponseHeaders($http_response_header ?? []);
        return ['status' => $status, 'body' => $body === false ? '' : $body];
    }

    private function statusFromResponseHeaders(array $responseHeaders): int
    {
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}
