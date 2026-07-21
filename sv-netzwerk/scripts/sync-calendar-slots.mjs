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
const preferredCalendarName = (process.env.CALENDAR_NAME || '').trim().toLowerCase();

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

const graphGetJson = async (url) => {
  const response = await fetch(url, {
    headers: {
      Authorization: `Bearer ${accessToken}`,
      Prefer: 'outlook.timezone="UTC"',
    },
  });
  if (!response.ok) {
    const errorBody = await response.text();
    throw new Error(`Graph-Abruf fehlgeschlagen (${response.status}): ${errorBody}`);
  }
  return response.json();
};

const fetchCalendarView = async (calendarPathSegment) => {
  const baseUrl = `https://graph.microsoft.com/v1.0/users/${encodeURIComponent(calendarUserId)}/${calendarPathSegment}/calendarView`;
  const initialUrl = new URL(baseUrl);
  initialUrl.searchParams.set('startDateTime', rangeStart.toISOString());
  initialUrl.searchParams.set('endDateTime', rangeEnd.toISOString());
  initialUrl.searchParams.set('$orderby', 'start/dateTime');
  initialUrl.searchParams.set('$select', 'id,subject,start,end,isAllDay,showAs,location,webLink,organizer,sensitivity');
  initialUrl.searchParams.set('$top', '200');

  const events = [];
  let nextUrl = initialUrl.toString();
  while (nextUrl) {
    const eventsPayload = await graphGetJson(nextUrl);
    events.push(...(eventsPayload.value || []));
    nextUrl = eventsPayload['@odata.nextLink'] || '';
  }
  return events;
};

const calendarsPayload = await graphGetJson(
  `https://graph.microsoft.com/v1.0/users/${encodeURIComponent(calendarUserId)}/calendars?$select=id,name,canEdit,isDefaultCalendar`,
);
const availableCalendars = (calendarsPayload.value || []);

let selectedCalendars = availableCalendars;
if (preferredCalendarName) {
  selectedCalendars = availableCalendars.filter((item) =>
    String(item.name || '').toLowerCase().includes(preferredCalendarName),
  );
  if (selectedCalendars.length === 0) {
    throw new Error(`Kein Kalender passend zu CALENDAR_NAME='${process.env.CALENDAR_NAME}' gefunden.`);
  }
}

const allEvents = [];
for (const calendar of selectedCalendars) {
  const calendarId = String(calendar.id || '').trim();
  if (!calendarId) continue;
  const scopedEvents = await fetchCalendarView(`calendars/${encodeURIComponent(calendarId)}`);
  allEvents.push(...scopedEvents.map((event) => ({
    ...event,
    _calendarId: calendarId,
    _calendarName: calendar.name || '',
  })));
}

if (allEvents.length === 0 && !preferredCalendarName) {
  const defaultEvents = await fetchCalendarView('calendar');
  allEvents.push(...defaultEvents.map((event) => ({
    ...event,
    _calendarId: 'default',
    _calendarName: 'default',
  })));
}

const seenEventIds = new Set();
const uniqueEvents = [];
for (const event of allEvents) {
  const id = String(event.id || '').trim();
  if (!id || seenEventIds.has(id)) continue;
  seenEventIds.add(id);
  uniqueEvents.push(event);
}

const slotMap = new Map();
let busyCount = 0;

for (const event of uniqueEvents) {
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
    totalEvents: uniqueEvents.length,
    busyEvents: busyCount,
    freeEvents: Math.max(uniqueEvents.length - busyCount, 0),
  },
  calendarStats: {
    selectedCalendarCount: selectedCalendars.length,
    defaultCalendarCount: selectedCalendars.filter((item) => Boolean(item.isDefaultCalendar)).length,
  },
  slots,
};

await mkdir(path.dirname(outputFile), { recursive: true });
await writeFile(outputFile, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');

console.log(`Kalenderslots geschrieben: ${outputFile}`);
console.log(`Events: total=${uniqueEvents.length}, busy=${busyCount}, days=${slots.length}, calendars=${selectedCalendars.length}`);
