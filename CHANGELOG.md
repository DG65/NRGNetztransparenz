# Changelog

## 0.1.0 (22.09.2026)

- Repo angelegt, aus DVHub extrahiert und verallgemeinert (dort ursprünglich als
  eigener Client gebaut, dann aus Rate-Limit-Gründen als eigenständiges,
  verbundweites Modul ausgelagert — Entscheidung mit Dietmar am selben Tag).
  `NTP_Client` gegen die öffentliche Swagger-UI live verifiziert (nicht nur PDF-Doku):
  korrekte Pfadsegment-URLs, vollständige `logic`-Werteliste (1,2,3,4,6,15).
- Neues, IPS-freies `NTP_NegativePriceCache`: entscheidet Auffrischung/Zusammenführung
  der Stunden-Cache, Grundlage für die Ratenbegrenzung.
- `Netztransparenz`-Hauptmodul (Prefix `NTP`): Zugangsdaten-Handshake (Muster wie
  MeterHubs Inexogy-Anbindung), `IsNegativePriceHour()` (gecacht, Fail-safe `false`),
  `GetHistoricalNegativePreise()`, `GetConnectionState()`, Drosselung (>= 600 ms
  zwischen echten HTTP-Anfragen).
- 43 Tests (`cache_test.php`, `client_test.php`, `module_test.php`), alle grün.
- Noch offen: Migration von Szenariorechners bereits vorhandenen Zugangsdaten/
  Infrastruktur hierher (Abstimmung mit der Szenariorechner-Sitzung aussteht), DVHub
  auf dieses Modul umstellen statt eigenem Client, Live-Test mit echten Zugangsdaten.
