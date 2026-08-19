import { createHash } from "node:crypto";

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

export class LaravelCollectorClient {
  constructor(config, fetchImpl = globalThis.fetch) {
    this.config = config;
    this.fetch = fetchImpl;
  }

  async forwardImage(metadata, imageUrl, attachmentIndex) {
    const image = await this.downloadImage(imageUrl);
    const sha256 = createHash("sha256").update(image.bytes).digest("hex");
    const form = new FormData();
    form.set("group_id", metadata.groupId);
    form.set("message_id", metadata.messageId);
    form.set("sender_id", metadata.senderId);
    form.set("sender_name", metadata.senderName);
    form.set("sent_at", metadata.sentAt);
    form.set("attachment_index", String(attachmentIndex));
    form.set("sha256", sha256);
    form.set("raw_payload", JSON.stringify({ source: "zca-js", image_url: imageUrl }));
    form.set("file", new Blob([image.bytes], { type: image.contentType }), image.filename);

    return this.withRetry(async () => {
      const response = await this.fetch(this.config.apiUrl, {
        method: "POST",
        headers: { Authorization: `Bearer ${this.config.apiToken}`, Accept: "application/json" },
        body: form,
        signal: AbortSignal.timeout(this.config.requestTimeoutMs),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        const error = new Error(`Laravel API returned ${response.status}: ${JSON.stringify(body)}`);
        error.retryable = response.status === 429 || response.status >= 500;
        throw error;
      }
      return body;
    });
  }

  async downloadImage(imageUrl) {
    const response = await this.fetch(imageUrl, { signal: AbortSignal.timeout(this.config.requestTimeoutMs) });
    if (!response.ok) throw new Error(`Image download returned ${response.status}`);
    const declaredSize = Number(response.headers.get("content-length") || 0);
    if (declaredSize > this.config.maxImageBytes) throw new Error("Image exceeds configured size limit");
    const bytes = Buffer.from(await response.arrayBuffer());
    if (bytes.length === 0) throw new Error("Downloaded image is empty");
    if (bytes.length > this.config.maxImageBytes) throw new Error("Image exceeds configured size limit");
    const contentType = response.headers.get("content-type")?.split(";")[0] || "image/jpeg";
    const extension = contentType.split("/")[1]?.replace("jpeg", "jpg") || "jpg";
    return { bytes, contentType, filename: `zalo-image.${extension}` };
  }

  async withRetry(operation) {
    let lastError;
    for (let attempt = 1; attempt <= this.config.retryAttempts; attempt += 1) {
      try {
        return await operation();
      } catch (error) {
        lastError = error;
        if (error.retryable === false || attempt === this.config.retryAttempts) break;
        await sleep(this.config.retryBaseDelayMs * 2 ** (attempt - 1));
      }
    }
    throw lastError;
  }
}
