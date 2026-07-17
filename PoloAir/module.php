<?php

declare(strict_types=1);

/**
 * PoloAir – Poloplast POLO-AIR Komfortwohnraumlüftung über Modbus TCP
 *
 * Registerbasis: "Gebrauchsanweisung POLO-AIR + Geräte – Modbusverbindung" (08/2018).
 * Die POLO-AIR Geräte basieren auf der Komfovent C6 Steuerung; alle Register sind
 * Holding-Register (FC 03 lesen, FC 06/16 schreiben). Die Anbindung erfolgt über den
 * Netzwerkadapter (PING2) des Geräts, Standard 192.168.0.60, Port 502.
 *
 *  - Hauptkontrolle    Reg.   1–34  (An/Aus, Modi, Einheiten, Zeit)
 *  - Einstellungsmodi  Reg. 100–145 (Sollwerte je Betriebsmodus, Timer)
 *  - Eco/Luftqualität  Reg. 200–214
 *  - Alarme            Reg. 600–610 (aktive Alarme, Reset)
 *  - Überwachung       Reg. 900–951 (Temperaturen, Luftmengen, Energie, Panel)
 *  - Firmware          Reg. 1000–1001
 */
class PoloAir extends IPSModule
{
    private const WEBHOOK = '/hook/poloair';
    private const GUID_WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // Magischer Wert zum Quittieren aller aktiven Alarme (Register 600)
    private const ALARM_RESET = 0x99C6;

    // Schreibbare Register: Ident => [Register, Kodierung]
    //  bool = 0/1 | raw = ganzzahlig | t10 = Temperatur ×10 | u32 = 2 Register (High zuerst)
    private const WRITE_MAP = [
        'Power'             => [1, 'bool'],
        'EcoMode'           => [3, 'bool'],
        'AutoMode'          => [4, 'bool'],
        'Modus'             => [5, 'raw'],
        'TempKontrolle'     => [11, 'raw'],
        'SollAbwesend'      => [104, 't10'],
        'SollNormal'        => [110, 't10'],
        'SollIntensiv'      => [116, 't10'],
        'SollBoost'         => [122, 't10'],
        'ZuluftAbwesendSoll' => [100, 'u32'],
        'AbluftAbwesendSoll' => [102, 'u32'],
        'ZuluftNormalSoll'   => [106, 'u32'],
        'AbluftNormalSoll'   => [108, 'u32'],
        'ZuluftIntensivSoll' => [112, 'u32'],
        'AbluftIntensivSoll' => [114, 'u32'],
        'ZuluftBoostSoll'    => [118, 'u32'],
        'AbluftBoostSoll'    => [120, 'u32'],
        'KuecheTimer'       => [130, 'raw'],
        'FeuerTimer'        => [137, 'raw'],
        'OverrideTimer'     => [145, 'raw'],
        'AQAktiv'           => [205, 'bool'],
        'AQTempSoll'        => [206, 't10'],
        'AQSollwert'        => [207, 'raw'],
        'AQFeuchteSoll'     => [208, 'raw'],
        'AQMinIntensitaet'  => [209, 'raw'],
        'AQMaxIntensitaet'  => [210, 'raw']
    ];

    // Werte, die je nach Geräteausstattung fehlen (kein Wasserregister, kein
    // E-Heizer, kein Bedienpanel ...) -> im Dashboard erst zeigen, wenn sie
    // mindestens einmal geliefert wurden
    private const OPTIONAL_VALUES = [
        'WasserTemp', 'ElHeizer', 'Heizleistung', 'Luftklappen',
        'HeizerVerbrauchTag', 'HeizerVerbrauchMonat', 'HeizerVerbrauchGesamt',
        'PanelTemp', 'PanelFeuchte', 'PanelAQ'
    ];

