#!/usr/bin/env python3
"""Build a 200-title Libris starter catalogue from Open Library's editions dump."""

from __future__ import annotations

import argparse
import gzip
import json
import os
import re
import sys
import urllib.request
from pathlib import Path
from typing import IO, Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DUMP = ROOT / "artifacts" / "downloads" / "ol_dump_editions_latest.txt.gz"
DEFAULT_OUTPUT = ROOT / "database" / "seed.sql"
DUMP_URL = "https://openlibrary.org/data/ol_dump_editions_latest.txt.gz"

CATEGORIES: dict[str, tuple[str, ...]] = {
    "mystery": ("mystery", "detective", "crime fiction", "thriller", "suspense"),
    "fantasy": ("fantasy", "fairy tales", "magic", "mythical", "dragons"),
    "science-fiction": ("science fiction", "sci-fi", "space fiction", "dystopian", "time travel"),
    "romance": ("romance", "romantic fiction", "love stories", "love story"),
    "history": ("history", "historical", "ancient history", "world war", "medieval"),
    "biography": ("biography", "autobiography", "memoir", "personal narratives", "diaries"),
    "science": ("science", "physics", "biology", "chemistry", "astronomy", "mathematics", "technology"),
    "philosophy": ("philosophy", "ethics", "metaphysics", "logic", "existentialism"),
    "classics": ("classics", "classic literature", "literary classics", "greek literature", "roman literature"),
    "fiction": ("fiction", "novel", "short stories", "literary fiction"),
}


def sql_text(value: str | None) -> str:
    """Quote bounded text as a portable SQL string literal."""
    text = (value or "").replace("\x00", " ").replace("\r", " ").replace("\n", " ").strip()
    return "'" + text.replace("'", "''") + "'"


def sql_number(value: int | None) -> str:
    return "NULL" if value is None else str(value)


def first_text(value: Any) -> str | None:
    if isinstance(value, str):
        return value.strip() or None
    if isinstance(value, dict):
        return first_text(value.get("value"))
    if isinstance(value, list):
        for item in value:
            text = first_text(item)
            if text:
                return text
    return None


def classify(record: dict[str, Any]) -> str | None:
    title = first_text(record.get("title")) or ""
    subjects = record.get("subjects", [])
    if isinstance(subjects, str):
        subjects = [subjects]
    if not isinstance(subjects, list):
        subjects = []
    searchable = " ".join([title, *(str(subject) for subject in subjects)]).casefold()
    for category, terms in CATEGORIES.items():
        if any(term in searchable for term in terms):
            return category
    return None


def open_dump(path: Path) -> IO[str]:
    if path.suffix.lower() == ".gz":
        return gzip.open(path, "rt", encoding="utf-8", errors="replace")
    return path.open("rt", encoding="utf-8", errors="replace")


def normalize_record(key: str, record: dict[str, Any], category: str, price_iqd: int) -> dict[str, Any] | None:
    title = first_text(record.get("title"))
    if not title or len(title) > 300:
        return None
    isbn_values = record.get("isbn_13") or record.get("isbn_10") or []
    if not isinstance(isbn_values, list):
        isbn_values = [isbn_values]
    isbn13 = next((re.sub(r"\D", "", str(value)) for value in isbn_values if len(re.sub(r"\D", "", str(value))) == 13), None)
    page_count = record.get("number_of_pages")
    if not isinstance(page_count, int) or not 1 <= page_count <= 65535:
        page_count = None
    published = first_text(record.get("publish_date")) or ""
    year_match = re.search(r"(?:^|\D)(1[0-9]{3}|20[0-9]{2}|2100)(?:\D|$)", published)
    year = int(year_match.group(1)) if year_match else None
    work_id = None
    works = record.get("works")
    if isinstance(works, list) and works and isinstance(works[0], dict):
        work_id = str(works[0].get("key", "")).rsplit("/", 1)[-1]
        if not re.fullmatch(r"OL[0-9]+W", work_id):
            work_id = None
    edition_id = key.rsplit("/", 1)[-1]
    cover_id = edition_id if re.fullmatch(r"OL[0-9]+M", edition_id) else work_id
    cover_url = f"https://covers.openlibrary.org/b/olid/{cover_id}-L.jpg" if cover_id else None
    descriptions = first_text(record.get("description"))
    publishers = record.get("publishers") or []
    publisher = first_text(publishers)
    author_records = record.get("authors") or []
    authors: list[tuple[str, str]] = []
    if isinstance(author_records, list):
        for author in author_records:
            if not isinstance(author, dict):
                continue
            author_id = str(author.get("key", "")).rsplit("/", 1)[-1]
            if not re.fullmatch(r"OL[0-9]+A", author_id):
                continue
            name = first_text(author.get("name")) or f"Open Library author {author_id}"
            authors.append((author_id, name[:200]))
    language_code = "en"
    languages = record.get("languages")
    if isinstance(languages, list) and languages and isinstance(languages[0], dict):
        candidate = str(languages[0].get("key", "")).rsplit("/", 1)[-1]
        if re.fullmatch(r"[a-z]{3}", candidate):
            language_code = candidate
    return {
        "id": edition_id,
        "title": title,
        "description": descriptions[:20000] if descriptions else None,
        "language": language_code,
        "isbn13": isbn13,
        "cover_url": cover_url,
        "year": year,
        "publisher": publisher[:200] if publisher else None,
        "page_count": page_count,
        "price": price_iqd,
        "category": category,
        "authors": authors,
    }


