import fs from "node:fs";
import path from "node:path";

export function acquireProcessLock(lockPath) {
  fs.mkdirSync(path.dirname(lockPath), { recursive: true });
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try {
      const descriptor = fs.openSync(lockPath, "wx", 0o600);
      fs.writeFileSync(descriptor, String(process.pid), "utf8");
      fs.closeSync(descriptor);
      let released = false;
      return () => {
        if (released) return;
        released = true;
        try {
          const owner = Number(fs.readFileSync(lockPath, "utf8"));
          if (owner === process.pid) fs.unlinkSync(lockPath);
        } catch {
          // Lock already removed.
        }
      };
    } catch (error) {
      if (error.code !== "EEXIST") throw error;
      const owner = readOwner(lockPath);
      if (owner && isProcessAlive(owner)) {
        throw new Error(`Another Collector process is already running with PID ${owner}`);
      }
      fs.rmSync(lockPath, { force: true });
    }
  }
  throw new Error("Unable to acquire Collector process lock");
}

function readOwner(lockPath) {
  try {
    return Number(fs.readFileSync(lockPath, "utf8"));
  } catch {
    return 0;
  }
}

function isProcessAlive(pid) {
  try {
    process.kill(pid, 0);
    return true;
  } catch (error) {
    return error.code === "EPERM";
  }
}
