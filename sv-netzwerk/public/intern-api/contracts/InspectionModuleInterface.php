<?php
declare(strict_types=1);

namespace SvIntern\Contracts;

/**
 * Jedes Inspektionsmodul implementiert dieses Interface.
 *
 * Ein Modul kapselt alles, was eine bestimmte Inspektionsart ausmacht:
 * - Datenbankschema (eigene Tabelle fuer modulspezifische Felder)
 * - Routing (API-Endpunkte unter /intern-api/modules/{slug}/...)
 * - Auswertungslogik
 * - Export-Variante
 *
 * Gemeinsame Dienste (Auth, Users, Projects, Buildings, Photos, AuditLog, Export)
 * werden vom Core bereitgestellt und sind nicht Teil des Moduls.
 */
interface InspectionModuleInterface
{
    /**
     * Technischer Bezeichner des Moduls (URL-sicher, kleingeschrieben, z. B. "windows").
     */
    public function getSlug(): string;

    /**
     * Lesbarer Name, z. B. "Fensterbeschlagspruefung".
     */
    public function getName(): string;

    /**
     * Unveraenderliche UUID des Moduls (muss mit dem DB-Eintrag uebereinstimmen).
     */
    public function getId(): string;

    /**
     * Version des Moduls (Semver, z. B. "1.0.0").
     */
    public function getVersion(): string;

    /**
     * HTTP-Methode und Pfad-Segmente auf Handler-Callable mappen.
     *
     * Wird vom zentralen Router aufgerufen wenn die URL
     * /intern-api/modules/{slug}/{...} passt.
     *
     * Gibt null zurueck wenn kein Handler passt (Router sendet 404).
     *
     * @param string   $method    HTTP-Methode (GET, POST, PUT, DELETE, …)
     * @param string[] $segments  Pfad-Segmente nach dem Modul-Slug
     * @param array    $session   Aktive Session oder []
     * @param \PDO     $db        Datenbank-Verbindung
     * @return callable|null
     */
    public function route(string $method, array $segments, array $session, \PDO $db): ?callable;

    /**
     * Liefert Modul-spezifische Statistiken fuer das Dashboard.
     *
     * @param string $projectId
     * @return array<string, mixed>
     */
    public function getDashboardStats(string $projectId, \PDO $db): array;
}
