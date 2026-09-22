<?php

declare(strict_types=1);

require __DIR__ . '/../libs/NegativePriceCache.php';

$fail = 0;
function t(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}

$now = strtotime('2024-01-15T12:00:00Z');

t('hourKey formatiert auf volle UTC-Stunde', NTP_NegativePriceCache::hourKey(strtotime('2024-01-15T13:45:22Z')) === '2024-01-15 13:00');

t('needsRefresh: frisch (< 15 Min) braucht kein Update', !NTP_NegativePriceCache::needsRefresh($now - 300, $now));
t('needsRefresh: älter als 15 Min braucht Update', NTP_NegativePriceCache::needsRefresh($now - 901, $now));
t('needsRefresh: niemals aktualisiert (0) braucht Update', NTP_NegativePriceCache::needsRefresh(0, $now));

$cache = ['2024-01-15 10:00' => true, '2024-01-15 11:00' => false];
$fresh = ['2024-01-15 11:00' => true, '2024-01-15 12:00' => true]; // 11:00 wird überschrieben
$merged = NTP_NegativePriceCache::merge($cache, $fresh, $now);
t('merge: neue Werte gewinnen bei Überschneidung', $merged['2024-01-15 11:00'] === true);
t('merge: unveränderte alte Werte bleiben', $merged['2024-01-15 10:00'] === true);
t('merge: neue Werte kommen dazu', $merged['2024-01-15 12:00'] === true);

$oldEntry = ['2020-01-01 00:00' => true, '2024-01-15 10:00' => false];
$prunedMerge = NTP_NegativePriceCache::merge($oldEntry, [], $now);
t('merge: sehr alte Einträge (> 60 Tage) werden entfernt', !array_key_exists('2020-01-01 00:00', $prunedMerge));
t('merge: aktuelle Einträge bleiben beim Aufräumen erhalten', array_key_exists('2024-01-15 10:00', $prunedMerge));

t('lookup: vorhandene Stunde liefert den Wert', NTP_NegativePriceCache::lookup($cache, strtotime('2024-01-15T10:30:00Z')) === true);
t('lookup: fehlende Stunde liefert null', NTP_NegativePriceCache::lookup($cache, strtotime('2024-01-16T10:30:00Z')) === null);

echo "\n" . ($fail === 0 ? 'Alle Prüfungen bestanden.' : "$fail Prüfung(en) fehlgeschlagen.") . "\n";
exit($fail > 0 ? 1 : 0);
