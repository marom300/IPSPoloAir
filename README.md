# IPSPoloAir – Poloplast POLO-AIR Wohnraumlüftung für IP-Symcon

IP-Symcon-Modul zur Anbindung einer **Poloplast POLO-AIR** Komfortwohnraumlüftung
(z. B. **AIR 250**) über **Modbus TCP**. Die POLO-AIR Geräte basieren auf
Komfovent-Steuerungen – das Modul unterstützt **beide Generationen** und erkennt
sie automatisch:

- **C6** – neuere Geräte mit Ethernet direkt am Regler (voller Funktionsumfang)
- **C4** – ältere Geräte mit **PING2-Netzwerkadapter** (reduzierter Registersatz)

Standard-IP `192.168.0.60`, Port `502`.

Das Modul liest zyklisch alle relevanten Werte, macht das Gerät schaltbar
(Ein/Aus, Betriebsmodus, ECO/AUTO, Sollwerte) und bringt ein fertiges
HTML-Dashboard mit Anlagenschema mit – im gleichen Stil wie
[IPSStiebelWPM](https://github.com/marom300/IPSStiebelWPM) und
[IPSPoolSkimmer](https://github.com/marom300/IPSPoolSkimmer).

## Funktionsumfang

**Lesen (alle 30 s, konfigurierbar):**
- Betriebszustand: Ein/Aus, aktueller Modus, ECO/AUTO, nächster Zeitplan-Modus
- Statusbits: Ventilatoren, Wärmetauscher, Heizen, Kühlen, freies Heizen/Kühlen (Bypass), Störung/Warnung
- Temperaturen: Außenluft, Zuluft, Abluft, Wasserregister (falls vorhanden)
- Luftmengen: Zuluft/Abluft aktuell, Ventilator-Intensitäten, Wärmetauscher- und Heizer-Leistung
- Filter-Verschmutzung, Luftklappen
- Energiedaten: Leistungsaufnahme, Rückgewinnung, Wärmetauscher-Effizienz, SPI,
  Verbrauch (Tag/Monat/gesamt), Rückgewinnungs-Energie (Tag/Monat/gesamt)
- Bedienpanel: Raumtemperatur, Feuchte, Luftqualität (falls Panel mit Sensoren vorhanden)
- Alarme: Anzahl + Codes (z. B. `3F`, `12W`), Firmware-Version

**Schreiben (abschaltbar):**
- Gerät Ein/Aus, Betriebsmodus (Abwesend/Normal/Intensiv/Boost), ECO- und AUTO-Modus
- Temperatur-Sollwerte je Modus, Zuluft-/Abluft-Mengen je Modus
- Timer Küche/Feuerstätte/Override, Temperatur-Regelungsart (Zuluft/Abluft/Balance/Raum)
- Luftqualitäts-Regelung (aktiv, Temperatur-, CO₂/VOC-, Feuchte-Sollwert, min/max Intensität)
- Alarme quittieren (`PAIR_ResetAlarms`)

> **Hinweis C6:** Per Modbus sind als Modus nur *Abwesend, Normal, Intensiv, Boost*
> schaltbar. Küche/Feuerstätte/Override/Urlaub startet das Gerät selbst
> (Bedienteil, Zeitplan oder Schalteingänge) – das Modul zeigt sie an und kann
> ihre Laufzeit-Timer einstellen.

### Funktionsumfang C4 (PING2)

Die ältere C4-Steuerung stellt per Modbus deutlich weniger Daten bereit:

**Lesen:** Ein/Aus, Lüftungsstufe (0–4), AUTO-Modus, Saison (Winter/Sommer),
Zuluft-Temperatur, Wasserregister-Temperatur, Ventilator-Leistungen (%),
Wärmetauscher-/Heizer-/Wasserheizung-/Wasserkühlung-Leistung (%),
Warnungen und Stopp-Codes im Klartext, OVR-Status.

**Schreiben:** Ein/Aus, Stufe 1–3, AUTO-Modus, Saison, Temperatur-Sollwert und
-Korrektur, Zuluft-/Abluft-Intensität je Stufe (1–4), OVR aktivieren + Laufzeit.

*Nicht verfügbar bei C4:* Außen-/Abluft-Temperatur, Luftmengen in m³/h,
Filter-Verschmutzung, Energiedaten, Luftqualitäts-Regelung, Alarm-Quittierung
per Modbus. Das Dashboard blendet die betroffenen Anzeigen automatisch aus.

## Installation

1. In IP-Symcon: **Kern Instanzen → Module Control → Hinzufügen** und die
   Repository-URL eintragen:
   ```
   https://github.com/marom300/IPSPoloAir.git
   ```
2. Instanz anlegen: **Instanz hinzufügen → Poloplast → POLO-AIR Wohnraumlüftung (Modbus TCP)**
3. IP-Adresse des Lüftungsgeräts eintragen und übernehmen.
4. Mit **„Verbindung testen“** prüfen – es sollten Firmware, Modus und Temperaturen erscheinen.

Voraussetzung: Das Gerät ist per LAN erreichbar und Port 502 ist offen
(Standard beim PING2/C6-Regler, keine zusätzliche Freischaltung nötig).

## Konfiguration

### Verbindung

| Parameter | Standard | Bereich | Wirkung |
|---|---|---|---|
| IP-Adresse | `192.168.0.60` | – | IP des Lüftungsgeräts (PING2/C6-Regler). Im Zweifel am Bedienteil unter „Anschlussmöglichkeiten“ nachsehen. |
| Modbus-Port | `502` | 1–65535 | TCP-Port des Modbus-Servers. |
| Modbus Unit-ID | `1` | 0–255 | Geräteadresse im Modbus-Frame. Bei Modbus TCP fast immer 1. |
| Abfrageintervall | `30 s` | 5–3600 s | Zyklus, in dem alle Register gelesen werden. |
| Steuerungsgeneration | Automatisch | Auto/C6/C4 | Automatische Erkennung über die Jahres-Register (C6: Reg 30, C4: Reg 1005); bei Bedarf fest vorgeben. |

### Funktionsumfang

| Parameter | Standard | Wirkung |
|---|---|---|
| Schreibzugriff aktivieren | an | Ohne Haken sind alle Variablen reine Anzeige, Dashboard-Steuerung ist gesperrt. |
| Sollwerte je Betriebsmodus | an | Legt die Variablen für Temperatur-/Luftmengen-Sollwerte und Timer an (Register 100–145). |
| Luftqualitäts-Regelung | an | Legt die AQ-Variablen an (Register 205–210). Nur sinnvoll, wenn CO₂/VOC/Feuchte-Sensorik vorhanden ist. |
| Energiedaten | an | Leistung, Rückgewinnung, Effizienz, SPI und Zählerstände (Register 921–944). |
| Dashboard-WebHook | an | Registriert `/hook/poloair` und liefert dort das HTML-Dashboard aus. |
| Alle Werte automatisch archivieren | an | Aktiviert das Logging aller Zahlen-/Bool-Variablen im Archive Control. |
| PIN für Dashboard | leer | Wenn gesetzt, verlangt das Dashboard vor jeder Änderung diese PIN (eigener Ziffernblock, PIN wird pro Sitzung gemerkt). |
| Grundfunktionen ohne PIN | an | Ein/Aus und Stufe (C4) bzw. Betriebsmodus (C6) sind auch bei gesetzter PIN direkt schaltbar. Sollwerte, Luftmengen, Timer und Alarm-Quittierung bleiben PIN-geschützt. Ohne Haken gilt die PIN für alles. |

## Dashboard

Aufruf: `http://<Symcon-IP>:3777/hook/poloair`

- **Anlagen-Seite:** Anlagenschema mit Außenluft/Zuluft/Abluft/Fortluft,
  rotierendem Wärmetauscher, Ventilatoren, Bypass- und Heizer-Anzeige,
  Filter-Ampel und Wohnraum-Werten; darunter Kacheln für Betrieb, Luftmengen,
  Wärmerückgewinnung, Energie und Filter/Alarme.
- **Details-Seite:** Sollwerte je Modus (Temperatur + Luftmengen), Timer,
  Luftqualitäts-Einstellungen, Energie-Details und Geräteinfos.
- Steuerung direkt im Dashboard: Ein/Aus, Modus, ECO/AUTO, alle Sollwerte,
  Alarm-Quittierung – optional PIN-geschützt.
- Für Touch-Panels (IPSView/WebView) ausgelegt: dunkles Design, keine
  Blink-Animationen, responsive in Breite und Höhe.

## Wichtige PHP-Funktionen

| Funktion | Beschreibung |
|---|---|
| `PAIR_Update(int $id)` | Alle Register sofort lesen. |
| `PAIR_TestConnection(int $id)` | Verbindungstest mit Kurz-Zusammenfassung (Firmware, Modus, Temperaturen). |
| `PAIR_ResetAlarms(int $id)` | Alle aktiven Alarme quittieren. |
| `PAIR_DumpRegisters(int $id)` | Alle Registerblöcke als Rohwerte ausgeben (Diagnose). |
| `IPS_RequestAction($id, 'Modus', 2)` | Beispiel: Modus auf „Normal“ schalten (1=Abwesend, 2=Normal, 3=Intensiv, 4=Boost). |

## Technische Details

- Registerbasis: *„Gebrauchsanweisung POLO-AIR + Geräte – Modbusverbindung“* (08/2018),
  kompatibel ab Firmware `x.x.x.6`. Alle Register sind Holding-Register
  (FC 03 lesen, FC 06/16 schreiben).
- **Adress-Offset:** Manche Firmwares erwarten die dokumentierte Registernummer
  1:1, andere um −1 versetzt (Modbus-Konvention). Das Modul erkennt das
  automatisch über das Jahres-Register (Reg. 30) und merkt sich das Ergebnis.
- 32-Bit-Werte (Luftmengen, Zählerstände) werden als zwei Register mit
  High-Word zuerst übertragen; Temperaturen sind ×10 kodiert, Prozentwerte der
  Ventilatoren/Wärmetauscher ×10, SPI ×1000.
- Alarm-Codes: `<Nummer>F` = Störung (Gerät stoppt), `<Nummer>W` = Warnung.
  Die Bedeutung der Nummern steht in der Geräteanleitung.
- Alle Modbus-Zugriffe (Timer, Dashboard, Skripte) werden über eine Semaphore
  serialisiert; Blöcke werden bei Timeout bis zu 3× mit neuer Verbindung
  wiederholt.
- Werte, die je nach Ausstattung fehlen (Wasserregister, E-Heizer, Panel-Sensoren),
  werden erst angezeigt, wenn das Gerät sie tatsächlich liefert.

## Fehlersuche

- **„Verbindung zum Lüftungsgerät fehlgeschlagen“:** IP prüfen (Weboberfläche
  im Browser erreichbar? Login `user`/`user`), Port 502 nicht durch Firewall/VLAN
  blockiert, nur begrenzt viele gleichzeitige Modbus-Verbindungen möglich –
  andere Modbus-Clients (z. B. Loxone, Home Assistant) testweise trennen.
- **Werte bleiben leer / unplausibel:** In der Instanz **„Register-Diagnose“**
  ausführen und die Rohwerte prüfen; dort sieht man auch den erkannten Adress-Offset.
- **Schreiben ohne Wirkung:** Einige Register akzeptiert das Gerät nur in
  bestimmten Zuständen (z. B. C6-Modus nur 1–4, C4-Stufe nur im Manuell-Modus –
  das Modul schaltet dafür automatisch von AUTO auf Manuell um). Schlägt FC 06
  fehl, versucht das Modul automatisch FC 16. Meldungen stehen im Debug-Fenster
  der Instanz. Hilft das nicht, testweise die **Unit-ID auf 254** stellen
  (Broadcast-Adresse der PING/NET-Module für Einzelgeräte).

## Lizenz

MIT
