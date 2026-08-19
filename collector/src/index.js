import "dotenv/config";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";
import { ThreadType, Zalo } from "zca-js";
import { loadConfig } from "./config.js";
import { readCredentials, writeCredentials } from "./credentials.js";
import { LaravelCollectorClient } from "./laravel-client.js";
import { extractImageUrls, normalizeMessage } from "./message-parser.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const credentialsPath = path.join(root, "data", "credentials.json");
const qrPath = path.join(root, "data", "qr.png");
const config = loadConfig();

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
let queue = Promise.resolve();

api.listener.on("message", (message) => {
  if (message.type !== ThreadType.Group || !config.allowedGroupIds.has(String(message.threadId))) return;
  const urls = extractImageUrls(message);
  if (urls.length === 0) return;

  queue = queue.then(async () => {
    const metadata = normalizeMessage(message);
    if (!metadata.messageId) throw new Error("Zalo message has no stable message ID");
    for (const [index, url] of urls.entries()) {
      const result = await client.forwardImage(metadata, url, index);
      console.log("Forwarded Zalo image", metadata.messageId, index, result?.data?.status ?? "OK");
    }
  }).catch((error) => console.error("Failed to process Zalo image:", error));
});

api.listener.on("error", (error) => console.error("Zalo listener error:", error));
api.listener.start();
console.log(`Collector started. Watching ${config.allowedGroupIds.size} allowed group(s).`);

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => {
    api.listener.stop();
    process.exit(0);
  });
}
