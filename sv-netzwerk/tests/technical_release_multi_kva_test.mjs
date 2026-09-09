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
const claimsforceCentral = fs.readFileSync(
  new URL("../public/intern/claimsforce-central.js", import.meta.url),
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
  page.includes("KVA-Freigabe Sanierer") &&
    page.includes("Technische Freigabe Allgemein / VN") &&
    page.includes('class="vf-release-grid"'),
  "Sanierer- und VN-Freigabe muessen nebeneinander eindeutig bezeichnet sein.",
);
assert(
  page.includes('class="vf-analysis-grid"') &&
    page.includes('.vf-analysis-grid{display:grid;grid-column:1/-1;grid-template-columns:minmax(0,1fr) minmax(0,1fr)'),
  "PLAUD und Polycam muessen in einer gemeinsamen, mittig geteilten Zeile stehen.",
);
assert(
  claimsforceCentral.includes("const analysisGrid=document.querySelector('.vf-analysis-grid')") &&
    claimsforceCentral.includes("claimsCard.closest('.vf-import-grid')?[analysisGrid]:[analysisGrid,claimsCard]") &&
    claimsforceCentral.includes('movableCards.forEach'),
  "Die ClaimsForce-Nachsortierung darf PLAUD und Polycam nicht aus ihrer gemeinsamen Zeile loesen.",
);
assert(
  page.indexOf('id="vf-system-panel"') < page.indexOf('class="vf-import-grid vf-import-grid-bottom"'),
  "ClaimsForce und Rekon muessen als gemeinsame Importzeile ganz unten stehen.",
);
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
assert(page.includes('technicalMailFallback'), 'A sachverstaendiger fallback mail text should be generated');
assert(page.includes('usableTechnicalDraft'), 'Unformatted or evidence-gap drafts must fall back to the controlled wording');
assert(page.includes('Etwaige darüber hinausgehende Mehrleistungen'), 'The mail must reserve additional work for separate review');
assert(core.includes('email_draft'), 'The technical analysis should return a source-grounded mail draft');
assert(core.includes('Ursprungsangebot'), 'The mail prompt should cover documented prior offers');
assert(core.includes('Nicht belegte Bausteine werden vollständig weggelassen'), 'Missing comparison data must be omitted from the mail');
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
