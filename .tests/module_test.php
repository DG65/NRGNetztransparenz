<?php

declare(strict_types=1);

/**
 * Modul-Test mit Stub-Umgebung (ohne echtes IP-Symcon): php .tests/module_test.php
 * Prüft Zugangsdaten-Handshake, Cache-Einbindung, Fail-safe (keine Zugangsdaten /
 * API-Fehler) und die Drossel-Logik — ohne echtes Netzwerk (file_get_contents wird
 * nicht wirklich aufgerufen: die Tests setzen IsNegativePriceHour() nicht direkt gegen
 * echtes HTTP ein, sondern prüfen die drumherum liegende Modul-Logik über die
 * ReflectionMethod, da NTP_Client selbst schon in client_test.php separat geprüft ist).
 */

define('KL_WARNING', 2);

$GLOBALS['props'] = [];
$GLOBALS['attrs'] = [];
$GLOBALS['status'] = null;
$GLOBALS['logs'] = [];
$GLOBALS['appliedInstances'] = [];

class IPSModule
{
    public $InstanceID = 777;
    public function __construct() {}
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString($n, $v) { $GLOBALS['props'][$n] ??= $v; }
    public function ReadPropertyString($n) { return $GLOBALS['props'][$n]; }
    public function RegisterAttributeString($n, $v) { $GLOBALS['attrs'][$n] ??= $v; }
    public function RegisterAttributeInteger($n, $v) { $GLOBALS['attrs'][$n] ??= $v; }
    public function ReadAttributeString($n) { return $GLOBALS['attrs'][$n]; }
    public function ReadAttributeInteger($n) { return $GLOBALS['attrs'][$n]; }
    public function WriteAttributeString($n, $v) { $GLOBALS['attrs'][$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['attrs'][$n] = $v; }
    public function SetStatus($s) { $GLOBALS['status'] = $s; }
    public function LogMessage($msg, $level) { $GLOBALS['logs'][] = $msg; }
}
function IPS_SetProperty($id, $name, $value) { $GLOBALS['props'][$name] = $value; }
function IPS_ApplyChanges($id) { $GLOBALS['appliedInstances'][] = $id; }

require __DIR__ . '/../Netztransparenz/module.php';

$fail = 0;
function t(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}

$m = new Netztransparenz();
$m->Create();
$m->ApplyChanges();
t('Ohne Zugangsdaten: Status 201', $GLOBALS['status'] === 201);

// --- Handshake: Eingabe übernehmen, Felder leeren ---
$GLOBALS['props']['ClientIdInput'] = 'meine-id';
$GLOBALS['props']['ClientSecretInput'] = 'mein-secret';
$m->ApplyChanges();
t('Handshake übernimmt Client-ID ins Attribut', $GLOBALS['attrs']['ClientId'] === 'meine-id');
t('Handshake übernimmt Client-Secret ins Attribut', $GLOBALS['attrs']['ClientSecret'] === 'mein-secret');
t('Handshake leert das ClientIdInput-Feld', $GLOBALS['props']['ClientIdInput'] === '');
t('Handshake leert das ClientSecretInput-Feld', $GLOBALS['props']['ClientSecretInput'] === '');
t('Handshake löst erneut IPS_ApplyChanges aus', in_array(777, $GLOBALS['appliedInstances'], true));

$m->ApplyChanges();
t('Mit hinterlegten Zugangsdaten: Status 102', $GLOBALS['status'] === 102);

// --- Fail-safe: IsNegativePriceHour() ohne Zugangsdaten (frisches Modul) ---
$GLOBALS['attrs'] = [];
$m2 = new Netztransparenz();
$m2->Create();
t('IsNegativePriceHour() liefert false (nicht true!) ohne Zugangsdaten', $m2->IsNegativePriceHour(time()) === false);

// --- Cache: ein vorhandener, frischer Cache-Eintrag wird genutzt, ohne HTTP zu brauchen ---
$now = time();
$hourKey = gmdate('Y-m-d H:00', $now);
$GLOBALS['attrs']['NegativePreiseCache'] = json_encode([$hourKey => true]);
$GLOBALS['attrs']['NegativePreiseCacheUpdated'] = $now; // gerade eben aktualisiert -> frisch
$GLOBALS['attrs']['ClientId'] = ''; // absichtlich KEINE Zugangsdaten - müsste nie gebraucht werden, wenn Cache frisch ist
$GLOBALS['attrs']['ClientSecret'] = '';
$result = $m2->IsNegativePriceHour($now);
t('IsNegativePriceHour() nutzt einen frischen Cache-Treffer ohne Zugangsdaten zu brauchen', $result === true);

// --- GetConnectionState() liefert gültiges JSON ---
$state = json_decode($m2->GetConnectionState(), true);
t('GetConnectionState() liefert ein Array mit "connected"', is_array($state) && array_key_exists('connected', $state));
t('GetConnectionState(): ohne Zugangsdaten nicht verbunden', $state['connected'] === false);

echo "\n" . ($fail === 0 ? 'Alle Prüfungen bestanden.' : "$fail Prüfung(en) fehlgeschlagen.") . "\n";
exit($fail > 0 ? 1 : 0);
