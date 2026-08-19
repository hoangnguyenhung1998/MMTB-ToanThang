import assert from "node:assert/strict";
import test from "node:test";
import { extractImageUrls, normalizeMessage } from "../src/message-parser.js";

test("extracts photo URLs only from group photo messages", () => {
  const message = {
    type: 1,
    threadId: "group-1",
    data: { msgType: "chat.photo", content: { href: "https://example.test/a.jpg", thumb: "https://example.test/t.jpg" } },
  };
  assert.deepEqual(extractImageUrls(message), ["https://example.test/a.jpg"]);
  assert.deepEqual(extractImageUrls({ ...message, type: 0 }), []);
});

test("falls back to thumbnail when no original image URL exists", () => {
  const message = {
    type: 1,
    threadId: "group-1",
    data: { msgType: "chat.photo", content: { thumb: "https://example.test/t" } },
  };
  assert.deepEqual(extractImageUrls(message), ["https://example.test/t"]);
});

test("extracts image URL from JSON params", () => {
  const message = {
    type: 1,
    threadId: "group-1",
    data: { msgType: "chat.photo", content: { params: JSON.stringify({ hdUrl: "https://example.test/photo" }) } },
  };
  assert.deepEqual(extractImageUrls(message), ["https://example.test/photo"]);
});

test("normalizes Zalo metadata", () => {
  const result = normalizeMessage({
    threadId: "group-1",
    data: { msgId: "message-1", uidFrom: "sender-1", dName: "Driver", ts: "1787132920000" },
  });
  assert.equal(result.groupId, "group-1");
  assert.equal(result.messageId, "message-1");
  assert.equal(result.senderName, "Driver");
  assert.match(result.sentAt, /^2026-/);
});
