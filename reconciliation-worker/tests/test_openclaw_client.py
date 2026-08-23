import json
import unittest
from pathlib import Path
from unittest.mock import patch

from mmtb_reconciliation_worker.openclaw_client import OpenClawClient


class OpenClawClientTest(unittest.TestCase):
    @patch("mmtb_reconciliation_worker.openclaw_client.subprocess.run")
    def test_parses_cli_json_and_uses_isolated_session(self, run):
        agent_result = {
            "outcome": "WARNING", "summary": "Thiếu ảnh cuối ca", "confidence": 0.8,
            "findings": [{"code": "MISSING_END", "severity": "WARNING", "title": "Thiếu ảnh"}],
        }
        run.return_value.returncode = 0
        run.return_value.stdout = json.dumps({"payloads": [{"text": json.dumps(agent_result)}]})
        run.return_value.stderr = ""
        client = OpenClawClient("openclaw", "mmtb-reconciliation", 600)
        result = client.reconcile({"id": 1}, {8: Path("image.jpg")})
        self.assertEqual("WARNING", result.outcome)
        command = run.call_args.args[0]
        self.assertIn("--session-key", command)
        self.assertIn("mmtb-reconciliation-job-1", command)
        self.assertIn("--message-file", command)
        self.assertNotIn("--deliver", command)

    def test_reads_gateway_result_shape_and_markdown_fence(self):
        text = "```json\n{\"outcome\":\"UNRESOLVED\",\"findings\":[]}\n```"
        self.assertEqual(text, self._text({"result": {"payloads": [{"text": text}]}}))

    @patch("mmtb_reconciliation_worker.openclaw_client.os.name", "nt")
    @patch("mmtb_reconciliation_worker.openclaw_client.shutil.which")
    def test_runs_npm_cmd_shim_through_windows_command_processor(self, which):
        which.return_value = r"C:\Users\HUNG EJA\AppData\Roaming\npm\openclaw.cmd"
        client = OpenClawClient("openclaw", "mmtb-reconciliation", 600)
        command = client._build_command(["agent", "--json"])
        self.assertEqual("/c", command[3])
        self.assertEqual(5, len(command))
        self.assertIn('"C:\\Users\\HUNG EJA\\AppData\\Roaming\\npm\\openclaw.cmd"', command[4])
        self.assertIn("agent --json", command[4])

    @staticmethod
    def _text(envelope):
        return OpenClawClient._response_text(envelope)
