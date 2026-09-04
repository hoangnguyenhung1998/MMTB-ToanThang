import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { AccountStore, normalizeAccountId, normalizeGroupIds } from "../src/account-store.js";

function temporaryStore(t) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "mmtb-zalo-accounts-"));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return { directory, store: new AccountStore(directory) };
}

test("normalizes safe account IDs and unique group IDs", () => {
  assert.equal(normalizeAccountId("zalo-test"), "zalo-test");
  assert.deepEqual(normalizeGroupIds("group-1, group-1,group-2"), ["group-1", "group-2"]);
  assert.throws(() => normalizeAccountId("../secret"), /Account ID/);
});

test("creates profiles without credentials and never exposes session data", (t) => {
  const { store } = temporaryStore(t);
  const profile = store.create("zalo-test", "Zalo kiểm thử", "g1,g2");
  assert.deepEqual(profile.group_ids, ["g1", "g2"]);
  assert.deepEqual(store.list().map(({ id, has_credentials }) => ({ id, has_credentials })), [
    { id: "zalo-test", has_credentials: false },
  ]);
});

test("activation requires credentials and resolves account-specific groups", (t) => {
  const { store } = temporaryStore(t);
  store.create("zalo-company", "Zalo công ty", ["company-group"]);
  assert.throws(() => store.activate("zalo-company"), /no saved Zalo session/);
  fs.writeFileSync(store.credentialsPath("zalo-company"), JSON.stringify({ cookie: [], imei: "imei", userAgent: "agent" }), "utf8");
  store.activate("zalo-company");
  const active = store.resolve(["legacy-group"]);
  assert.equal(active.id, "zalo-company");
  assert.deepEqual(active.groupIds, ["company-group"]);
});

test("updates account name and allowed groups without touching credentials", (t) => {
  const { store } = temporaryStore(t);
  store.create("zalo-company", "Old name", ["g1"]);
  fs.writeFileSync(store.credentialsPath("zalo-company"), JSON.stringify({ cookie: [], imei: "imei", userAgent: "agent" }), "utf8");
  const updated = store.update("zalo-company", { name: "Zalo công ty", groupIds: "g2,g3" });
  assert.equal(updated.name, "Zalo công ty");
  assert.deepEqual(updated.group_ids, ["g2", "g3"]);
  assert.equal(store.list()[0].has_credentials, true);
});

test("legacy mode remains unchanged until a managed account is activated", (t) => {
  const { directory, store } = temporaryStore(t);
  const legacy = store.resolve(new Set(["legacy-group"]));
  assert.equal(legacy.id, "legacy");
  assert.equal(legacy.credentialsPath, path.join(directory, "credentials.json"));
  assert.deepEqual(legacy.groupIds, ["legacy-group"]);
});

test("imports legacy credentials by copying and preserves the original", (t) => {
  const { directory, store } = temporaryStore(t);
  const credentials = JSON.stringify({ cookie: [], imei: "legacy-imei", userAgent: "legacy-agent" });
  fs.writeFileSync(path.join(directory, "credentials.json"), credentials, "utf8");
  const profile = store.importLegacy("current", "Tài khoản hiện tại", ["g1"]);
  assert.equal(fs.readFileSync(store.credentialsPath(profile.id), "utf8"), credentials);
  assert.equal(fs.readFileSync(path.join(directory, "credentials.json"), "utf8"), credentials);
});
