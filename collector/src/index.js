import "dotenv/config";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";
import { ThreadType, Zalo } from "zca-js";
import { loadConfig } from "./config.js";
import { readCredentials, writeCredentials } from "./credentials.js";
import { LaravelCollectorClient } from "./laravel-client.js";
import { extractImageUrls, normalizeMessage } from "./message-parser.js";
import { acquireProcessLock } from "./process-lock.js";
import { QueueStore } from "./queue-store.js";
import { QueueWorker } from "./queue-worker.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const credentialsPath = path.join(root, "data", "credentials.json");
const qrPath = path.join(root, "data", "qr.png");
const config = loadConfig();
const releaseProcessLock = acquireProcessLock(path.join(root, "data", "collector.lock"));
const queue = new QueueStore(path.join(root, "data"), config);

if (config.allowedGroupIds.size === 0) {
  console.error("ZALO_ALLOWED_GROUP_IDS is empty. Collector stops safely without collecting messages.");
  process.exit(1);
}

const zalo = new Zalo({ logging: true });
const savedCredentials = readCredentials(credentialsPath);
let api;

try {
  api = savedCredentials ? await zalo.login(savedCredentials) : await zalo.loginQR({ qrPath });
} catch (error) {
  if (!savedCredentials) throw error;
  console.warn("Saved Zalo session is invalid. Scan the new QR code:", qrPath);
  api = await zalo.loginQR({ qrPath });
}

writeCredentials(credentialsPath, api);
const client = new LaravelCollectorClient(config);
const worker = new QueueWorker(queue, client);
worker.start(config.queuePollMs);

api.listener.on("message", (message) => {
  if (message.type !== ThreadType.Group || !config.allowedGroupIds.has(String(message.threadId))) return;
  const urls = extractImageUrls(message);
  if (urls.length === 0) return;

  const metadata = normalizeMessage(message);
  if (!metadata.messageId) {
    console.error("Zalo message has no stable message ID");
    return;
  }
  let inserted = 0;
  urls.forEach((url, index) => {
    if (queue.enqueue(metadata, url, index).inserted) inserted += 1;
  });
  console.log(`Queued ${inserted} image(s) from Zalo message ${metadata.messageId}`, queue.stats());
});

api.listener.on("error", (error) => console.error("Zalo listener error:", error));
api.listener.start();
console.log(`Collector started. Watching ${config.allowedGroupIds.size} allowed group(s).`);
console.log("Durable queue status:", queue.stats());

const cleanupTimer = setInterval(() => {
  const removed = queue.pruneCompleted();
  if (removed > 0) console.log(`Removed ${removed} sent queue job(s) after retention period.`);
}, 60 * 60 * 1000);

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => {
    clearInterval(cleanupTimer);
    worker.stop();
    api.listener.stop();
    queue.close();
    releaseProcessLock();
    process.exit(0);
  });
}
