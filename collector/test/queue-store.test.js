import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { QueueStore } from "../src/queue-store.js";
import { QueueWorker } from "../src/queue-worker.js";

const config = {
  queueMaxAttempts: 5,
  retryBaseDelayMs: 1,
  retryMaxDelayMs: 10,
  sentRetentionDays: 7,
};

function metadata(id = "message-1") {
  return { groupId: "group-1", messageId: id, senderId: "sender-1", senderName: "Driver", sentAt: new Date().toISOString() };
}

function temporaryDirectory(t) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "mmtb-queue-"));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return directory;
}

test("queue survives process restart and keeps downloaded bytes", (t) => {
  const directory = temporaryDirectory(t);
  let queue = new QueueStore(directory, config);
  const { id, inserted } = queue.enqueue(metadata(), "https://example.test/a.jpg", 0);
  assert.equal(inserted, true);
  const job = queue.claimNext();
  queue.saveDownloaded(job, { bytes: Buffer.from("image-bytes"), contentType: "image/jpeg", filename: "zalo.jpg" });
  queue.close();

  queue = new QueueStore(directory, config);
  const restored = queue.claimNext();
  assert.equal(restored.id, id);
  assert.equal(fs.readFileSync(restored.local_path, "utf8"), "image-bytes");
  queue.close();
});

test("duplicate Zalo message attachment is queued only once", (t) => {
  const queue = new QueueStore(temporaryDirectory(t), config);
  assert.equal(queue.enqueue(metadata(), "https://example.test/a.jpg", 0).inserted, true);
  assert.equal(queue.enqueue(metadata(), "https://example.test/a.jpg", 0).inserted, false);
  assert.equal(queue.stats().QUEUED, 1);
  queue.close();
});

test("worker retries while Laravel is down and sends after recovery", async (t) => {
  const queue = new QueueStore(temporaryDirectory(t), config);
  queue.enqueue(metadata(), "https://example.test/a.jpg", 0);
  let online = false;
  const client = {
    downloadImage: async () => ({ bytes: Buffer.from("image"), contentType: "image/jpeg", filename: "zalo.jpg" }),
    forwardStoredImage: async () => {
      if (!online) throw new Error("Laravel is offline");
      return { data: { status: "STORED" } };
    },
  };
  const logger = { log() {}, error() {} };
  const worker = new QueueWorker(queue, client, logger);
  assert.equal(await worker.runOnce(), "RETRY");
  await new Promise((resolve) => setTimeout(resolve, 5));
  online = true;
  assert.equal(await worker.runOnce(), "SENT");
  assert.equal(queue.stats().SENT, 1);
  queue.close();
});

test("offline API never blocks newer Zalo images from being downloaded", async (t) => {
  const queue = new QueueStore(temporaryDirectory(t), config);
  queue.enqueue(metadata("message-1"), "https://example.test/a.jpg", 0);
  queue.enqueue(metadata("message-2"), "https://example.test/b.jpg", 0);
  const client = {
    downloadImage: async (url) => ({ bytes: Buffer.from(url), contentType: "image/jpeg", filename: "zalo.jpg" }),
    forwardStoredImage: async () => { throw new Error("Laravel is offline"); },
  };
  const worker = new QueueWorker(queue, client, { log() {}, error() {} });
  await worker.runOnce();
  await worker.runOnce();
  assert.ok(queue.get("message-1:0").local_path);
  assert.ok(queue.get("message-2:0").local_path);
  queue.close();
});
