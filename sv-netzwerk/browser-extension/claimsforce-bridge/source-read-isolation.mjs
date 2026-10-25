// A legacy-file conflict must not hide independent task messages. Identity,
// access and transport errors still fail closed; no upload fallback is allowed.
export async function readSourceOrRecordLegacyConflict(read, onConflict) {
  try { return await read(); }
  catch (error) {
    if (!/^LEGACY_SOURCE_BINDING_REQUIRED(?:\s|:|$)/.test(String(error?.message || ''))) throw error;
    await onConflict(error);
    return null;
  }
}
