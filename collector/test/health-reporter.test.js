import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { HealthReporter } from "../src/health-reporter.js";

test("event-loop heartbeat never masquerades as a Laravel API success", (t) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "mmtb-health-"));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const reporter = new HealthReporter(path.join(directory, "health.json"), () => new Date("2026-09-09T02:00:00Z"));

  reporter.eventLoopAlive({ QUEUED: 2, RETRY: 1 });
  let state = JSON.parse(fs.readFileSync(path.join(directory, "health.json"), "utf8"));
  assert.equal(state.last_api_success_at, undefined);
  assert.equal(state.event_loop_at, "2026-09-09T02:00:00.000Z");
  assert.deepEqual(state.queue, { QUEUED: 2, RETRY: 1 });

  reporter.laravelForwardSucceeded();
  state = JSON.parse(fs.readFileSync(path.join(directory, "health.json"), "utf8"));
  assert.equal(state.last_api_success_at, "2026-09-09T02:00:00.000Z");
});

