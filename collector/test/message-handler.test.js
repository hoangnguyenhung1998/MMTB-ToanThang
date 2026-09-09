import assert from "node:assert/strict";
import test from "node:test";
import { handleZaloMessage } from "../src/message-handler.js";
import { ZALO_CLIENT_OPTIONS } from "../src/zalo-options.js";

function message(overrides = {}) {
  return {
    type: 1,
    threadId: "allowed-group",
    isSelf: false,
    data: {
      msgId: "message-1",
      uidFrom: "member-1",
      dName: "Member",
      ts: "1787132920000",
      msgType: "chat.photo",
      content: { href: "https://example.test/image.jpg" },
    },
    ...overrides,
  };
}

function setup() {
  const jobs = [];
  const ignored = [];
  const health = {
    imageQueued(count) { jobs.push(count); },
    messageIgnored(reason) { ignored.push(reason); },
  };
  const queue = {
    enqueue(metadata, url, index) {
      jobs.push({ metadata, url, index });
      return { inserted: true };
    },
    stats() { return { QUEUED: 1 }; },
  };
  return { jobs, ignored, health, queue };
}

test("accepts images from the Collector account and another member identically", () => {
  assert.equal(ZALO_CLIENT_OPTIONS.selfListen, true);
  for (const incoming of [
    message({ isSelf: true, data: { ...message().data, uidFrom: "collector-account", msgId: "self-message" } }),
    message({ isSelf: false, data: { ...message().data, uidFrom: "member-2", msgId: "member-message" } }),
  ]) {
    const { queue, health, jobs } = setup();
    const result = handleZaloMessage(incoming, new Set(["allowed-group"]), queue, health, { log() {}, error() {} });
    assert.equal(result.status, "ENQUEUED");
    assert.equal(jobs.filter((item) => typeof item === "object").length, 1);
  }
});

test("rejects a group outside the account allowlist", () => {
  const { queue, health, ignored } = setup();
  const result = handleZaloMessage(message({ threadId: "other-group" }), new Set(["allowed-group"]), queue, health, { log() {}, error() {} });
  assert.equal(result.status, "IGNORED");
  assert.equal(result.reason, "GROUP_NOT_ALLOWED");
  assert.deepEqual(ignored, ["GROUP_NOT_ALLOWED"]);
});

test("duplicate message image remains idempotent", () => {
  const queue = {
    enqueue() { return { inserted: false }; },
    stats() { return { SENT: 1 }; },
  };
  const result = handleZaloMessage(message(), new Set(["allowed-group"]), queue, null, { log() {}, error() {} });
  assert.equal(result.status, "DUPLICATE");
  assert.equal(result.inserted, 0);
});
