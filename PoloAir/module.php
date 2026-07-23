<?php

declare(strict_types=1);

/**
 * PoloAir – Poloplast POLO-AIR Komfortwohnraumlüftung über Modbus TCP
 *
 * Unterstützt beide Komfovent-Steuerungsgenerationen, die in POLO-AIR Geräten
 * verbaut wurden (die Generation wird automatisch erkannt):
 *
 *  - C6  (neuere Geräte, Ethernet onboard):
 *    Registerbasis "Gebrauchsanweisung POLO-AIR + Geräte – Modbusverbindung" (08/2018).
 *    Hauptkontrolle 1–34, Einstellungsmodi 100–145, Eco/Luftqualität 200–214,
 *    Alarme 600–610, Überwachung 900–951, Firmware 1000–1001.
 *
 *  - C4  (ältere Geräte mit PING2-Netzwerkadapter):
 *    Registerbasis "Modbus registers of C4 controller" (Komfovent/Vortvent 2016).
 *    General 1000–1013, Ventilation 1100–1116, Temperatur 1200–1205.
 *
 * Alle Register sind Holding-Register (FC 03 lesen, FC 06/16 schreiben).
 */
class PoloAir extends IPSModule
{
    private const WEBHOOK = '/hook/poloair';
    private const GUID_WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // Magischer Wert zum Quittieren aller aktiven Alarme (C6, Register 600)
    private const ALARM_RESET = 0x99C6;

    private const CTRL_AUTO = 0;
    private const CTRL_C6 = 1;
    private const CTRL_C4 = 2;

