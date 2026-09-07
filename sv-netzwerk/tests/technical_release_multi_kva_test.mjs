import fs from "node:fs";

const page = fs.readFileSync(
  new URL("../src/pages/intern/versicherungsfaelle/index.astro", import.meta.url),
  "utf8",
);
const endpoint = fs.readFileSync(
  new URL("../public/intern/api/technical-release-pdf.php", import.meta.url),
  "utf8",
);
const core = fs.readFileSync(
  new URL("../public/intern/api/kva-release-core-v2.php", import.meta.url),
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
  !page.includes("Referenz öffnen"),
  "Die interne PDF-Vorlage darf nicht als Bedienpunkt angezeigt werden.",
);
assert(
  page.includes('id="vf-technical-kvas"') &&
    page.includes("querySelectorAll('input:checked')"),
  "Mehrfachauswahl der KVA aus der Fallakte fehlt.",
);
assert(
  page.includes('id="vf-technical-files"') &&
    page.includes('role="button" tabindex="0"') &&
    page.includes('type="file" hidden multiple'),
  "Drag-and-drop muss mehrere KVA annehmen.",
);
assert(
  page.includes("for(let index=0;index<targets.length;index++)") &&
    page.includes("action=technical_analyze"),
  "Ausgewählte und abgelegte KVA werden nicht vollständig nacheinander eingelesen.",
);
assert(
  page.includes("rows.reduce((sum,row)=>sum+(Number(row.gross_total)||0),0)"),
  "Die gemeinsame Bruttosumme fehlt.",
);
assert(
  page.includes("action=search_cases") && page.includes("recipient_name") &&
    !page.includes("read.disabled=!(active()?.folder_id"),
  "Die technische Freigabe muss ohne zuvor geöffneten Fall arbeiten und selbst zuordnen.",
);
for (const id of ["vf-tr-download", "vf-tr-save", "vf-tr-send"]) {
  assert(page.includes(`id="${id}"`), `Ausgabeaktion ${id} fehlt.`);
}
assert(fs.existsSync(reference), "Das Referenzformular fehlt im Portalbestand.");
assert(
  endpoint.includes("requireAuth();") && endpoint.includes("trBuildTechnicalReleasePdf"),
  "Die fertige technische Freigabe wird nicht geschützt als PDF erzeugt.",
);
assert(
  core.indexOf("if ($action === 'technical_analyze')") < core.indexOf("requireCaseFolderAccess($folder, $user)"),
  "Die KVA-Analyse verlangt weiterhin einen zuvor geöffneten Fall.",
);
assert(
  core.includes("calculated_from_net_and_vat_rate") && core.includes("recipient_name"),
  "Berechnete Bruttowerte oder die automatische Fallzuordnung fehlen.",
);

console.log("Technische Freigabe: Mehrfach-KVA und Referenzformular sind verdrahtet.");
