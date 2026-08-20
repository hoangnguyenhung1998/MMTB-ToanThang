const IMAGE_EXTENSIONS = /\.(?:avif|gif|jpe?g|png|webp)(?:\?|$)/i;
const PRIMARY_IMAGE_KEYS = new Set(["href", "url", "hdurl", "downloadurl", "src"]);
const FALLBACK_IMAGE_KEYS = new Set(["thumb", "thumbnail"]);

function parseJson(value) {
  if (typeof value !== "string") return value;
  const trimmed = value.trim();
  if (!trimmed.startsWith("{") && !trimmed.startsWith("[")) return value;
  try {
    return JSON.parse(trimmed);
  } catch {
    return value;
  }
}

function collectUrls(value, primaryUrls, fallbackUrls, key = "") {
  const parsed = parseJson(value);
  if (parsed !== value) return collectUrls(parsed, primaryUrls, fallbackUrls, key);

  if (typeof value === "string") {
    const normalizedKey = key.toLowerCase();
    if (/^https?:\/\//i.test(value) && FALLBACK_IMAGE_KEYS.has(normalizedKey)) {
      fallbackUrls.add(value);
    } else if (/^https?:\/\//i.test(value) && (PRIMARY_IMAGE_KEYS.has(normalizedKey) || IMAGE_EXTENSIONS.test(value))) {
      primaryUrls.add(value);
    }
    return;
  }

  if (Array.isArray(value)) {
    value.forEach((item) => collectUrls(item, primaryUrls, fallbackUrls, key));
    return;
  }

  if (value && typeof value === "object") {
    Object.entries(value).forEach(([childKey, child]) => collectUrls(child, primaryUrls, fallbackUrls, childKey));
  }
}

export function extractImageUrls(message) {
  if (!message || message.type !== 1 || typeof message.threadId !== "string") return [];
  const msgType = String(message.data?.msgType ?? "").toLowerCase();
  if (!msgType.includes("photo") && !msgType.includes("image")) return [];

  const primaryUrls = new Set();
  const fallbackUrls = new Set();
  collectUrls(message.data?.content, primaryUrls, fallbackUrls);
  return [...(primaryUrls.size > 0 ? primaryUrls : fallbackUrls)];
}

export function normalizeMessage(message) {
  const data = message.data ?? {};
  return {
    groupId: String(message.threadId),
    messageId: String(data.msgId || data.cliMsgId || data.actionId || ""),
    senderId: String(data.uidFrom || data.userId || ""),
    senderName: String(data.dName || "Unknown"),
    sentAt: normalizeTimestamp(data.ts),
  };
}

function normalizeTimestamp(timestamp) {
  const numeric = Number(timestamp);
  if (!Number.isFinite(numeric) || numeric <= 0) return new Date().toISOString();
  const milliseconds = numeric < 10_000_000_000 ? numeric * 1000 : numeric;
  return new Date(milliseconds).toISOString();
}
