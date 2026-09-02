<?php

declare(strict_types=1);

/**
 * UniFi Access Controller
 *
 * Fragt einen UniFi Access Controller ueber die Developer-API ab, bildet alle Tueren
 * im Objektbaum ab und kann pro Tuer eine Sirene (Shelly 1 Gen4) ausloesen, wenn die
 * Tuer laenger als eine konfigurierbare Zeit offen steht.
 */
class UniFiAccessController extends IPSModule
{
    private const PROFILE_DURATION  = 'UAC.Duration';
    private const PROFILE_DOOR      = 'UAC.DoorOpen';
    private const PROFILE_ALARM     = 'UAC.Alarm';
    private const PROFILE_REACHABLE = 'UAC.Reachable';
    private const PROFILE_RELAY     = 'UAC.Relay';
    private const PROFILE_MISMATCH  = 'UAC.Mismatch';

    private const STATUS_ACTIVE    = 102;
    private const STATUS_INACTIVE  = 104;
    private const STATUS_NO_HOST   = 201;
    private const STATUS_NO_TOKEN  = 202;
    private const STATUS_API_ERROR = 203;

    private const FLOORPLAN_MODULE_ID = '{D282D828-200B-41CE-BA29-960F1DAB54F5}';

    private const DOOR_PREFIX         = 'Door_';
    private const SIREN_PREFIX        = 'Siren_';
    private const SIREN_SWITCH_PREFIX = 'SirenEnabled_';

    /* ===================================================================== */
    /* Lebenszyklus                                                          */
    /* ===================================================================== */

    public function Create()
    {
        parent::Create();

        // --- Controller ---
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 12445);
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyInteger('Timeout', 5000);
        $this->RegisterPropertyBoolean('VerifyTLS', false);

        // --- Sirene / Shelly ---
        $this->RegisterPropertyInteger('ShellyChannel', 0);
        $this->RegisterPropertyString('ShellyUser', 'admin');
        $this->RegisterPropertyString('ShellyPassword', '');
        $this->RegisterPropertyInteger('SirenMaxRuntime', 300);
        $this->RegisterPropertyInteger('SirenCheckInterval', 60);

        // --- Tueren ---
        $this->RegisterPropertyString('DoorSettings', '[]');
        $this->RegisterPropertyBoolean('RemoveOrphans', false);

        // --- Verlauf / Wartung ---
        $this->RegisterPropertyInteger('HistoryLimit', 200);
        $this->RegisterPropertyInteger('BypassReset', 0);
        $this->RegisterPropertyBoolean('ArchiveDoorStates', false);

        // --- interner Zustand ---
        // DoorState: { "<doorId>": {"ident":..,"name":..,"open":bool,"sensor":bool,"since":ts,"alarm":bool,"bypassSince":ts} }
        $this->RegisterAttributeString('DoorState', '{}');
        // SirenState: { "<host>|<channel>": {"on":bool,"since":ts,"muted":bool} } - Soll-Zustand
        $this->RegisterAttributeString('SirenState', '{}');
        // SirenStatus: { "<host>|<channel>": {"reachable":bool,"output":bool|null,"ms":float,"ts":int,"error":string} } - Ist-Zustand
        $this->RegisterAttributeString('SirenStatus', '{}');
        // EventLog: [ {"door":..,"name":..,"start":ts,"end":ts|0,"siren":bool}, ... ] neueste zuerst
        $this->RegisterAttributeString('EventLog', '[]');

        // Eigene Kachel in der Visualisierung (HTML-SDK)
        $this->SetVisualizationType(1);

        $this->RegisterTimer('Poll', 0, 'UAC_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('AlarmCheck', 0, 'UAC_CheckAlarms($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SirenCheck', 0, 'UAC_CheckSirens($_IPS[\'TARGET\']);');

        $this->RegisterProfiles();

        $this->RegisterVariableFloat('LastDuration', 'Letzte Abfragedauer', self::PROFILE_DURATION, 10);
        $this->RegisterVariableInteger('LastUpdate', 'Zeitpunkt der letzten Abfrage', '~UnixTimestamp', 20);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 30);
        $this->RegisterVariableInteger('LastErrorTime', 'Letzter Fehler (Zeitpunkt)', '~UnixTimestamp', 40);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('Poll', 0);
        $this->SetTimerInterval('AlarmCheck', 0);
        $this->SetTimerInterval('SirenCheck', 0);

        // Beim Systemstart erst weitermachen, wenn der Kernel bereit ist
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }
        if ($this->CleanHost($this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(self::STATUS_NO_HOST);
            return;
        }
        if (trim($this->ReadPropertyString('Token')) === '') {
            $this->SetStatus(self::STATUS_NO_TOKEN);
            return;
        }

        $this->SetStatus(self::STATUS_ACTIVE);

        // Erste Abfrage kurz nach dem Speichern, damit die Konfigurationsmaske nicht blockiert.
        // Poll() setzt den Timer danach auf das konfigurierte Intervall.
        $this->SetTimerInterval('Poll', 1000);

        // Shelly-Rueckfrage laeuft bewusst in einem eigenen Timer: ein nicht
        // erreichbarer Shelly darf die Tuerabfrage nicht ausbremsen.
        $sirenInterval = $this->ReadPropertyInteger('SirenCheckInterval');
        $this->SetTimerInterval('SirenCheck', $sirenInterval > 0 ? $sirenInterval * 1000 : 0);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->ApplyChanges();
        }
    }

    /* ===================================================================== */
    /* Oeffentliche Funktionen (UAC_*)                                       */
    /* ===================================================================== */

    /**
     * Fragt den Controller ab und aktualisiert den Objektbaum.
     */
    public function Poll(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(self::STATUS_INACTIVE);
            return false;
        }

        // Timer auf den konfigurierten Wert setzen (auch nach dem 1s-Erststart)
        $interval = $this->ReadPropertyInteger('Interval');
        $this->SetTimerInterval('Poll', $interval > 0 ? $interval * 1000 : 0);

        $error = '';
        $start = microtime(true);
        $doors = $this->ApiGetDoors($error);
        $duration = (microtime(true) - $start) * 1000;

        $this->SetValue('LastDuration', round($duration, 1));
        $this->SetValue('LastUpdate', time());

        if ($doors === null) {
            $this->SetValue('LastError', $error);
            $this->SetValue('LastErrorTime', time());
            $this->SetStatus(self::STATUS_API_ERROR);
            $this->LogMessage('UniFi Access Abfrage fehlgeschlagen: ' . $error, KL_ERROR);
            // Alarmlogik mit dem letzten bekannten Zustand weiterlaufen lassen
            $this->EvaluateAlarms();
            return false;
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        $this->UpdateDoors($doors);
        return true;
    }

    /**
     * Wird vom Alarm-Timer aufgerufen (sekundengenaue Auswertung der Offen-Dauer).
     */
    public function CheckAlarms(): void
    {
        $this->EvaluateAlarms();
    }

    /**
     * Fragt alle konfigurierten Shellys nach ihrem tatsaechlichen Relaiszustand.
     * Damit laesst sich pruefen, ob ein Schaltbefehl auch angekommen ist.
     */
    public function CheckSirens(): void
    {
        $this->QuerySirens();
    }

