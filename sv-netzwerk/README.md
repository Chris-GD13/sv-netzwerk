\# SV-Netzwerk



Offizielle Projektquelle der Website \*\*SV-Netzwerk\*\*.



\## Website



https://www.sv-netzwerk.eu



\---



\## Technik



\- Astro

\- Node.js 22 (GitHub-Actions-Deployment)

\- Git

\- GitHub

\- GitHub Actions

\- IONOS Deployment



\---



\## Installation



```bash

npm install

```



Entwicklungsserver



```bash

npm run dev

```



Build



```bash

npm run build

```



\---



\## Deployment



Änderungen unter `sv-netzwerk/` auf **main** (mit Ausnahmen für das
Fachbeitragsprotokoll und die Kalender-Slots) lösen automatisch aus:



1\. GitHub Actions

2\. Build der Website

3\. Upload des Inhalts von `dist/` per SFTP direkt in das IONOS-Document-Root
   `/sv-netzwerk`

Der vollständige Ablauf und die Live-Prüfungen stehen in der Repository-Datei
[`DEPLOYMENT.md`](../DEPLOYMENT.md).



\---



\## Projektstruktur



```

src/

public/

assets/

docs/

.github/

```



\---



\## Copyright



© Christian Wächter



Alle Rechte vorbehalten.
