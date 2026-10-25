# Libris: Online Bookstore + Library

## 1. Project idea (from the owner)

A big, professional project that I will keep working on until my 4th year of college (I am in my 3rd year now).

It is an **online bookstore and library** in one website, built with **HTML, CSS, JavaScript, PHP and Python**.

What I want:

- A library limited to **200 books**.
- A **very big, professional database**.
- A website with **very nice movement** (smooth animations).
- Every book has **its own picture** (cover).
- Everything a real bookstore and library has in real life: **ordering books, borrowing books**, and more.
- The website is in **English**, with a **button that switches all the content to Kurdish Sorani** (right-to-left).

---

## 2. Overview

Libris has two sides:

- **Customer side:** browse, search, buy, borrow, reserve, review.
- **Admin / librarian side:** manage books, orders, loans, users and see statistics.

Every title can have several physical copies. Some copies are for **sale** and some are for **borrowing**, just like in a real shop that is also a library.

---

## 3. Tech stack

| Part | Tool | Job |
|---|---|---|
| Frontend | HTML, CSS, vanilla JavaScript (GSAP optional later) | Pages, layout, smooth animations, language switch |
| Backend | PHP (PDO) | Login, cart, orders, borrowing, admin API |
| Database | MySQL / MariaDB | All the data |
| Python | Scripts | Fill the database with 200 books and covers (Open Library API), reports, later recommendations |
| Local server | XAMPP | Runs PHP and MySQL on the PC |

---

## 4. Language support (English + Kurdish Sorani)

- Default language: **English**.
- A button in the header switches the whole site to **Kurdish Sorani (`ckb`)**, and back.
- Sorani is right-to-left, so the page direction changes to `rtl` automatically.
- The chosen language is remembered in the browser (`localStorage`) and later in the user's profile (`users.preferred_lang`).
- Interface texts live in one dictionary file (`assets/js/i18n.js`); the HTML uses `data-i18n="key"`.
- Database content has both languages where it matters (for example `categories.name_en` and `categories.name_ckb`).
- CSS uses logical properties (`margin-inline`, `padding-inline`) so the layout flips correctly.
- A Sorani-friendly font (Noto Sans Arabic) is used when the language is Sorani.

---

## 5. Database design

**People**
- `users` (role: customer / librarian / admin, preferred language)
- `addresses`

**Catalogue**
- `books`, `authors`, `categories`
- `book_authors`, `book_categories` (link tables)
- `book_copies` (physical copies: purpose `sale` or `loan`, status available / reserved / sold / loaned / lost)

**Shopping**
- `cart_items`
- `orders`, `order_items`
- `payments` (simulated)

**Library**
- `loans` (borrow date, due date, return date, status)
- `reservations` (waiting list when no copy is free)
- `fines` (late returns)

**Community and admin**
- `reviews` (rating 1 to 5), `wishlist`
- `activity_log`

**Extras**
- A database trigger that **blocks inserting more than 200 books**.
- A view `v_book_availability` that shows how many copies of each title are free for sale and for loan.

---

## 6. Features by phase

### Phase 1: Foundation (weeks 1-3)
- Folder structure, `config.php`, PDO connection.
- Database schema (`database/schema.sql`).
- Python script that collects **200 books with cover images** (20 books in each of 10 categories) and writes `database/seed.sql`.
- Homepage showing the books, with animations.
- English / Kurdish Sorani language button.

### Phase 2: Browsing (weeks 4-6)
- Book details page.
- Search and filters (category, author, price, availability).
- Pagination.

### Phase 3: Accounts (weeks 7-8)
- Register, login, logout.
- Password hashing, sessions.
- User profile and addresses.

### Phase 4: Store (weeks 9-12)
- Cart, checkout, orders, order history.
- Stock handling through `book_copies`.
- Simulated payment.

### Phase 5: Library (weeks 13-16)
- Borrow and return books.
- Due dates (14 days by default).
- Reservations when no copy is free.
- Late fines.
- "My Loans" page.

### Phase 6: Admin dashboard (weeks 17-20)
- Add, edit and delete books (respecting the 200 limit).
- Manage orders, loans and users.
- Charts and statistics.

### Phase 7: Polish (ongoing)
- Page transitions, hover and scroll animations.
- Dark mode.
- Reviews, ratings and wishlist.
- Mobile responsive design.
- Security review (SQL injection, XSS, CSRF, password rules).

### Phase 8: 4th-year upgrades
- Book recommendations in Python.
- Email notifications (due dates, order status).
- REST API.
- PDF invoices.
- Deployment to a real server.

---

## 7. Project folder structure

```
bookstore/
├── index.php                  homepage
├── config.php                 database settings, site name, 200 limit
├── PROJECT_PLAN.md            this file
├── includes/
│   ├── db.php                 PDO connection
│   └── functions.php          helpers (escape, cover URL, load homepage data)
├── database/
│   ├── schema.sql             all tables, trigger, view
│   └── seed.sql               generated by the Python script
├── python/
│   └── collect_books.py       collects 200 books + covers
└── assets/
    ├── css/style.css
    ├── js/i18n.js             English + Sorani texts
    ├── js/main.js             language switch, filters, animations
    └── images/covers/         one picture per book
```

---

## 8. Decisions made

| Question | Answer |
|---|---|
| Languages | English by default, with a button for Kurdish Sorani (RTL) |
| Payments | Simulated for now (real gateway can be added in Phase 8) |
| Local server | XAMPP (to be confirmed) |
| Book limit | 200 books, enforced by a database trigger |

---

## 9. Status

| Item | State |
|---|---|
| Plan | Written (this file) |
| `config.php`, `includes/db.php`, `includes/functions.php` | Written |
| `database/schema.sql` | Written, not yet imported or tested |
| `python/collect_books.py` | Written, not yet run |
| `assets/js/i18n.js`, `assets/js/main.js` | Written, not yet tested in a browser |
| `index.php`, `assets/css/style.css` | Not written yet |

---

## 10. How to run it (once Phase 1 is finished)

1. Install **XAMPP** and start **Apache** and **MySQL**.
2. Copy the `bookstore` folder into `C:\xampp\htdocs\`.
3. In phpMyAdmin, import `database/schema.sql`.
4. Run `python python/collect_books.py` (needs internet). It downloads the covers and creates `database/seed.sql`.
5. In phpMyAdmin, import `database/seed.sql`.
6. Open `http://localhost/bookstore/` in the browser.
