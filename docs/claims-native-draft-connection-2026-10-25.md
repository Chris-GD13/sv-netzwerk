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
