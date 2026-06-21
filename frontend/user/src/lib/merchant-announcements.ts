export type MerchantAnnouncement = {
  id: number
  title: string
  summary: string
  content: string
  created_at: string
}

const STORAGE_PREFIX = 'user:announcement-read:'

function storageKey(merchantId: string | number | null | undefined) {
  return `${STORAGE_PREFIX}${String(merchantId || 'default')}`
}

function unique(values: string[]) {
  return Array.from(new Set(values.filter(Boolean)))
}

export function normalizeMerchantAnnouncement(payload: Record<string, any>): MerchantAnnouncement | null {
  const id = Number(payload.id || 0)
  const title = String(payload.title || '').trim()

  if (!id || title === '') {
    return null
  }

  const summary = String(payload.summary || '').trim()
  const content = String(payload.content || summary).trim()

  return {
    id,
    title,
    summary,
    content,
    created_at: String(payload.created_at || '').trim(),
  }
}

export function announcementFingerprint(item: MerchantAnnouncement) {
  return [item.id, item.title, item.summary, item.content, item.created_at].join('::')
}

export function readAnnouncementFingerprints(merchantId: string | number | null | undefined) {
  try {
    const raw = localStorage.getItem(storageKey(merchantId))
    const parsed = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? unique(parsed.map((item) => String(item))) : []
  } catch {
    return []
  }
}

export function isAnnouncementRead(
  item: MerchantAnnouncement,
  readFingerprints: Set<string>,
) {
  return readFingerprints.has(announcementFingerprint(item))
}

export function markAnnouncementRead(
  merchantId: string | number | null | undefined,
  item: MerchantAnnouncement,
) {
  const next = unique([
    ...readAnnouncementFingerprints(merchantId),
    announcementFingerprint(item),
  ])
  localStorage.setItem(storageKey(merchantId), JSON.stringify(next))
  return new Set(next)
}

export function markAnnouncementsRead(
  merchantId: string | number | null | undefined,
  items: MerchantAnnouncement[],
) {
  const next = unique([
    ...readAnnouncementFingerprints(merchantId),
    ...items.map((item) => announcementFingerprint(item)),
  ])
  localStorage.setItem(storageKey(merchantId), JSON.stringify(next))
  return new Set(next)
}
