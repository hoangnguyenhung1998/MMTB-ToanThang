import fs from "node:fs";

export class LaravelCollectorClient {
  constructor(config, fetchImpl = globalThis.fetch) {
    this.config = config;
    this.fetch = fetchImpl;
  }

  async forwardStoredImage(job) {
    const bytes = await fs.promises.readFile(job.local_path);
    const form = new FormData();
    form.set("group_id", job.group_id);
    form.set("message_id", job.message_id);
    form.set("sender_id", job.sender_id);
    form.set("sender_name", job.sender_name);
    form.set("sent_at", job.message_sent_at);
    form.set("attachment_index", String(job.attachment_index));
    form.set("sha256", job.sha256);
    form.set("raw_payload", JSON.stringify({ source: "zca-js", image_url: job.source_url }));
    form.set("file", new Blob([bytes], { type: job.content_type }), job.filename);

    const response = await this.fetch(this.config.apiUrl, {
      method: "POST",
      headers: { Authorization: `Bearer ${this.config.apiToken}`, Accept: "application/json" },
      body: form,
      signal: AbortSignal.timeout(this.config.requestTimeoutMs),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(`Laravel API returned ${response.status}: ${JSON.stringify(body)}`);
      error.permanent = response.status >= 400 && response.status < 500 && response.status !== 408 && response.status !== 429;
      throw error;
    }
    return body;
  }

  async downloadImage(imageUrl) {
    const response = await this.fetch(imageUrl, { signal: AbortSignal.timeout(this.config.requestTimeoutMs) });
    if (!response.ok) throw new Error(`Image download returned ${response.status}`);
    const declaredSize = Number(response.headers.get("content-length") || 0);
    if (declaredSize > this.config.maxImageBytes) throw permanentError("Image exceeds configured size limit");
    const bytes = Buffer.from(await response.arrayBuffer());
    if (bytes.length === 0) throw permanentError("Downloaded image is empty");
    if (bytes.length > this.config.maxImageBytes) throw permanentError("Image exceeds configured size limit");
    const contentType = response.headers.get("content-type")?.split(";")[0] || "image/jpeg";
    const extension = contentType.split("/")[1]?.replace("jpeg", "jpg") || "jpg";
    return { bytes, contentType, filename: `zalo-image.${extension}` };
  }

}

function permanentError(message) {
  const error = new Error(message);
  error.permanent = true;
  return error;
}
