<?php
/**
 * Zentraler KI-Service – SV-Netzwerk Prüfportal
 *
 * Kapselung der OpenAI-API-Kommunikation. Alle KI-Funktionen der Anwendung
 * laufen über diese Klasse. Zukunftssichere Architektur für:
 * - Dokumentenanalyse (aktuell)
 * - Automatische Prüfprotokolle
 * - Mängelerkennung anhand von Fotos
 * - Beschlagserkennung
 * - Glasidentifikation
 * - OCR von Typenschildern
 * - Automatische Berichte
 * - Intelligente Suchfunktion
 */

declare(strict_types=1);

class AiService
{
    private string $apiKey;
    private string $model;
    private string $endpoint = 'https://api.openai.com/v1/chat/completions';

    public function __construct(?string $apiKey = null, string $model = 'gpt-4o')
    {
        $this->apiKey = $apiKey ?? env('OPENAI_API_KEY', '');
        $this->model = $model;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    // ─── Dokumentenanalyse ───────────────────────────────────────────────────

    /**
     * Analysiert ein hochgeladenes Dokument und extrahiert strukturierte Daten.
     *
     * @param string $base64Content Base64-encodierter Dateiinhalt
     * @param string $mimeType      MIME-Typ der Datei
     * @param string $fileName      Originaler Dateiname
     * @param array  $context       Bestehende Projektdaten für Kontext
     * @return array|null           Strukturiertes Analyseergebnis oder null bei Fehler
     */
    public function analyzeDocument(string $base64Content, string $mimeType, string $fileName, array $context): ?array
    {
        $systemPrompt = $this->buildDocumentAnalysisPrompt($context);
        $content = $this->prepareFileContent($base64Content, $mimeType, $fileName);
        $content[] = ['type' => 'text', 'text' => 'Analysiere dieses Dokument vollständig und extrahiere alle erkennbaren Gebäude-, Etagen-, Raum- und Fensterdaten inkl. Hersteller, Maße, Beschlagsysteme und Bemerkungen.'];

        return $this->chat($systemPrompt, $content, 8192, 0.1);
    }

    // ─── Zukünftige KI-Funktionen (Platzhalter) ─────────────────────────────

    /**
     * Erkennt Mängel anhand eines Fotos.
     * @param string $base64Image Base64-encodiertes Bild
     * @return array|null Erkannte Mängel
     */
    public function detectDefects(string $base64Image, string $mimeType = 'image/jpeg'): ?array
    {
        $prompt = "Du bist ein Experte für Fensterbeschlagsprüfung. Analysiere das Foto und identifiziere alle sichtbaren Mängel an Fensterbeschlägen, Dichtungen, Rahmen etc. Antworte als JSON mit: {\"defects\": [{\"type\": \"...\", \"severity\": \"gering|mittel|schwer\", \"description\": \"...\", \"location\": \"...\"}]}";
        $content = [
            ['type' => 'image_url', 'image_url' => ['url' => "data:$mimeType;base64,$base64Image", 'detail' => 'high']],
            ['type' => 'text', 'text' => 'Welche Mängel erkennst du auf diesem Foto?'],
        ];
        return $this->chat($prompt, $content, 4096, 0.2);
    }

    /**
     * OCR von Typenschildern.
     * @param string $base64Image Base64-encodiertes Bild
     * @return array|null Erkannter Text und Interpretation
     */
    public function ocrTypeLabel(string $base64Image, string $mimeType = 'image/jpeg'): ?array
    {
        $prompt = "Du bist ein Experte für Fensterbeschläge. Lese das Typenschild und extrahiere: Hersteller, Modell, Seriennummer, Baujahr, Größe, und alle weiteren sichtbaren Informationen. Antworte als JSON: {\"manufacturer\": \"...\", \"model\": \"...\", \"serial\": \"...\", \"year\": null, \"dimensions\": \"...\", \"additional\": {}}";
        $content = [
            ['type' => 'image_url', 'image_url' => ['url' => "data:$mimeType;base64,$base64Image", 'detail' => 'high']],
            ['type' => 'text', 'text' => 'Lese das Typenschild und extrahiere alle Informationen.'],
        ];
        return $this->chat($prompt, $content, 2048, 0.1);
    }

    /**
     * Beschlagserkennung anhand eines Fotos.
     */
    public function identifyHardware(string $base64Image, string $mimeType = 'image/jpeg'): ?array
    {
        $prompt = "Du bist ein Experte für Fensterbeschläge. Identifiziere den Beschlagstyp, Hersteller und Zustand auf dem Foto. Antworte als JSON: {\"hardware_type\": \"...\", \"manufacturer\": \"...\", \"model\": \"...\", \"condition\": \"gut|befriedigend|mangelhaft\", \"notes\": \"...\"}";
        $content = [
            ['type' => 'image_url', 'image_url' => ['url' => "data:$mimeType;base64,$base64Image", 'detail' => 'high']],
            ['type' => 'text', 'text' => 'Identifiziere den Fensterbeschlag auf diesem Foto.'],
        ];
        return $this->chat($prompt, $content, 2048, 0.2);
    }

    // ─── Kern-API-Kommunikation ──────────────────────────────────────────────

    /**
     * Sendet einen Chat-Request an die OpenAI API.
     *
     * @param string $systemPrompt System-Anweisung
     * @param array  $userContent  User-Content (text, image_url, file etc.)
     * @param int    $maxTokens    Maximale Antwort-Token
     * @param float  $temperature  Kreativität (0-2)
     * @return array|null          Geparstes JSON oder null bei Fehler
     */
    private function chat(string $systemPrompt, array $userContent, int $maxTokens = 4096, float $temperature = 0.1): ?array
    {
        if (!$this->isConfigured()) {
            error_log('[AiService] Kein API-Key konfiguriert.');
            return null;
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            error_log("[AiService] cURL-Fehler: $curlErr");
            return null;
        }

        if ($httpCode !== 200 || $response === false) {
            error_log("[AiService] API-Fehler HTTP $httpCode: " . substr((string)$response, 0, 1000));
            return null;
        }

        $decoded = json_decode((string)$response, true);
        $text = $decoded['choices'][0]['message']['content'] ?? '';

        return $this->extractJson($text);
    }

    /**
     * Extrahiert JSON aus einer KI-Antwort (auch aus Markdown-Codeblöcken).
     */
    private function extractJson(string $text): ?array
    {
        // Versuche zuerst JSON aus Codeblock zu extrahieren
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        // Versuche JSON direkt zu parsen
        $parsed = json_decode(trim($text), true);
        if ($parsed !== null) {
            return $parsed;
        }

        // Letzter Versuch: erstes { bis letztes }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        error_log("[AiService] JSON-Parse-Fehler: " . substr($text, 0, 500));
        return null;
    }

    // ─── Datei-Vorbereitung ──────────────────────────────────────────────────

    /**
     * Bereitet den Dateiinhalt für die OpenAI API auf.
     */
    private function prepareFileContent(string $base64, string $mime, string $fileName): array
    {
        $content = [];

        if (str_starts_with($mime, 'text/') || $mime === 'application/csv') {
            // Text-Dateien direkt senden
            $text = base64_decode($base64);
            $content[] = ['type' => 'text', 'text' => "Datei: $fileName\n\nInhalt:\n$text"];
        } elseif ($mime === 'application/pdf') {
            // PDF als file_data
            $content[] = [
                'type' => 'file',
                'file' => [
                    'filename' => $fileName,
                    'file_data' => "data:$mime;base64,$base64",
                ],
            ];
        } elseif (str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || str_contains($mime, 'ms-excel')) {
            // Excel: als file senden
            $content[] = [
                'type' => 'file',
                'file' => [
                    'filename' => $fileName,
                    'file_data' => "data:$mime;base64,$base64",
                ],
            ];
        } elseif (str_contains($mime, 'word') || str_contains($mime, 'document')) {
            // Word: als file senden
            $content[] = [
                'type' => 'file',
                'file' => [
                    'filename' => $fileName,
                    'file_data' => "data:$mime;base64,$base64",
                ],
            ];
        } else {
            // Bilder (JPG, PNG, TIFF, WebP, GIF)
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:$mime;base64,$base64",
                    'detail' => 'high',
                ],
            ];
        }

