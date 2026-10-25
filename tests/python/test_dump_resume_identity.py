import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from python.collect_books import DUMP_URL, download_dump, pin_partial_identity


DATED_URL = "https://archive.example/ol_dump_editions_2026-09-30.txt.gz"
ETAG = '"edition-v1"'
LAST_MODIFIED = "Fri, 02 Oct 2026 13:44:37 GMT"


class Response(io.BytesIO):
    def __init__(self, data: bytes, status: int, headers: dict[str, str], url: str = DATED_URL):
        super().__init__(data)
        self.status = status
        self.headers = headers
        self.url = url

    def geturl(self) -> str:
        return self.url


def write_identity(partial: Path, total_size: int = 6, etag: str = ETAG) -> Path:
    path = partial.with_name(partial.name + ".identity.json")
    path.write_text(
        json.dumps(
            {
                "format_version": 1,
                "request_url": DUMP_URL,
                "response_url": DATED_URL,
                "total_size": total_size,
                "validator_header": "ETag",
                "validator_value": etag,
                "etag": etag,
                "last_modified": LAST_MODIFIED,
            }
        ),
        encoding="utf-8",
    )
    return path


class DumpResumeIdentityTests(unittest.TestCase):
    def test_interrupted_initial_download_persists_identity_and_rejects_changed_latest(self):
        class InterruptedResponse(Response):
            def __init__(self):
                super().__init__(
                    b"",
                    200,
                    {
                        "Content-Length": "6",
                        "ETag": ETAG,
                        "Last-Modified": LAST_MODIFIED,
                    },
                )
                self.sent = False

            def read(self, size: int = -1) -> bytes:
                if not self.sent:
                    self.sent = True
                    return b"abc"
                raise ConnectionResetError("mock interrupted transfer")

        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "editions.txt.gz"
            partial = destination.with_suffix(destination.suffix + ".part")
            with patch(
                "python.collect_books.urllib.request.urlopen",
                return_value=InterruptedResponse(),
            ):
                with self.assertRaises(ConnectionResetError):
                    download_dump(destination)

            self.assertEqual(partial.read_bytes(), b"abc")
            identity_path = partial.with_name(partial.name + ".identity.json")
            identity = json.loads(identity_path.read_text(encoding="utf-8"))
            self.assertEqual(identity["response_url"], DATED_URL)
            self.assertEqual(identity["etag"], ETAG)
            self.assertEqual(identity["total_size"], 6)

            changed = Response(
                b"def",
                206,
                {
                    "Content-Range": "bytes 3-5/6",
                    "Content-Length": "3",
                    "ETag": '"edition-v2"',
                    "Last-Modified": LAST_MODIFIED,
                },
            )
            with patch("python.collect_books.urllib.request.urlopen", return_value=changed) as opener:
                with self.assertRaisesRegex(RuntimeError, "validator changed"):
                    download_dump(destination)

            request_headers = {
                name.lower(): value
                for name, value in opener.call_args.args[0].header_items()
            }
            self.assertEqual(request_headers["range"], "bytes=3-")
            self.assertEqual(request_headers["if-range"], ETAG)
            self.assertEqual(partial.read_bytes(), b"abc")
            self.assertFalse(destination.exists())

    def test_unknown_legacy_partial_fails_closed_without_network_request(self):
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "editions.txt.gz"
            partial = destination.with_suffix(destination.suffix + ".part")
            partial.write_bytes(b"abc")

            with patch("python.collect_books.urllib.request.urlopen") as opener:
                with self.assertRaisesRegex(RuntimeError, "no saved identity"):
                    download_dump(destination)

            opener.assert_not_called()
            self.assertEqual(partial.read_bytes(), b"abc")
            self.assertFalse(destination.exists())

    def test_initial_download_without_strong_etag_fails_before_writing(self):
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "editions.txt.gz"
            response = Response(
                b"abcdef",
                200,
                {"Content-Length": "6", "Last-Modified": LAST_MODIFIED},
            )

            with patch("python.collect_books.urllib.request.urlopen", return_value=response):
                with self.assertRaisesRegex(RuntimeError, "no strong ETag"):
                    download_dump(destination)

            partial = destination.with_suffix(destination.suffix + ".part")
            self.assertFalse(partial.exists())
            self.assertFalse(destination.exists())

    def test_explicit_bootstrap_checks_pinned_url_size_etag_and_bytes_then_resumes(self):
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "editions.txt.gz"
            partial = destination.with_suffix(destination.suffix + ".part")
            partial.write_bytes(b"abc")
            probe = Response(
                b"abc",
                206,
                {
                    "Content-Range": "bytes 0-2/6",
                    "Content-Length": "3",
                    "ETag": ETAG,
                    "Last-Modified": LAST_MODIFIED,
                },
            )
            remainder = Response(
                b"def",
                206,
                {
                    "Content-Range": "bytes 3-5/6",
                    "Content-Length": "3",
                    "ETag": ETAG,
                    "Last-Modified": LAST_MODIFIED,
                },
            )
            with patch(
                "python.collect_books.urllib.request.urlopen",
                side_effect=[probe, remainder],
            ) as opener:
                identity_path = pin_partial_identity(destination, DATED_URL, 6, ETAG)
                download_dump(destination)

            self.assertTrue(identity_path.is_file())
            self.assertEqual(destination.read_bytes(), b"abcdef")
            self.assertFalse(partial.exists())
            self.assertEqual(opener.call_count, 2)
            resume_headers = {
                name.lower(): value
                for name, value in opener.call_args_list[1].args[0].header_items()
            }
            self.assertEqual(resume_headers["range"], "bytes=3-")
            self.assertEqual(resume_headers["if-range"], ETAG)

    def test_bootstrap_rejects_latest_resolving_to_another_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "editions.txt.gz"
            partial = destination.with_suffix(destination.suffix + ".part")
            partial.write_bytes(b"abc")
            changed = Response(
                b"abc",
                206,
                {
                    "Content-Range": "bytes 0-2/6",
                    "Content-Length": "3",
                    "ETag": '"edition-v2"',
                    "Last-Modified": LAST_MODIFIED,
                },
                url="https://archive.example/ol_dump_editions_2026-10-01.txt.gz",
            )

            with patch("python.collect_books.urllib.request.urlopen", return_value=changed):
                with self.assertRaisesRegex(RuntimeError, "does not resolve"):
                    pin_partial_identity(destination, DATED_URL, 6, ETAG)

            self.assertEqual(partial.read_bytes(), b"abc")
            self.assertFalse(partial.with_name(partial.name + ".identity.json").exists())


if __name__ == "__main__":
    unittest.main()
