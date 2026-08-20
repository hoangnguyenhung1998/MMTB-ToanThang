import path from "node:path";
import { fileURLToPath } from "node:url";
import { Zalo } from "zca-js";
import { readCredentials, writeCredentials } from "./credentials.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const credentialsPath = path.join(root, "data", "credentials.json");
const qrPath = path.join(root, "data", "qr.png");
const zalo = new Zalo({ logging: true });
const savedCredentials = readCredentials(credentialsPath);

let api;
try {
  api = savedCredentials ? await zalo.login(savedCredentials) : await zalo.loginQR({ qrPath });
} catch (error) {
  if (!savedCredentials) throw error;
  console.warn("Saved Zalo session is invalid. Scan the new QR code:", qrPath);
  api = await zalo.loginQR({ qrPath });
}

writeCredentials(credentialsPath, api);
const groups = await api.getAllGroups();
const groupIds = Object.keys(groups.gridVerMap ?? {});

if (groupIds.length === 0) {
  console.log("This Zalo account is not in any groups.");
  process.exit(0);
}

const details = await api.getGroupInfo(groupIds);
const rows = groupIds.map((id) => {
  const group = details.gridInfoMap?.[id] ?? {};
  return { name: group.name || group.groupName || "Unknown group", id };
});

rows.sort((a, b) => a.name.localeCompare(b.name, "vi"));
console.table(rows);
console.log("Copy only verified group IDs into ZALO_ALLOWED_GROUP_IDS.");
