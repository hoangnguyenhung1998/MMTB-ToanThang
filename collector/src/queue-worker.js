export class QueueWorker {
  constructor(queue, client, logger = console, health = null) {
    this.queue = queue;
    this.client = client;
    this.logger = logger;
    this.running = false;
    this.health = health;
  }

  async runOnce() {
    const claimed = this.queue.claimNext();
    if (!claimed) return null;
    this.health?.jobStarted(claimed.id);
    try {
      let job = claimed;
      if (!job.local_path) {
        const image = await this.client.downloadImage(job.source_url);
        job = this.queue.saveDownloaded(job, image);
      }
      const result = await this.client.forwardStoredImage(job);
      this.queue.markCompleted(job.id);
      this.health?.alive();
      this.health?.jobSucceeded();
      this.logger.log("Forwarded queued Zalo image", job.message_id, job.attachment_index,
        result?.data?.status ?? "OK");
      return "SENT";
    } catch (error) {
      const status = this.queue.markFailure(this.queue.get(claimed.id), error);
      this.logger.error(`Queue job ${claimed.id} moved to ${status}:`, error.message);
      return status;
    } finally {
      this.health?.jobFinished();
    }
  }

  start(pollMilliseconds) {
    if (this.running) return;
    this.running = true;
    const loop = async () => {
      if (!this.running) return;
      let result = null;
      try {
        result = await this.runOnce();
      } catch (error) {
        this.logger.error("Queue worker loop error:", error);
      }
      this.timer = setTimeout(loop, result === null ? pollMilliseconds : 100);
    };
    loop();
  }

  stop() {
    this.running = false;
    clearTimeout(this.timer);
  }
}
