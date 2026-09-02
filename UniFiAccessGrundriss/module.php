<?php

declare(strict_types=1);

/**
 * UniFi Access Grundriss
 *
 * Zeigt die Tueren einer UniFi-Access-Controller-Instanz als eigene Kachel auf einem
 * Grundriss an. Mehrere Instanzen sind moeglich, z. B. eine je Etage oder Gebaeude.
 */
class UniFiAccessGrundriss extends IPSModule
{
    private const CONTROLLER_MODULE_ID = '{DEAF2759-F7B9-45FD-80E5-3BBE8DE3FB0A}';

    private const STATUS_ACTIVE        = 102;
    private const STATUS_NO_CONTROLLER = 201;
    private const STATUS_WRONG_MODULE  = 202;
    private const STATUS_NO_PLAN       = 203;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ControllerID', 0);
        $this->RegisterPropertyInteger('FloorPlanMedia', 0);
        $this->RegisterPropertyString('Caption', '');
        $this->RegisterPropertyBoolean('ShowLabels', true);
        $this->RegisterPropertyBoolean('OnlyPlaced', false);

        // Positions: { "<doorId>": {"x":Prozent,"y":Prozent} }
        $this->RegisterAttributeString('Positions', '{}');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }

        $this->SetStatus($this->DetermineStatus());
        $this->PushVisualization();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->ApplyChanges();
        }
    }

    private function DetermineStatus(): int
    {
        $controllerID = $this->ReadPropertyInteger('ControllerID');

        if ($controllerID <= 0 || !IPS_InstanceExists($controllerID)) {
            return self::STATUS_NO_CONTROLLER;
        }
        if (IPS_GetInstance($controllerID)['ModuleInfo']['ModuleID'] !== self::CONTROLLER_MODULE_ID) {
            return self::STATUS_WRONG_MODULE;
        }

        $mediaID = $this->ReadPropertyInteger('FloorPlanMedia');
        if ($mediaID <= 0 || !IPS_MediaExists($mediaID)) {
            return self::STATUS_NO_PLAN;
        }

        return self::STATUS_ACTIVE;
    }

    /* ===================================================================== */
    /* Oeffentliche Funktionen (UACFP_*)                                     */
    /* ===================================================================== */

    /**
     * Wird vom Controller aufgerufen, sobald sich dort etwas geaendert hat.
     */
    public function Refresh(): void
    {
        $this->PushVisualization();
    }

    /**
     * Setzt die Position einer Tuer auf dem Grundriss (Prozent der Bildflaeche).
     * X oder Y ausserhalb 0..100 nimmt die Tuer wieder vom Grundriss.
     */
    public function SetDoorPosition(string $DoorID, float $X, float $Y): bool
    {
        if ($DoorID === '') {
            return false;
        }

        $positions = $this->ReadPositions();

        if ($X < 0 || $X > 100 || $Y < 0 || $Y > 100) {
            unset($positions[$DoorID]);
        } else {
            $positions[$DoorID] = ['x' => round($X, 2), 'y' => round($Y, 2)];
        }

        $this->WriteAttributeString('Positions', (string) json_encode($positions));
        $this->PushVisualization();
        return true;
    }

    /**
     * Nimmt alle Tueren vom Grundriss.
     */
    public function ClearDoorPositions(): void
    {
        $this->WriteAttributeString('Positions', '{}');
        $this->PushVisualization();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ((string) $Ident) {
            case 'SetDoorPosition':
                $data = json_decode((string) $Value, true);
                if (is_array($data)) {
                    $this->SetDoorPosition(
                        (string) ($data['door'] ?? ''),
                        (float) ($data['x'] ?? -1),
                        (float) ($data['y'] ?? -1)
                    );
                }
                break;

            case 'ClearDoorPositions':
                $this->ClearDoorPositions();
                break;

            case 'Refresh':
                $controllerID = $this->ReadPropertyInteger('ControllerID');
                if ($controllerID > 0 && function_exists('UAC_Poll')) {
                    UAC_Poll($controllerID);
                }
                $this->PushVisualization();
                break;

            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    /* ===================================================================== */
    /* Visualisierung (HTML-SDK)                                             */
    /* ===================================================================== */

    public function GetVisualizationTile()
    {
        $html = @file_get_contents(__DIR__ . '/plan.html');
        if ($html === false) {
            return '<div style="padding:12px">plan.html wurde nicht gefunden.</div>';
        }

        // Das Bild wird nur hier eingebettet, nicht bei jedem UpdateVisualizationValue -
        // sonst ginge der Grundriss bei jeder Zustandsaenderung erneut ueber die Leitung.
        return str_replace(
            ['/*%%PAYLOAD%%*/null', '%%FLOORPLAN%%'],
            [(string) json_encode($this->BuildPayload()), $this->GetFloorPlanDataUri()],
            $html
        );
    }

    private function PushVisualization(): void
    {
        $payload = $this->BuildPayload();

        // "now" beim Vergleich aussen vor lassen, sonst waere der Hash nie identisch
        $comparable = $payload;
        unset($comparable['now']);
        $hash = md5((string) json_encode($comparable));
        if ($this->GetBuffer('VisHash') === $hash) {
            return;
        }
        $this->SetBuffer('VisHash', $hash);

        $this->UpdateVisualizationValue((string) json_encode($payload));
    }

    private function BuildPayload(): array
    {
        $positions = $this->ReadPositions();
        $source    = $this->ReadControllerData();
        $onlyPlaced = $this->ReadPropertyBoolean('OnlyPlaced');
        $doors     = [];

        foreach (($source['doors'] ?? []) as $door) {
            if (!is_array($door)) {
                continue;
            }
            $id     = (string) ($door['id'] ?? '');
            $placed = isset($positions[$id]);

            if ($onlyPlaced && !$placed) {
                continue;
            }

            $doors[] = [
                'id'        => $id,
                'name'      => (string) ($door['name'] ?? $id),
                'open'      => !empty($door['open']),
                'sensor'    => !empty($door['sensor']),
                'alarm'     => !empty($door['alarm']),
                'since'     => (int) ($door['since'] ?? 0),
                'monitored' => !empty($door['monitored']),
                'siren'     => !empty($door['siren']),
                'lock'      => (string) ($door['lock'] ?? ''),
                'x'         => $placed ? (float) $positions[$id]['x'] : null,
                'y'         => $placed ? (float) $positions[$id]['y'] : null
            ];
        }

        return [
            'now'      => time(),
            'status'   => $this->GetStatus(),
            'caption'  => $this->ReadPropertyString('Caption'),
            'labels'   => $this->ReadPropertyBoolean('ShowLabels'),
            'lastPoll' => (int) ($source['lastPoll'] ?? 0),
            'error'    => (string) ($source['error'] ?? ''),
            'source'   => (int) ($source['status'] ?? 0),
            'doors'    => $doors
        ];
    }

    /**
     * Holt den aktuellen Tuerzustand von der Controller-Instanz.
     */
    private function ReadControllerData(): array
    {
        $controllerID = $this->ReadPropertyInteger('ControllerID');
        if ($controllerID <= 0 || !IPS_InstanceExists($controllerID)) {
            return [];
        }
        if (!function_exists('UAC_GetTileData')) {
            return [];
        }

        $json = @UAC_GetTileData($controllerID);
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Liefert den Grundriss als data:-URI, damit die Kachel ohne Webserver
     * oder Hook auskommt.
     */
    private function GetFloorPlanDataUri(): string
    {
        $mediaID = $this->ReadPropertyInteger('FloorPlanMedia');
        if ($mediaID <= 0 || !IPS_MediaExists($mediaID)) {
            return '';
        }

        $media = IPS_GetMedia($mediaID);
        $ext   = strtolower((string) pathinfo((string) $media['MediaFile'], PATHINFO_EXTENSION));

        $types = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'bmp'  => 'image/bmp'
        ];
        if (!isset($types[$ext])) {
            $this->LogMessage('Grundriss: Dateityp "' . $ext . '" wird nicht unterstuetzt.', KL_WARNING);
            return '';
        }

        $content = @IPS_GetMediaContent($mediaID);
        if (!is_string($content) || $content === '') {
            return '';
        }
        if (strlen($content) > 4 * 1024 * 1024) {
            $this->LogMessage(sprintf(
                'Grundriss ist %d KB gross und wird bei jedem Laden der Kachel uebertragen. Ein kleineres Bild waere sinnvoll.',
                (int) (strlen($content) / 1024)
            ), KL_WARNING);
        }

        return 'data:' . $types[$ext] . ';base64,' . $content;
    }

    private function ReadPositions(): array
    {
        $positions = json_decode($this->ReadAttributeString('Positions'), true);
        return is_array($positions) ? $positions : [];
    }
}