    /**
     * Wie CheckSirens, gibt das Ergebnis aber als Text aus (Button im Formular).
     */
    public function ShowSirenStatus(): void
    {
        $this->QuerySirens();

        $sirens = $this->BuildSirenPayload();
        if (count($sirens) === 0) {
            echo 'Es ist keine Shelly-Adresse konfiguriert.';
            return;
        }

        $out = '';
        foreach ($sirens as $siren) {
            $out .= ($siren['name'] !== '' ? $siren['name'] . ' - ' : '')
                . $siren['host'] . ' (Kanal ' . $siren['channel'] . ")\n";
            if (!$siren['reachable']) {
                $out .= '  NICHT ERREICHBAR: ' . $siren['error'] . "\n\n";
                continue;
            }
            $out .= '  Relais Ist:  ' . ($siren['output'] === null ? 'unbekannt' : ($siren['output'] ? 'EIN' : 'AUS')) . "\n";
            $out .= '  Relais Soll: ' . ($siren['target'] ? 'EIN' : 'AUS') . "\n";
            $out .= '  Antwortzeit: ' . $siren['ms'] . " ms\n";
            if ($siren['mismatch']) {
                $out .= "  ACHTUNG: Der Shelly hat den letzten Schaltbefehl nicht umgesetzt.\n";
            }
            $out .= "\n";
        }
        echo $out;
    }

