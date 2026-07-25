<?php
declare(strict_types=1);

namespace SvIntern\Registry;

use SvIntern\Contracts\InspectionModuleInterface;

/**
 * Zentrales Modul-Register des SVOS Inspection Platform.
 *
 * Module werden per registerModule() angemeldet und sind anschliessend
 * per Slug oder ID abrufbar. Der Router nutzt das Register um eingehende
 * Anfragen an das zustaendige Modul weiterzuleiten.
 */
final class ModuleRegistry
{
    /** @var array<string, InspectionModuleInterface> Keyed by slug */
    private array $bySlug = [];

    /** @var array<string, InspectionModuleInterface> Keyed by id */
    private array $byId = [];

    private static ?self $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Registriert ein Modul.
     * @throws \InvalidArgumentException bei Duplikaten
     */
    public function register(InspectionModuleInterface $module): void
    {
        $slug = $module->getSlug();
        $id   = $module->getId();

        if (isset($this->bySlug[$slug])) {
            throw new \InvalidArgumentException("Modul-Slug bereits registriert: {$slug}");
        }
        if (isset($this->byId[$id])) {
            throw new \InvalidArgumentException("Modul-ID bereits registriert: {$id}");
        }

        $this->bySlug[$slug] = $module;
        $this->byId[$id]     = $module;
    }

    public function findBySlug(string $slug): ?InspectionModuleInterface
    {
        return $this->bySlug[$slug] ?? null;
    }

    public function findById(string $id): ?InspectionModuleInterface
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<InspectionModuleInterface>
     */
    public function all(): array
    {
        return array_values($this->bySlug);
    }

    /**
     * Gibt eine Liste aller registrierten Module fuer API-Antworten zurueck.
     * @return list<array{id: string, slug: string, name: string, version: string}>
     */
    public function toApiList(): array
    {
        return array_map(
            static fn(InspectionModuleInterface $m) => [
                'id'      => $m->getId(),
                'slug'    => $m->getSlug(),
                'name'    => $m->getName(),
                'version' => $m->getVersion(),
            ],
            array_values($this->bySlug)
        );
    }
}