def collect_records(stream: IO[str], per_category: int = 20, price_iqd: int = 15000) -> list[dict[str, Any]]:
    if per_category < 1 or per_category * len(CATEGORIES) > 200:
        raise ValueError("The requested selection must be from 1 to 200 total titles.")
    selected: dict[str, list[dict[str, Any]]] = {name: [] for name in CATEGORIES}
    seen_editions: set[str] = set()
    for line_number, line in enumerate(stream, 1):
        if line_number % 1_000_000 == 0:
            selected_count = sum(len(records) for records in selected.values())
            print(f"Read {line_number:,} edition records; selected {selected_count}.", file=sys.stderr)
        fields = line.rstrip("\n").split("\t", 4)
        if len(fields) != 5 or fields[0] != "/type/edition":
            continue
        key = fields[1]
        if key in seen_editions:
            continue
        try:
            record = json.loads(fields[4])
        except json.JSONDecodeError:
            continue
        if not isinstance(record, dict):
            continue
        category = classify(record)
        if category is None or len(selected[category]) >= per_category:
            continue
        normalized = normalize_record(key, record, category, price_iqd)
        if normalized is None:
            continue
        selected[category].append(normalized)
        seen_editions.add(key)
    missing = {category: per_category - len(records) for category, records in selected.items() if len(records) < per_category}
    if missing:
        summary = ", ".join(f"{category}: {count} more" for category, count in missing.items())
        raise ValueError(f"The dump did not contain enough categorized editions ({summary}).")
    return [record for records in selected.values() for record in records]


