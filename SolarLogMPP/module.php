<?php

class SolarLogMPP extends IPSModule
{
    private const PROFILE_WATT = 'SLMPP.Watt';
    private const PROFILE_VOLT = 'SLMPP.Volt';
    private const PROFILE_WATT_PER_KWP = 'SLMPP.WattPerKwp';
    private const PROFILE_PERCENT = 'SLMPP.Percent';
    private const PROFILE_WH = 'SLMPP.Wh';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '192.168.2.25');
        $this->RegisterPropertyInteger('Interval', 300);
        $this->RegisterPropertyInteger('Timeout', 5);
        $this->RegisterPropertyInteger('InverterIndex', 0);
        $this->RegisterPropertyInteger('SeriesIndex', 0);
        $this->RegisterPropertyInteger('FreshnessMinutes', 15);
        $this->RegisterPropertyString('MPP1Name', 'Wohnhaus');
        $this->RegisterPropertyFloat('MPP1kWp', 13.875);
        $this->RegisterPropertyString('MPP2Name', 'Garage');
        $this->RegisterPropertyFloat('MPP2kWp', 2.775);
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('Cookie', 'banner_hidden=false');

        $this->RegisterProfiles();

        $this->RegisterVariableBoolean('CommunicationOK', 'Kommunikation OK', '~Switch', 10);
        $this->RegisterVariableBoolean('DataFresh', 'Messdaten aktuell', '~Switch', 20);
        $this->RegisterVariableInteger('LastFetch', 'Letzte Abfrage', '~UnixTimestamp', 30);
        $this->RegisterVariableInteger('LastSample', 'Letzter Solar-Log Messpunkt', '~UnixTimestamp', 40);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 50);

        $this->RegisterVariableFloat('Pac', 'AC-Leistung gesamt', self::PROFILE_WATT, 100);
        $this->RegisterVariableFloat('PdcTotal', 'DC-Leistung gesamt', self::PROFILE_WATT, 110);
        $this->RegisterVariableFloat('DcAcEfficiency', 'DC/AC-Wirkungsgrad', self::PROFILE_PERCENT, 120);
        $this->RegisterVariableFloat('DailyYield', 'Tagesertrag', self::PROFILE_WH, 130);

        $this->RegisterVariableFloat('MPP1Pdc', 'MPP1 Leistung DC', self::PROFILE_WATT, 200);
        $this->RegisterVariableFloat('MPP1Udc', 'MPP1 Spannung DC', self::PROFILE_VOLT, 210);
        $this->RegisterVariableFloat('MPP1Specific', 'MPP1 spezifische Leistung', self::PROFILE_WATT_PER_KWP, 220);

        $this->RegisterVariableFloat('MPP2Pdc', 'MPP2 Leistung DC', self::PROFILE_WATT, 300);
        $this->RegisterVariableFloat('MPP2Udc', 'MPP2 Spannung DC', self::PROFILE_VOLT, 310);
        $this->RegisterVariableFloat('MPP2Specific', 'MPP2 spezifische Leistung', self::PROFILE_WATT_PER_KWP, 320);

        $this->RegisterTimer('UpdateTimer', 0, 'SLMPP_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('Host'));
        $interval = max(60, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('UpdateTimer', $host === '' ? 0 : $interval * 1000);
        $this->SetSummary($host);

        $mpp1Name = trim($this->ReadPropertyString('MPP1Name')) ?: 'MPP1';
        $mpp2Name = trim($this->ReadPropertyString('MPP2Name')) ?: 'MPP2';

        IPS_SetName($this->GetIDForIdent('MPP1Pdc'), $mpp1Name . ' Leistung DC');
        IPS_SetName($this->GetIDForIdent('MPP1Udc'), $mpp1Name . ' Spannung DC');
        IPS_SetName($this->GetIDForIdent('MPP1Specific'), $mpp1Name . ' spezifische Leistung');
        IPS_SetName($this->GetIDForIdent('MPP2Pdc'), $mpp2Name . ' Leistung DC');
        IPS_SetName($this->GetIDForIdent('MPP2Udc'), $mpp2Name . ' Spannung DC');
        IPS_SetName($this->GetIDForIdent('MPP2Specific'), $mpp2Name . ' spezifische Leistung');

        if ($host === '') {
            $this->SetStatus(104);
            return;
        }

        $this->SetStatus(102);
    }

    /**
     * Liest den aktuellen Tagesdatensatz aus dem Solar-Log und übernimmt den letzten Messpunkt.
     * Öffentlich verfügbar als SLMPP_Update(<InstanzID>).
     */
    public function Update(): string
    {
        $fetchTimestamp = time();
        $this->SetValueChanged('LastFetch', $fetchTimestamp);

        try {
            $response = $this->FetchRaw();
            $sample = $this->ExtractLatestSample($response);
            $this->ApplySample($sample);

            $this->SetValueChanged('CommunicationOK', true);
            $this->SetValueChanged('LastError', '');
            $this->SetStatus(102);

            return sprintf(
                'OK: %s, Pac %.0f W, %s %.0f W, %s %.0f W, Tagesertrag %.0f Wh',
                date('H:i:s', $sample['timestamp']),
                $sample['pac'],
                $this->ReadPropertyString('MPP1Name'),
                $sample['mpp1Pdc'],
                $this->ReadPropertyString('MPP2Name'),
                $sample['mpp2Pdc'],
                $sample['dailyYield']
            );
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $this->SendDebug('UpdateError', $message, 0);
            $this->SetValueChanged('CommunicationOK', false);
            $this->SetValueChanged('DataFresh', false);
            $this->SetValueChanged('LastError', $message);
            $this->SetStatus(201);
            return 'FEHLER: ' . $message;
        }
    }

    /**
     * Führt nur die HTTP/JSON-Abfrage aus und gibt eine kurze Diagnose zurück.
     * Öffentlich verfügbar als SLMPP_Test(<InstanzID>).
     */
    public function Test(): string
    {
        try {
            $response = $this->FetchRaw();
            $sample = $this->ExtractLatestSample($response);
            return sprintf(
                'Antwort OK (%d Bytes). Letzter Messpunkt %s: [%s]',
                strlen($response),
                date('Y-m-d H:i:s', $sample['timestamp']),
                implode(', ', $sample['raw'])
            );
        } catch (Throwable $e) {
            return 'FEHLER: ' . $e->getMessage();
        }
    }

    private function FetchRaw(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            throw new RuntimeException('Solar-Log Host ist leer.');
        }

        if (!preg_match('#^https?://#i', $host)) {
            $host = 'http://' . $host;
        }
        $host = rtrim($host, '/');
        $url = $host . '/getjp';

        $inverterIndex = max(0, $this->ReadPropertyInteger('InverterIndex'));
        $seriesIndex = max(0, $this->ReadPropertyInteger('SeriesIndex'));
        $token = $this->ReadPropertyString('Token');

        // Exakt die von der Solar-Log-Weboberfläche beobachtete Query-Struktur.
        $query = sprintf(
            '{"141":{"%d":{"711":{"%d":null}}}}',
            $inverterIndex,
            $seriesIndex
        );
        $body = 'token=' . $token . ';preval=666;postval=666;' . $query;

        $headers = [
            'Accept: */*',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Connection: close',
            'Origin: ' . $host,
            'Referer: ' . $host . '/'
        ];

        $cookie = trim($this->ReadPropertyString('Cookie'));
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => max(1, $this->ReadPropertyInteger('Timeout')),
                'ignore_errors' => true
            ]
        ]);

        $this->SendDebug('RequestURL', $url, 0);
        $this->SendDebug('RequestBody', $body, 0);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP-Abfrage fehlgeschlagen' . (($error && isset($error['message'])) ? ': ' . $error['message'] : '.'));
        }

        $this->SendDebug('ResponseLength', strlen($response), 0);
        return $response;
    }

    private function ExtractLatestSample(string $response): array
    {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Solar-Log Antwort ist kein gültiges JSON: ' . json_last_error_msg());
        }

        $inverterIndex = (string) max(0, $this->ReadPropertyInteger('InverterIndex'));
        $seriesIndex = (string) max(0, $this->ReadPropertyInteger('SeriesIndex'));

        if (!isset($decoded['141'][$inverterIndex]['711'][$seriesIndex]) || !is_array($decoded['141'][$inverterIndex]['711'][$seriesIndex])) {
            throw new RuntimeException('Erwarteter Datenpfad 141/' . $inverterIndex . '/711/' . $seriesIndex . ' fehlt.');
        }

        $rows = $decoded['141'][$inverterIndex]['711'][$seriesIndex];
        if (count($rows) === 0) {
            throw new RuntimeException('Solar-Log liefert keine Messpunkte.');
        }

        $latest = end($rows);
        if (!is_array($latest) || count($latest) < 2 || !is_string($latest[0]) || !is_array($latest[1])) {
            throw new RuntimeException('Letzter Solar-Log Messpunkt hat ein unbekanntes Format.');
        }

        $values = $latest[1];
        if (count($values) < 9) {
            throw new RuntimeException('Messpunkt enthält weniger als die erwarteten 9 Werte.');
        }

        $timestamp = $this->BuildTodayTimestamp($latest[0]);

        return [
            'timestamp' => $timestamp,
            'pac' => (float) $values[0],
            'mpp1Pdc' => (float) $values[1],
            'mpp2Pdc' => (float) $values[2],
            'mpp1Udc' => (float) $values[4],
            'mpp2Udc' => (float) $values[5],
            'dailyYield' => (float) $values[8],
            'raw' => $values
        ];
    }

    private function ApplySample(array $sample): void
    {
        $pdcTotal = $sample['mpp1Pdc'] + $sample['mpp2Pdc'];
        $efficiency = $pdcTotal > 10.0 ? ($sample['pac'] / $pdcTotal) * 100.0 : 0.0;

        $mpp1kWp = $this->ReadPropertyFloat('MPP1kWp');
        $mpp2kWp = $this->ReadPropertyFloat('MPP2kWp');
        $mpp1Specific = $mpp1kWp > 0.0 ? $sample['mpp1Pdc'] / $mpp1kWp : 0.0;
        $mpp2Specific = $mpp2kWp > 0.0 ? $sample['mpp2Pdc'] / $mpp2kWp : 0.0;

        $this->SetValueChanged('LastSample', $sample['timestamp']);
        $this->SetValueChanged('Pac', $sample['pac']);
        $this->SetValueChanged('PdcTotal', $pdcTotal);
        $this->SetValueChanged('DcAcEfficiency', $efficiency);
        $this->SetValueChanged('DailyYield', $sample['dailyYield']);
        $this->SetValueChanged('MPP1Pdc', $sample['mpp1Pdc']);
        $this->SetValueChanged('MPP1Udc', $sample['mpp1Udc']);
        $this->SetValueChanged('MPP1Specific', $mpp1Specific);
        $this->SetValueChanged('MPP2Pdc', $sample['mpp2Pdc']);
        $this->SetValueChanged('MPP2Udc', $sample['mpp2Udc']);
        $this->SetValueChanged('MPP2Specific', $mpp2Specific);

        $freshnessSeconds = max(5, $this->ReadPropertyInteger('FreshnessMinutes')) * 60;
        $age = max(0, time() - $sample['timestamp']);
        $this->SetValueChanged('DataFresh', $age <= $freshnessSeconds);

        $this->SendDebug('LatestSample', json_encode($sample, JSON_UNESCAPED_SLASHES), 0);
    }

    private function BuildTodayTimestamp(string $time): int
    {
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            throw new RuntimeException('Ungültige Solar-Log Uhrzeit: ' . $time);
        }

        $timestamp = strtotime(date('Y-m-d') . ' ' . $time);
        if ($timestamp === false) {
            throw new RuntimeException('Solar-Log Uhrzeit konnte nicht interpretiert werden: ' . $time);
        }

        // Schutz für Abfragen unmittelbar nach Mitternacht, falls der Solar-Log noch einen Messpunkt vom Vortag liefert.
        if ($timestamp > time() + 3600) {
            $timestamp -= 86400;
        }

        return $timestamp;
    }

    private function SetValueChanged(string $ident, $value): void
    {
        $variableID = $this->GetIDForIdent($ident);
        $current = GetValue($variableID);

        if (is_float($value)) {
            if (!is_numeric($current) || abs((float) $current - $value) > 0.0001) {
                SetValue($variableID, $value);
            }
            return;
        }

        if ($current !== $value) {
            SetValue($variableID, $value);
        }
    }

    private function RegisterProfiles(): void
    {
        $this->EnsureFloatProfile(self::PROFILE_WATT, ' W', 0);
        $this->EnsureFloatProfile(self::PROFILE_VOLT, ' V', 0);
        $this->EnsureFloatProfile(self::PROFILE_WATT_PER_KWP, ' W/kWp', 0);
        $this->EnsureFloatProfile(self::PROFILE_PERCENT, ' %', 1);
        $this->EnsureFloatProfile(self::PROFILE_WH, ' Wh', 0);
    }

    private function EnsureFloatProfile(string $name, string $suffix, int $digits): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileDigits($name, $digits);
    }
}
