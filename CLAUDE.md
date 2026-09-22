# Netztransparenz — Hinweise für die Arbeit an diesem Repository

## Entstehung

Entstanden am 22.09.2026 aus dem DVHub-Repo heraus (siehe dort `Solarpark-Neubau-Konzept.md`
und dessen CLAUDE.md für die volle Vorgeschichte: EEG-§51-Grund-Klassifikation für die
Solarpark-Hofweier-Abrechnung). Grund für die Auslagerung in ein eigenes, verbundweites
Modul statt eines DVHub-internen Clients: die Netztransparenz-API erlaubt nur 2
Anfragen/Sekunde/IP, sonst 2 h IP-Sperre — mehrere Verbund-Module (DVHub,
Szenariorechner, absehbar auch EMS), die unabhängig voneinander von derselben Adresse
pollen, würden sich gegenseitig aussperren können. Deshalb ein zentraler, gemeinsam
genutzter Zugriffspunkt mit eigenem Cache statt drei eigenständiger Clients.

## Live-Verifikation der API (wichtig, nicht aus der PDF-Doku allein übernehmen)

Die offizielle "Dokumentation WebAPI Netztransparenz" (PDF, Version 07.02.2025)
beschreibt `dateFrom`/`dateTo` als Query-Parameter. Das ist **veraltet** — live gegen
die öffentliche Swagger-UI geprüft (https://api-portal.netztransparenz.de/public-swagger-ui,
kein Login nötig, 22.09.2026): tatsächlich sind es **Pfadsegmente**. Korrekte Endpunkte:

- `GET /api/v1/data/NegativePreise/{logic}/{dateFrom}/{dateTo}` — historische
  Regelvariante explizit wählen. Gültige `logic`-Werte laut Swagger: **1, 2, 3, 4, 6,
  15** (mehr als die PDF-Doku vermuten ließ).
- `GET /api/v1/data/NegativePreise/{dateFrom}/{dateTo}` — **ohne** `logic`,
  "entitlement to remuneration according to the **hourly claim bases**". Wendet
  selbst die zum abgefragten Zeitpunkt aktuell gültige Regel an — das ist, was
  `NTP_IsNegativePriceHour()` nutzt (`NTP_Client::fetchCurrentNegativePreise()`).
  **Konsumenten brauchen dadurch keine EEG-Fassung-zu-`logic`-Zuordnung zu kennen**,
  solange sie nur die AKTUELLE Stunde klassifizieren wollen (DVHubs Anwendungsfall).

**Rechtliche Zuordnung Fassung -> `logic`-Zahl für historische Zeiträume: weiterhin
NICHT verifiziert.** Grober Anhaltspunkt aus dem Kontext (Anzahl/Reihenfolge der
Varianten passt zur bekannten Historie mehrfacher Verschärfungen: ehemals 6
aufeinanderfolgende Stunden -> 4 -> 3 -> seit 1.10.2025 kalenderstundenscharf), aber
ohne Abgleich mit dem tatsächlichen Gesetzestext nicht belastbar. Nur relevant für
`GetHistoricalNegativePreise()` (rückwirkende Auswertungen über Jahre, z. B.
Szenariorechners Förderende-Szenario) — vor Verwendung dafür recherchieren, nicht raten.

**Lehre, verbundweit gültig:** vor jeder neuen Endpunkt-Anbindung die Swagger-UI
gegenprüfen (identische Lehre bereits in Szenariorechners KONZEPT.md für den
`marktpraemie`-Endpunkt), nicht blind aus der PDF-Doku übernehmen — sie kann hinter der
Live-API zurückliegen.

## Architektur

`libs/NetztransparenzClient.php` (`NTP_Client`): IPS-freier HTTP-Client, Callables für
POST/GET injiziert (kein echtes Netzwerk in Tests). `libs/NegativePriceCache.php`
(`NTP_NegativePriceCache`): reine Cache-Logik (Auffrischung, Zusammenführung,
Aufräumen alter Einträge, Stunden-Schlüssel), ebenfalls IPS-frei. `Netztransparenz/module.php`:
verbindet beides mit echtem IPS (Zugangsdaten-Attribute, `file_get_contents` +
Stream-Context für HTTP — Muster identisch zu Szenariorechners
`getNetztransparenzToken()`, nicht neu erfunden), Drosselung (>= 600 ms zwischen
echten HTTP-Anfragen, `LastRequestAtMs`-Attribut).

## Zugangsdaten (Verbund-Konvention, siehe MeterHub-CLAUDE.md "Zugangsdaten bei
Cloud-/API-Zählern")

`ClientIdInput`/`ClientSecretInput` (Property, `PasswordTextBox` fürs Secret) werden
beim `ApplyChanges()` einmalig in `ClientId`/`ClientSecret` (Attribute, nicht Property)
übernommen und danach sofort geleert (`takeOverCredentials()`) — kein Klartext-Rest im
Formular. Kein Handshake-/Token-Verfahren möglich (echte Cloud-API, kein Peer-zu-Peer),
deshalb dauerhafte Speicherung nötig — wie bei Tibber/Inexogy.

**Dietmar hat bereits einen Zugang, aktuell in der Szenariorechner-Instanz hinterlegt.**
Noch offen: Migration dorthin oder neue eigene Zugangsdaten für dieses Modul — mit der
Szenariorechner-Sitzung abzustimmen, nicht eigenmächtig in deren Repo zu ändern.

## Fail-safe-Prinzip

`IsNegativePriceHour()` liefert `false`, nicht `true`, wenn Zugangsdaten fehlen oder die
Abfrage fehlschlägt. Begründung: Eine nicht verifizierte negative-Preis-Behauptung wird
nicht stillschweigend als Rechtsanspruch (keine Vergütung) unterstellt — im Zweifel gilt
eine Abregelung als kommerziell verursacht, nicht als unbezahlbares EEG-Ereignis. Ein
falsches `true` würde Konsumenten (z. B. DVHubs Grund-Klassifikation) dazu bringen, eine
Abregelung fälschlich als "kein Vergütungsanspruch" zu verbuchen und dadurch Geld zu
verlieren, das eigentlich berechtigt wäre.

## Nächste Schritte (Stand 22.09.2026)

1. Mit der Szenariorechner-Sitzung abstimmen: bestehende Zugangsdaten/Infrastruktur
   hierher migrieren, oder Szenariorechner ruft künftig `NTP_*` ab statt eigenem Client?
2. DVHub auf dieses Modul umstellen (`function_exists('NTP_IsNegativePriceHour')`-
   Aufruf statt eigenem `DVHUB_NetztransparenzClient`) — DVHubs Kopie war der
   Ausgangspunkt dieses Repos, sollte danach nicht dauerhaft parallel existieren.
3. Live-Test mit echten Zugangsdaten.
4. Rechtliche Fassung-zu-`logic`-Zuordnung verifizieren, sobald tatsächlich für eine
   historische Auswertung gebraucht (siehe oben).
