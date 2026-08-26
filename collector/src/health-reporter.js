import fs from "node:fs";

export class HealthReporter {
  constructor(path) {
    this.path = path;
    this.state = {};
    try { this.state = JSON.parse(fs.readFileSync(path, "utf8")); } catch {}
  }

  alive() { this.write({ last_api_success_at: new Date().toISOString() }); }
  jobStarted(id) { this.write({ current_job: `job-${id}`, current_job_started_at: new Date().toISOString() }); }
  jobSucceeded() { this.write({ last_job_success_at: new Date().toISOString(), current_job: null, current_job_started_at: null }); }
  jobFinished() { this.write({ current_job: null, current_job_started_at: null }); }

  write(values) {
    this.state = { ...this.state, ...values };
    const temporary = `${this.path}.tmp`;
    fs.writeFileSync(temporary, JSON.stringify(this.state));
    fs.renameSync(temporary, this.path);
  }
}
