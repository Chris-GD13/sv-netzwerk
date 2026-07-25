<?php
declare(strict_types=1);

namespace SvIntern\Services;

use SvIntern\Config\Config;

/**
 * Sicherer Datei-Upload-Service.
 *
 * Sicherheitsmerkmale:
 * - Allowlist fuer MIME-Typen (keine Blocklist)
 * - Serverseitige MIME-Verifikation via finfo (nicht nur Content-Type-Header)
 * - Zufaellig generierte Speicherdateinamen (Original nur als Metadatum)
 * - Pfad-Traversal-Schutz via realpath()-Abgleich
 * - PHP-Ausfuehrung im Upload-Verzeichnis blockiert
 * - Maximale Dateigroesse konfigurierbar
 */
final class UploadService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB

    private string $uploadBase;

    public function __construct()
    {
        $configured = Config::get('UPLOAD_PATH', '');

        // Absoluter Pfad
        if ($configured !== '' && !str_starts_with($configured, '/')) {
            // Relativer Pfad → relativ zur intern-api Directory
            $configured = dirname(__DIR__, 1) . '/' . ltrim($configured, '/');
        }

        $this->uploadBase = $configured !== ''
            ? rtrim($configured, '/')
            : dirname(__DIR__) . '/../uploads/photos';

        $this->ensureUploadDir();
    }

    /**
     * Verarbeitet eine hochgeladene Datei.
     * @param array<string,mixed> $file  $_FILES-Element
     * @return array{storage_path:string,file_name:string,mime_type:string}
     * @throws \RuntimeException bei ungueltigem Upload
     */
    public function process(array $file): array
    {
        // 1. PHP-Upload-Fehler pruefen
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload-Fehler: ' . $this->uploadErrorMessage((int) $file['error']));
        }

        // 2. Dateigroesse pruefen
        if ((int) ($file['size'] ?? 0) > self::MAX_SIZE_BYTES) {
            throw new \RuntimeException('Datei zu gross. Maximale Groesse: 20 MB.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpPath)) {
            throw new \RuntimeException('Keine gueltige Upload-Datei.');
        }

        // 3. MIME-Typ serverseitig pruefen (finfo, nicht nur Content-Type)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpPath);

        if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
            throw new \RuntimeException(
                'Dateiformat nicht erlaubt. Erlaubt: JPEG, PNG, WebP, GIF.'
            );
        }

        // 4. Sicheren Speicherdateinamen generieren (niemals Original-Endung direkt verwenden)
        $ext         = self::ALLOWED_MIME_TYPES[$mime];
        $storageName = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath  = $this->uploadBase . '/' . $storageName;

        // 5. Pfad-Traversal-Schutz
        $realTarget = realpath(dirname($targetPath)) . '/' . basename($targetPath);
        $realBase   = realpath($this->uploadBase);
        if ($realBase === false || !str_starts_with($realTarget, $realBase . '/')) {
            throw new \RuntimeException('Ungueltige Zieldatei (Pfad-Traversal verhindert).');
        }

        // 6. Datei verschieben
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new \RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        return [
            'storage_path' => $storageName,
            'file_name'    => basename((string) ($file['name'] ?? 'upload')),
            'mime_type'    => $mime,
        ];
    }

    /**
     * Prueft ob eine gespeicherte Datei existiert und gibt den absoluten Pfad zurueck.
     * @throws \RuntimeException bei Pfad-Traversal-Versuch
     */
    public function resolvePath(string $storageName): string
    {
        // Dateiname darf nur sichere Zeichen enthalten
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/i', $storageName)) {
            throw new \RuntimeException('Ungueltiger Dateiname.');
        }

        $realBase = realpath($this->uploadBase);
        if ($realBase === false) {
            throw new \RuntimeException('Upload-Verzeichnis nicht erreichbar.');
        }

        $path = $realBase . '/' . $storageName;
        if (!is_file($path)) {
            throw new \RuntimeException('Datei nicht gefunden.');
        }

        return $path;
    }

    /**
     * Loescht eine gespeicherte Datei.
     */
    public function delete(string $storageName): void
    {
        try {
            $path = $this->resolvePath($storageName);
            @unlink($path);
        } catch (\RuntimeException) {
            // Datei existiert nicht mehr – ignorieren
        }
    }

    private function ensureUploadDir(): void
    {
        if (!is_dir($this->uploadBase)) {
            if (!mkdir($this->uploadBase, 0750, true) && !is_dir($this->uploadBase)) {
                throw new \RuntimeException(
                    'Upload-Verzeichnis konnte nicht erstellt werden: ' . $this->uploadBase
                );
            }
        }

        // .htaccess zum Schutz anlegen falls nicht vorhanden
        $htaccess = $this->uploadBase . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nOptions -ExecCGI\n");
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei zu gross.',
            UPLOAD_ERR_PARTIAL  => 'Upload unvollstaendig.',
            UPLOAD_ERR_NO_FILE  => 'Keine Datei gesendet.',
            UPLOAD_ERR_NO_TMP_DIR => 'Kein temporaeres Verzeichnis.',
            UPLOAD_ERR_CANT_WRITE => 'Fehler beim Schreiben.',
            default => 'Upload-Fehler (Code ' . $code . ').',
        };
    }

    public function getUploadBase(): string
    {
        return $this->uploadBase;
    }
}
