import { ThreadType } from "zca-js";

export class ListenerSupervisor {
  constructor(listener, options = {}) {
    this.listener = listener;
    this.health = options.health ?? null;
    this.logger = options.logger ?? console;
    this.reconnectBaseDelayMs = options.reconnectBaseDelayMs ?? 1_000;
    this.reconnectMaxDelayMs = options.reconnectMaxDelayMs ?? 60_000;
    this.reconnectMaxAttempts = options.reconnectMaxAttempts ?? 5;
    this.reconnectCooldownMs = options.reconnectCooldownMs ?? 5 * 60_000;
    this.probeIntervalMs = options.probeIntervalMs ?? 2 * 60_000;
    this.probeTimeoutMs = options.probeTimeoutMs ?? 30_000;
    this.setTimeoutFn = options.setTimeoutFn ?? setTimeout;
    this.clearTimeoutFn = options.clearTimeoutFn ?? clearTimeout;
    this.running = false;
    this.connected = false;
    this.starting = false;
    this.reconnectAttempts = 0;
    this.reconnectTimer = null;
    this.probeTimer = null;
    this.probeTimeoutTimer = null;
    this.forceCloseTimer = null;
    this.forceCloseGraceMs = options.forceCloseGraceMs ?? 5_000;
    this.bindEvents();
  }

  bindEvents() {
    this.listener.on("connected", () => this.onConnected());
    this.listener.on("disconnected", (code, reason) => this.onDisconnected(code, reason));
    this.listener.on("closed", (code, reason) => this.onDisconnected(code, reason));
    this.listener.on("error", (error) => this.onError(error));
    this.listener.on("message", () => this.health?.eventReceived("message"));
    this.listener.on("old_messages", () => {
      this.health?.eventReceived("old_messages");
      this.onProbeSucceeded();
    });
  }

  start() {
    if (this.running) return;
    this.running = true;
    this.startListener();
  }

  startListener() {
    if (!this.running || this.starting || this.connected) return;
    this.cancelReconnect();
    this.starting = true;
    this.health?.listenerConnecting(this.reconnectAttempts);
    try {
      this.listener.start();
    } catch (error) {
      this.starting = false;
      this.onError(error);
      this.scheduleReconnect();
    }
  }

  onConnected() {
    if (!this.running) return;
    this.connected = true;
    this.starting = false;
    this.cancelReconnect();
    const recoveryAttempt = this.reconnectAttempts;
    this.health?.listenerConnected(recoveryAttempt);
    this.logger.log(recoveryAttempt > 0 ? "Zalo listener reconnected; verifying." : "Zalo listener connected.");
    this.scheduleProbe(recoveryAttempt > 0 ? Math.min(5_000, this.probeIntervalMs) : this.probeIntervalMs);
  }

  onDisconnected(code, reason) {
    if (!this.running) return;
    if (!this.connected && !this.starting && this.reconnectTimer !== null) return;
    this.connected = false;
    this.starting = false;
    this.cancelProbe();
    this.cancelForceClose();
    const details = { code: safeCode(code), reason: sanitizeDiagnostic(reason) };
    this.health?.listenerDisconnected(details);
    this.logger.warn(`Zalo listener disconnected (code ${details.code}).`);
    this.scheduleReconnect();
  }

  onError(error) {
    const message = sanitizeDiagnostic(error?.message ?? error);
    this.health?.listenerError(message);
    this.logger.error(`Zalo listener error: ${message}`);
  }

