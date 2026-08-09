type PublicationRecord = { status?: string; publishedAt?: Date | string };
type PublicationCarrier = { data: { publication: PublicationRecord } };
type PublicationCarrierAlt = { publication: PublicationRecord };

const BERLIN_TIME_ZONE = 'Europe/Berlin';
const PUBLICATION_HOUR = 5;

const publicationStartIso = (publishedAt: Date | string) => {
  const date = typeof publishedAt === 'string'
    ? publishedAt
    : `${publishedAt.getUTCFullYear()}-${String(publishedAt.getUTCMonth() + 1).padStart(2, '0')}-${String(publishedAt.getUTCDate()).padStart(2, '0')}`;
  return `${date}T${String(PUBLICATION_HOUR).padStart(2, '0')}:00:00+02:00`;
};

export const getPublicationTimeZone = () => BERLIN_TIME_ZONE;

const hasDataPublication = (item: PublicationCarrier | PublicationCarrierAlt | PublicationRecord): item is PublicationCarrier =>
  typeof item === 'object' && item !== null && 'data' in item;
const hasDirectPublication = (item: PublicationCarrier | PublicationCarrierAlt | PublicationRecord): item is PublicationCarrierAlt =>
  typeof item === 'object' && item !== null && 'publication' in item;

export const isKnowledgeItemPublished = (
  item: PublicationCarrier | PublicationCarrierAlt | PublicationRecord,
  now = new Date(),
) => {
  const publication = hasDataPublication(item)
    ? item.data.publication
    : hasDirectPublication(item)
      ? item.publication
      : item;
  if (!publication || publication.status !== 'published' || !publication.publishedAt) return false;
  return now.getTime() >= new Date(publicationStartIso(publication.publishedAt)).getTime();
};

export const filterPublishedKnowledgeItems = <T extends PublicationCarrier>(
  entries: T[],
  now = new Date(),
) => entries.filter((entry) => isKnowledgeItemPublished(entry, now));

export const isLibraryItemPublished = (
  item: { date: string; type: string },
  now = new Date(),
) => item.type === 'article'
  ? now.getTime() >= new Date(publicationStartIso(item.date)).getTime()
  : true;

export const filterPublishedLibraryItems = <T extends { date: string; type: string }>(
  entries: T[],
  now = new Date(),
) => entries.filter((entry) => isLibraryItemPublished(entry, now));
