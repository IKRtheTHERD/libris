import io
import gzip
import json
import os
import tempfile
import unittest
from pathlib import Path

from unittest.mock import patch

from python.collect_books import CATEGORIES, collect_records, download_dump, emit_seed, open_dump, resume_dump, sql_text


def write_test_identity(partial: Path, total_size: int = 6) -> None:
    identity = {
        "format_version": 1,
        "request_url": "https://openlibrary.org/data/ol_dump_editions_latest.txt.gz",
        "response_url": "https://openlibrary.org/data/ol_dump_editions_latest.txt.gz",
        "total_size": total_size,
        "validator_header": "ETag",
        "validator_value": '"v1"',
        "etag": '"v1"',
        "last_modified": None,
    }
    identity_path = partial.with_name(partial.name + ".identity.json")
    identity_path.write_text(json.dumps(identity), encoding="utf-8")


class CollectBooksTests(unittest.TestCase):
    def make_dump(self):
        records = []
        subjects = {
            "mystery": "detective stories",
            "fantasy": "fantasy",
            "science-fiction": "science fiction",
            "romance": "romance",
            "history": "history",
            "biography": "biography",
            "science": "physics",
            "philosophy": "philosophy",
            "classics": "literary classics",
            "fiction": "fiction",
        }
        for index, (category, subject) in enumerate(subjects.items(), start=1):
            record = {
                "title": f"Sample {category.title()} {index}",
                "subjects": [subject],
                "isbn_13": [f"97800000000{index:03d}"],
                "authors": [{"key": f"/authors/OL{index}A"}],
                "works": [{"key": f"/works/OL{index}W"}],
                "number_of_pages": 180,
                "publish_date": "2001",
                "publishers": ["Example Press"],
            }
            records.append("\t".join(["/type/edition", f"/books/OL{index}M", "1", "2026-01-01", json.dumps(record)]))
        return io.StringIO("\n".join(records) + "\n")

    def test_selects_one_record_per_supported_category(self):
        records = collect_records(self.make_dump(), per_category=1)
        self.assertEqual(len(records), len(CATEGORIES))
        self.assertEqual({record["category"] for record in records}, set(CATEGORIES))
        self.assertTrue(all(record["cover_url"].startswith("https://covers.openlibrary.org/") for record in records))

    def test_selects_full_200_title_catalogue(self):
        editions = []
        for batch in range(20):
            for index, line in enumerate(self.make_dump().getvalue().splitlines(), start=1):
                fields = line.split("\t", 4)
                record = json.loads(fields[4])
                record["title"] += f" {batch + 1}"
                fields[1] = f"/books/OL{batch * len(CATEGORIES) + index}M"
                fields[4] = json.dumps(record)
                editions.append("\t".join(fields))

        records = collect_records(io.StringIO("\n".join(editions)), per_category=20)
        self.assertEqual(len(records), 200)
        self.assertEqual(
            {category: sum(record["category"] == category for record in records) for category in CATEGORIES},
            {category: 20 for category in CATEGORIES},
        )

    def test_collect_records_rejects_selection_over_the_200_title_cap(self):
        with self.assertRaises(ValueError):
            collect_records(io.StringIO(""), per_category=21)

    def test_sql_text_quotes_delimiters_and_removes_line_breaks(self):
        self.assertEqual(sql_text("D'Arcy\nBook"), "'D''Arcy Book'")

    def test_generated_sql_preserves_quotes_backslashes_and_normalizes_controls(self):
        records = collect_records(self.make_dump(), per_category=1)
        title = "D'Arcy\\Book\x00\r\nSemi; --"
        records[0]["title"] = title

        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "seed.sql"
            emit_seed(records, output)

            sql = output.read_text(encoding="utf-8")
            self.assertIn("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES';", sql)
            self.assertIn(sql_text(title), sql)
            self.assertNotIn("\x00", sql)

    def test_emits_seed_copies_and_refuses_to_replace_output(self):
        records = collect_records(self.make_dump(), per_category=1)
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "seed.sql"
            emit_seed(records, output, sale_copies=1, loan_copies=1)
            sql = output.read_text(encoding="utf-8")
            self.assertIn("INSERT INTO book_copies", sql)
            self.assertIn("'sale'", sql)
            self.assertIn("'loan'", sql)
            self.assertNotIn("ON DUPLICATE KEY UPDATE title", sql)
            self.assertIn("AND NOT EXISTS (SELECT 1 FROM book_copies", sql)
            with self.assertRaises(FileExistsError):
                emit_seed(records, output)

    def test_emit_seed_refuses_to_replace_a_destination_created_before_publish(self):
        records = collect_records(self.make_dump(), per_category=1)
        real_link = os.link

        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "seed.sql"
            staged = output.with_suffix(output.suffix + ".tmp")
            existing_bytes = b"created by a concurrent writer"

            def create_destination_then_link(source, destination):
                Path(destination).write_bytes(existing_bytes)
                return real_link(source, destination)

            with patch("python.collect_books.os.link", side_effect=create_destination_then_link):
                with self.assertRaises(FileExistsError):
                    emit_seed(records, output)

            self.assertEqual(output.read_bytes(), existing_bytes)
            self.assertFalse(staged.exists())

    def test_emit_seed_refuses_to_replace_an_existing_staging_file(self):
        records = collect_records(self.make_dump(), per_category=1)

        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "seed.sql"
            staged = output.with_suffix(output.suffix + ".tmp")
            staged.write_bytes(b"keep this staging file")

            with self.assertRaises(FileExistsError):
                emit_seed(records, output)

            self.assertEqual(staged.read_bytes(), b"keep this staging file")
            self.assertFalse(output.exists())

    def test_collect_records_reads_gzip_to_eof_after_finding_all_requested_titles(self):
        records = self.make_dump().getvalue().encode("utf-8")
        with tempfile.TemporaryDirectory() as temporary:
            dump = Path(temporary) / "truncated.txt.gz"
            import gzip
            dump.write_bytes(gzip.compress(records)[:-8])

            with open_dump(dump) as stream:
                with self.assertRaises(EOFError):
                    collect_records(stream, per_category=1)

    def test_resumes_an_existing_archive_into_a_new_path_and_preserves_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "incomplete.txt.gz"
            destination = Path(temporary) / "recovered.txt.gz"
            source.write_bytes(b"abc")
            write_test_identity(source)

            class Response(io.BytesIO):
                status = 206
                headers = {"ETag": '"v1"', "Content-Range": "bytes 3-5/6"}

            with patch("python.collect_books.urllib.request.urlopen", return_value=Response(b"def")) as opener:
                resume_dump(source, destination)

            self.assertEqual(source.read_bytes(), b"abc")
            self.assertEqual(destination.read_bytes(), b"abcdef")
            self.assertEqual(opener.call_args.args[0].get_header("Range"), "bytes=3-")

    def test_failed_recovery_keeps_source_and_resumable_partial(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "incomplete.txt.gz"
            destination = Path(temporary) / "recovered.txt.gz"
            source.write_bytes(b"abc")
            write_test_identity(source)

            class Response(io.BytesIO):
                status = 206
                headers = {"ETag": '"v1"', "Content-Range": "bytes 3-5/6", "Content-Length": "3"}

            with patch("python.collect_books.urllib.request.urlopen", return_value=Response(b"d")):
                with self.assertRaisesRegex(RuntimeError, "response was incomplete"):
                    resume_dump(source, destination)

            self.assertEqual(source.read_bytes(), b"abc")
            partial = destination.with_suffix(destination.suffix + ".part")
            self.assertEqual(partial.read_bytes(), b"abcd")
            self.assertFalse(destination.exists())

    def test_full_seed_sql_is_repeatable_and_contains_200_books(self):
        editions = []
        for batch in range(20):
            for index, line in enumerate(self.make_dump().getvalue().splitlines(), start=1):
                fields = line.split("\t", 4)
                record = json.loads(fields[4])
                record["title"] += f" {batch + 1}"
                fields[1] = f"/books/OL{batch * len(CATEGORIES) + index}M"
                fields[4] = json.dumps(record)
                editions.append("\t".join(fields))
        records = collect_records(io.StringIO("\n".join(editions)), per_category=20)

        with tempfile.TemporaryDirectory() as temporary:
            first = Path(temporary) / "first.sql"
            second = Path(temporary) / "second.sql"
            emit_seed(records, first)
            emit_seed(records, second)
            first_sql = first.read_text(encoding="utf-8")
            self.assertEqual(first_sql, second.read_text(encoding="utf-8"))
            self.assertEqual(sum(line.startswith("INSERT INTO books ") for line in first_sql.splitlines()), 200)

    def test_refuses_to_recover_over_existing_destination_or_partial(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "incomplete.txt.gz"
            destination = Path(temporary) / "recovered.txt.gz"
            source.write_bytes(b"source")
            destination.write_bytes(b"keep")
            with self.assertRaises(FileExistsError):
                resume_dump(source, destination)
            self.assertEqual(destination.read_bytes(), b"keep")

            destination.unlink()
            partial = destination.with_suffix(destination.suffix + ".part")
            partial.write_bytes(b"keep partial")
            with self.assertRaises(FileExistsError):
                resume_dump(source, destination)
            self.assertEqual(partial.read_bytes(), b"keep partial")


    def test_download_resumes_partial_output(self):
        with tempfile.TemporaryDirectory() as temporary:
            destination = Path(temporary) / "editions.txt.gz"
            partial = destination.with_suffix(destination.suffix + ".part")
            partial.write_bytes(b"abc")
            write_test_identity(partial)

            class Response(io.BytesIO):
                status = 206
                headers = {"ETag": '"v1"', "Content-Range": "bytes 3-5/6"}

            with patch("python.collect_books.urllib.request.urlopen", return_value=Response(b"def")) as opener:
                download_dump(destination)

            self.assertEqual(destination.read_bytes(), b"abcdef")
            self.assertEqual(opener.call_args.args[0].get_header("Range"), "bytes=3-")


    def test_download_rejects_nonterminal_range(self):
        with tempfile.TemporaryDirectory() as temporary:
            destination = Path(temporary) / "editions.txt.gz"
            partial = destination.with_suffix(destination.suffix + ".part")
            partial.write_bytes(b"abc")
            write_test_identity(partial)

            class Response(io.BytesIO):
                status = 206
                headers = {"ETag": '"v1"', "Content-Range": "bytes 3-4/6", "Content-Length": "2"}

            with patch("python.collect_books.urllib.request.urlopen", return_value=Response(b"de")):
                with self.assertRaisesRegex(RuntimeError, "incomplete byte range"):
                    download_dump(destination)

            self.assertEqual(partial.read_bytes(), b"abc")
            self.assertFalse(destination.exists())

    def test_download_preserves_truncated_resumed_response(self):
        with tempfile.TemporaryDirectory() as temporary:
            destination = Path(temporary) / "editions.txt.gz"
            partial = destination.with_suffix(destination.suffix + ".part")
            partial.write_bytes(b"abc")
            write_test_identity(partial)

            class Response(io.BytesIO):
                status = 206
                headers = {"ETag": '"v1"', "Content-Range": "bytes 3-5/6", "Content-Length": "3"}

            with patch("python.collect_books.urllib.request.urlopen", return_value=Response(b"d")):
                with self.assertRaisesRegex(RuntimeError, "response was incomplete"):
                    download_dump(destination)

            self.assertEqual(partial.read_bytes(), b"abcd")
            self.assertFalse(destination.exists())


if __name__ == "__main__":
    unittest.main()