  scheduleReconnect() {
    if (!this.running || this.connected || this.reconnectTimer !== null) return;

    let cooldown = false;
    let delay;
    if (this.reconnectAttempts >= this.reconnectMaxAttempts) {
      cooldown = true;
      delay = this.reconnectCooldownMs;
      this.reconnectAttempts = 0;
    } else {
      this.reconnectAttempts += 1;
      delay = Math.min(
        this.reconnectBaseDelayMs * 2 ** (this.reconnectAttempts - 1),
        this.reconnectMaxDelayMs,
      );
    }

    const details = { attempt: this.reconnectAttempts, delay_ms: delay, cooldown };
    this.health?.listenerRecoveryScheduled(details);
    this.logger.warn(cooldown
      ? `Zalo listener recovery cooling down for ${delay}ms.`
      : `Zalo listener reconnect ${this.reconnectAttempts}/${this.reconnectMaxAttempts} scheduled in ${delay}ms.`);
    this.reconnectTimer = this.setTimeoutFn(() => {
      this.reconnectTimer = null;
      this.startListener();
    }, delay);
  }

  scheduleProbe(delay = this.probeIntervalMs) {
    this.cancelProbe();
    if (!this.running || !this.connected) return;
    this.probeTimer = this.setTimeoutFn(() => {
      this.probeTimer = null;
      this.runProbe();
    }, delay);
  }

  runProbe() {
    if (!this.running || !this.connected || this.probeTimeoutTimer !== null) return;
    this.health?.listenerProbeStarted();
    try {
      this.listener.requestOldMessages(ThreadType.Group);
    } catch (error) {
      this.failProbe(error);
      return;
    }
    this.probeTimeoutTimer = this.setTimeoutFn(() => {
      this.probeTimeoutTimer = null;
      this.failProbe(new Error("listener functional probe timed out"));
    }, this.probeTimeoutMs);
  }

  onProbeSucceeded() {
    if (this.probeTimeoutTimer === null) return;
    this.clearTimeoutFn(this.probeTimeoutTimer);
    this.probeTimeoutTimer = null;
    this.reconnectAttempts = 0;
    this.health?.listenerProbeSucceeded();
    this.scheduleProbe();
  }

  failProbe(error) {
    if (!this.running) return;
    const message = sanitizeDiagnostic(error?.message ?? error);
    this.cancelProbe();
    this.connected = false;
    this.starting = false;
    this.health?.listenerProbeFailed(message);
    this.logger.error(`Zalo listener functional probe failed: ${message}`);
    try {
      this.listener.stop();
    } catch (stopError) {
      this.onError(stopError);
    }
    if (this.reconnectTimer === null) {
      this.forceCloseTimer = this.setTimeoutFn(() => {
        this.forceCloseTimer = null;
        this.scheduleReconnect();
      }, this.forceCloseGraceMs);
    }
  }

  cancelReconnect() {
    if (this.reconnectTimer !== null) this.clearTimeoutFn(this.reconnectTimer);
    this.reconnectTimer = null;
  }

  cancelProbe() {
    if (this.probeTimer !== null) this.clearTimeoutFn(this.probeTimer);
    if (this.probeTimeoutTimer !== null) this.clearTimeoutFn(this.probeTimeoutTimer);
    this.probeTimer = null;
    this.probeTimeoutTimer = null;
  }

  cancelForceClose() {
    if (this.forceCloseTimer !== null) this.clearTimeoutFn(this.forceCloseTimer);
    this.forceCloseTimer = null;
  }

  stop() {
    if (!this.running) return;
    this.running = false;
    this.cancelReconnect();
    this.cancelProbe();
    this.cancelForceClose();
    this.connected = false;
    this.starting = false;
    try {
      this.listener.stop();
    } catch (error) {
      this.onError(error);
    }
    this.health?.listenerStopped();
  }
}

function safeCode(value) {
  const code = Number(value);
  return Number.isInteger(code) && code >= 0 ? code : 0;
}

export function sanitizeDiagnostic(value) {
  return String(value ?? "unknown error")
    .replace(/\b(?:https?|wss?):\/\/\S+/gi, "[redacted-url]")
    .replace(/\b(authorization|cookie|token|imei)\s*[:=]\s*\S+/gi, "$1=[redacted]")
    .slice(0, 1_000);
}
