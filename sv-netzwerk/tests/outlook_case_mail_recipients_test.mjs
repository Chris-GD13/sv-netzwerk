import fs from "node:fs";

const source = fs.readFileSync(
  new URL("../public/intern/api/outlook-case-mail.php", import.meta.url),
  "utf8",
);
const page = fs.readFileSync(
  new URL("../src/pages/intern/versicherungsfaelle/index.astro", import.meta.url),
  "utf8",
);

for (const address of [
  "service.schaden@sparkassenversicherung.de",
  "archiv@sparkassenversicherung.de",
]) {
  const protectedPreset = new RegExp(
    `value=["']${address.replaceAll(".", "\\.")}["'][^>]*data-recipient-field=["']bcc["']`,
  );
  if (!protectedPreset.test(page)) {
    throw new Error(`${address} ist in der Oberfläche nicht als BCC geschützt.`);
  }
  if (!source.includes(`'${address}'`)) {
    throw new Error(`${address} wird serverseitig nicht geschützt.`);
  }
}
if (
  !/value=["']ws@sv-schuett\.eu["'][^>]*data-recipient-field=["']cc["']/.test(
    page,
  )
) {
  throw new Error("Susanne fehlt als CC-Auswahl.");
}
if (!/>\s*Susanne\s*<small>\(CC\)<\/small>/.test(page)) {
  throw new Error("ws@sv-schuett.eu ist nicht eindeutig als Susanne beschriftet.");
}
if (
  !page.includes('id="vf-mail-bcc"') ||
  !/fd\.append\(["']bcc["']/.test(page)
) {
  throw new Error("BCC wird nicht sichtbar erfasst oder nicht übertragen.");
}
if (!source.includes("if ($bcc) $message['bccRecipients'] = $bcc;")) {
  throw new Error("BCC wird nicht an Microsoft Graph übergeben.");
}

console.log(
  "Outlook-Fallempfänger: BCC-Schutz und Susanne-CC sind eingerichtet.",
);