        return $content;
    }

    // ─── Prompt-Erstellung ───────────────────────────────────────────────────

    /**
     * Erstellt den System-Prompt für die Dokumentenanalyse.
     */
    private function buildDocumentAnalysisPrompt(array $context): string
    {
        $buildingsSummary = $context['buildings_summary'] ?? 'keine';
        $floorsSummary = $context['floors_summary'] ?? 'keine';
        $roomsSummary = $context['rooms_summary'] ?? 'keine';
        $windowsCount = $context['windows_count'] ?? '0';
        $windowsSummary = $context['windows_summary'] ?? '';

        return <<<PROMPT
Du bist ein Experte für Fensterbeschlagsprüfung und Gebäudeinspektion. Du analysierst Dokumente und extrahierst ALLE strukturierten Daten zu Gebäuden, Etagen, Räumen und Fenstern.

═══ BESTEHENDE PROJEKTDATEN (zum Abgleich) ═══
- Gebäude: {$buildingsSummary}
- Etagen: {$floorsSummary}
- Räume: {$roomsSummary}
- Fenster: {$windowsCount} Datensätze
{$windowsSummary}

═══ ANALYSE-REGELN ═══
1. Extrahiere ALLE erkennbaren Daten: Gebäude, Etagen, Räume, Fenster
2. Für Fenster: Fensternummer, Hersteller, Maße (B×H), Beschlagsystem, Bemerkungen
3. Status-Klassifizierung:
   - "new": Eintrag existiert noch nicht im Projekt
   - "update": Eintrag existiert, KI hat zusätzliche/ergänzende Daten gefunden
   - "conflict": Eintrag existiert, aber KI-Daten weichen von bestehenden ab
   - "exists": Eintrag existiert identisch – keine Änderung nötig
4. Niemals bestehende Daten löschen oder überschreiben vorschlagen
5. Gib für jeden Eintrag eine Konfidenz (0.0–1.0) an

═══ ANTWORT-FORMAT (ausschließlich JSON) ═══
{
  "document_type": "bauplan|fensterliste|raumliste|pruefbericht|herstellerdaten|sonstiges",
  "summary": "Kurze Beschreibung des Dokumenteninhalts",
  "items": [
    {
      "type": "building|floor|room|window",
      "status": "new|update|conflict|exists",
      "data": {
        // building: {"name":"...","code":"..."}
        // floor: {"name":"...","building_name":"...","level":0}
        // room: {"name":"...","room_number":"...","floor_name":"...","building_name":"..."}
        // window: {
        //   "window_number":"...",
        //   "room_name":"...", "floor_name":"...", "building_name":"...",
        //   "manufacturer":"...", "width_mm":null, "height_mm":null,
        //   "hardware_system":"...", "glass_type":"...",
        //   "notes":"..."
        // }
      },
      "confidence": 0.95,
      "change_description": "Optional: Was ist neu/anders gegenüber den bestehenden Daten"
    }
  ]
}
PROMPT;
    }

    // ─── Unterstützte Dateitypen ─────────────────────────────────────────────

    /**
     * Liste der unterstützten MIME-Typen für die Dokumentenanalyse.
     */
    public static function supportedMimeTypes(): array
    {
        return [
            // Bilder
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/tiff',
            // Dokumente
            'application/pdf',
            // Tabellen
            'text/csv',
            'text/plain',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            // Word
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            // E-Mail (.msg)
            'application/vnd.ms-outlook',
            'application/octet-stream', // .msg files often detected as generic binary
        ];
    }

    /**
     * Akzeptierte Dateiendungen für das Frontend.
     */
    public static function acceptedExtensions(): string
    {
        return 'image/*,.pdf,.csv,.xlsx,.xls,.docx,.doc,.tiff,.tif,.msg';
    }
}
