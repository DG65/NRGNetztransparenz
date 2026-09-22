# NRG-Stack Netztransparenz

Zentraler, ratenbegrenzter Zugriff auf die Netztransparenz.de-WebAPI für den gesamten
NRG-Stack-Verbund — insbesondere negative Spotmarktpreise nach EEG § 51 (Zeiten ohne
Vergütungsanspruch).

## Warum ein eigenes, zentrales Modul?

Die Netztransparenz-API erlaubt nur 2 Anfragen/Sekunde/IP, bei Überschreitung eine
2-Stunden-Sperre. Mehrere Verbund-Module (DVHub, Szenariorechner, künftig EMS), die
unabhängig voneinander von derselben Adresse pollen, könnten sich gegenseitig aussperren.
Dieses Modul bündelt Zugangsdaten, Token-Verwaltung, Drosselung und einen Stunden-Cache
an einer Stelle; andere Module fragen nur noch diese Instanz ab.

## Vertrag (für andere NRG-Stack-Module)

- `NTP_IsNegativePriceHour($id, int $unixTimestamp): bool` — ist diese Stunde nach der
  aktuell gültigen Regel eine negative Preis-Stunde? Gecacht, ratenbegrenzt. Fail-safe:
  `false` bei fehlenden Zugangsdaten oder API-Fehler (keine unbestätigte Behauptung
  eines Rechtsanspruchs).
- `NTP_GetHistoricalNegativePreise($id, int $logic, int $fromTimestamp, int $toTimestamp): string`
  (JSON) — gezielte historische Regelvariante (1/2/3/4/6/15, siehe `NTP_Client::GUELTIGE_LOGIC_WERTE`)
  für rückwirkende Auswertungen.
- `NTP_GetConnectionState($id): string` (JSON) — Verbindungsstatus für Konsumenten.

Details und Herleitung: [CLAUDE.md](CLAUDE.md).

## Status

Frühe Entwicklungsphase (0.1.0). Rechenkern und Modul-Logik fertig und getestet
(`php .tests/cache_test.php`, `php .tests/client_test.php`, `php .tests/module_test.php`
— 43 Prüfungen, alle grün). Noch nicht live gegen echte Zugangsdaten getestet.

## Lizenz

Private und nicht-kommerzielle Nutzung frei, gewerbliche Nutzung lizenzpflichtig —
siehe [LICENSE](LICENSE).
