# SolarLog MPP für IP-Symcon

Kleine IP-Symcon-Integration für die lokale, von der Solar-Log-Weboberfläche verwendete `/getjp`-Abfrage.

## Ziel

- Gesamt-AC-Leistung (`Pac`)
- Wohnhaus / MPP1: DC-Leistung und DC-Spannung
- Garage / MPP2: DC-Leistung und DC-Spannung
- Tagesertrag
- spezifische Leistung in W/kWp
- Kommunikations- und Frische-Status

Voreinstellung für diese Anlage:

- Solar-Log: `192.168.2.25`
- WR-Index: `0`
- MPP1 Wohnhaus: `13.875 kWp`
- MPP2 Garage: `2.775 kWp`
- Polling: `300 s`

## Beobachteter Datenpfad

POST `/getjp`

```text
token=;preval=666;postval=666;{"141":{"0":{"711":{"0":null}}}}
```

Der zurückgegebene Tagesdatensatz besteht aus 5-Minuten-Zeilen. Für die vorliegende Anlage wurden folgende Felder aus dem realen Verlauf abgeleitet und gegengeprüft:

- Index 0: Pac gesamt [W]
- Index 1: Pdc MPP1 / Wohnhaus [W]
- Index 2: Pdc MPP2 / Garage [W]
- Index 4: Udc MPP1 [V]
- Index 5: Udc MPP2 [V]
- Index 8: Tagesertrag [Wh]

Index 7 wird bewusst nicht verwendet, solange seine Bedeutung nicht sicher identifiziert ist.

## Installation in IP-Symcon

Unter **Kerninstanzen → Modules / Module Control → Hinzufügen** dieses Repository eintragen:

```text
https://github.com/AlexOX-80/SolarLogMPP-Symcon.git
```

Danach eine Instanz **Solar-Log MPP Tracker** anlegen und die Voreinstellungen prüfen.

## Betrieb

Die Abfrage erfolgt standardmäßig alle fünf Minuten, weil der verwendete `/getjp`-Endpunkt jeweils die komplette Tageskurve liefert. Das Modul übernimmt nur den letzten Messpunkt in operative Variablen.

Zwei getrennte Zustände sind vorhanden:

- `Kommunikation OK`: HTTP und JSON waren erfolgreich.
- `Messdaten aktuell`: letzter Solar-Log-Messpunkt liegt innerhalb der konfigurierten Frischegrenze.

Diese Trennung ist für eine spätere Alarmzentrale vorgesehen.
