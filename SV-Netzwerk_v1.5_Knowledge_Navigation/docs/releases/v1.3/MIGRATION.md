# Migrationsleitfaden

Das neue Grundsystem wird seitenweise eingeführt.

## Beispiel

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---

<BaseLayout
  title="Seitentitel"
  description="Kurze Seitenbeschreibung"
  breadcrumbs={[
    { label: 'Start', href: '/' },
    { label: 'Seitentitel' }
  ]}
>
  <h1>Seitentitel</h1>
  <p>Inhalt der Seite.</p>
</BaseLayout>
```

## Reihenfolge

1. Seite auswählen.
2. Inhalt sichern.
3. Seite auf `BaseLayout` umstellen.
4. lokalen Build ausführen.
5. Darstellung mobil und am Desktop prüfen.
6. Commit und Push.
