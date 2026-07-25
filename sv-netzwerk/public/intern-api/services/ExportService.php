<?php
declare(strict_types=1);

namespace SvIntern\Services;

/**
 * Export-Service: CSV und druckbarer HTML-Report.
 * PDF wird per Browser-Druckfunktion erzeugt (kein Server-seitiges PDF).
 */
final class ExportService
{
    private const PROJECT_TITLE   = 'Fensterbeschlagspruefung BMVg Bonn';
    private const OBJECT_NAME     = '1. Dienstsitz des Bundesministeriums der Verteidigung';
    private const OBJECT_ADDRESS  = 'Fontainengraben 150, 53123 Bonn';

    /**
     * Erzeugt einen CSV-String fuer die uebergebenen Datensaetze.
     * @param list<array<string,mixed>> $records
     */
    public function buildCsv(array $records, string $delimiter = ','): string
    {
        $header = [
            'Datensatz-ID', 'Pruefnummer', 'Fensternummer', 'Gebaeude',
            'Gebaeudeteil', 'Etage', 'Raumnummer', 'Raumbezeichnung',
            'Zugaenglichkeit', 'Status', 'Gesamtbewertung', 'Prioritaet',
            'Mangel', 'Sofortmassnahme', 'Spezialpruefung', 'Gefahr_Sofort',
            'Pruefer', 'Letzte_Aenderung', 'Abgeschlossen_am',
        ];

        $rows = [$this->csvRow($header, $delimiter)];

        foreach ($records as $r) {
            $rows[] = $this->csvRow([
                $r['record_id']                     ?? '',
                $r['inspection_number']             ?? '',
                $r['window_number']                 ?? '',
                $r['building_label']                ?? '',
                $r['section_label']                 ?? '',
                $r['floor_label']                   ?? '',
                $r['room_number']                   ?? '',
                $r['room_label']                    ?? '',
                $r['accessibility_status']          ?? '',
                $r['status']                        ?? '',
                $r['overall_rating']                ?? '',
                $r['priority']                      ?? '',
                $r['has_defect'] ? 'Ja' : 'Nein',
                $r['urgent_action_required'] ? 'Ja' : 'Nein',
                $r['special_inspection_required'] ? 'Ja' : 'Nein',
                $r['danger_immediate'] ? 'Ja' : 'Nein',
                $r['assigned_name']                 ?? '',
                $r['updated_at']                    ?? '',
                $r['completed_at']                  ?? '',
            ], $delimiter);
        }

        return implode("\n", $rows);
    }

    /**
     * Erzeugt einen druckbaren HTML-Report.
     * @param list<array<string,mixed>> $records
     */
    public function buildHtmlReport(array $records, string $title = 'Sammelprotokoll'): string
    {
        $now      = date('d.m.Y H:i');
        $count    = count($records);
        $h        = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rows     = '';

        foreach ($records as $r) {
            $rows .= '<tr>'
                . '<td>' . $h((string) ($r['record_id'] ?? '')) . '</td>'
                . '<td>' . $h((string) ($r['window_number'] ?? '')) . '</td>'
                . '<td>' . $h(implode(' · ', array_filter([
                    $r['building_label'] ?? null,
                    $r['section_label']  ?? null,
                    $r['floor_label']    ?? null,
                    $r['room_number']    ?? null,
                ]))) . '</td>'
                . '<td>' . $h((string) ($r['status'] ?? '')) . '</td>'
                . '<td>' . $h((string) ($r['overall_rating'] ?? '')) . '</td>'
                . '<td>' . $h((string) ($r['priority'] ?? '')) . '</td>'
                . '<td>' . ($r['has_defect'] ? '<strong>Ja</strong>' : 'Nein') . '</td>'
                . '<td>' . $h((string) ($r['assigned_name'] ?? '')) . '</td>'
                . '</tr>' . "\n";
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="de">
        <head>
          <meta charset="UTF-8">
          <title>{$h(self::PROJECT_TITLE)} – {$h($title)}</title>
          <style>
            *{box-sizing:border-box;margin:0;padding:0}
            body{font-family:Arial,Helvetica,sans-serif;font-size:11px;padding:20px;color:#071a2e}
            h1{font-size:16px;margin-bottom:4px}
            .meta{font-size:10px;color:#555;margin-bottom:16px}
            table{width:100%;border-collapse:collapse;margin-top:12px}
            th{background:#071a2e;color:#fff;padding:6px 8px;text-align:left;font-size:10px}
            td{padding:5px 8px;border-bottom:1px solid #e0e8f0;vertical-align:top}
            tr:nth-child(even) td{background:#f5f8fb}
            strong{color:#b91c1c}
            @page{margin:12mm}
          </style>
        </head>
        <body>
          <h1>{$h(self::PROJECT_TITLE)}</h1>
          <p class="meta">{$h(self::OBJECT_NAME)} · {$h(self::OBJECT_ADDRESS)}</p>
          <p class="meta">Bericht: {$h($title)} · Datenstand: {$h($now)} · {$h((string)$count)} Datensaetze</p>
          <table>
            <thead>
              <tr>
                <th>Datensatz</th><th>Fenster</th><th>Standort</th>
                <th>Status</th><th>Bewertung</th><th>Prioritaet</th>
                <th>Mangel</th><th>Pruefer</th>
              </tr>
            </thead>
            <tbody>{$rows}</tbody>
          </table>
        </body>
        </html>
        HTML;
    }

    /**
     * Gibt den sicheren Dateinamen fuer einen Export zurueck.
     */
    public function exportFileName(string $type, string $ext): string
    {
        return 'BMVg-Bonn_Fensterpruefung_' . $type . '_' . date('Y-m-d') . '.' . $ext;
    }

    private function csvRow(array $fields, string $delimiter): string
    {
        return implode($delimiter, array_map(
            static function (mixed $value) use ($delimiter): string {
                $text = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
                if (str_contains($text, '"') || str_contains($text, $delimiter)) {
                    return '"' . str_replace('"', '""', $text) . '"';
                }
                return $text;
            },
            $fields
        ));
    }
}
