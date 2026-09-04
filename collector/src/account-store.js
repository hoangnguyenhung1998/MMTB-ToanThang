import fs from "node:fs";
import path from "node:path";

const ACCOUNT_ID_PATTERN = /^[a-z0-9][a-z0-9_-]{0,49}$/;

function writeJsonAtomic(filePath, value) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  const temporaryPath = `${filePath}.${process.pid}.tmp`;
  fs.writeFileSync(temporaryPath, `${JSON.stringify(value, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  if (fs.existsSync(filePath)) fs.rmSync(filePath, { force: true });
  fs.renameSync(temporaryPath, filePath);
}

function readJson(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, "utf8"));
  } catch {
    return null;
  }
}

function hasValidCredentials(filePath) {
  const value = readJson(filePath);
  return Boolean(value?.cookie && value?.imei && value?.userAgent);
}

export function normalizeAccountId(value) {
  const accountId = String(value ?? "").trim().toLowerCase();
  if (!ACCOUNT_ID_PATTERN.test(accountId)) {
    throw new Error("Account ID must use only lowercase letters, numbers, hyphens, or underscores (maximum 50 characters).");
  }
  return accountId;
}

export function normalizeGroupIds(values) {
  const groups = Array.isArray(values)
    ? values
    : values instanceof Set
      ? [...values]
      : String(values ?? "").split(",");
  return [...new Set(groups.map((value) => String(value).trim()).filter(Boolean))];
}

export class AccountStore {
  constructor(dataDirectory) {
    this.dataDirectory = dataDirectory;
    this.accountsDirectory = path.join(dataDirectory, "accounts");
    this.activePath = path.join(dataDirectory, "active-account.json");
  }

  accountDirectory(accountId) {
    return path.join(this.accountsDirectory, normalizeAccountId(accountId));
  }

  profilePath(accountId) {
    return path.join(this.accountDirectory(accountId), "profile.json");
  }

  credentialsPath(accountId) {
    return path.join(this.accountDirectory(accountId), "credentials.json");
  }

  qrPath(accountId) {
    return path.join(this.accountDirectory(accountId), "qr.png");
  }

  create(accountId, name, groupIds) {
    const id = normalizeAccountId(accountId);
    const profilePath = this.profilePath(id);
    if (fs.existsSync(profilePath)) throw new Error(`Zalo account profile already exists: ${id}`);
    const profile = {
      id,
      name: String(name ?? "").trim() || id,
      group_ids: normalizeGroupIds(groupIds),
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    };
    writeJsonAtomic(profilePath, profile);
    return profile;
  }

  get(accountId) {
    const id = normalizeAccountId(accountId);
    const profile = readJson(this.profilePath(id));
    if (!profile || profile.id !== id) throw new Error(`Zalo account profile not found: ${id}`);
    return { ...profile, group_ids: normalizeGroupIds(profile.group_ids) };
  }

  update(accountId, changes = {}) {
    const profile = this.get(accountId);
    const updated = {
      ...profile,
      name: changes.name === undefined ? profile.name : String(changes.name).trim() || profile.id,
      group_ids: changes.groupIds === undefined ? profile.group_ids : normalizeGroupIds(changes.groupIds),
      updated_at: new Date().toISOString(),
    };
    writeJsonAtomic(this.profilePath(profile.id), updated);
    return updated;
  }

  list() {
    if (!fs.existsSync(this.accountsDirectory)) return [];
    return fs.readdirSync(this.accountsDirectory, { withFileTypes: true })
      .filter((entry) => entry.isDirectory())
      .map((entry) => {
        try {
          const profile = this.get(entry.name);
          return { ...profile, has_credentials: hasValidCredentials(this.credentialsPath(profile.id)) };
        } catch {
          return null;
        }
      })
      .filter(Boolean)
      .sort((left, right) => left.name.localeCompare(right.name, "vi"));
  }

  activeId() {
    const active = readJson(this.activePath);
    return active?.account_id ? normalizeAccountId(active.account_id) : null;
  }

  activate(accountId) {
    const profile = this.get(accountId);
    if (!hasValidCredentials(this.credentialsPath(profile.id))) {
      throw new Error(`Account ${profile.id} has no saved Zalo session. Run the login command first.`);
    }
    writeJsonAtomic(this.activePath, { account_id: profile.id, activated_at: new Date().toISOString() });
    return profile;
  }

  importLegacy(accountId, name, groupIds) {
    const legacyPath = path.join(this.dataDirectory, "credentials.json");
    if (!hasValidCredentials(legacyPath)) throw new Error("Legacy credentials.json was not found or is invalid.");
    const profile = fs.existsSync(this.profilePath(accountId))
      ? this.get(accountId)
      : this.create(accountId, name, groupIds);
    const targetPath = this.credentialsPath(profile.id);
    if (fs.existsSync(targetPath)) throw new Error(`Account ${profile.id} already has saved credentials.`);
    fs.copyFileSync(legacyPath, targetPath, fs.constants.COPYFILE_EXCL);
    return profile;
  }

  resolve(legacyGroupIds = []) {
    const activeId = this.activeId();
    if (!activeId) {
      return {
        id: "legacy",
        name: "Legacy Zalo account",
        groupIds: normalizeGroupIds(legacyGroupIds),
        credentialsPath: path.join(this.dataDirectory, "credentials.json"),
        qrPath: path.join(this.dataDirectory, "qr.png"),
        managed: false,
      };
    }
    const profile = this.get(activeId);
    return {
      id: profile.id,
      name: profile.name,
      groupIds: profile.group_ids,
      credentialsPath: this.credentialsPath(profile.id),
      qrPath: this.qrPath(profile.id),
      managed: true,
    };
  }
}
