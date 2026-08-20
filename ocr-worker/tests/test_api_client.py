import unittest

from mmtb_ocr_worker.api_client import LaravelOcrClient


class ApiClientTest(unittest.TestCase):
    def setUp(self):
        self.client = LaravelOcrClient(
            api_url="http://100.64.0.10/mmtbver2/public/api/ocr/v1",
            token="secret",
            worker_id="worker-test",
            timeout_seconds=30,
            temp_dir=__import__("pathlib").Path("data/test-tmp"),
        )

    def test_resolves_relative_image_url_against_tailscale_host(self):
        resolved = self.client._resolve_image_url(
            "/mmtbver2/public/api/ocr/v1/jobs/7/image?worker_id=worker-test"
        )
        self.assertEqual(
            "http://100.64.0.10/mmtbver2/public/api/ocr/v1/jobs/7/image?worker_id=worker-test",
            resolved,
        )

    def test_adds_laravel_subdirectory_to_root_api_path(self):
        resolved = self.client._resolve_image_url(
            "/api/ocr/v1/jobs/7/image?worker_id=worker-test"
        )
        self.assertEqual(
            "http://100.64.0.10/mmtbver2/public/api/ocr/v1/jobs/7/image?worker_id=worker-test",
            resolved,
        )

    def test_maps_png_content_type(self):
        self.assertEqual(".png", self.client._image_suffix("image/png; charset=binary"))

    def test_defaults_unknown_image_type_to_jpeg(self):
        self.assertEqual(".jpg", self.client._image_suffix("application/octet-stream"))


if __name__ == "__main__":
    unittest.main()
