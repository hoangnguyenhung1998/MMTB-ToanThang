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
import { HealthReporter } from "./health-reporter.js";
import { AccountStore } from "./account-store.js";
import { refreshGroupCatalog } from "./group-catalog.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const config = loadConfig();
const dataDirectory = path.join(root, "data");
const account = new AccountStore(dataDirectory).resolve(config.allowedGroupIds);
const allowedGroupIds = new Set(account.groupIds);
const releaseProcessLock = acquireProcessLock(path.join(dataDirectory, "collector.lock"));
const queue = new QueueStore(dataDirectory, config);
const health = new HealthReporter(path.join(dataDirectory, "health.json"));

if (allowedGroupIds.size === 0) {
  console.error(`No allowed Zalo groups are configured for account ${account.id}. Collector stops safely.`);
  process.exit(1);
}

const zalo = new Zalo({ logging: true });
const savedCredentials = readCredentials(account.credentialsPath);
let api;

try {
  api = savedCredentials ? await zalo.login(savedCredentials) : await zalo.loginQR({ qrPath: account.qrPath });
} catch (error) {
  if (!savedCredentials) throw error;
  console.warn(`Saved Zalo session for ${account.id} is invalid. Scan the new QR code:`, account.qrPath);
  api = await zalo.loginQR({ qrPath: account.qrPath });
}

writeCredentials(account.credentialsPath, api);
if (account.managed) {
  try {
    const catalog = await refreshGroupCatalog(api, new AccountStore(dataDirectory), account.id);
    console.log(`Refreshed ${catalog.length} safe Zalo group record(s) for ${account.id}.`);
  } catch (error) {
    console.warn(`Could not refresh Zalo group catalog for ${account.id}:`, error.message);
  }
}
const client = new LaravelCollectorClient(config);
const worker = new QueueWorker(queue, client, console, health);
worker.start(config.queuePollMs);
health.jobFinished();
health.alive();
const healthTimer = setInterval(() => health.alive(), 60 * 1000);

api.listener.on("message", (message) => {
  if (message.type !== ThreadType.Group || !allowedGroupIds.has(String(message.threadId))) return;
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
console.log(`Collector started with account ${account.id} (${account.name}). Watching ${allowedGroupIds.size} allowed group(s).`);
console.log("Durable queue status:", queue.stats());

const cleanupTimer = setInterval(() => {
  health.alive();
  const removed = queue.pruneCompleted();
  if (removed > 0) console.log(`Removed ${removed} sent queue job(s) after retention period.`);
}, 60 * 60 * 1000);

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => {
    clearInterval(cleanupTimer);
    clearInterval(healthTimer);
    worker.stop();
    api.listener.stop();
    queue.close();
    releaseProcessLock();
    process.exit(0);
  });
}
