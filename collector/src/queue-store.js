import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { DatabaseSync } from "node:sqlite";

export class QueueStore {
  constructor(dataDirectory, config) {
    this.dataDirectory = dataDirectory;
    this.imageDirectory = path.join(dataDirectory, "queue-images");
    this.config = config;
    fs.mkdirSync(this.imageDirectory, { recursive: true });
    this.database = new DatabaseSync(path.join(dataDirectory, "queue.sqlite"));
    this.database.exec("PRAGMA journal_mode = WAL; PRAGMA synchronous = FULL; PRAGMA busy_timeout = 5000;");
    this.migrate();
    this.database.prepare("UPDATE queue_jobs SET status = 'RETRY', next_attempt_at = 0 WHERE status = 'SENDING'").run();
  }

  migrate() {
    this.database.exec(`
      CREATE TABLE IF NOT EXISTS queue_jobs (
        id TEXT PRIMARY KEY,
        group_id TEXT NOT NULL,
        message_id TEXT NOT NULL,
        attachment_index INTEGER NOT NULL,
        sender_id TEXT NOT NULL,
        sender_name TEXT NOT NULL,
        message_sent_at TEXT NOT NULL,
        source_url TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'QUEUED',
        local_path TEXT,
        sha256 TEXT,
        content_type TEXT,
        filename TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        completed_at INTEGER,
        UNIQUE(message_id, attachment_index)
      );
      CREATE INDEX IF NOT EXISTS queue_jobs_ready_idx ON queue_jobs(status, next_attempt_at, created_at);
    `);
  }

  enqueue(metadata, imageUrl, attachmentIndex) {
    const now = Date.now();
    const id = `${metadata.messageId}:${attachmentIndex}`;
    const result = this.database.prepare(`
      INSERT OR IGNORE INTO queue_jobs (
        id, group_id, message_id, attachment_index, sender_id, sender_name,
        message_sent_at, source_url, status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'QUEUED', ?, ?)
    `).run(id, metadata.groupId, metadata.messageId, attachmentIndex, metadata.senderId,
      metadata.senderName, metadata.sentAt, imageUrl, now, now);
    return { id, inserted: result.changes === 1 };
  }

  claimNext(now = Date.now()) {
    const job = this.database.prepare(`
      SELECT * FROM queue_jobs
      WHERE status IN ('QUEUED', 'DOWNLOADED', 'RETRY') AND next_attempt_at <= ?
      ORDER BY CASE WHEN local_path IS NULL THEN 0 ELSE 1 END, created_at ASC LIMIT 1
    `).get(now);
    if (!job) return null;
    this.database.prepare("UPDATE queue_jobs SET status = 'SENDING', updated_at = ? WHERE id = ?").run(now, job.id);
    return { ...job, status: "SENDING" };
  }

  get(id) {
    return this.database.prepare("SELECT * FROM queue_jobs WHERE id = ?").get(id) ?? null;
  }

  saveDownloaded(job, image) {
    const sha256 = createHash("sha256").update(image.bytes).digest("hex");
    const extension = safeExtension(image.filename);
    const finalPath = path.join(this.imageDirectory, `${sha256}.${extension}`);
    const temporaryPath = `${finalPath}.${process.pid}.tmp`;
    fs.writeFileSync(temporaryPath, image.bytes, { flag: "wx" });
    if (fs.existsSync(finalPath)) fs.unlinkSync(temporaryPath);
    else fs.renameSync(temporaryPath, finalPath);

    this.database.prepare(`
      UPDATE queue_jobs SET status = 'DOWNLOADED', local_path = ?, sha256 = ?,
        content_type = ?, filename = ?, updated_at = ?, last_error = NULL WHERE id = ?
    `).run(finalPath, sha256, image.contentType, image.filename, Date.now(), job.id);
    return this.get(job.id);
  }

  markCompleted(id) {
    const now = Date.now();
    this.database.prepare(`
      UPDATE queue_jobs SET status = 'SENT', completed_at = ?, updated_at = ?, last_error = NULL WHERE id = ?
    `).run(now, now, id);
  }

  markFailure(job, error) {
    const attempts = job.attempts + 1;
    const failed = error.permanent === true || attempts >= this.config.queueMaxAttempts;
    const delay = Math.min(this.config.retryBaseDelayMs * 2 ** Math.min(attempts - 1, 20), this.config.retryMaxDelayMs);
    this.database.prepare(`
      UPDATE queue_jobs SET status = ?, attempts = ?, next_attempt_at = ?, last_error = ?, updated_at = ? WHERE id = ?
    `).run(failed ? "FAILED" : "RETRY", attempts, failed ? 0 : Date.now() + delay,
      String(error?.message ?? error).slice(0, 2000), Date.now(), job.id);
    return failed ? "FAILED" : "RETRY";
  }

  stats() {
    const rows = this.database.prepare("SELECT status, COUNT(*) AS count FROM queue_jobs GROUP BY status").all();
    return Object.fromEntries(rows.map((row) => [row.status, Number(row.count)]));
  }

  pruneCompleted(now = Date.now()) {
    const cutoff = now - this.config.sentRetentionDays * 24 * 60 * 60 * 1000;
    const jobs = this.database.prepare("SELECT id, local_path FROM queue_jobs WHERE status = 'SENT' AND completed_at < ?").all(cutoff);
    const paths = new Set(jobs.map((job) => job.local_path).filter(Boolean));
    const remove = this.database.prepare("DELETE FROM queue_jobs WHERE id = ?");
    this.database.exec("BEGIN IMMEDIATE");
    try {
      jobs.forEach((job) => remove.run(job.id));
      this.database.exec("COMMIT");
    } catch (error) {
      this.database.exec("ROLLBACK");
      throw error;
    }
    for (const filePath of paths) {
      const references = this.database.prepare("SELECT COUNT(*) AS count FROM queue_jobs WHERE local_path = ?").get(filePath);
      if (Number(references.count) === 0 && fs.existsSync(filePath)) fs.unlinkSync(filePath);
    }
    return jobs.length;
  }

  close() {
    this.database.close();
  }
}

function safeExtension(filename) {
  const extension = path.extname(filename).slice(1).toLowerCase();
  return /^[a-z0-9]{1,8}$/.test(extension) ? extension : "jpg";
}
