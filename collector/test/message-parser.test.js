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

test("extracts a forwarded image nested in serialized content", () => {
  const message = {
    type: 1,
    threadId: "group-1",
    data: {
      msgType: "chat.photo",
      content: JSON.stringify({ forwarded: { downloadUrl: "https://example.test/forwarded" } }),
    },
  };
  assert.deepEqual(extractImageUrls(message), ["https://example.test/forwarded"]);
});

test("extracts multiple album images and preserves stable order", () => {
  const message = {
    type: 1,
    threadId: "group-1",
    data: {
      msgType: "chat.album",
      content: {
        items: [
          { hdUrl: "https://example.test/one" },
          { url: "https://example.test/two" },
        ],
      },
    },
  };
  assert.deepEqual(extractImageUrls(message), ["https://example.test/one", "https://example.test/two"]);
});

test("extracts image attachments emitted by mobile and desktop payload variants", () => {
  const attachment = {
    type: 1,
    threadId: "group-1",
    data: {
      msgType: "chat.file",
      content: { fileName: "scan.heic", mimeType: "image/heic", href: "https://example.test/download?id=1" },
      attachments: [{ src: "https://example.test/scan-2.jpeg" }],
    },
  };
  assert.deepEqual(extractImageUrls(attachment), [
    "https://example.test/download?id=1",
    "https://example.test/scan-2.jpeg",
  ]);
});

test("does not mistake a non-image file or rich card thumbnail for an image", () => {
  const base = { type: 1, threadId: "group-1" };
  assert.deepEqual(extractImageUrls({ ...base, data: { msgType: "chat.file", content: { href: "https://example.test/report.pdf", mimeType: "application/pdf" } } }), []);
  assert.deepEqual(extractImageUrls({ ...base, data: { msgType: "chat.ecard", content: { href: "https://example.test/card.png" } } }), []);
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
