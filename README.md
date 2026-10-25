# Libris

Libris is a bilingual bookstore and lending library built with PHP, PDO, MySQL/MariaDB, Python, and plain HTML/CSS/JavaScript. It supports sale and loan copies, checkout with simulated payment, account history, reservations, reviews, wish lists, and a staff desk.

The interface is English by default and can switch to Kurdish Sorani (`ckb`) with right-to-left layout. Payments are simulated; the site does not collect payment credentials.

## Requirements

- PHP 8.1+ with PDO MySQL and `mbstring` extensions
- MySQL 8.0.16+ or MariaDB 10.4+ (the schema relies on enforced `CHECK` constraints)
- Python 3.10+
- No Composer or npm packages are required

## Local setup

1. Start a local MySQL/MariaDB instance you choose. This project does not start Windows services automatically.
2. Set `LIBRIS_DB_HOST`, `LIBRIS_DB_PORT`, `LIBRIS_DB_NAME`, `LIBRIS_DB_USER`, and `LIBRIS_DB_PASSWORD` in the shell that will run PHP. `config.php` uses local development defaults when variables are omitted.
3. Import the schema with the MySQL command-line client. The schema uses `DELIMITER` for its triggers. From PowerShell, run the client through `cmd.exe` so CMD handles SQL-file redirection:

   ```powershell
   cmd.exe /d /c "mysql -u root -p < database\schema.sql"
   ```

4. The official Open Library editions dump was approximately 9.2 GB in the published documentation. The currently dated 2026-09-30 archive reports 12,617,475,043 bytes (about 12.6 GB compressed). Download it and generate a bounded 200-title seed:

   ```powershell
   python python/collect_books.py --download
   ```

   This writes `database/seed.sql` and keeps the source dump under `artifacts/downloads/`. Public cover URLs are used with the local placeholder fallback; cover images are not bulk-downloaded. You can also point the script at an existing plain-text or `.txt.gz` dump with `--dump PATH`.

To resume an incomplete archive without changing it, choose a new dump path. The importer copies the existing archive to a new `.part` file, resumes from its byte offset, and reads the completed gzip stream to EOF before writing SQL:

```powershell
python python/collect_books.py --download --dump artifacts/downloads/ol_dump_editions_recovered.txt.gz --resume-from artifacts/downloads/ol_dump_editions_latest.txt.gz
```

5. Import `database/seed.sql` into the same database. From PowerShell, run the client through `cmd.exe` so CMD handles SQL-file redirection:

   ```powershell
   cmd.exe /d /c "mysql -u root -p -D libris < database\seed.sql"
   ```

6. Create an account from the site, then promote that account to the first administrator from the database console:

   ```sql
   UPDATE users SET role = 'admin' WHERE email = 'your-account@example.test';
   ```

7. Start PHP's development server from the project root:

   ```powershell
   php -S 127.0.0.1:8080 -t .
   ```

8. Open `http://127.0.0.1:8080/`.

Do not expose PHP's built-in server to the public internet. Configure a production-grade web server and database before any deployment.

## Main routes

- `/` — storefront
- `/books/` — browse, search, and filter
- `/books/show.php?id=…` — title detail, availability, reviews, borrowing, and reservation
- `/cart/` and `/checkout.php` — bag and simulated checkout
- `/auth/register.php`, `/auth/login.php`, `/auth/logout.php` — customer authentication
- `/account/` — account overview, profile, addresses, loans, reservations, and wish list
- `/orders/` — customer order history
- `/admin/` — librarian/admin dashboard and management tools

## Catalogue importer

`python/collect_books.py` streams the official Open Library editions dump with bounded memory. By default it selects 20 titles for each of the ten configured shelves (200 titles total), emits one sale copy and one loan copy per title, and writes a configurable starter price of 15,000 IQD. Change the price with `--price-iqd`; use `--sale-copies` and `--loan-copies` to adjust initial copy counts. The importer refuses to overwrite an existing seed file.

## Verification

```powershell
php tests/php/domain_test.php
python -m unittest discover -s tests/python -v
php -l path\to\changed-file.php
node --check assets/js/main.js
node --check assets/js/i18n.js
```

Database integration and the full set of signed-in customer/staff workflows require a running MySQL/MariaDB database with the schema and seed imported.