    // Schreibbare Register C6: Ident => [Register, Kodierung]
    //  bool = 0/1 | raw = ganzzahlig | t10 = Temperatur ×10 | u32 = 2 Register (High zuerst)
    private const WRITE_MAP_C6 = [
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

    // Schreibbare Register C4 (PING2): Ident => [Register, Kodierung]
    private const WRITE_MAP_C4 = [
        'Power'         => [1000, 'bool'],
        'Saison'        => [1001, 'bool'],
        'Stufe'         => [1100, 'raw'],
        'AutoMode'      => [1102, 'bool'],
        'ZuluftStufe1'  => [1103, 'raw'],
        'ZuluftStufe2'  => [1104, 'raw'],
        'ZuluftStufe3'  => [1105, 'raw'],
        'ZuluftStufe4'  => [1106, 'raw'],
        'AbluftStufe1'  => [1107, 'raw'],
        'AbluftStufe2'  => [1108, 'raw'],
        'AbluftStufe3'  => [1109, 'raw'],
        'AbluftStufe4'  => [1110, 'raw'],
        'OVREnable'     => [1111, 'bool'],
        'OVRTime'       => [1112, 'raw'],
        'SollTemp'      => [1201, 't10'],
        'TempKorrektur' => [1202, 't10']
    ];

    // C4 Stop-Codes (Register 1009)
    private const C4_STOP_CODES = [
        3  => 'Rotor gestoppt',
        4  => 'Heizer-Überhitzung',
        9  => 'Zuluftfühler B1 defekt',
        19 => 'Zulufttemperatur zu niedrig',
        20 => 'Zulufttemperatur zu hoch',
        27 => 'Wassertemperatur zu niedrig',
        28 => 'Frostgefahr'
    ];

    // C4 Warn-Bits (Register 1007)
    private const C4_WARN_BITS = [
        11 => 'Rotor gestoppt',
        13 => 'Heizer aus',
        14 => 'Service fällig'
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
        $this->RegisterPropertyInteger('Controller', self::CTRL_AUTO);
        $this->RegisterPropertyBoolean('EnableWrite', true);
        $this->RegisterPropertyBoolean('EnableModeSettings', true);
        $this->RegisterPropertyBoolean('EnableAirQuality', true);
        $this->RegisterPropertyBoolean('EnableEnergy', true);
        $this->RegisterPropertyBoolean('EnableDashboard', true);
        $this->RegisterPropertyBoolean('EnableArchive', true);
        $this->RegisterPropertyString('PinCode', '');
        $this->RegisterPropertyBoolean('PinExemptBasic', true);

        // Adress-Offset: die Registernummern der Doku sind 1-basiert, auf dem Bus
        // meist 0-basiert (Registernummer-1). Wird automatisch erkannt. -99 = unbekannt.
        $this->RegisterAttributeInteger('AddrOffset', -99);
        // Erkannte Steuerungsgeneration (0 = unbekannt, 1 = C6, 2 = C4)
        $this->RegisterAttributeInteger('CtrlType', 0);
        // Idents, die schon mindestens einmal einen echten Wert geliefert haben
        $this->RegisterAttributeString('AvailIdents', '[]');
        // Strömungseinheit lt. C6-Register 28 (0 = m³/h, 1 = l/s)
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

    /**
     * Wirksame Steuerungsgeneration: Vorgabe aus der Konfiguration oder
     * automatisch erkannter Typ.
     */
    private function ctrl(): int
    {
        $prop = $this->ReadPropertyInteger('Controller');
        if ($prop !== self::CTRL_AUTO) {
            return $prop;
        }
        return $this->ReadAttributeInteger('CtrlType');
    }

    private function writeMap(): array
    {
        return ($this->ctrl() === self::CTRL_C4) ? self::WRITE_MAP_C4 : self::WRITE_MAP_C6;
    }

    /**
     * Idents, die im Dashboard auch ohne PIN geschaltet werden dürfen.
     * Gedacht für die täglichen Grundfunktionen (Ein/Aus, Stufe bzw. Modus),
     * während Sollwerte und Einstellungen weiter PIN-geschützt bleiben.
     *
     * @return string[]
     */
    private function pinFreeIdents(): array
    {
        if (!$this->ReadPropertyBoolean('PinExemptBasic')) {
            return [];
        }
        return ($this->ctrl() === self::CTRL_C4)
            ? ['Power', 'Stufe']
            : ['Power', 'Modus'];
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
        $ok = false;

        try {
            $ctrlBefore = $this->ReadAttributeInteger('CtrlType');
            $off = $this->detectDevice($sock);
            if ($this->ctrl() === self::CTRL_AUTO) {
                // Erkennung fehlgeschlagen -> keine sinnvolle Abfrage möglich
                $this->SetStatus(202);
                return false;
            }
            // Nach erstmaliger Erkennung die passenden Variablen anlegen
            if ($ctrlBefore !== $this->ReadAttributeInteger('CtrlType')) {
                $this->MaintainVariables();
                $this->SetupArchive();
            }

            if ($this->ctrl() === self::CTRL_C4) {
                $ok = $this->updateC4($sock, $off);
            } else {
                $ok = $this->updateC6($sock, $off);
            }
        } finally {
            if ($sock !== false) {
                @fclose($sock);
            }
        }

        if (!$ok) {
            $this->SetStatus(201);
            return false;
        }

        $this->SetValueSafe('LastUpdate', time());
        $this->persistAvail();
        $this->SetStatus(102);
        return true;
    }

    private function updateC6(&$sock, int $off): bool
    {
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

        if ($bMon === null && $bMain === null) {
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
        return true;
    }

    private function updateC4(&$sock, int $off): bool
    {
        $bGen = $this->readBlock($sock, 1000 + $off, 14, 'General');
        $bVent = $this->readBlock($sock, 1100 + $off, 17, 'Ventilation');
        $bTemp = $this->readBlock($sock, 1200 + $off, 6, 'Temperatur');

        if ($bGen === null && $bVent === null) {
            return false;
        }

        $this->parseC4($bGen, $bVent, $bTemp);
        return true;
    }

    /**
     * Entfernt einen Wert wieder aus der Verfügbar-Liste (z. B. wenn das Gerät
     * einen "kein Fühler"-Marker liefert) und setzt die Variable auf 0.
     */
    private function dropAvail(string $ident): void
    {
        $avail = json_decode($this->ReadAttributeString('AvailIdents'), true);
        if (is_array($avail) && in_array($ident, $avail, true)) {
            $this->WriteAttributeString('AvailIdents', json_encode(array_values(array_diff($avail, [$ident]))));
        }
        $vid = @$this->GetIDForIdent($ident);
        if ($vid !== false && $vid > 0 && GetValue($vid) != 0) {
            SetValue($vid, 0);
        }
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
     * Quittiert alle aktiven Alarme (nur C6 – die C4 bietet dafür kein Register).
     */
    public function ResetAlarms(): bool
    {
        if ($this->ctrl() === self::CTRL_C4) {
            $this->LogMessage('Alarm-Quittierung per Modbus wird von der C4-Steuerung nicht unterstützt.', KL_WARNING);
            return false;
        }
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
            // Gemerkte Erkennung verwerfen -> läuft sichtbar neu
            $this->WriteAttributeInteger('AddrOffset', -99);
            $this->WriteAttributeInteger('CtrlType', 0);

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
                // 2) Steuerung + Adress-Offset erkennen (Jahres-Register beider Generationen)
                $out .= "2) Steuerungs-Erkennung (Jahres-Register, erwartet 2016–2099):\n";
                $candidates = [
                    [self::CTRL_C6, 30, 'C6 (Reg 30)'],
                    [self::CTRL_C4, 1005, 'C4/PING2 (Reg 1005)']
                ];
                foreach ($candidates as [$type, $reg, $name]) {
                    foreach ([-1, 0] as $off) {
                        $err = '';
                        $r = $this->mbRead($sock, $reg + $off, 1, $err);
                        if ($r === null) {
                            $out .= "   {$name}, Offset {$off}: {$err}\n";
                        } else {
                            $ok = ($r[0] >= 2016 && $r[0] <= 2099);
                            $out .= "   {$name}, Offset {$off}: Wert {$r[0]}" . ($ok ? ' -> PASST' : ' (unplausibel)') . "\n";
                        }
                    }
                }
                $off = $this->detectDevice($sock);
                $ctrl = $this->ctrl();
                $ctrlName = [self::CTRL_AUTO => 'NICHT ERKANNT', self::CTRL_C6 => 'C6', self::CTRL_C4 => 'C4 (PING2)'][$ctrl];
                $out .= "   -> Erkannt: {$ctrlName}, Adress-Offset {$off}\n\n";

                if ($ctrl === self::CTRL_AUTO) {
                    $out .= "Keine bekannte Steuerung erkannt. Mögliche Ursachen:\n" .
                        " - anderes Gerät auf dieser IP\n" .
                        " - falsche Unit-ID\n" .
                        " - anderer Modbus-Client blockiert die Steuerung\n";
                    return $out;
                }

                // 3) Wichtige Einzelwerte
                $out .= "3) Einzelwerte:\n";
                if ($ctrl === self::CTRL_C4) {
                    $tests = [
                        [1000, 'Ein/Aus (Reg 1000)', 'raw'],
                        [1101, 'Stufe aktuell (Reg 1101)', 'raw'],
                        [1200, 'Zuluft-Temp (Reg 1200)', 't10'],
                        [1201, 'Sollwert (Reg 1201)', 't10'],
                        [1115, 'Zuluft-Ventilator % (Reg 1115)', 'raw']
                    ];
                } else {
                    $tests = [
                        [1, 'Ein/Aus (Reg 1)', 'raw'],
                        [5, 'Modus (Reg 5)', 'mode'],
                        [902, 'Zuluft-Temp (Reg 902)', 't10'],
                        [904, 'Außen-Temp (Reg 904)', 't10'],
                        [917, 'Filter % (Reg 917)', 'raw']
                    ];
                }
                foreach ($tests as [$reg, $name, $conv]) {
                    $err = '';
                    $r = $this->mbRead($sock, $reg + $off, 1, $err);
                    if ($r === null) {
                        $out .= "   {$name}: {$err}\n";
                    } else {
                        $extra = '';
                        if ($conv === 't10') {
                            $extra = sprintf(' = %.1f °C', $this->s16($r[0]) / 10);
                        } elseif ($conv === 'mode') {
                            $extra = ' = ' . $this->modeName($r[0]);
                        }
                        $out .= "   {$name}: {$r[0]}{$extra}\n";
                    }
                }
                $out .= "\n";

                // 4) Blockgrößen-Test
                $out .= "4) Block-Lesetest:\n";
                if ($ctrl === self::CTRL_C4) {
                    $blocks = [
                        [1000, 14, 'General (Reg 1000, 14 Register)'],
                        [1100, 17, 'Ventilation (Reg 1100, 17 Register)'],
                        [1200, 6, 'Temperatur (Reg 1200, 6 Register)']
                    ];
                } else {
                    $blocks = [
                        [1, 34, 'Hauptkontrolle (Reg 1, 34 Register)'],
                        [900, 27, 'Überwachung (Reg 900, 27 Register)'],
                        [600, 11, 'Alarme (Reg 600, 11 Register)'],
                        [100, 46, 'Einstellungsmodi (Reg 100, 46 Register)'],
                        [927, 19, 'Verbrauch (Reg 927, 19 Register)']
                    ];
                }
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
        $map = $this->writeMap();
        if (!isset($map[$Ident])) {
            throw new Exception('Unbekannter Ident: ' . $Ident);
        }
        if (!$this->ReadPropertyBoolean('EnableWrite')) {
            $this->LogMessage('Schreibzugriff ist in der Instanzkonfiguration deaktiviert.', KL_WARNING);
            return;
        }

        [$register, $coding] = $map[$Ident];

        // Plausibilitätsgrenzen lt. Registerlisten
        switch ($Ident) {
            case 'Modus':
                // C6: schreibbar sind nur Abwesend/Normal/Intensiv/Boost
                if (!in_array((int) $Value, [1, 2, 3, 4], true)) {
                    throw new Exception('Als Modus sind nur Abwesend (1), Normal (2), Intensiv (3) und Boost (4) schaltbar.');
                }
                break;
            case 'Stufe':
                // C4: manuell schaltbar sind die Stufen 1–3
                if (!in_array((int) $Value, [1, 2, 3], true)) {
                    throw new Exception('Als Stufe sind nur 1, 2 und 3 schaltbar.');
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
            case 'OVRTime':
                $this->assertRange((int) $Value, 1, 90, $Ident);
                break;
            case 'SollAbwesend':
            case 'SollNormal':
            case 'SollIntensiv':
            case 'SollBoost':
            case 'AQTempSoll':
                $this->assertRange((float) $Value, 5, 40, $Ident);
                break;
            case 'SollTemp':
                $this->assertRange((float) $Value, 0, 30, $Ident);
                break;
            case 'TempKorrektur':
                $this->assertRange((float) $Value, -9, 9, $Ident);
                break;
            case 'ZuluftStufe1':
            case 'ZuluftStufe2':
            case 'ZuluftStufe3':
            case 'ZuluftStufe4':
            case 'AbluftStufe1':
            case 'AbluftStufe2':
            case 'AbluftStufe3':
            case 'AbluftStufe4':
            case 'AQFeuchteSoll':
            case 'AQMinIntensitaet':
            case 'AQMaxIntensitaet':
                $this->assertRange((int) $Value, 0, 100, $Ident);
                break;
            case 'AQSollwert':
                $this->assertRange((int) $Value, 0, 2000, $Ident);
                break;
        }

        // C4: Im AUTO-Modus folgt das Gerät dem Wochenprogramm und ignoriert die
        // manuelle Stufe -> vor dem Stellen auf Manuell umschalten (wie am Bedienteil)
        if ($Ident === 'Stufe' && $this->ctrl() === self::CTRL_C4) {
            $vid = @$this->GetIDForIdent('AutoMode');
            if ($vid !== false && $vid > 0 && GetValue($vid)) {
                if ($this->writeRegister(1102, 0)) {
                    $this->SetValueSafe('AutoMode', false);
                }
            }
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
            $ok = $this->writeRegister($register, $raw & 0xFFFF);
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
                $off = $this->detectDevice($sock);
                $ok = $this->mbWrite($sock, $register + $off, $raw);
                if (!$ok) {
                    // Manche Firmwares akzeptieren Einzelschreiben (FC 06) nicht
                    // -> mit FC 16 (Preset Multiple, 1 Register) erneut versuchen
                    $this->SendDebug('Modbus', "FC6 auf Reg {$register} fehlgeschlagen, versuche FC16", 0);
                    $ok = $this->mbWriteMultiple($sock, $register + $off, [$raw & 0xFFFF]);
                }
                return $ok;
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
                $off = $this->detectDevice($sock);
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
            if (!in_array($cmd, ['resetAlarms', 'setScheduleDay', 'setWeekplan', 'createWeekplan'], true) && !isset($this->writeMap()[$ident])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid ident']);
                return;
            }
            $pin = $this->ReadPropertyString('PinCode');
            $pinFree = ($cmd === '') && in_array($ident, $this->pinFreeIdents(), true);
            if ($pin !== '' && !$pinFree && (string) ($payload['pin'] ?? '') !== $pin) {
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
                } elseif ($cmd === 'setScheduleDay') {
                    $ok = $this->SetScheduleDay(
                        (int) ($payload['day'] ?? -1),
                        json_encode($payload['events'] ?? [])
                    );
                    if (!$ok) {
                        throw new Exception('Zeitprogramm-Schreiben fehlgeschlagen');
                    }
                } elseif ($cmd === 'setWeekplan') {
                    if (!$this->SetWeekplan(json_encode(['groups' => $payload['groups'] ?? []]))) {
                        throw new Exception('Wochenplan-Schreiben fehlgeschlagen');
                    }
                } elseif ($cmd === 'createWeekplan') {
                    $this->CreateWeekplan();
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

        if (isset($_GET['schedule'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->GetSchedule();
            return;
        }

        if (isset($_GET['weekplan'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->GetWeekplan();
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/dashboard.html');
    }

    private function collectData(): array
    {
        $idents = [
            // gemeinsam
            'Power', 'AutoMode', 'StatusText', 'Ventilator', 'Rotor', 'Heizen', 'Kuehlen',
            'Stoerung', 'Warnung', 'AlarmText',
            'ZuluftTemp', 'WasserTemp', 'ZuluftVent', 'AbluftVent', 'Waermetauscher', 'ElHeizer',
            'Firmware', 'LastUpdate',
            // C6
            'Modus', 'EcoMode', 'NaechsterModus', 'TempKontrolle', 'FreiesHeizen', 'FreiesKuehlen',
            'AlarmAnzahl', 'AussenTemp', 'AbluftTemp', 'ZuluftStrom', 'AbluftStrom',
            'FilterVerschmutzung', 'Luftklappen',
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
            // C4
            'Saison', 'Stufe', 'SollTemp', 'TempKorrektur', 'WasserHeizung', 'WasserKuehlung',
            'ZuluftStufe1', 'ZuluftStufe2', 'ZuluftStufe3', 'ZuluftStufe4',
            'AbluftStufe1', 'AbluftStufe2', 'AbluftStufe3', 'AbluftStufe4',
            'OVREnable', 'OVRTime', 'OVRRest'
        ];

        $avail = json_decode($this->ReadAttributeString('AvailIdents'), true);
        if (!is_array($avail)) {
            $avail = [];
        }
        $availSet = array_flip($avail);
        $optionalSet = array_flip(self::OPTIONAL_VALUES);

        $lib = @IPS_GetLibrary('{C32D4669-6698-4C95-9AC1-D5E25A6B4EA3}');
        $out = [
            'ctrl'         => [self::CTRL_AUTO => '?', self::CTRL_C6 => 'C6', self::CTRL_C4 => 'C4'][$this->ctrl()],
            'writeEnabled' => $this->ReadPropertyBoolean('EnableWrite'),
            'pinRequired'  => $this->ReadPropertyString('PinCode') !== '',
            'pinFree'      => $this->pinFreeIdents(),
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
    // Register-Parsing C6
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
            $parts[] = ($mode >= 0) ? $this->modeName($mode) : 'Betrieb';
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

    // =====================================================================
    // Register-Parsing C4 (PING2)
    // =====================================================================

    private function parseC4(?array $bGen, ?array $bVent, ?array $bTemp): void
    {
        $power = true;
        $stufe = -1;
        $heizt = false;
        $kuehlt = false;
        $stoerText = [];
        $warnText = [];

        if ($bGen !== null) {
            $g = fn (int $reg) => $bGen[$reg - 1000] ?? null;

            $power = ($g(1000) === 1);
            $this->SetValueSafe('Power', $power);
            $this->SetValueSafe('Saison', $g(1001) === 1);

            $warn = (int) ($g(1007) ?? 0);
            $flags = (int) ($g(1008) ?? 0);
            $code = (int) ($g(1009) ?? 0);
            $this->SetValueSafe('Warnung', $warn > 0);
            $this->SetValueSafe('Stoerung', $flags > 0 || $code > 0);

            foreach (self::C4_WARN_BITS as $bit => $text) {
                if ($warn & (1 << $bit)) {
                    $warnText[] = $text;
                }
            }
            if ($warn > 0 && count($warnText) === 0) {
                $warnText[] = 'Warnung (' . $warn . ')';
            }
            if ($code > 0) {
                $stoerText[] = self::C4_STOP_CODES[$code] ?? ('Stopp-Code ' . $code);
            }

            $wt = (int) ($g(1010) ?? 0);
            $el = (int) ($g(1011) ?? 0);
            $wh = (int) ($g(1012) ?? 0);
            $wk = (int) ($g(1013) ?? 0);
            $this->SetValueSafe('Waermetauscher', (float) $wt);
            $this->SetValueSafe('ElHeizer', (float) $el);
            $this->SetValueSafe('WasserHeizung', (float) $wh);
            $this->SetValueSafe('WasserKuehlung', (float) $wk);
            $this->SetValueSafe('Rotor', $wt > 0);
            $heizt = ($el > 0 || $wh > 0);
            $kuehlt = ($wk > 0);
            $this->SetValueSafe('Heizen', $heizt);
            $this->SetValueSafe('Kuehlen', $kuehlt);
        }

        if ($bVent !== null) {
            $g = fn (int $reg) => $bVent[$reg - 1100] ?? null;

            if ($g(1101) !== null) {
                $stufe = (int) $g(1101);
                $this->SetValueSafe('Stufe', $stufe);
            }
            $this->SetValueSafe('AutoMode', $g(1102) === 1);
            if ($this->ReadPropertyBoolean('EnableModeSettings')) {
                for ($i = 1; $i <= 4; $i++) {
                    if ($g(1102 + $i) !== null) {
                        $this->SetValueSafe('ZuluftStufe' . $i, (int) $g(1102 + $i));
                    }
                    if ($g(1106 + $i) !== null) {
                        $this->SetValueSafe('AbluftStufe' . $i, (int) $g(1106 + $i));
                    }
                }
            }
            $this->SetValueSafe('OVREnable', $g(1111) === 1);
            if ($g(1112) !== null) {
                $this->SetValueSafe('OVRTime', (int) $g(1112));
            }
            if ($g(1113) !== null) {
                $this->SetValueSafe('OVRRest', (int) $g(1113));
            }
            $this->SetValueSafe('Ventilator', $g(1114) === 1);
            if ($g(1115) !== null) {
                $this->SetValueSafe('ZuluftVent', (float) $g(1115));
            }
            if ($g(1116) !== null) {
                $this->SetValueSafe('AbluftVent', (float) $g(1116));
            }
        }

        if ($bTemp !== null) {
            $g = fn (int $reg) => $bTemp[$reg - 1200] ?? null;

            if ($g(1200) !== null) {
                $this->SetValueSafe('ZuluftTemp', $this->s16((int) $g(1200)) / 10);
            }
            if ($g(1201) !== null) {
                $this->SetValueSafe('SollTemp', $this->s16((int) $g(1201)) / 10);
            }
            if ($g(1202) !== null) {
                $this->SetValueSafe('TempKorrektur', $this->s16((int) $g(1202)) / 10);
            }
            // Wassertemperatur nur bei Geräten mit Wasserregister sinnvoll;
            // 0 und 0x7FFF (32767 = "kein Fühler") ausblenden
            $wraw = $g(1205);
            if ($wraw !== null) {
                $ws = $this->s16((int) $wraw);
                if ((int) $wraw !== 0 && (int) $wraw !== 0x7FFF && $ws > -500 && $ws < 1200) {
                    $this->SetValueSafe('WasserTemp', $ws / 10);
                } else {
                    $this->dropAvail('WasserTemp');
                }
            }
        }

        // Klartext-Status
        $parts = [];
        if (!$power) {
            $parts[] = 'Aus';
        } else {
            $parts[] = ($stufe >= 0) ? ('Stufe ' . $stufe) : 'Betrieb';
            if ($heizt) {
                $parts[] = 'Heizen';
            }
            if ($kuehlt) {
                $parts[] = 'Kühlen';
            }
        }
        if (count($stoerText) > 0) {
            $parts[] = 'STÖRUNG: ' . implode(', ', $stoerText);
        } elseif (count($warnText) > 0) {
            $parts[] = implode(', ', $warnText);
        }
        $this->SetValueSafe('StatusText', implode(' · ', $parts));

        $alarm = array_merge($stoerText, $warnText);
        $this->SetValueSafe('AlarmText', count($alarm) === 0 ? 'keine' : implode(', ', $alarm));
    }

    // =====================================================================
    // Hilfsfunktionen
    // =====================================================================

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
            $err = '';
            $r = $this->mbRead($sock, $addr, $qty, $err);
            if ($r !== null) {
                return $r;
            }
            // Bei einer Modbus-Exception antwortet das Gerät ja -> Wiederholen bringt nichts
            if (strpos($err, 'Exception') !== false) {
                $this->SendDebug('Modbus', "Block {$name}: {$err}", 0);
                return null;
            }
            $this->SendDebug('Modbus', "Block {$name}: Versuch {$try} fehlgeschlagen ({$err}), Verbindung wird erneuert", 0);
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
     * Erkennt Steuerungsgeneration (C6/C4) und Adress-Offset automatisch.
     * Prüfregister: Jahr (C6: Reg 30, C4: Reg 1005) muss plausibel sein.
     * Rückgabe: Adress-Offset (-1 oder 0).
     */
    private function detectDevice($sock): int
    {
        $cachedOff = $this->ReadAttributeInteger('AddrOffset');
        $cachedCtrl = $this->ReadAttributeInteger('CtrlType');
        $prop = $this->ReadPropertyInteger('Controller');

        if (($cachedOff === -1 || $cachedOff === 0)
            && ($prop !== self::CTRL_AUTO || $cachedCtrl !== 0)) {
            return $cachedOff;
        }

        // Bei fest eingestellter Steuerung nur deren Jahres-Register prüfen
        $candidates = [];
        if ($prop === self::CTRL_C6) {
            $candidates[] = [self::CTRL_C6, 30];
        } elseif ($prop === self::CTRL_C4) {
            $candidates[] = [self::CTRL_C4, 1005];
        } else {
            $candidates[] = [self::CTRL_C6, 30];
            $candidates[] = [self::CTRL_C4, 1005];
        }

        foreach ($candidates as [$type, $reg]) {
            foreach ([-1, 0] as $off) {
                $r = $this->mbRead($sock, $reg + $off, 1);
                if ($r !== null && $r[0] >= 2016 && $r[0] <= 2099) {
                    $this->WriteAttributeInteger('AddrOffset', $off);
                    $this->WriteAttributeInteger('CtrlType', $type);
                    $name = ($type === self::CTRL_C4) ? 'C4 (PING2)' : 'C6';
                    $this->SendDebug('Modbus', "Steuerung erkannt: {$name}, Adress-Offset {$off} (Jahr {$r[0]})", 0);
                    return $off;
                }
            }
        }

        $this->SendDebug('Modbus', 'Steuerung nicht erkennbar, verwende Offset -1', 0);
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

        // Solange die Steuerung nicht erkannt ist, beide Sätze anlegen;
        // nach der Erkennung räumt MaintainVariable die falschen wieder ab.
        $ctrl = $this->ctrl();
        $c6 = ($ctrl !== self::CTRL_C4);
        $c4 = ($ctrl !== self::CTRL_C6);

        // [Ident, Name, Typ (0=bool,1=int,2=float,3=string), Profil, Position, anlegen?, Aktion?]
        $vars = [
            // --- gemeinsam ---
            ['Power', 'Gerät Ein/Aus', 0, '~Switch', 10, true, $w],
            ['StatusText', 'Status', 3, '', 14, true, false],
            ['AutoMode', 'AUTO-Modus', 0, '~Switch', 13, true, $w],
            ['Ventilator', 'Ventilatoren', 0, 'PAIR.Aktiv', 20, true, false],
            ['Rotor', 'Wärmetauscher aktiv', 0, 'PAIR.Aktiv', 21, true, false],
            ['Heizen', 'Heizen', 0, 'PAIR.Aktiv', 22, true, false],
            ['Kuehlen', 'Kühlen', 0, 'PAIR.Aktiv', 23, true, false],
            ['Stoerung', 'Störung', 0, '~Alert', 26, true, false],
            ['Warnung', 'Warnung', 0, '~Alert', 27, true, false],
            ['AlarmText', 'Alarm-Meldungen', 3, '', 29, true, false],
            ['ZuluftTemp', 'Zuluft-Temperatur', 2, 'PAIR.TempC', 41, true, false],
            ['WasserTemp', 'Wasserregister-Temperatur', 2, 'PAIR.TempC', 43, true, false],
            ['ZuluftVent', 'Zuluft-Ventilator', 2, 'PAIR.Prozent', 52, true, false],
            ['AbluftVent', 'Abluft-Ventilator', 2, 'PAIR.Prozent', 53, true, false],
            ['Waermetauscher', 'Wärmetauscher-Leistung', 2, 'PAIR.Prozent', 54, true, false],
            ['ElHeizer', 'Elektrischer Heizer', 2, 'PAIR.Prozent', 55, true, false],
            ['LastUpdate', 'Letzte Aktualisierung', 1, '~UnixTimestamp', 131, true, false],

            // --- C6 ---
            ['Modus', 'Betriebsmodus', 1, 'PAIR.Modus', 11, $c6, $c6 && $w],
            ['EcoMode', 'ECO-Modus', 0, '~Switch', 12, $c6, $c6 && $w],
            ['NaechsterModus', 'Nächster Modus (Zeitplan)', 1, 'PAIR.Modus', 15, $c6, false],
            ['TempKontrolle', 'Temperatur-Regelung', 1, 'PAIR.TempKontrolle', 16, $c6, $c6 && $w],
            ['FreiesHeizen', 'Freies Heizen', 0, 'PAIR.Aktiv', 24, $c6, false],
            ['FreiesKuehlen', 'Freie Kühlung (Bypass)', 0, 'PAIR.Aktiv', 25, $c6, false],
            ['AlarmAnzahl', 'Aktive Alarme', 1, '', 28, $c6, false],
            ['AussenTemp', 'Außenluft-Temperatur', 2, 'PAIR.TempC', 40, $c6, false],
            ['AbluftTemp', 'Abluft-Temperatur', 2, 'PAIR.TempC', 42, $c6, false],
            ['ZuluftStrom', 'Zuluft-Menge aktuell', 1, 'PAIR.Flow', 50, $c6, false],
            ['AbluftStrom', 'Abluft-Menge aktuell', 1, 'PAIR.Flow', 51, $c6, false],
            ['FilterVerschmutzung', 'Filter-Verschmutzung', 1, 'PAIR.Pct', 56, $c6, false],
            ['Luftklappen', 'Luftklappen', 1, 'PAIR.Pct', 57, $c6, false],
            ['SollAbwesend', 'Sollwert Abwesend', 2, 'PAIR.TempSoll', 60, $c6 && $ms, $c6 && $ms && $w],
            ['SollNormal', 'Sollwert Normal', 2, 'PAIR.TempSoll', 61, $c6 && $ms, $c6 && $ms && $w],
            ['SollIntensiv', 'Sollwert Intensiv', 2, 'PAIR.TempSoll', 62, $c6 && $ms, $c6 && $ms && $w],
            ['SollBoost', 'Sollwert Boost', 2, 'PAIR.TempSoll', 63, $c6 && $ms, $c6 && $ms && $w],
            ['ZuluftAbwesendSoll', 'Zuluft Abwesend', 1, 'PAIR.FlowSoll', 70, $c6 && $ms, $c6 && $ms && $w],
            ['AbluftAbwesendSoll', 'Abluft Abwesend', 1, 'PAIR.FlowSoll', 71, $c6 && $ms, $c6 && $ms && $w],
            ['ZuluftNormalSoll', 'Zuluft Normal', 1, 'PAIR.FlowSoll', 72, $c6 && $ms, $c6 && $ms && $w],
            ['AbluftNormalSoll', 'Abluft Normal', 1, 'PAIR.FlowSoll', 73, $c6 && $ms, $c6 && $ms && $w],
            ['ZuluftIntensivSoll', 'Zuluft Intensiv', 1, 'PAIR.FlowSoll', 74, $c6 && $ms, $c6 && $ms && $w],
            ['AbluftIntensivSoll', 'Abluft Intensiv', 1, 'PAIR.FlowSoll', 75, $c6 && $ms, $c6 && $ms && $w],
            ['ZuluftBoostSoll', 'Zuluft Boost', 1, 'PAIR.FlowSoll', 76, $c6 && $ms, $c6 && $ms && $w],
            ['AbluftBoostSoll', 'Abluft Boost', 1, 'PAIR.FlowSoll', 77, $c6 && $ms, $c6 && $ms && $w],
            ['KuecheTimer', 'Timer Küche', 1, 'PAIR.Minuten', 78, $c6 && $ms, $c6 && $ms && $w],
            ['FeuerTimer', 'Timer Feuerstätte', 1, 'PAIR.Minuten', 79, $c6 && $ms, $c6 && $ms && $w],
            ['OverrideTimer', 'Timer Override', 1, 'PAIR.Minuten', 80, $c6 && $ms, $c6 && $ms && $w],
            ['AQAktiv', 'Luftqualität-Regelung', 0, '~Switch', 90, $c6 && $aq, $c6 && $aq && $w],
            ['AQTempSoll', 'Luftqualität Temperatur-Soll', 2, 'PAIR.TempSoll', 91, $c6 && $aq, $c6 && $aq && $w],
            ['AQSollwert', 'Luftqualität Sollwert (CO₂/VOC)', 1, 'PAIR.ppm', 92, $c6 && $aq, $c6 && $aq && $w],
            ['AQFeuchteSoll', 'Feuchtigkeit-Sollwert', 1, 'PAIR.PctSoll', 93, $c6 && $aq, $c6 && $aq && $w],
            ['AQMinIntensitaet', 'Luftqualität min. Intensität', 1, 'PAIR.PctSoll', 94, $c6 && $aq, $c6 && $aq && $w],
            ['AQMaxIntensitaet', 'Luftqualität max. Intensität', 1, 'PAIR.PctSoll', 95, $c6 && $aq, $c6 && $aq && $w],
            ['Leistung', 'Leistungsaufnahme', 1, 'PAIR.W', 100, $c6 && $energy, false],
            ['Heizleistung', 'Heizleistung', 1, 'PAIR.W', 101, $c6 && $energy, false],
            ['Rueckgewinnung', 'Wärmerückgewinnung', 1, 'PAIR.W', 102, $c6 && $energy, false],
            ['Effizienz', 'Wärmetauscher-Effizienz', 1, 'PAIR.Pct', 103, $c6 && $energy, false],
            ['Energiesparen', 'Energie sparen', 1, 'PAIR.Pct', 104, $c6 && $energy, false],
            ['SPI', 'SPI (spez. Leistungsaufnahme)', 2, 'PAIR.SPI', 105, $c6 && $energy, false],
            ['VerbrauchTag', 'Verbrauch heute', 2, 'PAIR.kWh', 110, $c6 && $energy, false],
            ['VerbrauchMonat', 'Verbrauch Monat', 2, 'PAIR.kWh', 111, $c6 && $energy, false],
            ['VerbrauchGesamt', 'Verbrauch gesamt', 2, 'PAIR.kWh', 112, $c6 && $energy, false],
            ['HeizerVerbrauchTag', 'Heizer-Verbrauch heute', 2, 'PAIR.kWh', 113, $c6 && $energy, false],
            ['HeizerVerbrauchMonat', 'Heizer-Verbrauch Monat', 2, 'PAIR.kWh', 114, $c6 && $energy, false],
            ['HeizerVerbrauchGesamt', 'Heizer-Verbrauch gesamt', 2, 'PAIR.kWh', 115, $c6 && $energy, false],
            ['RueckgewinnungTag', 'Rückgewinnung heute', 2, 'PAIR.kWh', 116, $c6 && $energy, false],
            ['RueckgewinnungMonat', 'Rückgewinnung Monat', 2, 'PAIR.kWh', 117, $c6 && $energy, false],
            ['RueckgewinnungGesamt', 'Rückgewinnung gesamt', 2, 'PAIR.kWh', 118, $c6 && $energy, false],
            ['PanelTemp', 'Raumtemperatur (Panel)', 2, 'PAIR.TempC', 120, $c6, false],
            ['PanelFeuchte', 'Raumfeuchte (Panel)', 1, 'PAIR.Pct', 121, $c6, false],
            ['PanelAQ', 'Luftqualität (Panel)', 1, 'PAIR.ppm', 122, $c6, false],
            ['Firmware', 'Firmware', 3, '', 130, $c6, false],

            // --- C4 (PING2) ---
            ['Stufe', 'Lüftungsstufe', 1, 'PAIR.Stufe', 11, $c4, $c4 && $w],
            ['Saison', 'Saison (Winter)', 0, 'PAIR.Saison', 12, $c4, $c4 && $w],
            ['SollTemp', 'Temperatur-Sollwert', 2, 'PAIR.TempSollC4', 44, $c4, $c4 && $w],
            ['TempKorrektur', 'Temperatur-Korrektur', 2, 'PAIR.TempKorr', 45, $c4, $c4 && $w],
            ['WasserHeizung', 'Wasser-Heizung', 2, 'PAIR.Prozent', 58, $c4, false],
            ['WasserKuehlung', 'Wasser-Kühlung', 2, 'PAIR.Prozent', 59, $c4, false],
            ['ZuluftStufe1', 'Zuluft-Intensität Stufe 1', 1, 'PAIR.PctSoll', 70, $c4 && $ms, $c4 && $ms && $w],
            ['ZuluftStufe2', 'Zuluft-Intensität Stufe 2', 1, 'PAIR.PctSoll', 71, $c4 && $ms, $c4 && $ms && $w],
            ['ZuluftStufe3', 'Zuluft-Intensität Stufe 3', 1, 'PAIR.PctSoll', 72, $c4 && $ms, $c4 && $ms && $w],
            ['ZuluftStufe4', 'Zuluft-Intensität Stufe 4', 1, 'PAIR.PctSoll', 73, $c4 && $ms, $c4 && $ms && $w],
            ['AbluftStufe1', 'Abluft-Intensität Stufe 1', 1, 'PAIR.PctSoll', 74, $c4 && $ms, $c4 && $ms && $w],
            ['AbluftStufe2', 'Abluft-Intensität Stufe 2', 1, 'PAIR.PctSoll', 75, $c4 && $ms, $c4 && $ms && $w],
            ['AbluftStufe3', 'Abluft-Intensität Stufe 3', 1, 'PAIR.PctSoll', 76, $c4 && $ms, $c4 && $ms && $w],
            ['AbluftStufe4', 'Abluft-Intensität Stufe 4', 1, 'PAIR.PctSoll', 77, $c4 && $ms, $c4 && $ms && $w],
            ['OVREnable', 'Override (OVR) aktiv', 0, '~Switch', 85, $c4, $c4 && $w],
            ['OVRTime', 'Override-Laufzeit', 1, 'PAIR.OVRMin', 86, $c4, $c4 && $w],
            ['OVRRest', 'Override-Restzeit', 1, 'PAIR.OVRMin', 87, $c4, false]
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
        $this->ProfileFloat('PAIR.TempSollC4', ' °C', 1, 10, 30, 0.5, 'Temperature');
        $this->ProfileFloat('PAIR.TempKorr', ' K', 1, -9, 9, 0.5, 'Temperature');
        $this->ProfileFloat('PAIR.kWh', ' kWh', 1, 0, 0, 0, 'Electricity');
        $this->ProfileFloat('PAIR.SPI', ' W/(m³/h)', 2, 0, 0, 0, 'Graph');

        $this->ProfileInt('PAIR.Flow', $flowSuffix, 0, 0, 0, 'WindSpeed');
        $this->ProfileInt('PAIR.FlowSoll', $flowSuffix, 0, 400, 5, 'WindSpeed');
        $this->ProfileInt('PAIR.Pct', ' %', 0, 100, 0, 'Intensity');
        $this->ProfileInt('PAIR.PctSoll', ' %', 0, 100, 5, 'Intensity');
        $this->ProfileInt('PAIR.W', ' W', 0, 0, 0, 'Electricity');
        $this->ProfileInt('PAIR.ppm', ' ppm', 0, 2000, 50, 'Leaf');
        $this->ProfileInt('PAIR.Minuten', ' min', 0, 300, 5, 'Clock');
        $this->ProfileInt('PAIR.OVRMin', ' min', 0, 90, 5, 'Clock');

        if (!IPS_VariableProfileExists('PAIR.Aktiv')) {
            IPS_CreateVariableProfile('PAIR.Aktiv', 0);
            IPS_SetVariableProfileIcon('PAIR.Aktiv', 'Power');
            IPS_SetVariableProfileAssociation('PAIR.Aktiv', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('PAIR.Aktiv', 1, 'An', '', 0x00A65E);
        }

        if (!IPS_VariableProfileExists('PAIR.Saison')) {
            IPS_CreateVariableProfile('PAIR.Saison', 0);
            IPS_SetVariableProfileIcon('PAIR.Saison', 'Temperature');
            IPS_SetVariableProfileAssociation('PAIR.Saison', 0, 'Sommer', '', 0xE8A33D);
            IPS_SetVariableProfileAssociation('PAIR.Saison', 1, 'Winter', '', 0x4DC3F4);
        }

        if (!IPS_VariableProfileExists('PAIR.Stufe')) {
            IPS_CreateVariableProfile('PAIR.Stufe', 1);
            IPS_SetVariableProfileIcon('PAIR.Stufe', 'Ventilation');
            IPS_SetVariableProfileAssociation('PAIR.Stufe', 0, 'Standby', '', -1);
            IPS_SetVariableProfileAssociation('PAIR.Stufe', 1, 'Stufe 1', '', 0x3D9EE8);
            IPS_SetVariableProfileAssociation('PAIR.Stufe', 2, 'Stufe 2', '', 0x00A65E);
            IPS_SetVariableProfileAssociation('PAIR.Stufe', 3, 'Stufe 3', '', 0xE8A33D);
            IPS_SetVariableProfileAssociation('PAIR.Stufe', 4, 'Stufe 4', '', 0xE86A3D);
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
                $off = $this->detectDevice($sock);
                $ctrl = $this->ctrl();
                $ctrlName = [self::CTRL_AUTO => 'unbekannt', self::CTRL_C6 => 'C6', self::CTRL_C4 => 'C4 (PING2)'][$ctrl];
                $out .= "Steuerung: {$ctrlName}, Adress-Offset: {$off}\n";

                if ($ctrl === self::CTRL_C4) {
                    $blocks = [
                        ['General', 1000, 14],
                        ['Ventilation', 1100, 17],
                        ['Temperatur', 1200, 6]
                    ];
                } else {
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
                }
                foreach ($blocks as [$name, $start, $qty]) {
                    $out .= "\n== {$name} ==\n";
                    $err = '';
                    $r = $this->mbRead($sock, $start + $off, $qty, $err);
                    if ($r === null) {
                        $out .= "keine Antwort ({$err})\n";
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
    // Symcon-Wochenplan (flexibler als das 3-Fenster-Zeitprogramm der C4)
    // =====================================================================

    /**
     * Schaltziel für den Symcon-Wochenplan.
     *  0 = Gerät aus, 1–3 = Stufe (C4) bzw. Modus (C6: 1 Abwesend, 2 Normal, 3 Intensiv).
     * Schaltet das Gerät bei Bedarf ein und (bei C4) von AUTO auf Manuell.
     */
    public function ScheduleAction(int $Level): void
    {
        if ($Level <= 0) {
            $this->RequestAction('Power', false);
            return;
        }
        $vid = @$this->GetIDForIdent('Power');
        if ($vid !== false && $vid > 0 && !GetValue($vid)) {
            $this->RequestAction('Power', true);
        }
        $this->RequestAction($this->ctrl() === self::CTRL_C4 ? 'Stufe' : 'Modus', min(3, $Level));
    }

    /**
     * Legt unter der Instanz einen IPS-Wochenplan an, der die Lüftungsstufe
     * schaltet. Vorteil gegenüber dem Geräte-Zeitprogramm: beliebig viele
     * Schaltpunkte, Zeiten über Mitternacht, grafisch bearbeitbar.
     * Vorbelegung: 22:00–06:00 Stufe 1, 06:00–06:30 und 20:00–20:30 Stufe 3
     * (Stoßlüften), sonst Stufe 2. Ein vorhandener Plan bleibt unangetastet.
     */
    public function CreateWeekplan(): string
    {
        $eid = @IPS_GetObjectIDByIdent('Wochenplan', $this->InstanceID);
        if ($eid !== false && $eid > 0) {
            return 'Der Wochenplan existiert bereits (Objekt #' . $eid . ') – Zeiten dort direkt bearbeiten.';
        }

        // Eine Gruppe für alle Tage (Mo–So = Bitmaske 127)
        $eid = $this->buildWeekplanEvent([
            ['days' => 127, 'points' => [0 => 1, 360 => 3, 390 => 2, 1200 => 3, 1230 => 2, 1320 => 1]]
        ]);

        return "Wochenplan angelegt (Objekt #{$eid}).\n" .
            "Vorbelegung: 22:00–06:00 Stufe 1 · 06:00–06:30 Lüften (Stufe 3) · " .
            "06:30–20:00 Stufe 2 · 20:00–20:30 Lüften · 20:30–22:00 Stufe 2.\n" .
            "Bearbeiten: im Dashboard (Details -> Wochenplan) oder im Wochenplan-Editor der Konsole.\n" .
            "WICHTIG: Den AUTO-Modus des Geräts ausschalten, damit sich das " .
            "interne Zeitprogramm und der Symcon-Plan nicht in die Quere kommen.";
    }

    /**
     * Liest den Symcon-Wochenplan.
     * Rückgabe: JSON {ok, exists, groups[]} mit days (Bitmaske Mo=1…So=64)
     * und points[] = {minute, action} (Minuten seit 0:00, Aktion 0–3).
     */
    public function GetWeekplan(): string
    {
        $eid = @IPS_GetObjectIDByIdent('Wochenplan', $this->InstanceID);
        if ($eid === false || $eid <= 0) {
            return json_encode(['ok' => true, 'exists' => false]);
        }
        $ev = IPS_GetEvent($eid);
        $groups = [];
        foreach ($ev['ScheduleGroups'] as $g) {
            $points = [];
            foreach ($g['Points'] as $p) {
                $points[] = [
                    'minute' => (int) $p['Start']['Hour'] * 60 + (int) $p['Start']['Minute'],
                    'action' => (int) $p['ActionID']
                ];
            }
            usort($points, fn ($a, $b) => $a['minute'] <=> $b['minute']);
            $groups[] = ['days' => (int) $g['Days'], 'points' => $points];
        }
        return json_encode(['ok' => true, 'exists' => true, 'active' => (bool) $ev['EventActive'], 'groups' => $groups]);
    }

    /**
     * Schreibt den kompletten Symcon-Wochenplan neu.
     * $PlanJSON: {groups: [{days: Bitmaske, points: [{minute, action}]}]}.
     * Das Ereignis wird neu aufgebaut, weil die IPS-API einzelne Schaltpunkte
     * nicht löschen kann.
     */
    public function SetWeekplan(string $PlanJSON): bool
    {
        if (!$this->ReadPropertyBoolean('EnableWrite')) {
            $this->LogMessage('Schreibzugriff ist in der Instanzkonfiguration deaktiviert.', KL_WARNING);
            return false;
        }
        $plan = json_decode($PlanJSON, true);
        $groups = is_array($plan) ? ($plan['groups'] ?? null) : null;
        if (!is_array($groups)) {
            throw new Exception('Ungültiges Wochenplan-Format.');
        }

        $usedDays = 0;
        $clean = [];
        foreach ($groups as $g) {
            $days = ((int) ($g['days'] ?? 0)) & 127;
            $points = $g['points'] ?? [];
            if ($days === 0 || !is_array($points) || count($points) === 0) {
                continue;
            }
            if ($usedDays & $days) {
                throw new Exception('Ein Wochentag ist mehreren Gruppen zugeordnet.');
            }
            $usedDays |= $days;
            $pts = [];
            foreach ($points as $p) {
                $minute = max(0, min(1439, (int) ($p['minute'] ?? 0)));
                $pts[$minute] = max(0, min(3, (int) ($p['action'] ?? 0)));
            }
            ksort($pts);
            $clean[] = ['days' => $days, 'points' => $pts];
        }
        if (count($clean) === 0) {
            throw new Exception('Der Plan braucht mindestens eine Gruppe mit Schaltpunkten.');
        }

        $old = @IPS_GetObjectIDByIdent('Wochenplan', $this->InstanceID);
        if ($old !== false && $old > 0) {
            IPS_DeleteEvent($old);
        }
        $this->buildWeekplanEvent($clean);
        return true;
    }

    /**
     * Baut das Wochenplan-Ereignis mit den Standard-Aktionen auf.
     *
     * @param array $groups [['days' => Bitmaske, 'points' => [Minute => Aktion]], …]
     */
    private function buildWeekplanEvent(array $groups): int
    {
        $eid = IPS_CreateEvent(2 /* Wochenplan */);
        IPS_SetParent($eid, $this->InstanceID);
        IPS_SetIdent($eid, 'Wochenplan');
        IPS_SetName($eid, 'Wochenplan Lüftung');
        IPS_SetPosition($eid, 5);

        IPS_SetEventScheduleAction($eid, 0, 'Aus', 0x808080, "PAIR_ScheduleAction({$this->InstanceID}, 0);");
        IPS_SetEventScheduleAction($eid, 1, 'Stufe 1 (Nacht)', 0x3D9EE8, "PAIR_ScheduleAction({$this->InstanceID}, 1);");
        IPS_SetEventScheduleAction($eid, 2, 'Stufe 2', 0x00A65E, "PAIR_ScheduleAction({$this->InstanceID}, 2);");
        IPS_SetEventScheduleAction($eid, 3, 'Stufe 3 (Lüften)', 0xE8A33D, "PAIR_ScheduleAction({$this->InstanceID}, 3);");

        foreach ($groups as $gi => $g) {
            IPS_SetEventScheduleGroup($eid, $gi, $g['days']);
            $pi = 0;
            foreach ($g['points'] as $minute => $action) {
                IPS_SetEventScheduleGroupPoint($eid, $gi, $pi++, intdiv($minute, 60), $minute % 60, 0, $action);
            }
        }

        IPS_SetEventActive($eid, true);
        return $eid;
    }

    // =====================================================================
    // Zeitprogramm (C4): 7 Tage × 3 Events (Start/Stopp/Stufe), Reg. 1300–1362
    // =====================================================================

    /**
     * Liest das C4-Wochenprogramm.
     * Rückgabe: JSON {ok, days[7][3]} mit start/stop in Minuten seit 0:00 und level 0–3.
     */
    public function GetSchedule(): string
    {
        $days = $this->readSchedule();
        return json_encode($days === null ? ['ok' => false] : ['ok' => true, 'days' => $days]);
    }

    private function readSchedule(): ?array
    {
        if ($this->ctrl() !== self::CTRL_C4) {
            return null;
        }
        if (!IPS_SemaphoreEnter($this->semName(), 5000)) {
            return null;
        }
        try {
            $sock = $this->mbConnect();
            if ($sock === false) {
                return null;
            }
            try {
                $off = $this->detectDevice($sock);
                $b = $this->mbRead($sock, 1300 + $off, 63);
                if ($b === null || count($b) < 63) {
                    return null;
                }
                $days = [];
                for ($d = 0; $d < 7; $d++) {
                    $events = [];
                    for ($e = 0; $e < 3; $e++) {
                        $start = (int) $b[$d * 6 + $e * 2];
                        $stop = (int) $b[$d * 6 + $e * 2 + 1];
                        $events[] = [
                            'start' => (($start >> 8) & 0xFF) * 60 + ($start & 0xFF),
                            'stop'  => (($stop >> 8) & 0xFF) * 60 + ($stop & 0xFF),
                            'level' => (int) $b[42 + $d * 3 + $e]
                        ];
                    }
                    $days[] = $events;
                }
                return $days;
            } finally {
                fclose($sock);
            }
        } finally {
            IPS_SemaphoreLeave($this->semName());
        }
    }

    /**
     * Schreibt einen Wochentag des C4-Zeitprogramms (alle 3 Events).
     *
     * @param int    $Day        0 = Montag … 6 = Sonntag
     * @param string $EventsJSON JSON-Array mit 3 Objekten {start, stop, level},
     *                           Zeiten in Minuten seit 0:00 (0–1440), Stufe 0–3
     */
    public function SetScheduleDay(int $Day, string $EventsJSON): bool
    {
        if ($this->ctrl() !== self::CTRL_C4) {
            $this->LogMessage('Das Zeitprogramm ist nur für die C4-Steuerung implementiert.', KL_WARNING);
            return false;
        }
        if (!$this->ReadPropertyBoolean('EnableWrite')) {
            $this->LogMessage('Schreibzugriff ist in der Instanzkonfiguration deaktiviert.', KL_WARNING);
            return false;
        }
        if ($Day < 0 || $Day > 6) {
            throw new Exception('Ungültiger Wochentag (0 = Montag … 6 = Sonntag).');
        }
        $events = json_decode($EventsJSON, true);
        if (!is_array($events) || count($events) !== 3) {
            throw new Exception('Es müssen genau 3 Events übergeben werden.');
        }

        $times = [];
        $levels = [];
        foreach ($events as $ev) {
            $start = max(0, min(1440, (int) ($ev['start'] ?? 0)));
            $stop = max(0, min(1440, (int) ($ev['stop'] ?? 0)));
            $times[] = (intdiv($start, 60) << 8) | ($start % 60);
            $times[] = (intdiv($stop, 60) << 8) | ($stop % 60);
            $levels[] = max(0, min(3, (int) ($ev['level'] ?? 0)));
        }

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
                $off = $this->detectDevice($sock);
                return $this->mbWriteMultiple($sock, 1300 + $Day * 6 + $off, $times)
                    && $this->mbWriteMultiple($sock, 1342 + $Day * 3 + $off, $levels);
            } finally {
                fclose($sock);
            }
        } finally {
            IPS_SemaphoreLeave($this->semName());
        }
    }

    /**
     * Sucht Register, die über die Dokumentation hinaus existieren.
     *
     * Vorgehen: Je bekanntem Blockanfang wird per Bisektion die größte noch
     * lesbare Blocklänge ermittelt (das Gerät antwortet auf zu lange Blöcke mit
     * "Illegal Data Address"), danach werden die Lücken einzeln abgeklopft.
     * Rein lesend – es wird nichts geschrieben.
     */
    public function ScanRegisters(): string
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
            try {
                $off = $this->detectDevice($sock);
                $c4 = ($this->ctrl() === self::CTRL_C4);

                // [Blockanfang, dokumentierte Länge, Bezeichnung]
                $blocks = $c4
                    ? [[1000, 14, 'General'], [1100, 17, 'Ventilation'], [1200, 6, 'Temperatur'], [1300, 63, 'Zeitplan']]
                    : [[1, 34, 'Hauptkontrolle'], [100, 46, 'Einstellungsmodi'], [200, 15, 'Eco/Luftqualität'],
                        [600, 11, 'Alarme'], [900, 27, 'Überwachung'], [927, 19, 'Verbrauch'], [1000, 6, 'Andere']];

                $out = "Register-Suche (rein lesend)\n";
                $out .= 'Steuerung: ' . ($c4 ? 'C4 (PING2)' : 'C6') . ", Adress-Offset: {$off}\n";

                foreach ($blocks as [$start, $doc, $name]) {
                    // Größte lesbare Länge per Bisektion (Modbus erlaubt max. 125)
                    $lo = 0;
                    $hi = 125;
                    while ($lo < $hi) {
                        $mid = (int) ceil(($lo + $hi) / 2);
                        if ($this->mbRead($sock, $start + $off, $mid) !== null) {
                            $lo = $mid;
                        } else {
                            $hi = $mid - 1;
                        }
                    }

                    $out .= "\n== {$name} (ab {$start}) ==\n";
                    $out .= "dokumentiert: {$doc} Register, lesbar: {$lo}\n";

                    if ($lo > $doc) {
                        $out .= "-> {$lo} statt {$doc} Register lesbar, undokumentierte Werte:\n";
                        $extra = $this->mbRead($sock, $start + $doc + $off, $lo - $doc);
                        if ($extra !== null) {
                            foreach ($extra as $i => $raw) {
                                $reg = $start + $doc + $i;
                                $s = $this->s16($raw);
                                $hint = ($s > -500 && $s < 1200 && $raw !== 0) ? '  <- könnte Temperatur sein' : '';
                                $out .= sprintf("%d: %u  [signed=%d  /10=%.1f]%s\n", $reg, $raw, $s, $s / 10, $hint);
                            }
                        }
                    } elseif ($lo < $doc) {
                        $out .= "-> Achtung: weniger lesbar als dokumentiert.\n";
                    } else {
                        $out .= "-> keine zusätzlichen Register.\n";
                    }
                }

                // Lücken zwischen den Blöcken einzeln abklopfen
                $gaps = $c4
                    ? [[1014, 1099], [1117, 1199], [1206, 1299]]
                    : [[35, 99], [146, 199], [215, 299]];
                $out .= "\n== Lücken zwischen den Blöcken ==\n";
                $found = 0;
                foreach ($gaps as [$from, $to]) {
                    for ($reg = $from; $reg <= $to; $reg++) {
                        $r = $this->mbRead($sock, $reg + $off, 1);
                        if ($r !== null) {
                            $s = $this->s16($r[0]);
                            $hint = ($s > -500 && $s < 1200 && $r[0] !== 0) ? '  <- könnte Temperatur sein' : '';
                            $out .= sprintf("%d: %u  [signed=%d  /10=%.1f]%s\n", $reg, $r[0], $s, $s / 10, $hint);
                            $found++;
                        }
                    }
                }
                if ($found === 0) {
                    $out .= "keine weiteren Register gefunden.\n";
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
