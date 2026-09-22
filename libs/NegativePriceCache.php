<?php

declare(strict_types=1);

/**
 * Reine Cache-Logik für die "Negative Preise"-Stundenwerte — getrennt vom eigentlichen
 * HTTP-Client, damit Auffrischungs-/Zusammenführungs-/Nachschlage-Entscheidungen ohne
 * IPS und ohne Netzwerk testbar sind. Grund für den Cache überhaupt: die
 * Netztransparenz-API erlaubt nur 2 Anfragen/Sekunde/IP (2 h Sperre bei Überschreitung)
 * — bei mehreren Verbund-Modulen als Konsumenten (DVHub, Szenariorechner, künftig EMS)
 * darf nicht jedes davon unabhängig pollen, sonst sperren sie sich gegenseitig aus.
 * Genau deshalb lebt dieser Cache zentral in diesem Modul, nicht bei den Konsumenten.
 */
class NTP_NegativePriceCache
{
    /** Deckt sich mit dem API-eigenen Aktualisierungszyklus (1-mal täglich bzw. stündlich neue Daten). */
    public const FRESHNESS_SECONDS = 900; // 15 Minuten

    /** Cache-Einträge älter als das werden beim Zusammenführen verworfen (kein unbegrenztes Wachstum). */
    public const MAX_AGE_SECONDS = 60 * 24 * 3600; // 60 Tage

    public static function needsRefresh(int $lastUpdated, int $now): bool
    {
        return ($now - $lastUpdated) >= self::FRESHNESS_SECONDS;
    }

    /** API liefert/erwartet UTC-Stunden. */
    public static function hourKey(int $unixTimestamp): string
    {
        return gmdate('Y-m-d H:00', $unixTimestamp);
    }

    /**
     * Führt frisch abgerufene Werte in den bestehenden Cache ein (neue Werte gewinnen
     * bei Überschneidung) und entfernt Einträge älter als MAX_AGE_SECONDS.
     *
     * @param array $existing [ 'YYYY-MM-DD HH:00' => bool ]
     * @param array $fresh [ 'YYYY-MM-DD HH:00' => bool ]
     */
    public static function merge(array $existing, array $fresh, int $now): array
    {
        $merged = array_merge($existing, $fresh);
        $cutoffKey = self::hourKey($now - self::MAX_AGE_SECONDS);
        foreach (array_keys($merged) as $key) {
            if ($key < $cutoffKey) {
                unset($merged[$key]);
            }
        }
        return $merged;
    }

    /** @return bool|null null = kein Cache-Eintrag für diese Stunde vorhanden. */
    public static function lookup(array $cache, int $unixTimestamp): ?bool
    {
        $key = self::hourKey($unixTimestamp);
        return array_key_exists($key, $cache) ? $cache[$key] : null;
    }
}
