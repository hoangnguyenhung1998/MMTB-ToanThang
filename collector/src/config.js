import process from "node:process";

function required(name) {
  const value = process.env[name]?.trim();
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

function positiveInteger(name, fallback) {
  const raw = process.env[name]?.trim();
  const value = raw ? Number(raw) : fallback;
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${name} must be a positive integer`);
  }
  return value;
}

export function loadConfig() {
  const apiUrl = new URL(required("LARAVEL_API_URL"));
  if (!["http:", "https:"].includes(apiUrl.protocol)) {
    throw new Error("LARAVEL_API_URL must use http or https");
  }

  const allowedGroupIds = new Set(
    (process.env.ZALO_ALLOWED_GROUP_IDS ?? "")
      .split(",")
      .map((value) => value.trim())
      .filter(Boolean),
  );

  return Object.freeze({
    apiUrl: apiUrl.toString(),
    apiToken: required("COLLECTOR_API_TOKEN"),
    allowedGroupIds,
    maxImageBytes: positiveInteger("COLLECTOR_MAX_IMAGE_BYTES", 25 * 1024 * 1024),
    requestTimeoutMs: positiveInteger("COLLECTOR_REQUEST_TIMEOUT_MS", 30_000),
    retryAttempts: positiveInteger("COLLECTOR_RETRY_ATTEMPTS", 3),
    retryBaseDelayMs: positiveInteger("COLLECTOR_RETRY_BASE_DELAY_MS", 1_000),
  });
}
