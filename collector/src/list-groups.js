import path from "node:path";
import { fileURLToPath } from "node:url";
import { Zalo } from "zca-js";
import { readCredentials, writeCredentials } from "./credentials.js";
import { AccountStore } from "./account-store.js";
import { refreshGroupCatalog } from "./group-catalog.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dataDirectory = path.join(root, "data");
const store = new AccountStore(dataDirectory);
const idIndex = process.argv.indexOf("--id");
const requestedId = idIndex >= 0 ? String(process.argv[idIndex + 1] ?? "").trim() : "";
const account = requestedId
  ? (() => {
      const profile = store.get(requestedId);
      return {
        id: profile.id,
        name: profile.name,
        groupIds: profile.group_ids,
        credentialsPath: store.credentialsPath(profile.id),
        qrPath: store.qrPath(profile.id),
        managed: true,
      };
    })()
  : store.resolve((process.env.ZALO_ALLOWED_GROUP_IDS ?? "").split(","));
const zalo = new Zalo({ logging: true });
const savedCredentials = readCredentials(account.credentialsPath);

let api;
try {
  api = savedCredentials ? await zalo.login(savedCredentials) : await zalo.loginQR({ qrPath: account.qrPath });
} catch (error) {
  if (!savedCredentials) throw error;
  console.warn(`Saved Zalo session for ${account.id} is invalid. Scan the new QR code:`, account.qrPath);
  api = await zalo.loginQR({ qrPath: account.qrPath });
}

writeCredentials(account.credentialsPath, api);
const rows = account.managed ? await refreshGroupCatalog(api, store, account.id) : [];
if (rows.length === 0) {
  console.log("This Zalo account is not in any groups.");
  process.exit(0);
}
console.table(rows);
console.log(`Saved ${rows.length} safe group name/ID record(s) for ${account.id}.`);
