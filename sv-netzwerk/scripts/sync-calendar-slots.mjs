import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outputFile = path.join(root, 'src', 'data', 'calendar-slots.json');

const requireEnv = (name) => {
  const value = process.env[name];
  if (!value) throw new Error(`Fehlende Umgebungsvariable: ${name}`);
  return value;
};

const parseNonNegativeInt = (value, name) => {
  const parsed = Number.parseInt(value, 10);
  if (Number.isNaN(parsed) || parsed < 0) throw new Error(`${name} muss >= 0 sein.`);
  return parsed;
};

const tenantId = requireEnv('M365_TENANT_ID');
const clientId = requireEnv('M365_CLIENT_ID');
const clientSecret = requireEnv('M365_CLIENT_SECRET');
const calendarUserId = requireEnv('M365_CALENDAR_USER_ID');

const timeZone = process.env.CALENDAR_TIMEZONE || 'Europe/Berlin';
const horizonDays = parseNonNegativeInt(process.env.CALENDAR_HORIZON_DAYS || '21', 'CALENDAR_HORIZON_DAYS');
const backfillDays = parseNonNegativeInt(process.env.CALENDAR_BACKFILL_DAYS || '0', 'CALENDAR_BACKFILL_DAYS');
const includeSubject = process.env.CALENDAR_INCLUDE_SUBJECTS === 'true';

const formatParts = (date, tz) => {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: tz,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });
  const parts = formatter.formatToParts(date).reduce((acc, part) => {
    if (part.type !== 'literal') acc[part.type] = part.value;
    return acc;
  }, {});
  return {
    date: `${parts.year}-${parts.month}-${parts.day}`,
    dateTime: `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`,
  };
};

const toIsoUtc = (graphDateTime) => {
  if (!graphDateTime || !graphDateTime.dateTime) return null;
  const raw = graphDateTime.dateTime;
  if (/[zZ]$|[+-]\d{2}:\d{2}$/.test(raw)) return new Date(raw).toISOString();
  if (graphDateTime.timeZone === 'UTC') return new Date(`${raw}Z`).toISOString();
  return new Date(raw).toISOString();
};

const tokenRequestBody = new URLSearchParams({
  client_id: clientId,
  client_secret: clientSecret,
  scope: 'https://graph.microsoft.com/.default',
  grant_type: 'client_credentials',
});

const tokenResponse = await fetch(`https://login.microsoftonline.com/${encodeURIComponent(tenantId)}/oauth2/v2.0/token`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: tokenRequestBody.toString(),
});

if (!tokenResponse.ok) {
  const errorBody = await tokenResponse.text();
  throw new Error(`Token-Abruf fehlgeschlagen (${tokenResponse.status}): ${errorBody}`);
}

const tokenPayload = await tokenResponse.json();
const accessToken = tokenPayload.access_token;
if (!accessToken) throw new Error('Token-Antwort enthält kein access_token.');

const now = new Date();
const rangeStart = new Date(now.getTime() - (backfillDays * 24 * 60 * 60 * 1000));
const rangeEnd = new Date(now.getTime() + (horizonDays * 24 * 60 * 60 * 1000));

const initialUrl = new URL(`https://graph.microsoft.com/v1.0/users/${encodeURIComponent(calendarUserId)}/calendarView`);
initialUrl.searchParams.set('startDateTime', rangeStart.toISOString());
initialUrl.searchParams.set('endDateTime', rangeEnd.toISOString());
initialUrl.searchParams.set('$orderby', 'start/dateTime');
initialUrl.searchParams.set('$select', 'id,subject,start,end,isAllDay,showAs,location,webLink,organizer,sensitivity');
initialUrl.searchParams.set('$top', '200');

const allEvents = [];
let nextUrl = initialUrl.toString();

while (nextUrl) {
  const eventsResponse = await fetch(nextUrl, {
    headers: {
      Authorization: `Bearer ${accessToken}`,
      Prefer: 'outlook.timezone="UTC"',
    },
  });
  if (!eventsResponse.ok) {
    const errorBody = await eventsResponse.text();
    throw new Error(`Kalender-Abruf fehlgeschlagen (${eventsResponse.status}): ${errorBody}`);
  }
  const eventsPayload = await eventsResponse.json();
  allEvents.push(...(eventsPayload.value || []));
  nextUrl = eventsPayload['@odata.nextLink'] || '';
}

const slotMap = new Map();
let busyCount = 0;

for (const event of allEvents) {
  const startIso = toIsoUtc(event.start);
  const endIso = toIsoUtc(event.end);
  if (!startIso || !endIso) continue;

  const startDate = new Date(startIso);
  const endDate = new Date(endIso);
  const startLocal = formatParts(startDate, timeZone);
  const endLocal = formatParts(endDate, timeZone);
  const showAs = (event.showAs || '').toLowerCase();
  const isBusy = showAs !== 'free';
  if (isBusy) busyCount += 1;

  if (!slotMap.has(startLocal.date)) slotMap.set(startLocal.date, []);
  if (isBusy) {
    const slotItem = {
      id: event.id,
      startIso,
      endIso,
      startLocal: startLocal.dateTime,
      endLocal: endLocal.dateTime,
      isAllDay: Boolean(event.isAllDay),
      showAs: event.showAs || 'unknown',
    };
    if (includeSubject) slotItem.subject = event.subject || '(ohne Betreff)';
    slotMap.get(startLocal.date).push(slotItem);
  }
}

const slots = Array.from(slotMap.entries())
  .map(([date, busy]) => ({
    date,
    busy: busy.sort((a, b) => a.startIso.localeCompare(b.startIso)),
  }))
  .sort((a, b) => a.date.localeCompare(b.date));

const payload = {
  generatedAt: now.toISOString(),
  timezone: timeZone,
  source: 'microsoft-graph',
  calendarUserId,
  range: {
    startIso: rangeStart.toISOString(),
    endIso: rangeEnd.toISOString(),
    horizonDays,
    backfillDays,
  },
  summary: {
    totalEvents: allEvents.length,
    busyEvents: busyCount,
    freeEvents: Math.max(allEvents.length - busyCount, 0),
  },
  slots,
};

await mkdir(path.dirname(outputFile), { recursive: true });
await writeFile(outputFile, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');

console.log(`Kalenderslots geschrieben: ${outputFile}`);
console.log(`Events: total=${allEvents.length}, busy=${busyCount}, days=${slots.length}`);
