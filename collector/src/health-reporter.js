import fs from "node:fs";
import path from "node:path";

export class HealthReporter {
  constructor(filePath, now = () => new Date()) {
    this.path = filePath;
    this.now = now;
    this.state = {};
    try { this.state = JSON.parse(fs.readFileSync(filePath, "utf8")); } catch {}
  }

  timestamp() { return this.now().toISOString(); }
  started(accountId, allowedGroupCount, queue) {
    this.write({
      process_started_at: this.timestamp(), account_id: accountId, allowed_group_count: allowedGroupCount,
      listener_state: "INITIALIZING", listener_connected_at: null,
      listener_probe_success_at: null, reconnect_attempt: 0, recovery_cooldown: false, queue,
    });
  }
  eventLoopAlive(queue) { this.write({ event_loop_at: this.timestamp(), queue }); }
  listenerConnecting(attempt) { this.write({ listener_state: "CONNECTING", reconnect_attempt: attempt }); }
  listenerConnected(recoveryAttempt = 0) {
    this.write({
      listener_state: recoveryAttempt > 0 ? "VERIFYING" : "CONNECTED",
      listener_connected_at: this.timestamp(), listener_probe_success_at: null,
      reconnect_attempt: recoveryAttempt, reconnect_delay_ms: null, recovery_cooldown: false,
      consecutive_listener_errors: 0,
    });
  }
  listenerDisconnected(details) { this.write({ listener_state: "DISCONNECTED", listener_disconnected_at: this.timestamp(), listener_close_code: details.code }); }
  listenerError(message) {
    this.write({
      listener_error_at: this.timestamp(), listener_error: message,
      listener_errors: Number(this.state.listener_errors ?? 0) + 1,
      consecutive_listener_errors: Number(this.state.consecutive_listener_errors ?? 0) + 1,
    });
  }
  listenerRecoveryScheduled(details) {
    this.write({
      listener_state: details.cooldown ? "FAILED_COOLDOWN" : "RECOVERING",
      reconnect_attempt: details.attempt, reconnect_delay_ms: details.delay_ms,
      recovery_cooldown: details.cooldown, recovery_scheduled_at: this.timestamp(),
    });
  }
  listenerProbeStarted() { this.write({ listener_probe_started_at: this.timestamp() }); }
  listenerProbeSucceeded() {
    this.write({
      listener_state: "CONNECTED", listener_probe_success_at: this.timestamp(),
      listener_probe_error: null, reconnect_attempt: 0, consecutive_listener_errors: 0,
    });
  }
  listenerProbeFailed(message) { this.write({ listener_state: "PROBE_FAILED", listener_probe_failed_at: this.timestamp(), listener_probe_error: message }); }
  listenerStopped() { this.write({ listener_state: "STOPPED", listener_stopped_at: this.timestamp() }); }
  eventReceived(kind) { this.increment("events_received", { last_event_received_at: this.timestamp(), last_event_type: kind }); }
  messageIgnored(reason, messageType = "unknown") {
    const ignored = { ...(this.state.ignored_messages ?? {}) };
    ignored[reason] = Number(ignored[reason] ?? 0) + 1;
    const safeType = String(messageType ?? "unknown").toLowerCase().replace(/[^a-z0-9._-]/g, "_").slice(0, 80) || "unknown";
    const ignoredTypes = { ...(this.state.ignored_message_types ?? {}) };
    ignoredTypes[safeType] = Number(ignoredTypes[safeType] ?? 0) + 1;
    this.write({ ignored_messages: ignored, ignored_message_types: ignoredTypes, last_ignored_reason: reason, last_ignored_message_type: safeType });
  }
  imageQueued(count, queue) {
    this.write({
      images_queued: Number(this.state.images_queued ?? 0) + count,
      last_image_queued_at: count > 0 ? this.timestamp() : this.state.last_image_queued_at,
      queue,
    });
  }
  laravelForwardSucceeded() { this.write({ last_api_success_at: this.timestamp(), last_job_success_at: this.timestamp() }); }
  jobStarted(id) { this.write({ current_job: `job-${id}`, current_job_started_at: this.timestamp() }); }
  jobFinished() { this.write({ current_job: null, current_job_started_at: null }); }
  increment(name, values = {}) { this.write({ [name]: Number(this.state[name] ?? 0) + 1, ...values }); }

  write(values) {
    this.state = { ...this.state, ...values };
    const temporary = `${this.path}.tmp`;
    fs.mkdirSync(path.dirname(this.path), { recursive: true });
    fs.writeFileSync(temporary, JSON.stringify(this.state));
    fs.renameSync(temporary, this.path);
  }
}
