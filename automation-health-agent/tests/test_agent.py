import importlib.util
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

MODULE_PATH = Path(__file__).resolve().parents[1] / "agent.py"
SPEC = importlib.util.spec_from_file_location("health_agent", MODULE_PATH)
agent = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = agent
SPEC.loader.exec_module(agent)


class HealthAgentTest(unittest.TestCase):
    def test_load_dotenv_preserves_existing_environment(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / ".env"
            path.write_text("FIRST=value\nSECOND=from-file\n", encoding="utf-8")
            os.environ["SECOND"] = "existing"
            agent.load_dotenv(path)
            self.assertEqual("value", os.environ["FIRST"])
            self.assertEqual("existing", os.environ["SECOND"])

    def test_log_snapshot_reads_latest_job_success_and_errors(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "worker.log"
            path.write_text(
                "2026-08-26 08:00:00 INFO Completed OCR job 41\n"
                "2026-08-26 08:01:00 WARNING API timeout\n"
                "2026-08-26 08:02:00 ERROR API unavailable\n",
                encoding="utf-8",
            )
            snapshot = agent.LogSnapshot(path)
            self.assertEqual("job-41", snapshot.current_job())
            self.assertEqual(2, snapshot.consecutive_errors())
            self.assertIn("API unavailable", snapshot.last_error())
            self.assertIsNotNone(snapshot.last_success_at())

    def test_missing_task_is_degraded(self):
        health = agent.HealthAgent(Path("C:/MMTB"), "openclaw")
        definition = health.definitions[0]
        result = health._task_snapshot(definition, None)
        self.assertEqual("DEGRADED", result["status"])
        self.assertEqual("TASK_MISSING", result["error_code"])

    def test_running_task_is_healthy(self):
        health = agent.HealthAgent(Path("C:/MMTB"), "openclaw")
        definition = health.definitions[1]
        result = health._task_snapshot(definition, {"state": "Running", "last_result": 267009})
        self.assertEqual("HEALTHY", result["status"])
        self.assertEqual("RUNNING", result["metrics"]["task_state"])


if __name__ == "__main__":
    unittest.main()
