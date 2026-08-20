import fs from "node:fs";
import path from "node:path";

export function readCredentials(filePath) {
  if (!fs.existsSync(filePath)) return null;
  try {
    const value = JSON.parse(fs.readFileSync(filePath, "utf8"));
    return value?.cookie && value?.imei && value?.userAgent ? value : null;
  } catch {
    return null;
  }
}

export function writeCredentials(filePath, api) {
  const context = api.getContext();
  const credentials = {
    cookie: context.cookie.toJSON()?.cookies || [],
    imei: context.imei,
    userAgent: context.userAgent,
  };
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, JSON.stringify(credentials, null, 2), { encoding: "utf8", mode: 0o600 });
}
