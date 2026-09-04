import "dotenv/config";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";
import { Zalo } from "zca-js";
import { AccountStore } from "./account-store.js";
import { readCredentials, writeCredentials } from "./credentials.js";
import { acquireProcessLock } from "./process-lock.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dataDirectory = path.join(root, "data");
const store = new AccountStore(dataDirectory);
const [command = "list", ...args] = process.argv.slice(2);

function option(name, fallback = "") {
  const index = args.indexOf(`--${name}`);
  return index >= 0 ? String(args[index + 1] ?? "") : fallback;
}

function hasOption(name) {
  return args.includes(`--${name}`);
}

function requiredOption(name) {
  const value = option(name).trim();
  if (!value) throw new Error(`Missing --${name}.`);
  return value;
}

async function login(accountId) {
  const profile = store.get(accountId);
  const release = acquireProcessLock(path.join(dataDirectory, "collector.lock"));
  try {
    const credentialsPath = store.credentialsPath(profile.id);
    const qrPath = store.qrPath(profile.id);
    const zalo = new Zalo({ logging: true });
    const saved = readCredentials(credentialsPath);
    let api;
    try {
      api = saved ? await zalo.login(saved) : await zalo.loginQR({ qrPath });
    } catch (error) {
      if (!saved) throw error;
      console.warn(`Saved session for ${profile.id} expired. Scan the new QR code: ${qrPath}`);
      api = await zalo.loginQR({ qrPath });
    }
    writeCredentials(credentialsPath, api);
    console.log(`Zalo session saved for ${profile.id}. Credentials were not displayed.`);
  } finally {
    release();
  }
}

try {
  if (command === "add") {
    const profile = store.create(requiredOption("id"), requiredOption("name"), option("groups"));
    console.log(`Created ${profile.id} (${profile.name}) with ${profile.group_ids.length} allowed group(s).`);
  } else if (command === "update") {
    const profile = store.update(requiredOption("id"), {
      name: hasOption("name") ? option("name") : undefined,
      groupIds: hasOption("groups") ? option("groups") : undefined,
    });
    console.log(`Updated ${profile.id} (${profile.name}) with ${profile.group_ids.length} allowed group(s).`);
  } else if (command === "login") {
    await login(requiredOption("id"));
  } else if (command === "activate") {
    const profile = store.activate(requiredOption("id"));
    console.log(`Active Zalo account is now ${profile.id} (${profile.name}).`);
  } else if (command === "import-legacy") {
    const groups = option("groups", process.env.ZALO_ALLOWED_GROUP_IDS ?? "");
    const profile = store.importLegacy(requiredOption("id"), requiredOption("name"), groups);
    console.log(`Copied the legacy session into ${profile.id}. The original session was preserved.`);
  } else if (command === "ready") {
    const account = store.resolve((process.env.ZALO_ALLOWED_GROUP_IDS ?? "").split(","));
    if (!readCredentials(account.credentialsPath)) throw new Error(`No valid credential file for ${account.id}.`);
    if (account.groupIds.length === 0) throw new Error(`No allowed groups configured for ${account.id}.`);
  } else if (command === "list") {
    const activeId = store.activeId();
    const rows = store.list().map((profile) => ({
      active: profile.id === activeId ? "YES" : "",
      id: profile.id,
      name: profile.name,
      groups: profile.group_ids.length,
      session: profile.has_credentials ? "SAVED" : "MISSING",
    }));
    console.table(rows);
    if (!activeId) console.log("No managed account is active; Collector uses the legacy credentials and .env groups.");
  } else {
    throw new Error(`Unknown account command: ${command}`);
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