    /** @var array<string,bool> im aktuellen Poll gelieferte Idents */
    private $availNow = [];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '192.168.0.60');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitID', 1);
        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyBoolean('EnableWrite', true);
        $this->RegisterPropertyBoolean('EnableModeSettings', true);
        $this->RegisterPropertyBoolean('EnableAirQuality', true);
        $this->RegisterPropertyBoolean('EnableEnergy', true);
        $this->RegisterPropertyBoolean('EnableDashboard', true);
        $this->RegisterPropertyBoolean('EnableArchive', true);
        $this->RegisterPropertyString('PinCode', '');

        // Adress-Offset: die C6 adressiert Register lt. Doku 1-basiert, auf dem Bus
        // meist 0-basiert (Registernummer-1). Wird automatisch erkannt. -99 = unbekannt.
        $this->RegisterAttributeInteger('AddrOffset', -99);
        // Idents, die schon mindestens einmal einen echten Wert geliefert haben
        $this->RegisterAttributeString('AvailIdents', '[]');
        // Strömungseinheit lt. Register 28 (0 = m³/h, 1 = l/s)
        $this->RegisterAttributeInteger('FlowUnit', 0);

        $this->RegisterTimer('Update', 0, 'PAIR_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->RegisterProfiles();
        $this->MaintainVariables();
        $this->SetupArchive();

        if ($this->ReadPropertyBoolean('EnableDashboard')) {
            $this->RegisterHook(self::WEBHOOK);
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('Update', $this->ReadPropertyInteger('Interval') * 1000);
        $this->Update();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    // =====================================================================
    // Öffentliche Funktionen
    // =====================================================================

    /**
     * Liest alle Register vom Gerät und aktualisiert die Statusvariablen.
     */
    public function Update(): bool
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return false;
        }
        // Alle Modbus-Zugriffe serialisieren (Timer, Dashboard, Konsole)
        if (!IPS_SemaphoreEnter($this->semName(), 5000)) {
            $this->SendDebug('Modbus', 'Update übersprungen (Zugriff belegt)', 0);
            return false;
        }
        try {
            return $this->doUpdate();
        } finally {
            IPS_SemaphoreLeave($this->semName());
        }
    }

    private function doUpdate(): bool
    {
        $sock = $this->mbConnect();
        if ($sock === false) {
            $this->SetStatus(201);
            return false;
        }

        $this->availNow = [];

        try {
            $off = $this->detectOffset($sock);

            // Überwachung ZUERST: die Live-Werte sind der wichtigste Block
            $bMon = $this->readBlock($sock, 900 + $off, 27, 'Überwachung');
            $bMain = $this->readBlock($sock, 1 + $off, 34, 'Hauptkontrolle');
            $bAlarm = $this->readBlock($sock, 600 + $off, 11, 'Alarme');
            $bModes = $this->ReadPropertyBoolean('EnableModeSettings')
                ? $this->readBlock($sock, 100 + $off, 46, 'Einstellungsmodi') : null;
            $bAQ = $this->ReadPropertyBoolean('EnableAirQuality')
                ? $this->readBlock($sock, 200 + $off, 15, 'Eco/Luftqualität') : null;
            $bCons = $this->ReadPropertyBoolean('EnableEnergy')
                ? $this->readBlock($sock, 927 + $off, 19, 'Verbrauch') : null;
            $bPanel = $this->readBlock($sock, 946 + $off, 6, 'Panel');
            $bFw = $this->readBlock($sock, 1000 + $off, 2, 'Firmware');
        } finally {
            if ($sock !== false) {
                @fclose($sock);
            }
        }

        if ($bMon === null && $bMain === null) {
            $this->SetStatus(201);
            return false;
        }

        $this->parseMain($bMain);
        $this->parseMonitoring($bMon, $bMain);
        $this->parseAlarms($bAlarm);
        $this->parseModeSettings($bModes);
        $this->parseAirQuality($bAQ);
        $this->parseConsumption($bCons);
        $this->parsePanel($bPanel);
        $this->parseFirmware($bFw);

        $this->SetValueSafe('LastUpdate', time());
        $this->persistAvail();
        $this->SetStatus(102);
        return true;
    }

    private function persistAvail(): void
    {
        $avail = json_decode($this->ReadAttributeString('AvailIdents'), true);
        if (!is_array($avail)) {
            $avail = [];
        }
        $merged = array_values(array_unique(array_merge($avail, array_keys($this->availNow))));
        if ($merged !== $avail) {
            $this->WriteAttributeString('AvailIdents', json_encode($merged));
        }
    }

    /**
     * Quittiert alle aktiven Alarme (Störungen und Warnungen).
     */
    public function ResetAlarms(): bool
    {
        $ok = $this->writeRegister(600, self::ALARM_RESET);
        if ($ok) {
            $this->Update();
        }
        return $ok;
    }

    /**
     * Baut eine Testverbindung auf und liefert eine Schritt-für-Schritt-Diagnose.
     */
    public function TestConnection(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        if ($host === '') {
            return 'Bitte zuerst die IP-Adresse konfigurieren.';
        }
        if (!IPS_SemaphoreEnter($this->semName(), 5000)) {
            return 'Modbus-Zugriff belegt, bitte erneut versuchen.';
        }
        try {
            // Gemerkten Adress-Offset verwerfen -> Erkennung läuft sichtbar neu
            $this->WriteAttributeInteger('AddrOffset', -99);

            $errno = 0;
            $errstr = '';
            $sock = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($sock === false) {
                return "1) TCP-Verbindung zu {$host}:{$port}: FEHLGESCHLAGEN ({$errstr} / {$errno})\n" .
                    "-> IP/Port prüfen. Wichtig: Der Test muss vom Symcon-Rechner aus klappen, nicht nur vom PC.";
            }
            stream_set_timeout($sock, 3);
            $out = "1) TCP-Verbindung zu {$host}:{$port}: OK\n";
            $out .= '   Unit-ID: ' . $this->ReadPropertyInteger('UnitID') . "\n\n";

            try {
                // 2) Einzelregister-Test mit beiden Adress-Offsets
                $out .= "2) Einzelregister-Test (Register 30 = Jahr, erwartet 2016–2099):\n";
                $found = null;
                foreach ([-1, 0] as $off) {
                    $err = '';
                    $r = $this->mbRead($sock, 30 + $off, 1, $err);
                    if ($r === null) {
                        $out .= "   Offset {$off}: {$err}\n";
                    } else {
                        $ok = ($r[0] >= 2016 && $r[0] <= 2099);
                        $out .= "   Offset {$off}: Wert {$r[0]}" . ($ok ? ' -> PASST' : ' (unplausibel)') . "\n";
                        if ($ok && $found === null) {
                            $found = $off;
                        }
                    }
                }
                if ($found === null) {
                    $out .= "   -> Kein Offset lieferte ein plausibles Jahr.\n" .
                        "      Mögliche Ursachen: anderes Gerät auf dieser IP, falsche Unit-ID,\n" .
                        "      oder ein anderer Modbus-Client blockiert die Steuerung\n" .
                        "      (Weboberfläche/andere Clients testweise schließen, Gerät kurz stromlos machen).\n";
                    // Trotzdem weiter testen mit -1
                    $found = -1;
                } else {
                    $this->WriteAttributeInteger('AddrOffset', $found);
                }
                $off = $found;
                $out .= "   Verwendeter Adress-Offset: {$off}\n\n";

                // 3) Wichtige Einzelwerte
                $out .= "3) Einzelwerte:\n";
                $tests = [
                    [1, 'Ein/Aus (Reg 1)'],
                    [5, 'Modus (Reg 5)'],
                    [902, 'Zuluft-Temp (Reg 902)'],
                    [904, 'Außen-Temp (Reg 904)'],
                    [1000, 'Firmware (Reg 1000)']
                ];
                foreach ($tests as [$reg, $name]) {
                    $err = '';
                    $r = $this->mbRead($sock, $reg + $off, 1, $err);
                    if ($r === null) {
                        $out .= "   {$name}: {$err}\n";
                    } else {
                        $extra = '';
                        if ($reg === 5) {
                            $extra = ' = ' . $this->modeName($r[0]);
                        } elseif ($reg === 902 || $reg === 904) {
                            $extra = sprintf(' = %.1f °C', $this->s16($r[0]) / 10);
                        }
                        $out .= "   {$name}: {$r[0]}{$extra}\n";
                    }
                }
                $out .= "\n";

                // 4) Blockgrößen-Test (manche Firmwares lehnen große Blöcke ab)
                $out .= "4) Block-Lesetest:\n";
                $blocks = [
                    [1, 12, 'Hauptkontrolle klein (Reg 1, 12 Register)'],
                    [1, 34, 'Hauptkontrolle groß (Reg 1, 34 Register)'],
                    [900, 13, 'Überwachung klein (Reg 900, 13 Register)'],
                    [900, 27, 'Überwachung groß (Reg 900, 27 Register)'],
                    [600, 11, 'Alarme (Reg 600, 11 Register)'],
                    [100, 46, 'Einstellungsmodi (Reg 100, 46 Register)'],
                    [927, 19, 'Verbrauch (Reg 927, 19 Register)']
                ];
                foreach ($blocks as [$start, $qty, $name]) {
                    $err = '';
                    $r = $this->mbRead($sock, $start + $off, $qty, $err);
                    $out .= '   ' . $name . ': ' . ($r === null ? $err : 'OK') . "\n";
                }

                return $out;
            } finally {
                fclose($sock);
            }
        } finally {
            IPS_SemaphoreLeave($this->semName());
        }
    }

    // =====================================================================
    // Aktionen (Variablen und Dashboard)
    // =====================================================================

    public function RequestAction($Ident, $Value)
    {
        if (!isset(self::WRITE_MAP[$Ident])) {
            throw new Exception('Unbekannter Ident: ' . $Ident);
        }
        if (!$this->ReadPropertyBoolean('EnableWrite')) {
            $this->LogMessage('Schreibzugriff ist in der Instanzkonfiguration deaktiviert.', KL_WARNING);
            return;
        }

        [$register, $coding] = self::WRITE_MAP[$Ident];

        // Plausibilitätsgrenzen lt. Registerliste
        switch ($Ident) {
            case 'Modus':
                // Schreibbar sind nur Abwesend/Normal/Intensiv/Boost
                if (!in_array((int) $Value, [1, 2, 3, 4], true)) {
                    throw new Exception('Als Modus sind nur Abwesend (1), Normal (2), Intensiv (3) und Boost (4) schaltbar.');
                }
                break;
            case 'TempKontrolle':
                $this->assertRange((int) $Value, 0, 3, $Ident);
                break;
            case 'KuecheTimer':
            case 'FeuerTimer':
            case 'OverrideTimer':
                $this->assertRange((int) $Value, 0, 300, $Ident);
                break;
            case 'SollAbwesend':
            case 'SollNormal':
            case 'SollIntensiv':
            case 'SollBoost':
            case 'AQTempSoll':
                $this->assertRange((float) $Value, 5, 40, $Ident);
                break;
            case 'AQFeuchteSoll':
                $this->assertRange((int) $Value, 0, 100, $Ident);
                break;
            case 'AQMinIntensitaet':
            case 'AQMaxIntensitaet':
                $this->assertRange((int) $Value, 0, 100, $Ident);
                break;
            case 'AQSollwert':
                $this->assertRange((int) $Value, 0, 2000, $Ident);
                break;
        }

        switch ($coding) {
            case 't10':
                $raw = (int) round(((float) $Value) * 10);
                break;
            case 'bool':
                $raw = $Value ? 1 : 0;
                break;
            default:
                $raw = (int) $Value;
        }

        if ($coding === 'u32') {
            $ok = $this->writeRegister32($register, max(0, (int) $Value));
        } else {
            $ok = $this->writeRegister($register, $raw);
        }
        if (!$ok) {
            throw new Exception('Schreiben auf Register ' . $register . ' fehlgeschlagen.');
        }

        // Lokale Variable sofort nachziehen, echter Wert kommt beim nächsten Poll
        switch ($coding) {
            case 'bool':
                $this->SetValueSafe($Ident, (bool) $Value);
                break;
            case 't10':
                $this->SetValueSafe($Ident, (float) $Value);
                break;
            default:
                $this->SetValueSafe($Ident, (int) $Value);
        }
        $this->persistAvail();
    }

    private function assertRange($value, $min, $max, string $ident): void
    {
        if ($value < $min || $value > $max) {
            throw new Exception("Wert für {$ident} außerhalb des zulässigen Bereichs ({$min}–{$max}).");
        }
    }

    private function writeRegister(int $register, int $raw): bool
    {
        if (!IPS_SemaphoreEnter($this->semName(), 5000)) {
            throw new Exception('Modbus-Zugriff belegt, bitte erneut versuchen.');
        }
        try {
            $sock = $this->mbConnect();
            if ($sock === false) {
                $this->SetStatus(201);
                throw new Exception('Keine Verbindung zum Lüftungsgerät.');
            }
            try {
                $off = $this->detectOffset($sock);
                return $this->mbWrite($sock, $register + $off, $raw);
            } finally {
                fclose($sock);
            }
        } finally {
            IPS_SemaphoreLeave($this->semName());
        }
    }

    private function writeRegister32(int $register, int $value): bool
    {
        if (!IPS_SemaphoreEnter($this->semName(), 5000)) {
            throw new Exception('Modbus-Zugriff belegt, bitte erneut versuchen.');
        }
        try {
            $sock = $this->mbConnect();
            if ($sock === false) {
                $this->SetStatus(201);
                throw new Exception('Keine Verbindung zum Lüftungsgerät.');
            }
            try {
                $off = $this->detectOffset($sock);
                return $this->mbWriteMultiple($sock, $register + $off, [($value >> 16) & 0xFFFF, $value & 0xFFFF]);
            } finally {
                fclose($sock);
            }
        } finally {
            IPS_SemaphoreLeave($this->semName());
        }
    }

    // =====================================================================
    // WebHook (Dashboard)
    // =====================================================================

    protected function ProcessHookData()
    {
        if (!$this->ReadPropertyBoolean('EnableDashboard')) {
            http_response_code(404);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $payload = json_decode(file_get_contents('php://input'), true);
            $ident = (string) ($payload['ident'] ?? '');
            $cmd = (string) ($payload['cmd'] ?? '');
            if ($cmd !== 'resetAlarms' && !isset(self::WRITE_MAP[$ident])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid ident']);
                return;
            }
            $pin = $this->ReadPropertyString('PinCode');
            if ($pin !== '' && (string) ($payload['pin'] ?? '') !== $pin) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'PIN']);
                return;
            }
            try {
                if ($cmd === 'resetAlarms') {
                    if (!$this->ResetAlarms()) {
                        throw new Exception('Alarm-Reset fehlgeschlagen');
                    }
                } else {
                    IPS_RequestAction($this->InstanceID, $ident, $payload['value'] ?? null);
                }
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
            } catch (Throwable $e) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            return;
        }

        if (isset($_GET['data'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->collectData());
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/dashboard.html');
    }

    private function collectData(): array
    {
        $idents = [
            'Power', 'Modus', 'EcoMode', 'AutoMode', 'StatusText', 'NaechsterModus', 'TempKontrolle',
            'Ventilator', 'Rotor', 'Heizen', 'Kuehlen', 'FreiesHeizen', 'FreiesKuehlen',
            'Stoerung', 'Warnung', 'AlarmAnzahl', 'AlarmText',
            'AussenTemp', 'ZuluftTemp', 'AbluftTemp', 'WasserTemp',
            'ZuluftStrom', 'AbluftStrom', 'ZuluftVent', 'AbluftVent',
            'Waermetauscher', 'ElHeizer', 'FilterVerschmutzung', 'Luftklappen',
            'SollAbwesend', 'SollNormal', 'SollIntensiv', 'SollBoost',
            'ZuluftAbwesendSoll', 'AbluftAbwesendSoll', 'ZuluftNormalSoll', 'AbluftNormalSoll',
            'ZuluftIntensivSoll', 'AbluftIntensivSoll', 'ZuluftBoostSoll', 'AbluftBoostSoll',
            'KuecheTimer', 'FeuerTimer', 'OverrideTimer',
            'AQAktiv', 'AQTempSoll', 'AQSollwert', 'AQFeuchteSoll', 'AQMinIntensitaet', 'AQMaxIntensitaet',
            'Leistung', 'Heizleistung', 'Rueckgewinnung', 'Effizienz', 'Energiesparen', 'SPI',
            'VerbrauchTag', 'VerbrauchMonat', 'VerbrauchGesamt',
            'HeizerVerbrauchTag', 'HeizerVerbrauchMonat', 'HeizerVerbrauchGesamt',
            'RueckgewinnungTag', 'RueckgewinnungMonat', 'RueckgewinnungGesamt',
            'PanelTemp', 'PanelFeuchte', 'PanelAQ',
            'Firmware', 'LastUpdate'
        ];

        $avail = json_decode($this->ReadAttributeString('AvailIdents'), true);
        if (!is_array($avail)) {
            $avail = [];
        }
        $availSet = array_flip($avail);
        $optionalSet = array_flip(self::OPTIONAL_VALUES);

        $lib = @IPS_GetLibrary('{C32D4669-6698-4C95-9AC1-D5E25A6B4EA3}');
        $out = [
            'writeEnabled' => $this->ReadPropertyBoolean('EnableWrite'),
            'pinRequired'  => $this->ReadPropertyString('PinCode') !== '',
            'modeset'      => $this->ReadPropertyBoolean('EnableModeSettings'),
            'aq'           => $this->ReadPropertyBoolean('EnableAirQuality'),
            'energy'       => $this->ReadPropertyBoolean('EnableEnergy'),
            'interval'     => $this->ReadPropertyInteger('Interval'),
            'flowUnit'     => $this->ReadAttributeInteger('FlowUnit') === 1 ? 'l/s' : 'm³/h',
            'version'      => is_array($lib) ? (string) ($lib['Version'] ?? '') : ''
        ];
        foreach ($idents as $ident) {
            // Nie gelieferte optionale Werte weglassen -> Dashboard zeigt "–" statt 0
            if (isset($optionalSet[$ident]) && !isset($availSet[$ident])) {
                continue;
            }
            $vid = @$this->GetIDForIdent($ident);
            if ($vid !== false && $vid > 0) {
                $out[$ident] = GetValue($vid);
            }
        }
        return $out;
    }

    // =====================================================================
    // Register-Parsing
    // =====================================================================

    private function parseMain(?array $b): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 1] ?? null;

        $this->SetValueSafe('Power', $g(1) === 1);
        $this->SetValueSafe('EcoMode', $g(3) === 1);
        $this->SetValueSafe('AutoMode', $g(4) === 1);
        if ($g(5) !== null) {
            $this->SetValueSafe('Modus', (int) $g(5));
        }
        if ($g(7) !== null) {
            $this->SetValueSafe('NaechsterModus', (int) $g(7));
        }
        if ($g(11) !== null) {
            $this->SetValueSafe('TempKontrolle', (int) $g(11));
        }

        // Strömungseinheit (Register 28) -> Profil-Suffix nachziehen
        $unit = $g(28);
        if ($unit !== null && (int) $unit !== $this->ReadAttributeInteger('FlowUnit')) {
            $this->WriteAttributeInteger('FlowUnit', (int) $unit);
            $suffix = ((int) $unit === 1) ? ' l/s' : ' m³/h';
            foreach (['PAIR.Flow', 'PAIR.FlowSoll'] as $profile) {
                if (IPS_VariableProfileExists($profile)) {
                    IPS_SetVariableProfileText($profile, '', $suffix);
                }
            }
        }
    }

    private function parseMonitoring(?array $b, ?array $bMain): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 900] ?? null;

        $mask = (int) ($g(900) ?? 0);
        $this->SetValueSafe('Ventilator', (bool) ($mask & (1 << 2)));
        $this->SetValueSafe('Rotor', (bool) ($mask & (1 << 3)));
        $this->SetValueSafe('Heizen', (bool) ($mask & (1 << 4)));
        $this->SetValueSafe('Kuehlen', (bool) ($mask & (1 << 5)));
        $this->SetValueSafe('FreiesHeizen', (bool) ($mask & (1 << 9)));
        $this->SetValueSafe('FreiesKuehlen', (bool) ($mask & (1 << 10)));
        $this->SetValueSafe('Stoerung', (bool) ($mask & (1 << 11)));
        $this->SetValueSafe('Warnung', (bool) ($mask & (1 << 12)));

        // Ausstattung lt. Register 901: Bit 0 E-Heizer, Bit 1 Wasserregister, Bit 2 DX
        $cfg = (int) ($g(901) ?? 0);
        $hasEl = (bool) ($cfg & 1);
        $hasWater = (bool) ($cfg & 2);

        $this->SetValueSafe('ZuluftTemp', $this->s16((int) $g(902)) / 10);
        $this->SetValueSafe('AbluftTemp', $this->s16((int) $g(903)) / 10);
        $this->SetValueSafe('AussenTemp', $this->s16((int) $g(904)) / 10);
        if ($hasWater) {
            $this->SetValueSafe('WasserTemp', $this->s16((int) $g(905)) / 10);
        }

        $this->SetValueSafe('ZuluftStrom', $this->u32($g(906), $g(907)));
        $this->SetValueSafe('AbluftStrom', $this->u32($g(908), $g(909)));
        $this->SetValueSafe('ZuluftVent', ((int) $g(910)) / 10);
        $this->SetValueSafe('AbluftVent', ((int) $g(911)) / 10);
        $this->SetValueSafe('Waermetauscher', ((int) $g(912)) / 10);
        if ($hasEl) {
            $this->SetValueSafe('ElHeizer', ((int) $g(913)) / 10);
        }
        $this->SetValueSafe('FilterVerschmutzung', (int) $g(917));
        if ($g(918) !== null && (int) $g(918) > 0) {
            $this->SetValueSafe('Luftklappen', (int) $g(918));
        }

        if ($this->ReadPropertyBoolean('EnableEnergy')) {
            $this->SetValueSafe('Leistung', (int) $g(921));
            if ($hasEl || $hasWater) {
                $this->SetValueSafe('Heizleistung', (int) $g(922));
            }
            $this->SetValueSafe('Rueckgewinnung', (int) $g(923));
            $this->SetValueSafe('Effizienz', (int) $g(924));
            $this->SetValueSafe('Energiesparen', (int) $g(925));
            $this->SetValueSafe('SPI', ((int) $g(926)) / 1000);
        }

        // Klartext-Status aus Modus + Statusbits
        $mode = ($bMain !== null && isset($bMain[4])) ? (int) $bMain[4] : -1;
        $power = ($bMain !== null && isset($bMain[0])) ? ((int) $bMain[0] === 1) : true;
        $parts = [];
        if (!$power || $mode === 10) {
            $parts[] = 'Aus';
        } else {
            $parts[] = $this->modeName($mode);
            if ($mask & (1 << 4)) {
                $parts[] = 'Heizen';
            }
            if ($mask & (1 << 5)) {
                $parts[] = 'Kühlen';
            }
            if ($mask & (1 << 9)) {
                $parts[] = 'freies Heizen';
            }
            if ($mask & (1 << 10)) {
                $parts[] = 'freie Kühlung';
            }
        }
        if ($mask & (1 << 11)) {
            $parts[] = 'STÖRUNG';
        } elseif ($mask & (1 << 12)) {
            $parts[] = 'Warnung';
        }
        $this->SetValueSafe('StatusText', implode(' · ', $parts));
    }

    private function parseAlarms(?array $b): void
    {
        if ($b === null) {
            return;
        }
        $count = (int) ($b[0] ?? 0);
        $this->SetValueSafe('AlarmAnzahl', $count);

        $codes = [];
        for ($i = 1; $i <= min($count, 10); $i++) {
            $raw = (int) ($b[$i] ?? 0);
            if ($raw === 0) {
                continue;
            }
            $codes[] = ($raw & 0x7F) . (($raw & 0x80) ? 'W' : 'F');
        }
        $this->SetValueSafe('AlarmText', $count === 0 ? 'keine' : implode(', ', $codes));
    }

    private function parseModeSettings(?array $b): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 100] ?? null;

        $this->SetValueSafe('ZuluftAbwesendSoll', $this->u32($g(100), $g(101)));
        $this->SetValueSafe('AbluftAbwesendSoll', $this->u32($g(102), $g(103)));
        $this->SetValueSafe('SollAbwesend', $this->s16((int) $g(104)) / 10);
        $this->SetValueSafe('ZuluftNormalSoll', $this->u32($g(106), $g(107)));
        $this->SetValueSafe('AbluftNormalSoll', $this->u32($g(108), $g(109)));
        $this->SetValueSafe('SollNormal', $this->s16((int) $g(110)) / 10);
        $this->SetValueSafe('ZuluftIntensivSoll', $this->u32($g(112), $g(113)));
        $this->SetValueSafe('AbluftIntensivSoll', $this->u32($g(114), $g(115)));
        $this->SetValueSafe('SollIntensiv', $this->s16((int) $g(116)) / 10);
        $this->SetValueSafe('ZuluftBoostSoll', $this->u32($g(118), $g(119)));
        $this->SetValueSafe('AbluftBoostSoll', $this->u32($g(120), $g(121)));
        $this->SetValueSafe('SollBoost', $this->s16((int) $g(122)) / 10);
        if ($g(130) !== null) {
            $this->SetValueSafe('KuecheTimer', (int) $g(130));
        }
        if ($g(137) !== null) {
            $this->SetValueSafe('FeuerTimer', (int) $g(137));
        }
        if ($g(145) !== null) {
            $this->SetValueSafe('OverrideTimer', (int) $g(145));
        }
    }

    private function parseAirQuality(?array $b): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 200] ?? null;

        $this->SetValueSafe('AQAktiv', $g(205) === 1);
        if ($g(206) !== null) {
            $this->SetValueSafe('AQTempSoll', $this->s16((int) $g(206)) / 10);
        }
        if ($g(207) !== null) {
            $this->SetValueSafe('AQSollwert', (int) $g(207));
        }
        if ($g(208) !== null) {
            $this->SetValueSafe('AQFeuchteSoll', (int) $g(208));
        }
        if ($g(209) !== null) {
            $this->SetValueSafe('AQMinIntensitaet', (int) $g(209));
        }
        if ($g(210) !== null) {
            $this->SetValueSafe('AQMaxIntensitaet', (int) $g(210));
        }
    }

    private function parseConsumption(?array $b): void
    {
        if ($b === null) {
            return;
        }
        $g = fn (int $reg) => $b[$reg - 927] ?? null;

        $this->SetValueSafe('VerbrauchTag', $this->u32($g(927), $g(928)) / 1000);
        $this->SetValueSafe('VerbrauchMonat', $this->u32($g(929), $g(930)) / 1000);
        $this->SetValueSafe('VerbrauchGesamt', $this->u32($g(931), $g(932)) / 1000);

        // Zusatzheizer-Verbrauch nur übernehmen, wenn er je Energie gezählt hat
        $ht = $this->u32($g(933), $g(934));
        $hm = $this->u32($g(935), $g(936));
        $hg = $this->u32($g(937), $g(938));
        if ($hg > 0 || $hm > 0 || $ht > 0) {
            $this->SetValueSafe('HeizerVerbrauchTag', $ht / 1000);
            $this->SetValueSafe('HeizerVerbrauchMonat', $hm / 1000);
            $this->SetValueSafe('HeizerVerbrauchGesamt', $hg / 1000);
        }

        $this->SetValueSafe('RueckgewinnungTag', $this->u32($g(939), $g(940)) / 1000);
        $this->SetValueSafe('RueckgewinnungMonat', $this->u32($g(941), $g(942)) / 1000);
        $this->SetValueSafe('RueckgewinnungGesamt', $this->u32($g(943), $g(944)) / 1000);
    }

    private function parsePanel(?array $b): void
    {
        if ($b === null) {
            return;
        }
        // Ohne angeschlossenes Bedienteil liefern die Register 0 -> dann weglassen
        $raw = (int) ($b[0] ?? 0);
        if ($raw !== 0) {
            $this->SetValueSafe('PanelTemp', $this->s16($raw) / 10);
        }
        $hum = (int) ($b[1] ?? 0);
        if ($hum > 0) {
            $this->SetValueSafe('PanelFeuchte', $this->s8($hum));
        }
        $aq = (int) ($b[2] ?? 0);
        if ($aq > 0) {
            $this->SetValueSafe('PanelAQ', $aq);
        }
    }

    private function parseFirmware(?array $b): void
    {
        if ($b === null || count($b) < 2) {
            return;
        }
        $this->SetValueSafe('Firmware', $this->fwString(($b[0] << 16) | $b[1]));
    }

    private function fwString(int $v): string
    {
        return sprintf('%d.%d.%d.%d', ($v >> 24) & 0xFF, ($v >> 20) & 0x0F, ($v >> 12) & 0xFF, $v & 0x0FFF);
    }

    private function modeName(int $code): string
    {
        switch ($code) {
            case 0: return 'Standby';
            case 1: return 'Abwesend';
            case 2: return 'Normal';
            case 3: return 'Intensiv';
            case 4: return 'Boost';
            case 5: return 'Küche';
            case 6: return 'Feuerstätte';
            case 7: return 'Override';
            case 8: return 'Urlaub';
            case 9: return 'Luftqualität';
            case 10: return 'Aus';
            default: return 'Unbekannt (' . $code . ')';
        }
    }

    private function s16(int $raw): int
    {
        return ($raw > 0x7FFF) ? $raw - 0x10000 : $raw;
    }

    private function s8(int $raw): int
    {
        return ($raw > 0x7F) ? $raw - 0x100 : $raw;
    }

    private function u32($hi, $lo): int
    {
        return (((int) $hi) << 16) | ((int) $lo & 0xFFFF);
    }

    private function SetValueSafe(string $ident, $value): void
    {
        if ($value === null) {
            return;
        }
        $this->availNow[$ident] = true;
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid <= 0) {
            return;
        }
        if (GetValue($vid) != $value) {
            SetValue($vid, $value);
        }
    }

    // =====================================================================
    // Modbus TCP
    // =====================================================================

    private function semName(): string
    {
        return 'PAIR_Modbus_' . $this->InstanceID;
    }

    /** @return resource|false */
    private function mbConnect()
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        $errno = 0;
        $errstr = '';
        for ($try = 0; $try < 3; $try++) {
            $sock = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($sock !== false) {
                stream_set_timeout($sock, 3);
                return $sock;
            }
            IPS_Sleep(300);
        }
        $this->SendDebug('Modbus', "Connect fehlgeschlagen: {$errstr} ({$errno})", 0);
        return false;
    }

    /**
     * Liest einen Registerblock (FC 03) mit Wiederholversuchen.
     *
     * @param resource|false $sock wird bei Bedarf neu aufgebaut
     */
    private function readBlock(&$sock, int $addr, int $qty, string $name): ?array
    {
        for ($try = 1; $try <= 3; $try++) {
            if ($sock === false) {
                $sock = $this->mbConnect();
                if ($sock === false) {
                    return null;
                }
            }
            $r = $this->mbRead($sock, $addr, $qty);
            if ($r !== null) {
                return $r;
            }
            $this->SendDebug('Modbus', "Block {$name}: Versuch {$try} fehlgeschlagen, Verbindung wird erneuert", 0);
            @fclose($sock);
            $sock = false;
            IPS_Sleep(300);
        }
        $this->SendDebug('Modbus', "Block {$name}: endgültig fehlgeschlagen", 0);
        return null;
    }

    /**
     * Liest $qty Holding-Register (FC 03) ab Adresse $addr.
     * Rückgabe: Array der Rohwerte (unsigned 16 Bit) oder null bei Fehler.
     * $err enthält dann eine Klartext-Ursache (für die Diagnose).
     */
    private function mbRead($sock, int $addr, int $qty, ?string &$err = null): ?array
    {
        $tid = random_int(1, 0xFFFF);
        $unit = $this->ReadPropertyInteger('UnitID');
        $pdu = pack('Cnn', 3, $addr, $qty);
        $adu = pack('nnnC', $tid, 0, strlen($pdu) + 1, $unit) . $pdu;

        if (@fwrite($sock, $adu) === false) {
            $err = 'Schreibfehler auf Socket';
            $this->SendDebug('Modbus', $err, 0);
            return null;
        }

        $hdr = $this->readBytes($sock, 7);
        if ($hdr === null) {
            $err = 'Timeout – keine Antwort';
            $this->SendDebug('Modbus', "Timeout (FC3 Adr {$addr})", 0);
            return null;
        }
        $h = unpack('ntid/nproto/nlen/Cunit', $hdr);
        $body = $this->readBytes($sock, $h['len'] - 1);
        if ($body === null || strlen($body) < 2) {
            $err = 'Antwort unvollständig';
            return null;
        }

        $rfc = ord($body[0]);
        if ($rfc & 0x80) {
            $code = ord($body[1]);
            $names = [1 => 'Illegal Function', 2 => 'Illegal Data Address', 3 => 'Illegal Data Value', 4 => 'Device Failure', 6 => 'Device Busy'];
            $err = 'Modbus-Exception ' . $code . (isset($names[$code]) ? ' (' . $names[$code] . ')' : '');
            $this->SendDebug('Modbus', $err . " (FC3 Adr {$addr})", 0);
            return null;
        }

        $count = ord($body[1]);
        $data = substr($body, 2, $count);
        if (strlen($data) < $count) {
            $err = 'Datenteil unvollständig';
            return null;
        }
        return array_values(unpack('n*', $data));
    }

    /**
     * Schreibt einen Wert in ein Holding-Register (FC 06).
     */
    private function mbWrite($sock, int $addr, int $value): bool
    {
        $tid = random_int(1, 0xFFFF);
        $unit = $this->ReadPropertyInteger('UnitID');
        $pdu = pack('Cnn', 6, $addr, $value & 0xFFFF);
        $adu = pack('nnnC', $tid, 0, strlen($pdu) + 1, $unit) . $pdu;

        if (@fwrite($sock, $adu) === false) {
            return false;
        }
        $hdr = $this->readBytes($sock, 7);
        if ($hdr === null) {
            return false;
        }
        $h = unpack('ntid/nproto/nlen/Cunit', $hdr);
        $body = $this->readBytes($sock, $h['len'] - 1);
        if ($body === null || strlen($body) < 1) {
            return false;
        }
        if (ord($body[0]) & 0x80) {
            $this->SendDebug('Modbus', 'Write Exception Code ' . ord($body[1] ?? "\0") . " (Adr {$addr})", 0);
            return false;
        }
        return true;
    }

    /**
     * Schreibt mehrere Register am Stück (FC 16), z. B. 32-Bit-Luftmengen.
     *
     * @param int[] $values Registerwerte (16 Bit), High-Word zuerst
     */
    private function mbWriteMultiple($sock, int $addr, array $values): bool
    {
        $tid = random_int(1, 0xFFFF);
        $unit = $this->ReadPropertyInteger('UnitID');
        $pdu = pack('CnnC', 16, $addr, count($values), count($values) * 2);
        foreach ($values as $v) {
            $pdu .= pack('n', $v & 0xFFFF);
        }
        $adu = pack('nnnC', $tid, 0, strlen($pdu) + 1, $unit) . $pdu;

        if (@fwrite($sock, $adu) === false) {
            return false;
        }
        $hdr = $this->readBytes($sock, 7);
        if ($hdr === null) {
            return false;
        }
        $h = unpack('ntid/nproto/nlen/Cunit', $hdr);
        $body = $this->readBytes($sock, $h['len'] - 1);
        if ($body === null || strlen($body) < 1) {
            return false;
        }
        if (ord($body[0]) & 0x80) {
            $this->SendDebug('Modbus', 'Write16 Exception Code ' . ord($body[1] ?? "\0") . " (Adr {$addr})", 0);
            return false;
        }
        return true;
    }

    private function readBytes($sock, int $len): ?string
    {
        $buf = '';
        // Harte Obergrenze gegen Endlos-Warten, falls das Gerät die Verbindung
        // annimmt, aber nicht antwortet (sonst Busy-Loop auf fread)
        $deadline = microtime(true) + 4.0;
        while (strlen($buf) < $len) {
            if (microtime(true) > $deadline) {
                $this->SendDebug('Modbus', 'readBytes: Deadline erreicht (Gerät antwortet nicht)', 0);
                return null;
            }
            $chunk = @fread($sock, $len - strlen($buf));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($sock);
                if ($meta['timed_out'] || feof($sock)) {
                    return null;
                }
                usleep(20000); // 20 ms – kein Busy-Loop
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Erkennt automatisch, ob das Gerät die dokumentierte Registernummer 1:1
     * oder um -1 versetzt (Modbus-Konvention) erwartet.
     * Prüfregister: 30 (Jahr) muss einen plausiblen Wert liefern.
     */
    private function detectOffset($sock): int
    {
        $cached = $this->ReadAttributeInteger('AddrOffset');
        if ($cached === -1 || $cached === 0) {
            return $cached;
        }

        foreach ([-1, 0] as $off) {
            $r = $this->mbRead($sock, 30 + $off, 1);
            if ($r !== null && $r[0] >= 2016 && $r[0] <= 2099) {
                $this->WriteAttributeInteger('AddrOffset', $off);
                $this->SendDebug('Modbus', "Adress-Offset erkannt: {$off} (Jahr {$r[0]})", 0);
                return $off;
            }
        }

        $this->SendDebug('Modbus', 'Adress-Offset nicht erkennbar, verwende -1 (Modbus-Konvention)', 0);
        return -1;
    }

    // =====================================================================
    // Variablen und Profile
    // =====================================================================

    private function MaintainVariables(): void
    {
        $w = $this->ReadPropertyBoolean('EnableWrite');
        $ms = $this->ReadPropertyBoolean('EnableModeSettings');
        $aq = $this->ReadPropertyBoolean('EnableAirQuality');
        $energy = $this->ReadPropertyBoolean('EnableEnergy');

        // [Ident, Name, Typ (0=bool,1=int,2=float,3=string), Profil, Position, anlegen?, Aktion?]
        $vars = [
            ['Power', 'Gerät Ein/Aus', 0, '~Switch', 10, true, $w],
            ['Modus', 'Betriebsmodus', 1, 'PAIR.Modus', 11, true, $w],
            ['EcoMode', 'ECO-Modus', 0, '~Switch', 12, true, $w],
            ['AutoMode', 'AUTO-Modus', 0, '~Switch', 13, true, $w],
            ['StatusText', 'Status', 3, '', 14, true, false],
            ['NaechsterModus', 'Nächster Modus (Zeitplan)', 1, 'PAIR.Modus', 15, true, false],
            ['TempKontrolle', 'Temperatur-Regelung', 1, 'PAIR.TempKontrolle', 16, true, $w],

            ['Ventilator', 'Ventilatoren', 0, 'PAIR.Aktiv', 20, true, false],
            ['Rotor', 'Wärmetauscher aktiv', 0, 'PAIR.Aktiv', 21, true, false],
            ['Heizen', 'Heizen', 0, 'PAIR.Aktiv', 22, true, false],
            ['Kuehlen', 'Kühlen', 0, 'PAIR.Aktiv', 23, true, false],
            ['FreiesHeizen', 'Freies Heizen', 0, 'PAIR.Aktiv', 24, true, false],
            ['FreiesKuehlen', 'Freie Kühlung (Bypass)', 0, 'PAIR.Aktiv', 25, true, false],
            ['Stoerung', 'Störung (F-Alarm)', 0, '~Alert', 26, true, false],
            ['Warnung', 'Warnung (W-Alarm)', 0, '~Alert', 27, true, false],
            ['AlarmAnzahl', 'Aktive Alarme', 1, '', 28, true, false],
            ['AlarmText', 'Alarm-Codes', 3, '', 29, true, false],

            ['AussenTemp', 'Außenluft-Temperatur', 2, 'PAIR.TempC', 40, true, false],
            ['ZuluftTemp', 'Zuluft-Temperatur', 2, 'PAIR.TempC', 41, true, false],
            ['AbluftTemp', 'Abluft-Temperatur', 2, 'PAIR.TempC', 42, true, false],
            ['WasserTemp', 'Wasserregister-Temperatur', 2, 'PAIR.TempC', 43, true, false],

            ['ZuluftStrom', 'Zuluft-Menge aktuell', 1, 'PAIR.Flow', 50, true, false],
            ['AbluftStrom', 'Abluft-Menge aktuell', 1, 'PAIR.Flow', 51, true, false],
            ['ZuluftVent', 'Zuluft-Ventilator', 2, 'PAIR.Prozent', 52, true, false],
            ['AbluftVent', 'Abluft-Ventilator', 2, 'PAIR.Prozent', 53, true, false],
            ['Waermetauscher', 'Wärmetauscher-Leistung', 2, 'PAIR.Prozent', 54, true, false],
            ['ElHeizer', 'Elektrischer Heizer', 2, 'PAIR.Prozent', 55, true, false],
            ['FilterVerschmutzung', 'Filter-Verschmutzung', 1, 'PAIR.Pct', 56, true, false],
            ['Luftklappen', 'Luftklappen', 1, 'PAIR.Pct', 57, true, false],

            ['SollAbwesend', 'Sollwert Abwesend', 2, 'PAIR.TempSoll', 60, $ms, $ms && $w],
            ['SollNormal', 'Sollwert Normal', 2, 'PAIR.TempSoll', 61, $ms, $ms && $w],
            ['SollIntensiv', 'Sollwert Intensiv', 2, 'PAIR.TempSoll', 62, $ms, $ms && $w],
            ['SollBoost', 'Sollwert Boost', 2, 'PAIR.TempSoll', 63, $ms, $ms && $w],
            ['ZuluftAbwesendSoll', 'Zuluft Abwesend', 1, 'PAIR.FlowSoll', 70, $ms, $ms && $w],
            ['AbluftAbwesendSoll', 'Abluft Abwesend', 1, 'PAIR.FlowSoll', 71, $ms, $ms && $w],
            ['ZuluftNormalSoll', 'Zuluft Normal', 1, 'PAIR.FlowSoll', 72, $ms, $ms && $w],
            ['AbluftNormalSoll', 'Abluft Normal', 1, 'PAIR.FlowSoll', 73, $ms, $ms && $w],
            ['ZuluftIntensivSoll', 'Zuluft Intensiv', 1, 'PAIR.FlowSoll', 74, $ms, $ms && $w],
            ['AbluftIntensivSoll', 'Abluft Intensiv', 1, 'PAIR.FlowSoll', 75, $ms, $ms && $w],
            ['ZuluftBoostSoll', 'Zuluft Boost', 1, 'PAIR.FlowSoll', 76, $ms, $ms && $w],
            ['AbluftBoostSoll', 'Abluft Boost', 1, 'PAIR.FlowSoll', 77, $ms, $ms && $w],
            ['KuecheTimer', 'Timer Küche', 1, 'PAIR.Minuten', 78, $ms, $ms && $w],
            ['FeuerTimer', 'Timer Feuerstätte', 1, 'PAIR.Minuten', 79, $ms, $ms && $w],
            ['OverrideTimer', 'Timer Override', 1, 'PAIR.Minuten', 80, $ms, $ms && $w],

            ['AQAktiv', 'Luftqualität-Regelung', 0, '~Switch', 90, $aq, $aq && $w],
            ['AQTempSoll', 'Luftqualität Temperatur-Soll', 2, 'PAIR.TempSoll', 91, $aq, $aq && $w],
            ['AQSollwert', 'Luftqualität Sollwert (CO₂/VOC)', 1, 'PAIR.ppm', 92, $aq, $aq && $w],
            ['AQFeuchteSoll', 'Feuchtigkeit-Sollwert', 1, 'PAIR.PctSoll', 93, $aq, $aq && $w],
            ['AQMinIntensitaet', 'Luftqualität min. Intensität', 1, 'PAIR.PctSoll', 94, $aq, $aq && $w],
            ['AQMaxIntensitaet', 'Luftqualität max. Intensität', 1, 'PAIR.PctSoll', 95, $aq, $aq && $w],

            ['Leistung', 'Leistungsaufnahme', 1, 'PAIR.W', 100, $energy, false],
            ['Heizleistung', 'Heizleistung', 1, 'PAIR.W', 101, $energy, false],
            ['Rueckgewinnung', 'Wärmerückgewinnung', 1, 'PAIR.W', 102, $energy, false],
            ['Effizienz', 'Wärmetauscher-Effizienz', 1, 'PAIR.Pct', 103, $energy, false],
            ['Energiesparen', 'Energie sparen', 1, 'PAIR.Pct', 104, $energy, false],
            ['SPI', 'SPI (spez. Leistungsaufnahme)', 2, 'PAIR.SPI', 105, $energy, false],
            ['VerbrauchTag', 'Verbrauch heute', 2, 'PAIR.kWh', 110, $energy, false],
            ['VerbrauchMonat', 'Verbrauch Monat', 2, 'PAIR.kWh', 111, $energy, false],
            ['VerbrauchGesamt', 'Verbrauch gesamt', 2, 'PAIR.kWh', 112, $energy, false],
            ['HeizerVerbrauchTag', 'Heizer-Verbrauch heute', 2, 'PAIR.kWh', 113, $energy, false],
            ['HeizerVerbrauchMonat', 'Heizer-Verbrauch Monat', 2, 'PAIR.kWh', 114, $energy, false],
            ['HeizerVerbrauchGesamt', 'Heizer-Verbrauch gesamt', 2, 'PAIR.kWh', 115, $energy, false],
            ['RueckgewinnungTag', 'Rückgewinnung heute', 2, 'PAIR.kWh', 116, $energy, false],
            ['RueckgewinnungMonat', 'Rückgewinnung Monat', 2, 'PAIR.kWh', 117, $energy, false],
            ['RueckgewinnungGesamt', 'Rückgewinnung gesamt', 2, 'PAIR.kWh', 118, $energy, false],

            ['PanelTemp', 'Raumtemperatur (Panel)', 2, 'PAIR.TempC', 120, true, false],
            ['PanelFeuchte', 'Raumfeuchte (Panel)', 1, 'PAIR.Pct', 121, true, false],
            ['PanelAQ', 'Luftqualität (Panel)', 1, 'PAIR.ppm', 122, true, false],

            ['Firmware', 'Firmware', 3, '', 130, true, false],
            ['LastUpdate', 'Letzte Aktualisierung', 1, '~UnixTimestamp', 131, true, false]
        ];

        foreach ($vars as [$ident, $name, $type, $profile, $pos, $keep, $action]) {
            $this->MaintainVariable($ident, $name, $type, $profile, $pos, $keep);
            if ($keep) {
                $this->MaintainAction($ident, $action);
            }
        }
    }

    private function RegisterProfiles(): void
    {
        $flowSuffix = $this->ReadAttributeInteger('FlowUnit') === 1 ? ' l/s' : ' m³/h';

        $this->ProfileFloat('PAIR.TempC', ' °C', 1, 0, 0, 0, 'Temperature');
        $this->ProfileFloat('PAIR.Prozent', ' %', 1, 0, 100, 0, 'Intensity');
        $this->ProfileFloat('PAIR.TempSoll', ' °C', 1, 5, 40, 0.5, 'Temperature');
        $this->ProfileFloat('PAIR.kWh', ' kWh', 1, 0, 0, 0, 'Electricity');
        $this->ProfileFloat('PAIR.SPI', ' W/(m³/h)', 2, 0, 0, 0, 'Graph');

        $this->ProfileInt('PAIR.Flow', $flowSuffix, 0, 0, 0, 'WindSpeed');
        $this->ProfileInt('PAIR.FlowSoll', $flowSuffix, 0, 400, 5, 'WindSpeed');
        $this->ProfileInt('PAIR.Pct', ' %', 0, 100, 0, 'Intensity');
        $this->ProfileInt('PAIR.PctSoll', ' %', 0, 100, 5, 'Intensity');
        $this->ProfileInt('PAIR.W', ' W', 0, 0, 0, 'Electricity');
        $this->ProfileInt('PAIR.ppm', ' ppm', 0, 2000, 50, 'Leaf');
        $this->ProfileInt('PAIR.Minuten', ' min', 0, 300, 5, 'Clock');

        if (!IPS_VariableProfileExists('PAIR.Aktiv')) {
            IPS_CreateVariableProfile('PAIR.Aktiv', 0);
            IPS_SetVariableProfileIcon('PAIR.Aktiv', 'Power');
            IPS_SetVariableProfileAssociation('PAIR.Aktiv', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('PAIR.Aktiv', 1, 'An', '', 0x00A65E);
        }

        if (!IPS_VariableProfileExists('PAIR.Modus')) {
            IPS_CreateVariableProfile('PAIR.Modus', 1);
            IPS_SetVariableProfileIcon('PAIR.Modus', 'Ventilation');
            IPS_SetVariableProfileAssociation('PAIR.Modus', 0, 'Standby', '', 0x808080);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 1, 'Abwesend', '', 0x3D9EE8);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 2, 'Normal', '', 0x00A65E);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 3, 'Intensiv', '', 0xE8A33D);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 4, 'Boost', '', 0xE86A3D);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 5, 'Küche', '', 0xE8CE3D);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 6, 'Feuerstätte', '', 0xFF7043);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 7, 'Override', '', 0xAB7DF6);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 8, 'Urlaub', '', 0x4DC3F4);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 9, 'Luftqualität', '', 0x7CCF6E);
            IPS_SetVariableProfileAssociation('PAIR.Modus', 10, 'Aus', '', -1);
        }

        if (!IPS_VariableProfileExists('PAIR.TempKontrolle')) {
            IPS_CreateVariableProfile('PAIR.TempKontrolle', 1);
            IPS_SetVariableProfileIcon('PAIR.TempKontrolle', 'Temperature');
            IPS_SetVariableProfileAssociation('PAIR.TempKontrolle', 0, 'Zuluft', '', -1);
            IPS_SetVariableProfileAssociation('PAIR.TempKontrolle', 1, 'Abluft', '', -1);
            IPS_SetVariableProfileAssociation('PAIR.TempKontrolle', 2, 'Balance', '', -1);
            IPS_SetVariableProfileAssociation('PAIR.TempKontrolle', 3, 'Raum', '', -1);
        }
    }

    private function ProfileFloat(string $name, string $suffix, int $digits, float $min, float $max, float $step, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        IPS_SetVariableProfileIcon($name, $icon);
    }

    private function ProfileInt(string $name, string $suffix, float $min, float $max, float $step, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        IPS_SetVariableProfileIcon($name, $icon);
    }

    // =====================================================================
    // Archivierung
    // =====================================================================

    /**
     * Aktiviert die Archivierung (Archive Control) für alle Zahlen-/Bool-Variablen.
     */
    private function SetupArchive(): void
    {
        if (!$this->ReadPropertyBoolean('EnableArchive')) {
            return;
        }
        $acIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acIDs) === 0) {
            return;
        }
        $ac = $acIDs[0];
        $skip = ['StatusText' => true, 'AlarmText' => true, 'Firmware' => true, 'LastUpdate' => true];

        $changed = false;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2 /* Variable */) {
                continue;
            }
            if (isset($skip[$obj['ObjectIdent']])) {
                continue;
            }
            if (!AC_GetLoggingStatus($ac, $cid)) {
                AC_SetLoggingStatus($ac, $cid, true);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($ac);
        }
    }

    // =====================================================================
    // Diagnose
    // =====================================================================

    /**
     * Gibt alle relevanten Register als Rohwerte aus (für die Fehlersuche).
     */
    public function DumpRegisters(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return 'Bitte zuerst die IP-Adresse konfigurieren.';
        }
        if (!IPS_SemaphoreEnter($this->semName(), 5000)) {
            return 'Modbus-Zugriff belegt, bitte erneut versuchen.';
        }
        try {
            $sock = $this->mbConnect();
            if ($sock === false) {
                return 'Keine Verbindung zum Lüftungsgerät.';
            }

            $out = '';
            try {
                $off = $this->detectOffset($sock);
                $out .= "Adress-Offset: {$off}\n";

                $blocks = [
                    ['Hauptkontrolle', 1, 34],
                    ['Einstellungsmodi', 100, 46],
                    ['Eco/Luftqualität', 200, 15],
                    ['Alarme', 600, 11],
                    ['Überwachung', 900, 27],
                    ['Verbrauch', 927, 19],
                    ['Panel', 946, 6],
                    ['Firmware', 1000, 2]
                ];
                foreach ($blocks as [$name, $start, $qty]) {
                    $out .= "\n== {$name} ==\n";
                    $r = $this->mbRead($sock, $start + $off, $qty);
                    if ($r === null) {
                        $out .= "keine Antwort\n";
                        continue;
                    }
                    foreach ($r as $i => $raw) {
                        $reg = $start + $i;
                        $signed = $this->s16($raw);
                        $out .= sprintf("%d: %u  [signed=%d  /10=%.1f]\n", $reg, $raw, $signed, $signed / 10);
                    }
                }
            } finally {
                fclose($sock);
            }
            return $out;
        } finally {
            IPS_SemaphoreLeave($this->semName());
        }
    }

    // =====================================================================
    // WebHook-Registrierung
    // =====================================================================

    private function RegisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::GUID_WEBHOOK_CONTROL);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $index => $entry) {
            if ($entry['Hook'] === $hook) {
                if ($entry['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }
}