def emit_seed(records: list[dict[str, Any]], destination: Path, sale_copies: int = 1, loan_copies: int = 1) -> None:
    if len(records) > 200 or sale_copies < 0 or loan_copies < 0 or sale_copies + loan_copies == 0:
        raise ValueError("Seed size must not exceed 200 and each title must have at least one copy.")
    if destination.exists():
        raise FileExistsError(f"Refusing to replace existing seed output: {destination}")
    lines = [
        "-- Generated from the Open Library editions dump; see https://openlibrary.org/developers/dumps",
        "SET NAMES utf8mb4;",
        "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES';",
        "START TRANSACTION;",
        "INSERT INTO categories (slug, name_en, name_ckb) VALUES",
        "('fiction','Fiction','ئەدەبیات'),('mystery','Mystery','نهێنی'),('fantasy','Fantasy','خەیاڵی'),",
        "('science-fiction','Science Fiction','زانستی خەیاڵی'),('romance','Romance','ڕۆمانسی'),('history','History','مێژوو'),",
        "('biography','Biography','ژیاننامە'),('science','Science','زانست'),('philosophy','Philosophy','فەلسەفە'),('classics','Classics','کلاسیک')",
        "ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_ckb = VALUES(name_ckb);",
    ]
    for book in records:
        title_id = book["id"]
        book_key = sql_text(title_id)
        lines.append(
            "INSERT INTO books (title, description, language_code, isbn13, open_library_id, cover_url, published_year, publisher, page_count, sale_price_iqd, is_active) SELECT "
            + ", ".join([
                sql_text(book["title"]), sql_text(book["description"]), sql_text(book["language"]),
                sql_text(book["isbn13"]), book_key, sql_text(book["cover_url"]), sql_number(book["year"]),
                sql_text(book["publisher"]), sql_number(book["page_count"]), sql_number(book["price"]), "1",
            ])
            + " FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM books WHERE open_library_id = " + book_key + ")"
            + (" AND NOT EXISTS (SELECT 1 FROM books WHERE isbn13 = " + sql_text(book["isbn13"]) + ")" if book["isbn13"] else "") + ";"
        )
        lines.append(
            "INSERT INTO book_categories (book_id, category_id) SELECT b.id, c.id FROM books b JOIN categories c ON c.slug = "
            + sql_text(book["category"]) + " WHERE b.open_library_id = " + book_key
            + " ON DUPLICATE KEY UPDATE category_id = VALUES(category_id);"
        )
        for author_id, author_name in book["authors"]:
            author_key = sql_text(author_id)
            lines.append("INSERT IGNORE INTO authors (name, open_library_id) VALUES (" + sql_text(author_name) + ", " + author_key + ");")
            lines.append(
                "INSERT INTO book_authors (book_id, author_id, author_order) SELECT b.id, a.id, 1 FROM books b JOIN authors a ON a.open_library_id = "
                + author_key + " WHERE b.open_library_id = " + book_key
                + " ON DUPLICATE KEY UPDATE author_order = VALUES(author_order);"
            )
        for purpose, count in (("sale", sale_copies), ("loan", loan_copies)):
            for sequence in range(1, count + 1):
                inventory_code = f"OL-{title_id}-{purpose[0].upper()}{sequence}"
                lines.append(
                    "INSERT INTO book_copies (book_id, inventory_code, purpose, status) SELECT b.id, "
                    + sql_text(inventory_code) + ", " + sql_text(purpose) + ", 'available' FROM books b WHERE b.open_library_id = "
                    + book_key + " AND NOT EXISTS (SELECT 1 FROM book_copies c WHERE c.inventory_code = " + sql_text(inventory_code) + ");"
                )
    lines.append("COMMIT;")
    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = destination.with_suffix(destination.suffix + ".tmp")
    if temporary.exists():
        raise FileExistsError(f"Refusing to overwrite existing temporary output: {temporary}")
    owns_temporary = False
    try:
        with temporary.open("x", encoding="utf-8", newline="\n") as output:
            owns_temporary = True
            output.write("\n".join(lines) + "\n")
            output.flush()
            os.fsync(output.fileno())
        os.link(temporary, destination)
    finally:
        if owns_temporary:
            temporary.unlink(missing_ok=True)


def _partial_path(destination: Path) -> Path:
    return destination.with_suffix(destination.suffix + ".part")


def _identity_path(partial: Path) -> Path:
    return partial.with_name(partial.name + ".identity.json")


def _response_url(response: Any) -> str:
    geturl = getattr(response, "geturl", None)
    return geturl() if callable(geturl) else DUMP_URL


def _validator(headers: Any) -> tuple[str, str]:
    etag = (headers.get("ETag") or "").strip()
    if etag and not etag.lower().startswith("w/"):
        return "ETag", etag
    raise RuntimeError("Archive has no strong ETag; refusing a download that cannot be safely resumed.")


def _identity_from_response(response: Any, total_size: int) -> dict[str, Any]:
    header_name, header_value = _validator(response.headers)
    return {
        "format_version": 1,
        "request_url": DUMP_URL,
        "response_url": _response_url(response),
        "total_size": total_size,
        "validator_header": header_name,
        "validator_value": header_value,
        "etag": response.headers.get("ETag"),
        "last_modified": response.headers.get("Last-Modified"),
    }


def _write_identity(path: Path, identity: dict[str, Any]) -> None:
    with path.open("x", encoding="utf-8", newline="\n") as output:
        json.dump(identity, output, ensure_ascii=False, sort_keys=True)
        output.write("\n")
        output.flush()
        os.fsync(output.fileno())


