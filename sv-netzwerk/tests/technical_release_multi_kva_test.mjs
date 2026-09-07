import fs from "node:fs";

const page = fs.readFileSync(
  new URL("../src/pages/intern/versicherungsfaelle/index.astro", import.meta.url),
  "utf8",
);
const endpoint = fs.readFileSync(
  new URL("../public/intern/api/technical-release-reference.php", import.meta.url),
  "utf8",
);
const htaccess = fs.readFileSync(
  new URL("../public/.htaccess", import.meta.url),
  "utf8",
);
const reference = new URL(
  "../public/intern/references/technische-kva-freigabe-referenz.pdf",
  import.meta.url,
);

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

assert(page.includes("Technische Freigabe"), "Der neue Portalpunkt fehlt.");
assert(
  page.includes('href="/intern/api/technical-release-reference.php"'),
  "Das Referenzformular wird nicht über den geschützten Endpunkt geöffnet.",
);
assert(
  page.includes('id="vf-technical-kvas"') &&
    page.includes("querySelectorAll('input:checked')"),
  "Mehrfachauswahl der KVA aus der Fallakte fehlt.",
);
assert(
  page.includes('id="vf-technical-files"') &&
    page.includes('type="file" hidden multiple'),
  "Drag-and-drop muss mehrere KVA annehmen.",
);
assert(
  page.includes("for(let index=0;index<targets.length;index++)"),
  "Ausgewählte und abgelegte KVA werden nicht vollständig nacheinander eingelesen.",
);
assert(
  page.includes("rows.reduce((sum,row)=>sum+Number(row.gross),0)"),
  "Die gemeinsame Bruttosumme fehlt.",
);
assert(fs.existsSync(reference), "Das Referenzformular fehlt im Portalbestand.");
assert(
  endpoint.includes("requireAuth();") &&
    htaccess.includes("technische-kva-freigabe-referenz") &&
    htaccess.includes("technical-release-reference.php"),
  "Das fallbezogene Referenzformular ist nicht gegen öffentlichen Abruf geschützt.",
);

console.log("Technische Freigabe: Mehrfach-KVA und Referenzformular sind verdrahtet.");
