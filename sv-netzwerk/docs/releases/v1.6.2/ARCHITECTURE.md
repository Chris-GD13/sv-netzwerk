# v1.6.2 – Search Engine

Die Suche besteht aus drei Schichten:

1. `src/lib/search/`: Normalisierung, Typen, Indexaufbau und Ranking.
2. `search-index.json`: statisch erzeugter, cachebarer Suchindex mit Facetten.
3. Suchoberflächen: globales Overlay und erweiterte Seite `/suche/`.

Die Indexstruktur ist unabhängig von der Darstellung und kann später durch einen externen Volltextdienst ersetzt werden.
