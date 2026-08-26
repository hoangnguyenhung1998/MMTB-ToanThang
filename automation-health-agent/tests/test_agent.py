import importlib.util
import os
import sys
import tempfile
import unittest
from datetime import datetime
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
            current = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            path.write_text(
                f"{current} INFO Completed OCR job 41\n"
                f"{current} WARNING API timeout\n"
                f"{current} ERROR API unavailable\n",
                encoding="utf-8",
            )
            snapshot = agent.LogSnapshot(path)
            self.assertEqual("job-41", snapshot.current_job())
            self.assertEqual(2, snapshot.consecutive_errors())
            self.assertIn("API unavailable", snapshot.last_error())
            self.assertIsNotNone(snapshot.last_success_at())

    def test_missing_task_is_degraded(self):
        health = agent.HealthAgent(Path("C:/MMTB"), "127.0.0.1", 18789)
        definition = health.definitions[0]
        result = health._task_snapshot(definition, None)
        self.assertEqual("DEGRADED", result["status"])
        self.assertEqual("TASK_MISSING", result["error_code"])

    def test_running_task_is_healthy(self):
        health = agent.HealthAgent(Path("C:/MMTB"), "127.0.0.1", 18789)
        definition = health.definitions[1]
        result = health._task_snapshot(definition, {"state": "Running", "last_result": 267009})
        self.assertEqual("HEALTHY", result["status"])
        self.assertEqual("RUNNING", result["metrics"]["task_state"])

    def test_old_errors_do_not_degrade_a_running_task(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            log_path = root / "ocr-worker/data/worker.log"
            log_path.parent.mkdir(parents=True)
            log_path.write_text(
                "2020-01-01 08:00:00 WARNING API timeout\n" * 4,
                encoding="utf-8",
            )
            health = agent.HealthAgent(root, "127.0.0.1", 18789)
            result = health._task_snapshot(
                health.definitions[1], {"state": "Running", "last_result": 267009}
            )
            self.assertEqual("HEALTHY", result["status"])
            self.assertEqual(0, result["consecutive_errors"])
            self.assertIsNone(result["error_message"])

    def test_health_file_supplies_real_api_and_job_timestamps(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); health_path = root / "ocr-worker/data/health.json"
            health_path.parent.mkdir(parents=True)
            health_path.write_text('{"last_api_success_at":"2026-08-26T07:00:00+00:00","last_job_success_at":"2026-08-26T06:55:00+00:00"}', encoding="utf-8")
            health = agent.HealthAgent(root, "127.0.0.1", 18789)
            result = health._task_snapshot(health.definitions[1], {"state": "Running", "last_result": 267009})
            self.assertEqual("2026-08-26T07:00:00+00:00", result["last_api_success_at"])
            self.assertEqual("2026-08-26T06:55:00+00:00", result["last_job_success_at"])

    def test_remote_actions_are_strictly_allowlisted(self):
        self.assertIn("Disable-ScheduledTask", agent.match_action("PAUSE", "MMTB-RapidOCRWorker"))
        self.assertIn("Start-ScheduledTask", agent.match_action("RETRY", "MMTB-RapidOCRWorker"))
        with self.assertRaises(RuntimeError):
            agent.match_action("RUN_POWERSHELL", "MMTB-RapidOCRWorker")

    def test_ready_task_is_started_once_per_recovery_cooldown(self):
        health = agent.HealthAgent(Path("C:/MMTB"), "127.0.0.1", 18789, recovery_cooldown_seconds=300)
        health.task_reader.start = Mock()
        tasks = {"MMTB-RapidOCRWorker": {"state": "Ready"}}

        health._recover_ready_tasks(tasks)
        health._recover_ready_tasks(tasks)

        health.task_reader.start.assert_called_once_with("MMTB-RapidOCRWorker")

    def test_disabled_task_is_not_automatically_started(self):
        health = agent.HealthAgent(Path("C:/MMTB"), "127.0.0.1", 18789)
        health.task_reader.start = Mock()

        health._recover_ready_tasks({"MMTB-RapidOCRWorker": {"state": "Disabled"}})

        health.task_reader.start.assert_not_called()


if __name__ == "__main__":
    unittest.main()
