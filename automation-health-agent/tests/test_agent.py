import importlib.util
import io
import json
import logging
import os
import sys
import tempfile
import unittest
from datetime import datetime
from pathlib import Path
from unittest.mock import Mock, patch

MODULE_PATH = Path(__file__).resolve().parents[1] / "agent.py"
SPEC = importlib.util.spec_from_file_location("health_agent", MODULE_PATH)
agent = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = agent
SPEC.loader.exec_module(agent)


class HealthAgentTest(unittest.TestCase):
    def api_response(self, body=b'{"services": []}', content_type="application/json"):
        response = Mock(status=200, headers={"Content-Type": content_type})
        response.read.return_value = body
        context = Mock()
        context.__enter__ = Mock(return_value=response)
        context.__exit__ = Mock(return_value=False)
        return context

    def test_api_requests_json_and_keeps_authorization(self):
        with patch.object(agent.urllib.request, "build_opener") as factory:
            factory.return_value.open.return_value = self.api_response()
            self.assertEqual({"services": []}, agent.post_json("https://example.test/api", "secret", {}))
            request = factory.return_value.open.call_args.args[0]
            self.assertEqual("application/json", request.get_header("Accept"))
            self.assertEqual("Bearer secret", request.get_header("Authorization"))

    def test_api_reports_empty_html_invalid_and_nonobject_json(self):
        for body, content_type, expected in [
            (b"", "application/json", "empty response"),
            (b"<html>private-secret</html>", "text/html", "non-JSON Content-Type"),
            (b"not-json-private-secret", "application/json", "invalid JSON"),
            (b"[]", "application/json", "expected JSON object"),
        ]:
            with self.subTest(expected=expected), patch.object(agent.urllib.request, "build_opener") as factory:
                factory.return_value.open.return_value = self.api_response(body, content_type)
                with self.assertRaisesRegex(RuntimeError, expected) as raised:
                    agent.post_json("https://example.test/api", "private-secret", {})
                self.assertNotIn("private-secret", str(raised.exception))

    def test_api_validation_error_names_field_without_logging_body(self):
        body = json.dumps({"errors": {"services.4.service_type": ["private-secret"]}}).encode()
        error = agent.urllib.error.HTTPError("https://example.test", 422, "Unprocessable", {}, io.BytesIO(body))
        with patch.object(agent.urllib.request, "build_opener") as factory:
            factory.return_value.open.side_effect = error
            with self.assertRaisesRegex(RuntimeError, "HTTP 422.*services.4.service_type") as raised:
                agent.post_json("https://example.test/api", "private-secret", {})
            self.assertNotIn("private-secret", str(raised.exception))

    def test_api_http_errors_are_explicit(self):
        for code in (302, 401, 403, 500):
            with self.subTest(code=code), patch.object(agent.urllib.request, "build_opener") as factory:
                factory.return_value.open.side_effect = agent.urllib.error.HTTPError("https://example.test", code, "error", {}, io.BytesIO(b"private-secret"))
                with self.assertRaisesRegex(RuntimeError, f"HTTP {code}"):
                    agent.post_json("https://example.test/api", "private-secret", {})

    def test_api_never_forwards_token_through_redirect(self):
        request = agent.urllib.request.Request("https://example.test/api", headers={"Authorization": "Bearer secret"})
        self.assertIsNone(agent.NoApiRedirect().redirect_request(request, None, 302, "redirect", {}, "https://other.test"))

    def test_snapshot_failure_does_not_exit_agent_loop(self):
        with patch.dict(os.environ, {"AUTOMATION_HEALTH_API_URL": "https://example.test/api/heartbeat", "AUTOMATION_HEALTH_TOKEN": "secret"}), \
             patch.object(agent, "load_dotenv"), patch.object(agent, "HealthAgent") as health, \
             patch.object(agent.logging, "FileHandler", return_value=logging.NullHandler()), \
             patch.object(agent.logging, "basicConfig"), patch.object(agent.logging, "warning"), \
             patch.object(agent, "post_heartbeat", return_value={"services": []}) as heartbeat, \
             patch.object(agent, "process_commands", return_value=0), \
             patch.object(agent.time, "sleep", side_effect=[None, KeyboardInterrupt]):
            health.return_value.snapshot.side_effect = [RuntimeError("Task query failed"), []]
            with self.assertRaises(KeyboardInterrupt):
                agent.main()
            self.assertEqual(2, health.return_value.snapshot.call_count)
            heartbeat.assert_called_once()

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

    def test_business_exception_outcomes_are_not_worker_errors(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "worker.log"
            current = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            path.write_text(
                f"{current} INFO Completed TimeMark OCR job 107: status=EXCEPTION\n"
                f"{current} INFO Completed journal OCR job 100: status=EXCEPTION\n"
                f"{current} INFO Completed reconciliation job 101: outcome=WARNING\n",
                encoding="utf-8",
            )
            snapshot = agent.LogSnapshot(path)
            self.assertEqual(0, snapshot.consecutive_errors())
            self.assertIsNone(snapshot.last_error())
            self.assertIsNotNone(snapshot.last_success_at())

    def test_warning_and_traceback_remain_worker_errors(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "worker.log"
            current = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            path.write_text(
                f"{current} WARNING API timeout\n"
                "Traceback (most recent call last):\n",
                encoding="utf-8",
            )
            snapshot = agent.LogSnapshot(path)
            self.assertEqual(2, snapshot.consecutive_errors())
            self.assertIn("Traceback", snapshot.last_error())

    def test_unstructured_node_error_remains_a_worker_error(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "collector.log"
            current = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            path.write_text(
                f"{current} Zalo listener error: connection closed\n",
                encoding="utf-8",
            )
            snapshot = agent.LogSnapshot(path)
            self.assertEqual(1, snapshot.consecutive_errors())

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

    def test_zalo_snapshot_exposes_metadata_without_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            account = root / "collector/data/accounts/zalo-test"
            account.mkdir(parents=True)
            (account / "profile.json").write_text(json.dumps({
                "id": "zalo-test", "name": "Zalo kiểm thử", "group_ids": ["g1", "g2"],
            }), encoding="utf-8")
            (account / "credentials.json").write_text(json.dumps({
                "cookie": ["private-cookie"], "imei": "private-imei", "userAgent": "private-agent",
            }), encoding="utf-8")
            (account / "groups.json").write_text(json.dumps([
                {"id": "g1", "name": "Nhóm một"}, {"id": "g2", "name": "Nhóm hai"},
                {"id": "g3", "name": "Nhóm ba"},
            ]), encoding="utf-8")
            (root / "collector/data/active-account.json").write_text(
                json.dumps({"account_id": "zalo-test"}), encoding="utf-8",
            )

            snapshot = agent.zalo_account_snapshot(root)
            serialized = json.dumps(snapshot)
            self.assertEqual("zalo-test", snapshot["active_account_id"])
            self.assertEqual(2, snapshot["zalo_accounts"][0]["group_count"])
            self.assertTrue(snapshot["zalo_accounts"][0]["ready"])
            self.assertEqual(3, snapshot["zalo_accounts"][0]["available_group_count"])
            enabled = {item["id"]: item["enabled"] for item in snapshot["zalo_accounts"][0]["groups"]}
            self.assertEqual({"g1": True, "g2": True, "g3": False}, enabled)
            self.assertNotIn("private-cookie", serialized)
            self.assertNotIn("private-imei", serialized)

    def test_zalo_switch_executes_only_safe_local_script(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            script = root / "collector/scripts/switch-account.ps1"
            script.parent.mkdir(parents=True)
            script.write_text("# test", encoding="utf-8")
            health = agent.HealthAgent(root, "127.0.0.1", 18789)
            command = {
                "action": "ZALO_ACCOUNT_SWITCH", "payload": {"account_id": "zalo-company"},
                "service": {"service_key": "zalo-collector"},
            }
            with patch.object(agent.subprocess, "run", return_value=Mock(returncode=0, stdout="", stderr="")) as run:
                result = health.execute_command(command)
            self.assertEqual("zalo-company", result["account_id"])
            self.assertIn(str(script), run.call_args.args[0])
            self.assertIn("zalo-company", run.call_args.args[0])

            command["payload"]["account_id"] = "../credentials"
            with self.assertRaisesRegex(RuntimeError, "không hợp lệ"):
                health.execute_command(command)

    def test_zalo_group_update_uses_only_catalogued_groups_and_restarts_active_account(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            account = root / "collector/data/accounts/zalo-company"
            account.mkdir(parents=True)
            (account / "profile.json").write_text(json.dumps({
                "id": "zalo-company", "name": "Zalo công ty", "group_ids": ["1"],
            }), encoding="utf-8")
            (account / "credentials.json").write_text(json.dumps({
                "cookie": [], "imei": "imei", "userAgent": "agent",
            }), encoding="utf-8")
            (account / "groups.json").write_text(json.dumps([
                {"id": "1", "name": "Nhóm một"}, {"id": "2", "name": "Nhóm hai"},
            ]), encoding="utf-8")
            (root / "collector/data/active-account.json").write_text(
                json.dumps({"account_id": "zalo-company"}), encoding="utf-8",
            )
            accounts = root / "collector/src/accounts.js"; accounts.parent.mkdir(parents=True, exist_ok=True)
            accounts.write_text("// test", encoding="utf-8")
            script = root / "collector/scripts/switch-account.ps1"; script.parent.mkdir(parents=True, exist_ok=True)
            script.write_text("# test", encoding="utf-8")
            health = agent.HealthAgent(root, "127.0.0.1", 18789)
            command = {
                "action": "ZALO_GROUPS_UPDATE", "payload": {"account_id": "zalo-company", "group_ids": ["1", "2"]},
                "service": {"service_key": "zalo-collector"},
            }
            with patch.object(agent.shutil, "which", return_value="node.exe"), \
                 patch.object(agent.subprocess, "run", side_effect=[Mock(returncode=0, stdout="", stderr=""), Mock(returncode=0, stdout="", stderr="")]) as run:
                result = health.execute_command(command)
            self.assertEqual(2, result["group_count"])
            self.assertEqual(2, run.call_count)
            self.assertIn("1,2", run.call_args_list[0].args[0])

            command["payload"]["group_ids"] = ["999"]
            with self.assertRaisesRegex(RuntimeError, "không tồn tại"):
                health.execute_command(command)

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
