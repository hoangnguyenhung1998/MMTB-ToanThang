import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { acquireProcessLock } from "../src/process-lock.js";

test("process lock prevents two collectors and recovers after release", (t) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "mmtb-lock-"));
  const lockPath = path.join(directory, "collector.lock");
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const release = acquireProcessLock(lockPath);
  assert.throws(() => acquireProcessLock(lockPath), /already running/);
  release();
  const releaseAgain = acquireProcessLock(lockPath);
  releaseAgain();
});

test("process lock removes a stale owner", (t) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "mmtb-lock-"));
  const lockPath = path.join(directory, "collector.lock");
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  fs.writeFileSync(lockPath, "999999999", "utf8");
  const release = acquireProcessLock(lockPath);
  assert.equal(fs.existsSync(lockPath), true);
  release();
});
