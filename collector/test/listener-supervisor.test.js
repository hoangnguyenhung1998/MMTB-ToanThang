import assert from "node:assert/strict";
import { EventEmitter } from "node:events";
import test from "node:test";
import { ListenerSupervisor, sanitizeDiagnostic } from "../src/listener-supervisor.js";

class FakeListener extends EventEmitter {
  constructor() {
    super();
    this.startCalls = 0;
    this.stopCalls = 0;
    this.probeCalls = 0;
  }

  start() { this.startCalls += 1; }
  stop() {
    this.stopCalls += 1;
    this.emit("disconnected", 1000, "manual recovery");
  }
  requestOldMessages() { this.probeCalls += 1; }
}

class FakeScheduler {
  constructor() {
    this.tasks = [];
    this.nextId = 1;
  }

  setTimeout(callback, delay) {
    const task = { id: this.nextId++, callback, delay, cancelled: false };
    this.tasks.push(task);
    return task.id;
  }

  clearTimeout(id) {
    const task = this.tasks.find((item) => item.id === id);
    if (task) task.cancelled = true;
  }

  pending(delay = null) {
    return this.tasks.filter((task) => !task.cancelled && (delay === null || task.delay === delay));
  }

  runNext(delay = null) {
    const task = this.pending(delay)[0];
    assert.ok(task, `No pending timer${delay === null ? "" : ` with delay ${delay}`}`);
    task.cancelled = true;
    task.callback();
  }
}

function setup(overrides = {}) {
  const listener = new FakeListener();
  const scheduler = new FakeScheduler();
  const events = [];
  const health = new Proxy({}, {
    get: (_target, name) => (...args) => events.push([String(name), ...args]),
  });
  const supervisor = new ListenerSupervisor(listener, {
    health,
    logger: { log() {}, warn() {}, error() {} },
    reconnectBaseDelayMs: 10,
    reconnectMaxDelayMs: 20,
    reconnectMaxAttempts: 2,
    reconnectCooldownMs: 1_000,
    probeIntervalMs: 100,
    probeTimeoutMs: 25,
    setTimeoutFn: scheduler.setTimeout.bind(scheduler),
    clearTimeoutFn: scheduler.clearTimeout.bind(scheduler),
    ...overrides,
  });
  return { listener, scheduler, events, supervisor };
}

test("disconnect is observable and reconnects without duplicate starts", () => {
  const { listener, scheduler, events, supervisor } = setup();
  supervisor.start();
  listener.emit("connected");
  listener.emit("disconnected", 1006, "abnormal closure");
  listener.emit("closed", 1006, "abnormal closure");

  assert.equal(listener.startCalls, 1);
  assert.equal(scheduler.pending(10).length, 1);
  assert.ok(events.some(([name]) => name === "listenerDisconnected"));

  scheduler.runNext(10);
  assert.equal(listener.startCalls, 2);
  listener.emit("connected");
  listener.emit("message", {});
  assert.equal(events.filter(([name]) => name === "eventReceived").length, 1);
  assert.equal(scheduler.pending(10).length, 0);
  supervisor.stop();
});

test("reconnect attempts are bounded per cycle and enter cooldown", () => {
  const { listener, scheduler, events, supervisor } = setup();
  supervisor.start();
  listener.emit("connected");

  listener.emit("disconnected", 1006, "lost");
  scheduler.runNext(10);
  listener.emit("disconnected", 1006, "lost again");
  scheduler.runNext(20);
  listener.emit("disconnected", 1006, "still lost");

  assert.equal(listener.startCalls, 3);
  assert.equal(scheduler.pending(1_000).length, 1);
  assert.ok(events.some(([name, details]) => name === "listenerRecoveryScheduled" && details.cooldown === true));

  scheduler.runNext(1_000);
  assert.equal(listener.startCalls, 4);
  supervisor.stop();
});

test("a reconnect is verified by a functional probe before retry budget resets", () => {
  const { listener, scheduler, events, supervisor } = setup();
  supervisor.start();
  listener.emit("connected");
  listener.emit("disconnected", 1006, "lost");
  scheduler.runNext(10);
  listener.emit("connected");
  listener.emit("disconnected", 1006, "unstable reconnect");
  assert.equal(scheduler.pending(20).length, 1);

  scheduler.runNext(20);
  listener.emit("connected");
  scheduler.runNext(100);
  listener.emit("old_messages", [], 1);
  listener.emit("disconnected", 1006, "later disconnect");

  assert.equal(scheduler.pending(10).length, 1);
  assert.ok(events.some(([name, attempt]) => name === "listenerConnected" && attempt === 2));
  supervisor.stop();
});

test("functional probe timeout forces a bounded listener recovery", () => {
  const { listener, scheduler, events, supervisor } = setup();
  supervisor.start();
  listener.emit("connected");

  scheduler.runNext(100);
  assert.equal(listener.probeCalls, 1);
  scheduler.runNext(25);

  assert.equal(listener.stopCalls, 1);
  assert.equal(scheduler.pending(10).length, 1);
  assert.ok(events.some(([name]) => name === "listenerProbeFailed"));
  supervisor.stop();
});

test("functional probe response verifies listener without requiring a new image", () => {
  const { listener, scheduler, events, supervisor } = setup();
  supervisor.start();
  listener.emit("connected");
  scheduler.runNext(100);
  listener.emit("old_messages", [], 1);

  assert.ok(events.some(([name]) => name === "listenerProbeSucceeded"));
  assert.equal(scheduler.pending(25).length, 0);
  assert.equal(scheduler.pending(100).length, 1);
  supervisor.stop();
});

test("listener diagnostics redact URLs and credential-like values", () => {
  const message = sanitizeDiagnostic("failed wss://secret.example/socket?token=abc cookie=session-secret imei=device-secret");
  assert.equal(message.includes("secret.example"), false);
  assert.equal(message.includes("session-secret"), false);
  assert.equal(message.includes("device-secret"), false);
});

test("listener errors are observable without exposing sensitive diagnostics", () => {
  const { listener, events, supervisor } = setup();
  supervisor.start();
  listener.emit("error", new Error("socket failed wss://secret.example/path?token=abc"));
  const reported = events.find(([name]) => name === "listenerError");
  assert.ok(reported);
  assert.equal(reported[1].includes("secret.example"), false);
  supervisor.stop();
});