    /**
     * Schaltbefehle aus der Visualisierung (Kachel oder Variable).
     */
    public function RequestAction($Ident, $Value)
    {
        if (strpos((string) $Ident, self::SIREN_SWITCH_PREFIX) === 0) {
            $this->SetSirenEnabledByIdent((string) $Ident, (bool) $Value);
            return;
        }

        switch ((string) $Ident) {
            case 'Refresh':
                $this->Poll();
                break;

            case 'ClearHistory':
                $this->ClearEventLog();
                break;

            case 'CheckSirens':
                $this->QuerySirens();
                break;

            case 'TestSiren':
                $data = json_decode((string) $Value, true);
                if (is_array($data)) {
                    $this->TestSiren((string) ($data['host'] ?? ''), !empty($data['on']));
                }
                break;

            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    /**
     * Liefert den Verlauf als JSON-Array (neueste Oeffnung zuerst).
     */
    public function GetEventLog(): string
    {
        return json_encode($this->ReadEventLog());
    }

    /**
     * Aktueller Zustand aller Tueren als JSON. Wird von der Grundriss-Instanz gelesen.
     */
    public function GetTileData(): string
    {
        return (string) json_encode($this->BuildPayload());
    }

    /**
     * Loescht den gespeicherten Verlauf.
     */
    public function ClearEventLog(): void
    {
        $this->WriteAttributeString('EventLog', '[]');
        $this->PushVisualization();
    }

    /**
     * Schaltet den Wartungsmodus einer Tuer per Skript.
     * $DoorID ist die Tuer-ID aus der UniFi-API.
     */
    public function SetSirenEnabled(string $DoorID, bool $Enabled): bool
    {
        $state = $this->ReadState();
        if (!isset($state[$DoorID])) {
            return false;
        }
        $varID = $this->FindSwitchVariable($DoorID);
        if ($varID <= 0) {
            return false;
        }
        SetValue($varID, $Enabled);
        $state[$DoorID]['bypassSince'] = $Enabled ? 0 : time();
        $this->WriteState($state);
        $this->EvaluateAlarms();
        return true;
    }

    /* ===================================================================== */
    /* Visualisierung (HTML-SDK)                                             */
    /* ===================================================================== */

    public function GetVisualizationTile()
    {
        $html = @file_get_contents(__DIR__ . '/tile.html');
        if ($html === false) {
            return '<div style="padding:12px">tile.html wurde nicht gefunden.</div>';
        }

        return str_replace('/*%%PAYLOAD%%*/null', (string) json_encode($this->BuildPayload()), $html);
    }

    private function PushVisualization(): void
    {
        $payload = $this->BuildPayload();

        // Die Kachel zaehlt laufende Offen-Dauern selbst hoch. Waehrend eine Tuer
        // offen ist laeuft EvaluateAlarms jede Sekunde - ohne diesen Vergleich
        // wuerde dabei sekuendlich das komplette Datenpaket verschickt.
        // "now" bleibt beim Hash aussen vor, sonst waere er nie identisch.
        $comparable = $payload;
        unset($comparable['now']);
        $hash = md5((string) json_encode($comparable));
        if ($this->GetBuffer('VisHash') === $hash) {
            return;
        }
        $this->SetBuffer('VisHash', $hash);

        $this->UpdateVisualizationValue((string) json_encode($payload));
        $this->NotifyFloorPlans();
    }

    /**
     * Stoesst alle Grundriss-Instanzen an, die auf diesen Controller zeigen.
     */
    private function NotifyFloorPlans(): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::FLOORPLAN_MODULE_ID) as $instanceID) {
            if (@IPS_GetProperty($instanceID, 'ControllerID') != $this->InstanceID) {
                continue;
            }
            if (function_exists('UACFP_Refresh')) {
                UACFP_Refresh($instanceID);
            }
        }
    }

    /**
     * Datenpaket fuer die Kachel: aktueller Zustand aller Tueren plus Verlauf.
     */
    private function BuildPayload(): array
    {
        $config = $this->GetDoorConfig();
        $state  = $this->ReadState();
        $doors  = [];

        foreach ($state as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ident = (string) ($entry['ident'] ?? '');
            $cfg   = $config[(string) $id] ?? null;
            $catID = $ident !== '' ? $this->FindChildByIdent($this->InstanceID, $ident) : 0;

            $doors[] = [
                'id'        => (string) $id,
                'name'      => (string) ($entry['name'] ?? $id),
                'type'      => $catID > 0 ? (string) $this->ReadDoorValue($catID, 'Type') : '',
                'lock'      => $catID > 0 ? (string) $this->ReadDoorValue($catID, 'DoorLockRelayStatus') : '',
                'position'  => $catID > 0 ? (string) $this->ReadDoorValue($catID, 'DoorPositionStatus') : '',
                'open'      => !empty($entry['open']),
                'sensor'    => !empty($entry['sensor']),
                'since'     => (int) ($entry['since'] ?? 0),
                'alarm'     => !empty($entry['alarm']),
                'monitored' => ($cfg !== null && $cfg['enabled']),
                'delay'     => $cfg !== null ? (int) $cfg['delay'] : 0,
                'siren'     => $this->IsSirenEnabled((string) $id),
                'switch'    => $this->SirenSwitchIdent((string) $id)
            ];
        }

        usort($doors, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'sirens'   => $this->BuildSirenPayload(),
            'now'      => time(),
            'lastPoll' => (int) $this->GetValue('LastUpdate'),
            'duration' => (float) $this->GetValue('LastDuration'),
            'error'    => (string) $this->GetValue('LastError'),
            'errorAt'  => (int) $this->GetValue('LastErrorTime'),
            'status'   => $this->GetStatus(),
            'doors'    => $doors,
            'events'   => $this->ReadEventLog()
        ];
    }

    /**
     * Soll-/Ist-Vergleich aller konfigurierten Shellys fuer die Kachel.
     */
    private function BuildSirenPayload(): array
    {
        $status = json_decode($this->ReadAttributeString('SirenStatus'), true);
        if (!is_array($status)) {
            $status = [];
        }
        $desired = json_decode($this->ReadAttributeString('SirenState'), true);
        if (!is_array($desired)) {
            $desired = [];
        }

        $result = [];
        foreach ($this->GetConfiguredSirens() as $key => $siren) {
            $entry     = $status[$key] ?? [];
            $checked   = isset($entry['ts']);
            $reachable = !empty($entry['reachable']);
            $output    = array_key_exists('output', $entry) ? $entry['output'] : null;
            $wanted    = !empty($desired[$key]['on']);

            $result[] = [
                'host'      => $siren['host'],
                'name'      => trim((string) ($siren['name'] ?? '')),
                'channel'   => $siren['channel'],
                'checked'   => $checked,
                'reachable' => $reachable,
                'output'    => $output,
                'target'    => $wanted,
                'mismatch'  => ($reachable && $output !== null && $output !== $wanted),
                'ms'        => (float) ($entry['ms'] ?? 0),
                'ts'        => (int) ($entry['ts'] ?? 0),
                'error'     => (string) ($entry['error'] ?? '')
            ];
        }

        return $result;
    }

    private function ReadDoorValue(int $catID, string $ident)
    {
        $varID = $this->FindChildByIdent($catID, $ident);
        return $varID > 0 && IPS_VariableExists($varID) ? GetValue($varID) : '';
    }

    /**
     * Verbindungstest inkl. Ausgabe aller gefundenen Tueren.
     */
    public function TestConnection(): void
    {
        $error = '';
        $start = microtime(true);
        $doors = $this->ApiGetDoors($error);
        $ms = round((microtime(true) - $start) * 1000, 1);

        if ($doors === null) {
            echo "Fehler nach {$ms} ms:\n\n" . $error;
            return;
        }

        $out = 'Verbindung OK (' . $ms . " ms) - " . count($doors) . " Tuer(en) gefunden:\n\n";
        foreach ($doors as $door) {
            $out .= sprintf(
                "%s\n  ID:       %s\n  type:     %s\n  Schloss:  %s\n  Position: %s\n\n",
                (string) ($door['name'] ?? '?'),
                (string) ($door['id'] ?? '?'),
                (string) ($door['type'] ?? '-'),
                $this->AsString($door['door_lock_relay_status'] ?? null, '-'),
                $this->AsString($door['door_position_status'] ?? null, '- (kein Tuerkontakt)')
            );
        }
        echo $out;
    }

    /**
     * Laedt die Tueren vom Controller und befuellt die Konfigurationsliste.
     */
    public function LoadDoors(): void
    {
        $error = '';
        $doors = $this->ApiGetDoors($error);
        if ($doors === null) {
            echo 'Fehler beim Laden der Tueren:' . "\n\n" . $error;
            return;
        }

        $existing = json_decode($this->ReadPropertyString('DoorSettings'), true);
        if (!is_array($existing)) {
            $existing = [];
        }
        $byId = [];
        foreach ($existing as $row) {
            if (is_array($row) && isset($row['DoorID']) && $row['DoorID'] !== '') {
                $byId[(string) $row['DoorID']] = $row;
            }
        }

        $merged = [];
        foreach ($doors as $door) {
            $id = (string) ($door['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $row = $byId[$id] ?? [
                'DoorID'       => $id,
                'AlarmEnabled' => false,
                'OpenDelay'    => 60,
                'ShellyHost'   => '',
                'ShellyName'   => ''
            ];
            unset($byId[$id]);
            $row['DoorID']   = $id;
            $row['DoorName'] = (string) ($door['name'] ?? $id);
            $merged[] = $row;
        }
        // Manuell angelegte / unbekannte Zeilen erhalten
        foreach ($byId as $row) {
            $merged[] = $row;
        }

        $this->UpdateFormField('DoorSettings', 'values', json_encode($merged));
        echo count($doors) . ' Tuer(en) geladen.' . "\n\n"
            . 'Bitte jetzt "Uebernehmen" klicken, damit die Einstellungen gespeichert werden.';
    }

    /**
     * Entfernt Tuer-Kategorien aus dem Objektbaum, die der Controller nicht mehr meldet.
     */
    public function CleanupDoors(): void
    {
        $error = '';
        $doors = $this->ApiGetDoors($error);
        if ($doors === null) {
            echo 'Aufraeumen nicht moeglich, Controller nicht erreichbar:' . "\n\n" . $error;
            return;
        }

        $keep = [];
        foreach ($doors as $door) {
            $id = (string) ($door['id'] ?? '');
            if ($id !== '') {
                $keep[] = $this->DoorIdent($id);
            }
        }

        $removed = $this->RemoveOrphanedDoors($keep);
        echo $removed === 0
            ? 'Keine verwaisten Tueren gefunden.'
            : $removed . ' verwaiste Tuer(en) entfernt.';
    }

    /**
     * Schaltet die Standard-Sirene zum Testen ein bzw. aus.
     */
    public function TestSiren(string $Host, bool $On): bool
    {
        $host = $this->CleanHost($Host);
        if ($host === '') {
            $this->LogMessage('Sirenentest ohne Adresse aufgerufen.', KL_ERROR);
            return false;
        }

        $channel = $this->ReadPropertyInteger('ShellyChannel');
        $key     = $host . '|' . $channel;
        $label   = $this->SirenLabelForKey($key);
        $max     = $On ? $this->ReadPropertyInteger('SirenMaxRuntime') : 0;

        $error = '';
        $ok = $this->ShellySwitch($host, $channel, $On, $max, $error);

        if ($ok) {
            $this->LogMessage(sprintf(
                'Sirene %s wurde zum Test %s.',
                $label,
                $On ? 'eingeschaltet' : 'ausgeschaltet'
            ), KL_NOTIFY);

            // Nur den Soll-Zustand dieser Sirene zuruecksetzen, damit der Test
            // die Alarmlogik der anderen Sirenen nicht verfaelscht
            $applied = json_decode($this->ReadAttributeString('SirenState'), true);
            if (is_array($applied)) {
                unset($applied[$key]);
                $this->WriteAttributeString('SirenState', (string) json_encode($applied));
            }
        } else {
            $this->LogMessage('Sirene ' . $label . ' konnte nicht geschaltet werden: ' . $error, KL_ERROR);
        }

        $this->QuerySirens([$key]);
        return $ok;
    }

    /* ===================================================================== */
    /* Objektbaum                                                            */
    /* ===================================================================== */

    private function UpdateDoors(array $doors): void
    {
        $config         = $this->GetDoorConfig();
        $oldState       = $this->ReadState();
        $newState       = [];
        $idents         = [];
        $position       = 100;
        $switchPosition = 50;

        foreach ($doors as $door) {
            if (!is_array($door)) {
                continue;
            }
            $id = (string) ($door['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $name       = (string) ($door['name'] ?? $id);
            $type       = $this->AsString($door['type'] ?? null, '');
            $lockStatus = $this->AsString($door['door_lock_relay_status'] ?? null, '');
            $posStatus  = $this->AsString($door['door_position_status'] ?? null, '');

            $ident    = $this->DoorIdent($id);
            $idents[] = $ident;
            $catID    = $this->MaintainCategory($ident, $name, $position);
            $position += 10;

            $this->MaintainChildVariable($catID, 'Name', 'Name', VARIABLETYPE_STRING, '', 10, true, $name);
            $this->MaintainChildVariable($catID, 'Type', 'Typ (type)', VARIABLETYPE_STRING, '', 20, true, $type);
            $this->MaintainChildVariable($catID, 'DoorLockRelayStatus', 'Schlossrelais (door_lock_relay_status)', VARIABLETYPE_STRING, '', 30, true, $lockStatus);
            $this->MaintainChildVariable($catID, 'DoorPositionStatus', 'Tuerposition (door_position_status)', VARIABLETYPE_STRING, '', 40, true, $posStatus);

            // Tuerkontakt vorhanden? Ohne Kontakt liefert die API null / leer.
            $hasSensor = ($posStatus !== '');
            $isOpen    = (strtolower($posStatus) === 'open');

            $openID = $this->MaintainChildVariable($catID, 'DoorOpen', 'Tuer offen', VARIABLETYPE_BOOLEAN, self::PROFILE_DOOR, 50, $hasSensor, $isOpen);

            $cfg          = $config[$id] ?? null;
            $alarmEnabled = ($cfg !== null && $cfg['enabled'] && $hasSensor);

            // Offen-seit-Zeitstempel fortschreiben
            $prev    = $oldState[$id] ?? [];
            $wasOpen = !empty($prev['open']);
            $since   = (int) ($prev['since'] ?? 0);
            if ($isOpen) {
                if ($since <= 0 || !$wasOpen) {
                    $since = time();
                }
            } else {
                $since = 0;
            }

            // Verlauf fortschreiben
            if ($isOpen && !$wasOpen) {
                $this->HistoryOpen($id, $name, $since);
            } elseif (!$isOpen && $wasOpen) {
                $this->HistoryClose($id);
            }

            $this->MaintainChildVariable($catID, 'OpenSince', 'Offen seit', VARIABLETYPE_INTEGER, '~UnixTimestamp', 60, $alarmEnabled, $since);
            $alarmID = $this->MaintainChildVariable($catID, 'AlarmActive', 'Sirenenalarm', VARIABLETYPE_BOOLEAN, self::PROFILE_ALARM, 70, $alarmEnabled, (bool) ($prev['alarm'] ?? false));

            // Wartungsschalter: echte Modulvariable direkt unter der Instanz, damit
            // EnableAction greift. IPS_SetVariableCustomAction akzeptiert nur Skript-IDs,
            // eine Instanz-ID waere dort ungueltig.
            $bypassIdent = $this->SirenSwitchIdent($id);

            // Altlast aufraeumen: in der ersten Fassung lag der Schalter in der Tuer-Kategorie
            $this->MaintainChildVariable($catID, $bypassIdent, 'Sirene aktiv', VARIABLETYPE_BOOLEAN, '~Switch', 80, false);

            $existed = $this->FindChildByIdent($this->InstanceID, $bypassIdent) > 0;
            $this->MaintainVariable($bypassIdent, 'Sirene aktiv: ' . $name, VARIABLETYPE_BOOLEAN, '~Switch', $switchPosition, $alarmEnabled);
            $switchPosition++;

            if ($alarmEnabled) {
                $this->EnableAction($bypassIdent);
                if (!$existed) {
                    $this->SetValue($bypassIdent, true);
                }
                $sirenEnabled = (bool) $this->GetValue($bypassIdent);
            } else {
                $sirenEnabled = true;
            }

            $this->MaintainArchive($openID, $alarmID);

            $newState[$id] = [
                'ident'       => $ident,
                'name'        => $name,
                'open'        => $isOpen,
                'sensor'      => $hasSensor,
                'since'       => $since,
                'alarm'       => (bool) ($prev['alarm'] ?? false),
                'bypassSince' => $sirenEnabled ? 0 : ((int) ($prev['bypassSince'] ?? 0) ?: time())
            ];
        }

        $this->WriteState($newState);
        $this->HistoryCloseMissing(array_keys($newState));

        if ($this->ReadPropertyBoolean('RemoveOrphans')) {
            $this->RemoveOrphanedDoors($idents);
        }

        $this->CheckBypassReset();
        $this->EvaluateAlarms();
    }

    /**
     * Setzt einen vergessenen Wartungsschalter nach der konfigurierten Zeit wieder auf "Sirene aktiv".
     */
    private function CheckBypassReset(): void
    {
        $minutes = $this->ReadPropertyInteger('BypassReset');
        if ($minutes <= 0) {
            return;
        }

        $state   = $this->ReadState();
        $now     = time();
        $changed = false;

        foreach ($state as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $since = (int) ($entry['bypassSince'] ?? 0);
            if ($since <= 0 || ($now - $since) < ($minutes * 60)) {
                continue;
            }
            $switchID = $this->FindSwitchVariable((string) $id);
            if ($switchID > 0) {
                SetValue($switchID, true);
                $this->LogMessage(sprintf(
                    'Wartungsmodus fuer Tuer "%s" nach %d Minuten automatisch beendet, Sirene wieder aktiv.',
                    (string) ($entry['name'] ?? $id),
                    $minutes
                ), KL_NOTIFY);
            }
            $state[$id]['bypassSince'] = 0;
            $changed = true;
        }

        if ($changed) {
            $this->WriteState($state);
        }
    }

    /**
     * Aktiviert bei Bedarf das Archiv-Logging fuer Tuerstatus und Alarm.
     */
    private function MaintainArchive(int $openID, int $alarmID): void
    {
        if (!$this->ReadPropertyBoolean('ArchiveDoorStates')) {
            return;
        }
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        if ($archiveID <= 0) {
            return;
        }
        foreach ([$openID, $alarmID] as $varID) {
            if ($varID > 0 && IPS_VariableExists($varID) && !AC_GetLoggingStatus($archiveID, $varID)) {
                AC_SetLoggingStatus($archiveID, $varID, true);
            }
        }
    }

    /**
     * Sucht ein Kindobjekt anhand des Idents. Bewusst ohne IPS_GetObjectIDByIdent,
     * damit kein Warning/Exception-Verhalten je nach Kernel-Version auftritt.
     */
    private function FindChildByIdent(int $parentID, string $ident): int
    {
        if ($parentID <= 0 || !IPS_ObjectExists($parentID)) {
            return 0;
        }
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            if (IPS_GetObject($childID)['ObjectIdent'] === $ident) {
                return $childID;
            }
        }
        return 0;
    }

    private function MaintainCategory(string $ident, string $name, int $position, int $parentID = 0): int
    {
        if ($parentID <= 0) {
            $parentID = $this->InstanceID;
        }
        $id = $this->FindChildByIdent($parentID, $ident);
        if ($id === 0) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
            IPS_SetPosition($id, $position);
            IPS_SetIcon($id, 'Door');
        } elseif (IPS_GetName($id) !== $name) {
            // Umbenennung im UniFi Access uebernehmen
            IPS_SetName($id, $name);
        }
        return $id;
    }

    /**
     * Legt eine Variable unterhalb der Tuer-Kategorie an, aktualisiert sie oder
     * entfernt sie wieder ($keep = false).
     */
    private function MaintainChildVariable(int $parentID, string $ident, string $name, int $type, string $profile, int $position, bool $keep, $value = null): int
    {
        $id = $this->FindChildByIdent($parentID, $ident);

        if (!$keep) {
            if ($id > 0 && IPS_VariableExists($id)) {
                IPS_DeleteVariable($id);
            }
            return 0;
        }

        if ($id === 0) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
            IPS_SetPosition($id, $position);
            if ($profile !== '') {
                IPS_SetVariableCustomProfile($id, $profile);
            }
        }

        if ($value !== null) {
            SetValue($id, $value);
        }
        return $id;
    }

    private function RemoveOrphanedDoors(array $keepIdents): int
    {
        return $this->RemoveOrphanedCategories($this->InstanceID, self::DOOR_PREFIX, $keepIdents);
    }

    /**
     * Loescht alle Unterkategorien von $parentID, deren Ident mit $prefix beginnt
     * und die nicht in $keepIdents stehen.
     */
    private function RemoveOrphanedCategories(int $parentID, string $prefix, array $keepIdents): int
    {
        if ($parentID <= 0 || !IPS_ObjectExists($parentID)) {
            return 0;
        }

        $removed = 0;
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] != 0 /* Kategorie */) {
                continue;
            }
            $ident = $object['ObjectIdent'];
            if (strpos($ident, $prefix) !== 0) {
                continue;
            }
            if (in_array($ident, $keepIdents, true)) {
                continue;
            }
            $this->DeleteObjectRecursive($childID);
            $removed++;
        }
        return $removed;
    }

    private function DeleteObjectRecursive(int $objectID): void
    {
        foreach (IPS_GetChildrenIDs($objectID) as $childID) {
            $this->DeleteObjectRecursive($childID);
        }
        $object = IPS_GetObject($objectID);
        switch ($object['ObjectType']) {
            case 0:
                IPS_DeleteCategory($objectID);
                break;
            case 2:
                IPS_DeleteVariable($objectID);
                break;
            case 3:
                IPS_DeleteScript($objectID, true);
                break;
            case 4:
                IPS_DeleteEvent($objectID);
                break;
            case 5:
                IPS_DeleteMedia($objectID, true);
                break;
            case 6:
                IPS_DeleteLink($objectID);
                break;
            default:
                // Instanzen werden bewusst nicht geloescht
                break;
        }
    }

    /* ===================================================================== */
    /* Alarmlogik                                                            */
    /* ===================================================================== */

    private function EvaluateAlarms(): void
    {
        $state  = $this->ReadState();
        $config = $this->GetDoorConfig();
        $now    = time();

        $desired  = [];   // "host|channel" => bool
        $pending  = false;
        $alarming = false;

        foreach ($state as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ident   = (string) ($entry['ident'] ?? '');
            $cfg     = $config[$id] ?? null;
            $enabled = ($cfg !== null && $cfg['enabled'] && !empty($entry['sensor']));

            // Wartungsschalter: unterdrueckt die Sirene unabhaengig von der Offen-Dauer
            if ($enabled && !$this->IsSirenEnabled((string) $id)) {
                $enabled = false;
            }

            if (!$enabled) {
                if (!empty($entry['alarm'])) {
                    $state[$id]['alarm'] = false;
                    $this->WriteDoorVariable($ident, 'AlarmActive', false);
                }
                continue;
            }

            $key = $cfg['host'] . '|' . $cfg['channel'];
            if (!isset($desired[$key])) {
                $desired[$key] = false;
            }

            if (!empty($entry['open'])) {
                $since   = (int) ($entry['since'] ?? 0);
                $elapsed = $since > 0 ? ($now - $since) : 0;

                if ($since > 0 && $elapsed >= $cfg['delay']) {
                    if (empty($entry['alarm'])) {
                        $state[$id]['alarm'] = true;
                        $this->WriteDoorVariable($ident, 'AlarmActive', true);
                        $this->HistoryMarkSiren((string) $id);
                        $this->LogMessage(sprintf(
                            'Sirenenalarm: Tuer "%s" ist seit %d Sekunden offen (Grenzwert %d s).',
                            (string) ($entry['name'] ?? $id),
                            $elapsed,
                            $cfg['delay']
                        ), KL_WARNING);
                    }
                    $desired[$key] = true;
                    $alarming = true;
                } else {
                    $pending = true;
                }
            } else {
                if (!empty($entry['alarm'])) {
                    $state[$id]['alarm'] = false;
                    $this->WriteDoorVariable($ident, 'AlarmActive', false);
                    $this->LogMessage(sprintf(
                        'Sirenenalarm beendet: Tuer "%s" ist wieder geschlossen.',
                        (string) ($entry['name'] ?? $id)
                    ), KL_NOTIFY);
                }
            }

            $this->WriteDoorVariable($ident, 'OpenSince', (int) ($entry['since'] ?? 0));
        }

        $this->WriteState($state);
        $this->ApplySirens($desired);

        // Sekundengenaue Nachverfolgung nur solange noetig
        $this->SetTimerInterval('AlarmCheck', ($pending || $alarming) ? 1000 : 0);

        $this->PushVisualization();
    }

    /**
     * Bringt alle betroffenen Shellys auf den gewuenschten Zustand.
     * Mehrere Tueren duerfen sich eine Sirene teilen - abgeschaltet wird erst,
     * wenn keine der Tueren mehr Alarm meldet.
     */
    private function ApplySirens(array $desired): void
    {
        $applied = json_decode($this->ReadAttributeString('SirenState'), true);
        if (!is_array($applied)) {
            $applied = [];
        }
        $max      = $this->ReadPropertyInteger('SirenMaxRuntime');
        $now      = time();
        $switched = [];

        foreach ($desired as $key => $want) {
            [$host, $channel] = array_pad(explode('|', (string) $key, 2), 2, '0');
            $channel = (int) $channel;
            $current = $applied[$key] ?? ['on' => false, 'since' => 0, 'muted' => false];
            $error   = '';

            if ($want) {
                if (!empty($current['on']) && $max > 0 && ($now - (int) $current['since']) >= $max) {
                    // Maximale Laufzeit erreicht -> abschalten und stummschalten
                    $this->ShellySwitch($host, $channel, false, 0, $error);
                    $current    = ['on' => false, 'since' => 0, 'muted' => true];
                    $switched[] = $key;
                } elseif (empty($current['on']) && empty($current['muted'])) {
                    if ($this->ShellySwitch($host, $channel, true, $max, $error)) {
                        $current = ['on' => true, 'since' => $now, 'muted' => false];
                    } else {
                        $this->LogMessage('Sirene ' . $this->SirenLabelForKey((string) $key) . ' konnte nicht eingeschaltet werden: ' . $error, KL_ERROR);
                    }
                    $switched[] = $key;
                }
            } else {
                if (!empty($current['on'])) {
                    if (!$this->ShellySwitch($host, $channel, false, 0, $error)) {
                        $this->LogMessage('Sirene ' . $this->SirenLabelForKey((string) $key) . ' konnte nicht ausgeschaltet werden: ' . $error, KL_ERROR);
                    }
                    $switched[] = $key;
                }
                $current = ['on' => false, 'since' => 0, 'muted' => false];
            }

            $applied[$key] = $current;
        }

        // Shellys, die nicht mehr in der Konfiguration vorkommen, sicher abschalten
        foreach ($applied as $key => $current) {
            if (isset($desired[$key]) || empty($current['on'])) {
                continue;
            }
            [$host, $channel] = array_pad(explode('|', (string) $key, 2), 2, '0');
            $error = '';
            $this->ShellySwitch($host, (int) $channel, false, 0, $error);
            $applied[$key] = ['on' => false, 'since' => 0, 'muted' => false];
            $switched[]    = $key;
        }

        $this->WriteAttributeString('SirenState', json_encode($applied));

        // Direkt nach jedem Schaltvorgang nachfragen, ob der Shelly wirklich
        // reagiert hat - das ist der Moment, in dem es interessant ist.
        if (count($switched) > 0) {
            $this->QuerySirens(array_values(array_unique($switched)));
        }
    }

    /* ===================================================================== */
    /* Shelly-Rueckfrage (Ist-Zustand)                                       */
    /* ===================================================================== */

    /**
     * Alle konfigurierten Shelly-Adressen, indiziert wie der Soll-Zustand ("host|kanal").
     */
    private function GetConfiguredSirens(): array
    {
        $result = [];

        foreach ($this->GetDoorConfig() as $cfg) {
            if ($cfg['host'] === '') {
                continue;
            }
            $key = $cfg['host'] . '|' . $cfg['channel'];
            if (!isset($result[$key])) {
                $result[$key] = [
                    'host'    => $cfg['host'],
                    'channel' => (int) $cfg['channel'],
                    'name'    => $cfg['sirenName'],
                    'doors'   => [$cfg['name']]
                ];
            } else {
                // Teilen mehrere Tueren eine Sirene, genuegt es, den Namen einmal einzutragen
                if ($result[$key]['name'] === '' && $cfg['sirenName'] !== '') {
                    $result[$key]['name'] = $cfg['sirenName'];
                }
                $result[$key]['doors'][] = $cfg['name'];
            }
        }

        return $result;
    }

    /**
     * Anzeigename einer Sirene: "Name (IP)" bzw. nur die IP, wenn kein Name gesetzt ist.
     */
    private function SirenLabel(array $siren): string
    {
        $name = trim((string) ($siren['name'] ?? ''));
        return $name !== '' ? $name . ' (' . $siren['host'] . ')' : (string) $siren['host'];
    }

    /**
     * Anzeigename zu einem "host|kanal"-Schluessel, fuer Logmeldungen.
     */
    private function SirenLabelForKey(string $key): string
    {
        $sirens = $this->GetConfiguredSirens();
        if (isset($sirens[$key])) {
            return $this->SirenLabel($sirens[$key]);
        }
        [$host] = array_pad(explode('|', $key, 2), 2, '');
        return $host;
    }

    /**
     * Fragt jeden Shelly per Switch.GetStatus ab und legt das Ergebnis im
     * Objektbaum sowie im Attribut SirenStatus ab.
     *
     * @param array $onlyKeys Leer = alle, sonst nur diese "host|kanal"-Schluessel
     */
    private function QuerySirens(array $onlyKeys = []): void
    {
        $sirens = $this->GetConfiguredSirens();
        if (count($sirens) === 0) {
            $this->WriteAttributeString('SirenStatus', '{}');
            $this->RemoveSirenTree();
            $this->PushVisualization();
            return;
        }

        $status = json_decode($this->ReadAttributeString('SirenStatus'), true);
        if (!is_array($status)) {
            $status = [];
        }

        foreach ($sirens as $key => $siren) {
            if (count($onlyKeys) > 0 && !in_array($key, $onlyKeys, true)) {
                continue;
            }

            $error = '';
            $start = microtime(true);
            $result = $this->ShellyRpc($siren['host'], 'Switch.GetStatus', ['id' => $siren['channel']], $error);
            $ms = round((microtime(true) - $start) * 1000, 1);

            if ($result === null) {
                $status[$key] = [
                    'reachable' => false,
                    'output'    => null,
                    'ms'        => $ms,
                    'ts'        => time(),
                    'error'     => $error
                ];
                $this->SendDebug('Shelly Status', $siren['host'] . ': ' . $error, 0);
            } else {
                $status[$key] = [
                    'reachable' => true,
                    'output'    => isset($result['output']) ? (bool) $result['output'] : null,
                    'ms'        => $ms,
                    'ts'        => time(),
                    'error'     => ''
                ];
            }
        }

        // Eintraege entfernen, die nicht mehr konfiguriert sind
        foreach (array_keys($status) as $key) {
            if (!isset($sirens[$key])) {
                unset($status[$key]);
            }
        }

        $this->WriteAttributeString('SirenStatus', json_encode($status));
        $this->UpdateSirenTree($sirens, $status);
        $this->PushVisualization();
    }

    private function UpdateSirenTree(array $sirens, array $status): void
    {
        $desired = json_decode($this->ReadAttributeString('SirenState'), true);
        if (!is_array($desired)) {
            $desired = [];
        }

        $rootID   = $this->MaintainCategory('Sirens', 'Sirenen', 90);
        $keep     = [];
        $position = 10;

        foreach ($sirens as $key => $siren) {
            $ident  = $this->SirenIdent($key);
            $keep[] = $ident;

            $name  = trim((string) ($siren['name'] ?? ''));
            $catID = $this->MaintainCategory(
                $ident,
                $name !== '' ? $name : 'Sirene ' . $siren['host'],
                $position,
                $rootID
            );
            $position += 10;

            $entry     = $status[$key] ?? [];
            $reachable = !empty($entry['reachable']);
            $output    = array_key_exists('output', $entry) ? $entry['output'] : null;
            $wanted    = !empty($desired[$key]['on']);
            $mismatch  = ($reachable && $output !== null && $output !== $wanted);

            $this->MaintainChildVariable($catID, 'Reachable', 'Erreichbar', VARIABLETYPE_BOOLEAN, self::PROFILE_REACHABLE, 10, true, $reachable);
            $this->MaintainChildVariable($catID, 'RelayState', 'Relais (Ist)', VARIABLETYPE_BOOLEAN, self::PROFILE_RELAY, 20, true, (bool) $output);
            $this->MaintainChildVariable($catID, 'RelayTarget', 'Relais (Soll)', VARIABLETYPE_BOOLEAN, self::PROFILE_RELAY, 30, true, $wanted);
            $this->MaintainChildVariable($catID, 'Mismatch', 'Abweichung', VARIABLETYPE_BOOLEAN, self::PROFILE_MISMATCH, 40, true, $mismatch);
            $this->MaintainChildVariable($catID, 'ResponseTime', 'Antwortzeit', VARIABLETYPE_FLOAT, self::PROFILE_DURATION, 50, true, (float) ($entry['ms'] ?? 0));
            $this->MaintainChildVariable($catID, 'LastCheck', 'Letzte Pruefung', VARIABLETYPE_INTEGER, '~UnixTimestamp', 60, true, (int) ($entry['ts'] ?? 0));
            $this->MaintainChildVariable($catID, 'LastError', 'Letzter Fehler', VARIABLETYPE_STRING, '', 70, true, (string) ($entry['error'] ?? ''));

            if ($mismatch) {
                $this->LogMessage(sprintf(
                    'Sirene %s, Kanal %d: Relais steht auf %s, erwartet wird %s.',
                    $this->SirenLabel($siren),
                    $siren['channel'],
                    $output ? 'EIN' : 'AUS',
                    $wanted ? 'EIN' : 'AUS'
                ), KL_WARNING);
            } elseif (!$reachable) {
                $this->LogMessage(sprintf(
                    'Sirene %s antwortet nicht: %s',
                    $this->SirenLabel($siren),
                    (string) ($entry['error'] ?? '')
                ), KL_WARNING);
            }
        }

        $this->RemoveOrphanedCategories($rootID, self::SIREN_PREFIX, $keep);
    }

    private function RemoveSirenTree(): void
    {
        $rootID = $this->FindChildByIdent($this->InstanceID, 'Sirens');
        if ($rootID > 0) {
            $this->DeleteObjectRecursive($rootID);
        }
    }

    private function SirenIdent(string $key): string
    {
        return self::SIREN_PREFIX . preg_replace('/[^A-Za-z0-9]/', '_', $key);
    }

    /* ===================================================================== */
    /* UniFi Access API                                                      */
    /* ===================================================================== */

    /**
     * GET /api/v1/developer/doors
     *
     * @return array|null Liste der Tueren oder null im Fehlerfall
     */
    private function ApiGetDoors(?string &$error): ?array
    {
        $error = '';
        $host  = $this->CleanHost($this->ReadPropertyString('Host'));
        $token = trim($this->ReadPropertyString('Token'));

        if ($host === '') {
            $error = 'Es ist keine IP-Adresse konfiguriert.';
            return null;
        }
        if ($token === '') {
            $error = 'Es ist kein API-Token konfiguriert.';
            return null;
        }

        $port    = $this->ReadPropertyInteger('Port');
        $timeout = max(500, $this->ReadPropertyInteger('Timeout'));
        $verify  = $this->ReadPropertyBoolean('VerifyTLS');
        $url     = sprintf('https://%s:%d/api/v1/developer/doors', $host, $port);

        $this->SendDebug('API Request', $url, 0);

        $ch = curl_init($url);
        if ($ch === false) {
            $error = 'cURL konnte nicht initialisiert werden.';
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeout, 5000),
            CURLOPT_TIMEOUT_MS        => $timeout,
            CURLOPT_SSL_VERIFYPEER    => $verify,
            CURLOPT_SSL_VERIFYHOST    => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER        => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = 'Verbindungsfehler: ' . curl_error($ch);
            curl_close($ch);
            return null;
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->SendDebug('API Response (' . $code . ')', (string) $body, 0);

        if ($code === 401 || $code === 403) {
            $error = 'HTTP ' . $code . ': API-Token ungueltig oder ohne Berechtigung.';
            return null;
        }
        if ($code < 200 || $code >= 300) {
            $error = 'HTTP ' . $code . ': ' . substr((string) $body, 0, 300);
            return null;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            $error = 'Antwort ist kein gueltiges JSON: ' . substr((string) $body, 0, 300);
            return null;
        }
        if (isset($json['code']) && strtoupper((string) $json['code']) !== 'SUCCESS') {
            $error = 'API-Fehler ' . $json['code'] . ': ' . ($json['msg'] ?? 'unbekannt');
            return null;
        }
        if (!isset($json['data']) || !is_array($json['data'])) {
            $error = 'Antwort enthaelt kein Datenfeld "data".';
            return null;
        }

        return $json['data'];
    }

    /* ===================================================================== */
    /* Shelly 1 Gen4 (Gen2+ RPC)                                             */
    /* ===================================================================== */

    private function ShellySwitch(string $host, int $channel, bool $on, int $toggleAfter, ?string &$error): bool
    {
        $params = ['id' => $channel, 'on' => $on];
        if ($on && $toggleAfter > 0) {
            // Failsafe direkt im Shelly, falls IP-Symcon zwischenzeitlich ausfaellt
            $params['toggle_after'] = $toggleAfter;
        }
        return $this->ShellyRpc($host, 'Switch.Set', $params, $error) !== null;
    }

    /**
     * @return array|null Ergebnis des RPC-Aufrufs oder null im Fehlerfall
     */
    private function ShellyRpc(string $host, string $method, array $params, ?string &$error): ?array
    {
        $error = '';
        $host  = $this->CleanHost($host);
        if ($host === '') {
            $error = 'Keine Shelly-Adresse konfiguriert.';
            return null;
        }

        $url     = 'http://' . $host . '/rpc';
        $request = [
            'id'     => 1,
            'src'    => 'symcon-' . $this->InstanceID,
            'method' => $method,
            'params' => $params
        ];

        $this->SendDebug('Shelly Request', $url . ' ' . json_encode($request), 0);

        $code       = 0;
        $authHeader = '';
        $body = $this->HttpPostJson($url, (string) json_encode($request), $code, $error, $authHeader);
        if ($body === null) {
            return null;
        }

        if ($code === 401) {
            $user = trim($this->ReadPropertyString('ShellyUser'));
            if ($user === '') {
                $user = 'admin';
            }
            $password = $this->ReadPropertyString('ShellyPassword');
            if ($password === '') {
                $error = 'Der Shelly verlangt eine Authentifizierung, es ist aber kein Passwort hinterlegt.';
                return null;
            }
            $auth = $this->BuildShellyAuth($authHeader, $user, $password, $error);
            if ($auth === null) {
                return null;
            }
            $request['auth'] = $auth;
            $body = $this->HttpPostJson($url, (string) json_encode($request), $code, $error);
            if ($body === null) {
                return null;
            }
            if ($code === 401) {
                $error = 'Anmeldung am Shelly fehlgeschlagen. Bitte Benutzer und Passwort pruefen.';
                return null;
            }
        }

        $this->SendDebug('Shelly Response (' . $code . ')', (string) $body, 0);

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $error = 'Ungueltige Shelly-Antwort (HTTP ' . $code . '): ' . substr($body, 0, 200);
            return null;
        }
        if (isset($json['error'])) {
            $detail = is_array($json['error'])
                ? (($json['error']['code'] ?? '?') . ': ' . json_encode($json['error']['message'] ?? ''))
                : json_encode($json['error']);
            $error = 'Shelly-Fehler ' . $detail;
            return null;
        }
        if ($code < 200 || $code >= 300) {
            $error = 'Shelly HTTP ' . $code . ': ' . substr($body, 0, 200);
            return null;
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }

    /**
     * Digest-Authentifizierung nach Shelly-Gen2+-Schema (SHA-256).
     *
     * Die Challenge kommt bei HTTP ausschliesslich im WWW-Authenticate-Header,
     * der Antwortkoerper der 401 ist leer:
     *   WWW-Authenticate: Digest qop="auth", realm="shelly1g4-xxxx",
     *                     nonce="<base64>", algorithm=SHA-256
     */
    private function BuildShellyAuth(string $challengeHeader, string $user, string $password, ?string &$error): ?array
    {
        $realm = '';
        $nonce = '';

        if (preg_match('/realm="([^"]*)"/i', $challengeHeader, $match)) {
            $realm = $match[1];
        }
        if (preg_match('/nonce="([^"]*)"/i', $challengeHeader, $match)) {
            $nonce = $match[1];
        }

        if ($realm === '' || $nonce === '') {
            $error = 'Die Digest-Anforderung des Shelly konnte nicht gelesen werden: '
                . ($challengeHeader !== '' ? $challengeHeader : 'kein WWW-Authenticate-Header erhalten');
            return null;
        }

        // nc ist ein 8-stelliger Hex-Zaehler, cnonce eine Zufallszahl des Clients.
        // Fuer jede Anfrage wird eine frische Challenge geholt, daher bleibt nc bei 1.
        $nc     = '00000001';
        $cnonce = random_int(100000000, 999999999);

        // ha2 ist bei HTTP fest "POST:/rpc" - das dokumentierte
        // "dummy_method:dummy_uri" gilt nur fuer RPC ueber WebSocket.
        $ha1 = hash('sha256', $user . ':' . $realm . ':' . $password);
        $ha2 = hash('sha256', 'POST:/rpc');
        $response = hash('sha256', implode(':', [$ha1, $nonce, $nc, (string) $cnonce, 'auth', $ha2]));

        return [
            'realm'     => $realm,
            'username'  => $user,
            // Der nonce ist Base64 und darf nicht in eine Zahl gewandelt werden
            'nonce'     => $nonce,
            'cnonce'    => $cnonce,
            'nc'        => $nc,
            'response'  => $response,
            'algorithm' => 'SHA-256'
        ];
    }

    private function HttpPostJson(string $url, string $body, ?int &$httpCode, ?string &$error, ?string &$authHeader = null): ?string
    {
        $httpCode   = 0;
        $error      = '';
        $authHeader = '';

        $ch = curl_init($url);
        if ($ch === false) {
            $error = 'cURL konnte nicht initialisiert werden.';
            return null;
        }

        // Bei einer 401 steckt die Digest-Challenge nur im Header, nicht im Body
        $captured = '';
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_TIMEOUT_MS        => 5000,
            CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
            CURLOPT_HEADERFUNCTION    => function ($curl, $line) use (&$captured) {
                if (stripos($line, 'WWW-Authenticate:') === 0) {
                    $captured = trim(substr($line, strlen('WWW-Authenticate:')));
                }
                return strlen($line);
            }
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = 'Verbindungsfehler: ' . curl_error($ch);
            curl_close($ch);
            return null;
        }
        $httpCode   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $authHeader = $captured;
        curl_close($ch);

        return (string) $response;
    }

    /* ===================================================================== */
    /* Hilfsfunktionen                                                       */
    /* ===================================================================== */

    /**
     * Liest die Tuerkonfiguration aus dem Property, indiziert nach Tuer-ID.
     */
    private function GetDoorConfig(): array
    {
        $result = [];
        $list = json_decode($this->ReadPropertyString('DoorSettings'), true);
        if (!is_array($list)) {
            return $result;
        }

        $channel = $this->ReadPropertyInteger('ShellyChannel');

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['DoorID'] ?? ''));
            if ($id === '') {
                continue;
            }

            // Jede Tuer hat ihren eigenen Shelly, es gibt keinen Standard
            $host    = $this->CleanHost((string) ($row['ShellyHost'] ?? ''));
            $enabled = (bool) ($row['AlarmEnabled'] ?? false);
            if ($host === '') {
                // Ohne Sirene ergibt der Alarm keinen Sinn
                $enabled = false;
            }

            $result[$id] = [
                'name'      => (string) ($row['DoorName'] ?? ''),
                'enabled'   => $enabled,
                'delay'     => max(1, (int) ($row['OpenDelay'] ?? 60)),
                'host'      => $host,
                'channel'   => $channel,
                'sirenName' => trim((string) ($row['ShellyName'] ?? ''))
            ];
        }

        return $result;
    }

    private function ReadState(): array
    {
        $state = json_decode($this->ReadAttributeString('DoorState'), true);
        return is_array($state) ? $state : [];
    }

    private function WriteState(array $state): void
    {
        $this->WriteAttributeString('DoorState', json_encode($state));
    }

    private function WriteDoorVariable(string $doorIdent, string $varIdent, $value): void
    {
        if ($doorIdent === '') {
            return;
        }
        $catID = $this->FindChildByIdent($this->InstanceID, $doorIdent);
        if ($catID === 0) {
            return;
        }
        $varID = $this->FindChildByIdent($catID, $varIdent);
        if ($varID === 0 || !IPS_VariableExists($varID)) {
            return;
        }
        // globale IPS-Funktion, nicht die Methode $this->SetValue()
        SetValue($varID, $value);
    }

    private function DoorIdent(string $doorID): string
    {
        return self::DOOR_PREFIX . preg_replace('/[^A-Za-z0-9]/', '', $doorID);
    }

    private function DoorKey(string $doorID): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', $doorID);
    }

    /**
     * Ident des Wartungsschalters. Muss global eindeutig sein, weil RequestAction
     * nur den Ident und nicht das Elternobjekt uebergeben bekommt.
     */
    private function SirenSwitchIdent(string $doorID): string
    {
        return self::SIREN_SWITCH_PREFIX . $this->DoorKey($doorID);
    }

    /**
     * ID des Wartungsschalters. Er haengt direkt unter der Instanz, nicht in der
     * Tuer-Kategorie, weil EnableAction nur fuer Modulvariablen funktioniert.
     */
    private function FindSwitchVariable(string $doorID): int
    {
        $varID = $this->FindChildByIdent($this->InstanceID, $this->SirenSwitchIdent($doorID));
        return ($varID > 0 && IPS_VariableExists($varID)) ? $varID : 0;
    }

    /**
     * true = Sirene fuer diese Tuer freigegeben. Fehlt der Schalter, gilt "aktiv".
     */
    private function IsSirenEnabled(string $doorID): bool
    {
        $varID = $this->FindSwitchVariable($doorID);
        return $varID === 0 ? true : (bool) GetValue($varID);
    }

    private function SetSirenEnabledByIdent(string $switchIdent, bool $enabled): void
    {
        $key   = substr($switchIdent, strlen(self::SIREN_SWITCH_PREFIX));
        $state = $this->ReadState();

        foreach ($state as $id => $entry) {
            if (!is_array($entry) || $this->DoorKey((string) $id) !== $key) {
                continue;
            }
            $varID = $this->FindSwitchVariable((string) $id);
            if ($varID > 0) {
                SetValue($varID, $enabled);
            }
            $state[$id]['bypassSince'] = $enabled ? 0 : time();
            $this->WriteState($state);
            $this->LogMessage(sprintf(
                'Sirene fuer Tuer "%s" %s.',
                (string) ($entry['name'] ?? $id),
                $enabled ? 'wieder aktiviert' : 'fuer Wartung deaktiviert'
            ), KL_NOTIFY);
            $this->EvaluateAlarms();
            return;
        }

        $this->SendDebug('RequestAction', 'Keine Tuer zu Ident ' . $switchIdent . ' gefunden', 0);
    }

    /* --------------------------------------------------------------------- */
    /* Verlauf                                                               */
    /* --------------------------------------------------------------------- */

    private function ReadEventLog(): array
    {
        $log = json_decode($this->ReadAttributeString('EventLog'), true);
        return is_array($log) ? $log : [];
    }

    private function WriteEventLog(array $log): void
    {
        $limit = max(10, $this->ReadPropertyInteger('HistoryLimit'));
        if (count($log) > $limit) {
            $log = array_slice($log, 0, $limit);
        }
        $this->WriteAttributeString('EventLog', json_encode(array_values($log)));
    }

    private function HistoryOpen(string $doorID, string $name, int $start): void
    {
        $log = $this->ReadEventLog();
        array_unshift($log, [
            'door'  => $doorID,
            'name'  => $name,
            'start' => $start > 0 ? $start : time(),
            'end'   => 0,
            'siren' => false
        ]);
        $this->WriteEventLog($log);
    }

    private function HistoryClose(string $doorID): void
    {
        $log = $this->ReadEventLog();
        foreach ($log as $index => $entry) {
            if (($entry['door'] ?? '') === $doorID && (int) ($entry['end'] ?? 0) === 0) {
                $log[$index]['end'] = time();
                $this->WriteEventLog($log);
                return;
            }
        }
    }

    /**
     * Schliesst laufende Eintraege von Tueren, die der Controller nicht mehr meldet.
     * Sonst blieben sie dauerhaft als "offen" im Verlauf stehen.
     */
    private function HistoryCloseMissing(array $knownDoorIDs): void
    {
        $log     = $this->ReadEventLog();
        $changed = false;

        foreach ($log as $index => $entry) {
            if ((int) ($entry['end'] ?? 0) !== 0) {
                continue;
            }
            if (!in_array((string) ($entry['door'] ?? ''), $knownDoorIDs, true)) {
                $log[$index]['end'] = time();
                $changed = true;
            }
        }

        if ($changed) {
            $this->WriteEventLog($log);
        }
    }

    private function HistoryMarkSiren(string $doorID): void
    {
        $log = $this->ReadEventLog();
        foreach ($log as $index => $entry) {
            if (($entry['door'] ?? '') === $doorID && (int) ($entry['end'] ?? 0) === 0) {
                $log[$index]['siren'] = true;
                $this->WriteEventLog($log);
                return;
            }
        }
    }

    private function CleanHost(string $host): string
    {
        $host = trim($host);
        $host = preg_replace('#^https?://#i', '', $host);
        return rtrim((string) $host, '/');
    }

    private function AsString($value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_DURATION)) {
            IPS_CreateVariableProfile(self::PROFILE_DURATION, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits(self::PROFILE_DURATION, 1);
            IPS_SetVariableProfileText(self::PROFILE_DURATION, '', ' ms');
            IPS_SetVariableProfileIcon(self::PROFILE_DURATION, 'Clock');
        }

        if (!IPS_VariableProfileExists(self::PROFILE_DOOR)) {
            IPS_CreateVariableProfile(self::PROFILE_DOOR, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon(self::PROFILE_DOOR, 'Door');
            IPS_SetVariableProfileAssociation(self::PROFILE_DOOR, 0, 'Geschlossen', '', 0x00FF00);
            IPS_SetVariableProfileAssociation(self::PROFILE_DOOR, 1, 'Offen', '', 0xFF9900);
        }

        if (!IPS_VariableProfileExists(self::PROFILE_ALARM)) {
            IPS_CreateVariableProfile(self::PROFILE_ALARM, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon(self::PROFILE_ALARM, 'Alert');
            IPS_SetVariableProfileAssociation(self::PROFILE_ALARM, 0, 'Kein Alarm', '', 0x00FF00);
            IPS_SetVariableProfileAssociation(self::PROFILE_ALARM, 1, 'ALARM', '', 0xFF0000);
        }

        if (!IPS_VariableProfileExists(self::PROFILE_REACHABLE)) {
            IPS_CreateVariableProfile(self::PROFILE_REACHABLE, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon(self::PROFILE_REACHABLE, 'Network');
            IPS_SetVariableProfileAssociation(self::PROFILE_REACHABLE, 0, 'Nicht erreichbar', '', 0xFF0000);
            IPS_SetVariableProfileAssociation(self::PROFILE_REACHABLE, 1, 'Erreichbar', '', 0x00FF00);
        }

        if (!IPS_VariableProfileExists(self::PROFILE_RELAY)) {
            IPS_CreateVariableProfile(self::PROFILE_RELAY, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon(self::PROFILE_RELAY, 'Power');
            IPS_SetVariableProfileAssociation(self::PROFILE_RELAY, 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation(self::PROFILE_RELAY, 1, 'Ein', '', 0xFF0000);
        }

        if (!IPS_VariableProfileExists(self::PROFILE_MISMATCH)) {
            IPS_CreateVariableProfile(self::PROFILE_MISMATCH, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon(self::PROFILE_MISMATCH, 'Warning');
            IPS_SetVariableProfileAssociation(self::PROFILE_MISMATCH, 0, 'Soll = Ist', '', 0x00FF00);
            IPS_SetVariableProfileAssociation(self::PROFILE_MISMATCH, 1, 'Abweichung', '', 0xFF0000);
        }
    }
}
