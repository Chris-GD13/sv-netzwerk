#!/usr/bin/env node
/**
 * Backfill-Script: Fachbeiträge für 10.08–21.08.2026
 *
 * Triggert den Workflow `knowledge-standard.yml` via workflow_dispatch für
 * jeden Tag im angegebenen Zeitraum – je einmal für morning und afternoon –
 * wartet auf den Abschluss jedes Runs und gibt am Ende ein Summary aus.
 *
 * Voraussetzungen:
 *   GITHUB_TOKEN  – Personal Access Token mit `actions:write`-Berechtigung
 *
 * Aufruf (aus dem Repo-Stammverzeichnis):
 *   GITHUB_TOKEN=<token> node scripts/backfill-fachbeitrag-zwei-wochen.mjs
 *
 * Optionale Umgebungsvariablen:
 *   BACKFILL_START_DATE  – ISO-Datum des ersten Tages (Standard: 2026-08-10)
 *   BACKFILL_END_DATE    – ISO-Datum des letzten Tages (Standard: 2026-08-21)
 *   GITHUB_REPO          – owner/repo (Standard: Chris-GD13/sv-netzwerk)
 *   WORKFLOW_FILE        – Workflow-Dateiname (Standard: knowledge-standard.yml)
 *   POLL_INTERVAL_MS     – Polling-Intervall in ms (Standard: 30000)
 *   RUN_TIMEOUT_MS       – Max. Wartezeit pro Run in ms (Standard: 3600000 = 60 min)
 */

import { setTimeout as sleep } from 'node:timers/promises';

// ---------------------------------------------------------------------------
// Konfiguration
// ---------------------------------------------------------------------------

const token = process.env.GITHUB_TOKEN;
if (!token) {
  console.error('Fehler: Umgebungsvariable GITHUB_TOKEN ist nicht gesetzt.');
  process.exit(1);
}

const REPO       = process.env.GITHUB_REPO       ?? 'Chris-GD13/sv-netzwerk';
const WORKFLOW   = process.env.WORKFLOW_FILE      ?? 'knowledge-standard.yml';
const REF        = 'main';
const POLL_MS    = Number(process.env.POLL_INTERVAL_MS ?? 30_000);
const TIMEOUT_MS = Number(process.env.RUN_TIMEOUT_MS   ?? 60 * 60 * 1_000);

const START_DATE = process.env.BACKFILL_START_DATE ?? '2026-08-10';
const END_DATE   = process.env.BACKFILL_END_DATE   ?? '2026-08-21';

const API_BASE   = 'https://api.github.com';

// ---------------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------------

/** Gibt alle Tage von start bis end (inklusiv) als YYYY-MM-DD-Strings zurück. */
function dateRange(start, end) {
  const dates = [];
  const cur = new Date(`${start}T12:00:00Z`);
  const last = new Date(`${end}T12:00:00Z`);
  while (cur <= last) {
    dates.push(cur.toISOString().slice(0, 10));
    cur.setUTCDate(cur.getUTCDate() + 1);
  }
  return dates;
}

/** Basis-Fetch-Wrapper für die GitHub REST API. */
async function ghFetch(path, options = {}) {
  const url = path.startsWith('http') ? path : `${API_BASE}${path}`;
  const res = await fetch(url, {
    ...options,
    headers: {
      Accept: 'application/vnd.github+json',
      Authorization: `Bearer ${token}`,
      'X-GitHub-Api-Version': '2022-11-28',
      ...(options.headers ?? {}),
    },
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`GitHub API ${res.status} für ${url}: ${body}`);
  }
  const text = await res.text();
  return text ? JSON.parse(text) : null;
}

/**
 * Triggert einen workflow_dispatch-Event und gibt den Zeitstempel
 * unmittelbar vor dem Trigger zurück (wird zum Auffinden des neuen Runs benötigt).
 */
async function triggerWorkflow(date, slot) {
  const dispatchedAt = new Date().toISOString();
  await ghFetch(`/repos/${REPO}/actions/workflows/${WORKFLOW}/dispatches`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      ref: REF,
      inputs: { date, slot },
    }),
  });
  return dispatchedAt;
}