def _load_identity(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise RuntimeError(
            "Partial archive has no saved identity; refusing to append. "
            "Use --pin-partial with the verified dated archive URL, size, and ETag."
        )
    try:
        identity = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise RuntimeError("Partial archive identity is unreadable; refusing to append.") from error
    if (
        not isinstance(identity, dict)
        or identity.get("format_version") != 1
        or identity.get("request_url") != DUMP_URL
        or not isinstance(identity.get("response_url"), str)
        or not identity["response_url"]
        or not isinstance(identity.get("total_size"), int)
        or identity["total_size"] <= 0
        or identity.get("validator_header") != "ETag"
        or not isinstance(identity.get("validator_value"), str)
        or not identity["validator_value"]
    ):
        raise RuntimeError("Partial archive identity is invalid; refusing to append.")
    if identity["validator_value"].lower().startswith("w/"):
        raise RuntimeError("Partial archive identity uses a weak ETag; refusing to append.")
    return identity


def _assert_response_matches_identity(response: Any, identity: dict[str, Any]) -> None:
    if _response_url(response) != identity["response_url"]:
        raise RuntimeError("Archive URL changed since the partial download; partial data was preserved.")
    if response.headers.get(identity["validator_header"]) != identity["validator_value"]:
        raise RuntimeError("Archive validator changed since the partial download; partial data was preserved.")
    if identity.get("etag") is not None and response.headers.get("ETag") != identity["etag"]:
        raise RuntimeError("Archive ETag changed since the partial download; partial data was preserved.")
    if identity.get("last_modified") is not None and response.headers.get("Last-Modified") != identity["last_modified"]:
        raise RuntimeError("Archive Last-Modified changed since the partial download; partial data was preserved.")


def _parse_range(content_range: str) -> tuple[int, int, int]:
    match = re.fullmatch(r"bytes (\d+)-(\d+)/(\d+)", content_range)
    if not match:
        raise RuntimeError("Archive returned an invalid byte range; partial data preserved.")
    return tuple(int(value) for value in match.groups())


def pin_partial_identity(
    destination: Path,
    expected_url: str,
    expected_size: int,
    expected_etag: str,
) -> Path:
    """Bootstrap identity for an explicitly pinned legacy partial after byte probes."""
    partial = _partial_path(destination)
    if destination.exists():
        raise FileExistsError("Dump destination already exists; preserve it and choose another path.")
    if not partial.is_file():
        raise FileNotFoundError(f"Legacy partial not found: {partial}")
    if not expected_url or expected_size <= 0 or not expected_etag:
        raise ValueError("Expected archive URL, positive size, and strong ETag are required.")
    if expected_etag.lower().startswith("w/"):
        raise ValueError("A strong ETag is required to pin a legacy partial.")
    identity_path = _identity_path(partial)
    if identity_path.exists():
        raise FileExistsError(f"Refusing to replace existing identity file: {identity_path}")

    initial_stat = partial.stat()
    partial_size = initial_stat.st_size
    if partial_size <= 0 or partial_size >= expected_size:
        raise ValueError("Legacy partial size must be positive and smaller than the pinned archive.")
    sample_size = min(64 * 1024, partial_size)
    ranges = [(0, sample_size - 1)]
    tail_range = (partial_size - sample_size, partial_size - 1)
    if tail_range not in ranges:
        ranges.append(tail_range)

    local_samples: dict[tuple[int, int], bytes] = {}
    with partial.open("rb") as source:
        for start, end in ranges:
            source.seek(start)
            local_samples[(start, end)] = source.read(end - start + 1)

    response_headers: Any = None
    response_url = ""
    for start, end in ranges:
        request = urllib.request.Request(
            DUMP_URL,
            headers={
                "User-Agent": "LibrisCatalogSeeder/1.0 (local catalog import)",
                "Range": f"bytes={start}-{end}",
            },
        )
        with urllib.request.urlopen(request, timeout=60) as response:
            status = getattr(response, "status", 200)
            if status != 206:
                raise RuntimeError("Pinned archive probe did not return HTTP 206; partial data was preserved.")
            actual_start, actual_end, actual_total = _parse_range(response.headers.get("Content-Range", ""))
            if (actual_start, actual_end, actual_total) != (start, end, expected_size):
                raise RuntimeError("Pinned archive range or size did not match the expected identity.")
            actual_url = _response_url(response)
            if actual_url != expected_url:
                raise RuntimeError("Current Open Library latest URL does not resolve to the expected dated archive.")
            actual_etag = response.headers.get("ETag")
            if actual_etag != expected_etag or not actual_etag or actual_etag.lower().startswith("w/"):
                raise RuntimeError("Current archive ETag does not match the expected pinned ETag.")
            content_length = response.headers.get("Content-Length")
            if content_length is not None and int(content_length) != end - start + 1:
                raise RuntimeError("Pinned archive probe returned an inconsistent Content-Length.")
            data = response.read(end - start + 2)
            if len(data) != end - start + 1:
                raise RuntimeError("Pinned archive probe returned an incomplete byte range.")
            if data != local_samples[(start, end)]:
                raise RuntimeError("Legacy partial bytes do not match the pinned Open Library archive.")
            response_headers = response.headers
            response_url = actual_url

    final_stat = partial.stat()
    if (
        final_stat.st_size != initial_stat.st_size
        or final_stat.st_mtime_ns != initial_stat.st_mtime_ns
        or destination.exists()
    ):
        raise RuntimeError("Legacy partial changed during identity checks; no identity was written.")

    assert response_headers is not None
    identity = _identity_from_response(
        type("IdentityResponse", (), {"headers": response_headers, "geturl": lambda self: response_url})(),
        expected_size,
    )
    if identity["etag"] != expected_etag:
        raise RuntimeError("Pinned ETag was not retained in the archive identity.")
    _write_identity(identity_path, identity)
    return identity_path


def download_dump(destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    partial = _partial_path(destination)
    identity_path = _identity_path(partial)
    completed_identity_path = destination.with_name(destination.name + ".identity.json")
    if destination.exists():
        raise FileExistsError("Dump destination already exists; preserve it and choose another path.")
    if completed_identity_path.exists():
        raise FileExistsError(f"Refusing to replace existing identity file: {completed_identity_path}")

    resuming = partial.exists()
    offset = partial.stat().st_size if resuming else 0
    identity: dict[str, Any] | None = None
    if resuming:
        identity = _load_identity(identity_path)
        headers = {
            "User-Agent": "LibrisCatalogSeeder/1.0 (local catalog import)",
            "Range": f"bytes={offset}-",
            "If-Range": identity["validator_value"],
        }
    else:
        if identity_path.exists():
            raise FileExistsError(f"Identity exists without a partial archive: {identity_path}")
        headers = {"User-Agent": "LibrisCatalogSeeder/1.0 (local catalog import)"}

    request = urllib.request.Request(DUMP_URL, headers=headers)
    downloaded = 0
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            status = getattr(response, "status", 200)
            expected_bytes: int | None = None
            if resuming:
                assert identity is not None
                if status != 206:
                    raise RuntimeError("Archive did not honor the pinned resume validator; partial data preserved.")
                _assert_response_matches_identity(response, identity)
                range_start, range_end, archive_size = _parse_range(response.headers.get("Content-Range", ""))
                if range_start != offset or range_end < offset or range_end >= archive_size:
                    raise RuntimeError("Archive returned an invalid byte range; partial data preserved.")
                if archive_size != identity["total_size"]:
                    raise RuntimeError("Archive size changed since the partial download; partial data was preserved.")
                if range_end + 1 != archive_size:
                    raise RuntimeError("Archive returned an incomplete byte range; partial data was preserved for resume.")
                expected_bytes = range_end - offset + 1
            elif status == 200:
                content_length = response.headers.get("Content-Length")
                if content_length is None:
                    raise RuntimeError("Archive omitted Content-Length; refusing an unpinnable download.")
                try:
                    expected_bytes = int(content_length)
                except ValueError as error:
                    raise RuntimeError("Archive returned an invalid Content-Length; partial data preserved.") from error
                if expected_bytes <= 0:
                    raise RuntimeError("Archive returned an invalid Content-Length; partial data preserved.")
                identity = _identity_from_response(response, expected_bytes)
            else:
                raise RuntimeError(f"Archive returned unexpected HTTP status {status}.")

            content_length = response.headers.get("Content-Length")
            if content_length is not None:
                try:
                    advertised_response_bytes = int(content_length)
                except ValueError as error:
                    raise RuntimeError("Archive returned an invalid Content-Length; partial data preserved.") from error
                if advertised_response_bytes < 0:
                    raise RuntimeError("Archive returned an invalid Content-Length; partial data preserved.")
                if expected_bytes is not None and advertised_response_bytes != expected_bytes:
                    raise RuntimeError("Archive Content-Length did not match its byte range; partial data preserved.")

            mode = "ab" if resuming else "xb"
            with partial.open(mode) as output:
                if not resuming:
                    assert identity is not None
                    _write_identity(identity_path, identity)
                while chunk := response.read(8 * 1024 * 1024):
                    output.write(chunk)
                    output.flush()
                    downloaded += len(chunk)
                    if downloaded % (256 * 1024 * 1024) < len(chunk):
                        print(f"Downloaded {(offset + downloaded) / (1024 ** 3):.2f} GiB…", file=sys.stderr, flush=True)
            if expected_bytes is not None and downloaded != expected_bytes:
                raise RuntimeError(
                    f"Archive response was incomplete: received {downloaded} of {expected_bytes} bytes; partial data was preserved."
                )
            os.rename(partial, destination)
    except Exception:
        print(f"Download stopped; partial data preserved at {partial}", file=sys.stderr)
        raise


def resume_dump(source: Path, destination: Path) -> None:
    """Resume a known partial into a new path, preserving both source and its identity."""
    source = source.resolve()
    destination = destination.resolve()
    if source == destination:
        raise ValueError("Recovery destination must differ from the preserved source archive.")
    if not source.is_file():
        raise FileNotFoundError(f"Recovery source is not a file: {source}")
    if destination.exists():
        raise FileExistsError(f"Refusing to replace recovery destination: {destination}")
    partial = _partial_path(destination)
    if partial.exists():
        raise FileExistsError(f"Refusing to replace recovery partial: {partial}")
    source_identity_path = _identity_path(source)
    identity = _load_identity(source_identity_path)
    if source.stat().st_size >= identity["total_size"]:
        raise ValueError("Recovery source is not an incomplete archive.")
    identity_path = _identity_path(partial)
    if identity_path.exists():
        raise FileExistsError(f"Refusing to replace recovery identity: {identity_path}")

    destination.parent.mkdir(parents=True, exist_ok=True)
    with source.open("rb") as source_file, partial.open("xb") as partial_file:
        while chunk := source_file.read(8 * 1024 * 1024):
            partial_file.write(chunk)
    _write_identity(identity_path, identity)
    download_dump(destination)

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dump", type=Path, default=DEFAULT_DUMP, help="Local Open Library editions .txt.gz or .txt file")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT, help="Generated SQL output path")
    parser.add_argument("--download", action="store_true", help="Download the official editions dump when absent (the latest archive reported 12.6 GB compressed)")
    parser.add_argument("--resume-from", type=Path, help="Resume an incomplete archive into --dump without changing the source")
    parser.add_argument("--pin-partial", action="store_true", help="Verify and pin an explicitly identified legacy --dump.part archive before resuming")
    parser.add_argument("--expected-archive-url", help="Exact dated response URL expected by --pin-partial")
    parser.add_argument("--expected-archive-size", type=int, help="Exact archive size in bytes expected by --pin-partial")
    parser.add_argument("--expected-archive-etag", help="Exact strong ETag expected by --pin-partial")
    parser.add_argument("--per-category", type=int, default=20, help="Number of titles selected in each configured shelf (default: 20)")
    parser.add_argument("--price-iqd", type=int, default=15000, help="Starter sale price in IQD; update in the librarian desk")
    parser.add_argument("--sale-copies", type=int, default=1)
    parser.add_argument("--loan-copies", type=int, default=1)
    args = parser.parse_args()
    expected_identity = (args.expected_archive_url, args.expected_archive_size, args.expected_archive_etag)
    if args.pin_partial:
        if args.download or args.resume_from:
            parser.error("--pin-partial cannot be combined with --download or --resume-from")
        if not all(expected_identity):
            parser.error("--pin-partial requires --expected-archive-url, --expected-archive-size, and --expected-archive-etag")
        try:
            identity_path = pin_partial_identity(
                args.dump,
                args.expected_archive_url,
                args.expected_archive_size,
                args.expected_archive_etag,
            )
        except (FileNotFoundError, FileExistsError, RuntimeError, ValueError) as error:
            parser.error(str(error))
        print(f"Pinned legacy partial identity at {identity_path}")
        return 0
    if any(value is not None for value in expected_identity):
        parser.error("Expected archive identity options require --pin-partial")
    if args.resume_from and not args.download:
        parser.error("--resume-from requires --download")
    if not 0 <= args.price_iqd <= 2_147_483_647:
        parser.error("--price-iqd must be between 0 and 2,147,483,647")
    if args.resume_from:
        resume_dump(args.resume_from, args.dump)
    elif args.download and not args.dump.exists():
        download_dump(args.dump)
    if not args.dump.is_file():
        parser.error(f"Edition dump not found: {args.dump}. Use --download to fetch the official dump.")
    with open_dump(args.dump) as stream:
        records = collect_records(stream, args.per_category, args.price_iqd)
    emit_seed(records, args.output, args.sale_copies, args.loan_copies)
    print(f"Wrote {len(records)} titles across {len(CATEGORIES)} shelves to {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
