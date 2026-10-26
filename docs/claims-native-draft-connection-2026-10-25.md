# Native Claimsforce draft connection – 25 October 2026

## Evidence and current boundary

The native ZB was opened through Claimsforce's existing investigation list in
an authenticated CW browser session. One spelling correction to a supplier
name was entered using the native input, saved with `Entwurf speichern`, then
verified after leaving and reopening the original form. Monetary fields were
unchanged. This proves the manual native UI path, not the new extension path.

Apryse places field identity on `.text[id]`/`.choice[id]` wrappers, not on the
input's `name`. The readiness probe now recognizes those wrappers. Dynamically
numbered viewer frames remain bound to the exact case/form basePath.

## Extension 1.4.70 implementation

`claimsforce_native_form` queues inspect/save requests in the existing station
queue. Requests retain own-case ownership, profile and original Claims UUID.
Only workers >=1.4.70 may claim them. The source/tasks path and native path exit
before the older session-reset/import flow. Native work uses its own tab.

Inventory comes from actual native form links and card status. Save accepts
only an existing clean draft, an exact snapshot hash and explicit before/after
values. Identity fields and known calculated totals are protected. Duplicate
widgets are not writable. The entire patch is checked before first entry.
Success requires native save acknowledgement, leaving the form, reopening it,
and comparing requested plus unchanged fields. An operation journal prevents
blind retries after interruption; uncertain own tabs remain open. Concurrent
station work cannot replace a running native operation. No sending, attachment,
QS, new-form creation or modification of sent originals is implemented here.

## Independent document-read correction

DOCX sources now produce bounded paragraph/table text from original ZIP/XML,
including headers/footers and notes. Source SHA-256 and visual-review limitation
are returned. Invalid XML, external entities and oversized content are rejected.
No document is modified. Embedded pictures still need separate visual review.

## Tests before production

15 operation tests, 7 synthetic DOM tests, 6 worker tests, 27 existing DOM bridge
tests and 8 native navigation tests passed. Handshake, source isolation, source
reader, single-case scope and resume regressions passed. DOCX test passed using
the installed PHP ZIP extension enabled for the test process only. PHP syntax
checks passed. No live extension write result is asserted by these tests.

## Still required

Deploy/readback, extension reload, actual MCP inventory/inspect/write/reopen
pilot, fresh Langdock tool discovery and reference-case review remain separate
acceptance steps. Sent SE originals need the appropriate native revision workflow;
this adapter must reject them rather than silently overwrite them. Native report
authoring, new-form creation and upload/attachment work are not completed here.
The observed Langdock result also incorrectly used gross for the 70% net basis;
that result is not acceptable and must not be used as a financial patch.

## Live continuation, 26 October 2026

The existing integration and agent now both expose seven selected actions.
The agent's old association retained six until the same integration was
removed from its selection and selected again; no second integration was made.
The installed portal and worker both report 1.4.70.

An SW session correctly failed before reading the CW case (job 114816).
After the user's CW login, job 115269 returned three native forms (23 seconds
shown by Langdock). Job 115322 returned the native payment recommendation with
67 fields, clean draft status and a snapshot hash. Neither operation wrote.
The separate save/reopen/readback acceptance remains open.

The first inventory saw links before status badges and returned unknown;
the next inventory returned draft/sent. The local worker now waits for two
matching inventories with known statuses, bounded to 15 seconds. A save is
rejected if that readiness never occurs. Two additional synthetic tests cover
the observed delayed badge and an unresolved badge. This local fix has not
yet been shipped to the user's extension.

Persistent agent instructions now explicitly distinguish native ZB/ZBW
(Zahlungsbefuerwortung) from Zwischenbericht. Existing credentials are located
in the user's Google drive; their use must stay within authorized account
login and must not expose them in prompts, case files or logs. Automatic account
switching is not yet proven.

The sent SE's menu provides download, attach and delete. The viewer's top
Bearbeiten button enters title editing; it is not evidence of a revision API.
No new revision or field edit was made during that investigation.

## 26.10.2026 – 1.4.71 live und Wiederaufnahme nach Reload
- Browser-DOM bestätigt bridgeVersion und workerVersion 1.4.71.
- Job 121184 / e0dda973-87c6-4939-8a49-984dee9fedfc scheiterte nach Erweiterungsreload an CF-READ-SESSION.
- Der bestehende Claims-Tab war weiterhin als CW angemeldet. Nach eigenem Seitenreload: Job 121185 / c9c25bba-fe2f-4779-8288-01e1229f4689 erfolgreich in ca. 15 s (Langdock-Messung 10:30:57–10:31:12 UTC). Inventar: ZB draft, Vorblatt sent, SE sent. Abschlussmeldung korrekt: Native Formularliste gelesen, 3 Formulare, keine Änderung.
- Ursache: Leerer extension-session-Speicher wurde vor einer normalen Claims-Seitenanfrage als fehlende Anmeldung behandelt.
- 1.4.72 ergänzt einen begrenzten Wiederaufnahmeversuch über einen ausschließlich eigenen Lesetab. Keine fremden Tabs neu laden, kein Logout, kein Kontowechsel, keine Zugangsdaten nötig. Vorhandene fremde Kontozuordnung blockiert weiterhin.
- Sechs isolierte Wiederaufnahmetests und zehn bestehende Sitzungsprüfungen bestanden. Native Save/Reopen bleibt noch ohne produktiven Erfolgsnachweis; keine finanziellen Werte geändert.