/**
 * Sucht den zuletzt gestarteten Workflow-Run, der nach `dispatchedAt` erstellt
 * wurde.  Polling bis ein passender Run gefunden wird oder Timeout erreicht ist.
 */
async function findNewRun(dispatchedAt, timeoutMs = 120_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    await sleep(5_000);
    const data = await ghFetch(
      `/repos/${REPO}/actions/workflows/${WORKFLOW}/runs?per_page=10&event=workflow_dispatch`
    );
    const run = data?.workflow_runs?.find(
      (r) => new Date(r.created_at) >= new Date(dispatchedAt)
    );
    if (run) return run;
  }
  throw new Error(`Kein neuer Workflow-Run nach Dispatch um ${dispatchedAt} gefunden.`);
}

/** Wartet, bis ein Workflow-Run den Status "completed" erreicht hat. */
async function awaitRunCompletion(runId) {
  const deadline = Date.now() + TIMEOUT_MS;
  while (Date.now() < deadline) {
    await sleep(POLL_MS);
    const run = await ghFetch(`/repos/${REPO}/actions/runs/${runId}`);
    if (run.status === 'completed') return run;
    process.stdout.write('.');
  }
  throw new Error(
    `Timeout: Run ${runId} hat nach ${Math.round(TIMEOUT_MS / 1000)}s nicht abgeschlossen.`
  );
}

/** Gibt am Ende eine übersichtliche Zusammenfassung aller Runs aus. */
function printSummary(results) {
  const total  = results.length;
  const ok     = results.filter((r) => r.conclusion === 'success').length;
  const failed = total - ok;

  console.log('\n');
  console.log('═'.repeat(72));
  console.log('  BACKFILL-SUMMARY – Fachbeiträge 10.08–21.08.2026');
  console.log('═'.repeat(72));
  console.log(`  Gesamt: ${total}  ✅ Erfolg: ${ok}  ❌ Fehler: ${failed}`);
  console.log('─'.repeat(72));

  for (const r of results) {
    const icon = r.conclusion === 'success' ? '✅' : '❌';
    const url  = r.url ?? '–';
    console.log(
      `  ${icon}  ${r.date}  [${r.slot.padEnd(9)}]  ${String(r.conclusion ?? 'unknown').padEnd(10)}  ${url}`
    );
  }

  console.log('═'.repeat(72));

  if (failed > 0) {
    console.error(`\n${failed} Run(s) fehlgeschlagen. Bitte manuell prüfen.`);
    process.exitCode = 1;
  } else {
    console.log('\nAlle Beiträge erfolgreich veröffentlicht. 🎉');
  }
}

// ---------------------------------------------------------------------------
// Haupt-Logik
// ---------------------------------------------------------------------------

const dates = dateRange(START_DATE, END_DATE);
const slots = ['morning', 'afternoon'];
const jobs  = dates.flatMap((date) => slots.map((slot) => ({ date, slot })));

console.log(
  `Backfill: ${jobs.length} Runs für ${dates.length} Tage (${START_DATE} – ${END_DATE})`
);
console.log(`Repo: ${REPO}  |  Workflow: ${WORKFLOW}  |  Ref: ${REF}`);
console.log('Starte sequenzielle Verarbeitung (wegen concurrency-group)…\n');

const results = [];

for (const { date, slot } of jobs) {
  console.log(`\n▶  ${date}  [${slot}]  – Dispatch…`);
  const outcome = { date, slot, conclusion: 'error', url: null };

  try {
    const dispatchedAt = await triggerWorkflow(date, slot);
    console.log(`   Dispatch OK (${dispatchedAt}), suche Run…`);

    const run = await findNewRun(dispatchedAt);
    outcome.url = run.html_url;
    console.log(`   Run gefunden: #${run.run_number}  ${run.html_url}`);
    console.log(`   Warte auf Abschluss (Polling alle ${POLL_MS / 1000}s)…`);

    const completed = await awaitRunCompletion(run.id);
    outcome.conclusion = completed.conclusion;

    const icon = completed.conclusion === 'success' ? '✅' : '❌';
    console.log(`\n   ${icon}  Abgeschlossen: ${completed.conclusion}`);
  } catch (err) {
    console.error(`\n   ❌  Fehler: ${err.message}`);
  }

  results.push(outcome);
}

printSummary(results);
