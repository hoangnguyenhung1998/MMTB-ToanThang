import { ThreadType } from "zca-js";
import { extractImageUrls, normalizeMessage } from "./message-parser.js";

export function handleZaloMessage(message, allowedGroupIds, queue, health = null, logger = console) {
  if (message?.type !== ThreadType.Group) {
    health?.messageIgnored("NOT_GROUP", message?.data?.msgType);
    return { status: "IGNORED", reason: "NOT_GROUP" };
  }

  const groupId = String(message.threadId ?? "");
  if (!allowedGroupIds.has(groupId)) {
    health?.messageIgnored("GROUP_NOT_ALLOWED", message?.data?.msgType);
    return { status: "IGNORED", reason: "GROUP_NOT_ALLOWED" };
  }

  const urls = extractImageUrls(message);
  if (urls.length === 0) {
    health?.messageIgnored("NOT_IMAGE", message?.data?.msgType);
    return { status: "IGNORED", reason: "NOT_IMAGE" };
  }

  const metadata = normalizeMessage(message);
  if (!metadata.messageId) {
    health?.messageIgnored("MISSING_MESSAGE_ID", message?.data?.msgType);
    logger.error("Zalo image message has no stable message ID");
    return { status: "IGNORED", reason: "MISSING_MESSAGE_ID" };
  }

  let inserted = 0;
  urls.forEach((url, index) => {
    if (queue.enqueue(metadata, url, index).inserted) inserted += 1;
  });
  const stats = queue.stats();
  health?.imageQueued(inserted, stats);
  logger.log(`Queued ${inserted} image(s) from Zalo message ${metadata.messageId}`, stats);
  return { status: inserted > 0 ? "ENQUEUED" : "DUPLICATE", inserted, detected: urls.length };
}
