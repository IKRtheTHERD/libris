# Libris — Product and visual direction

Last reviewed: 2026-10-25

## Product contract

Libris is a small, bilingual bookstore and lending library for readers in Iraq. Readers discover titles, buy available sale copies, borrow library copies, join reservation queues, and keep track of orders and due dates. Staff manage a 200-title catalogue and the physical copies attached to each title.

## Confirmed decisions

- Scope: full product in `PROJECT_PLAN.md` through Phase 7. Phase 8 remains future work.
- Languages: English by default and Kurdish Sorani (`ckb`) with right-to-left layout.
- Currency: Iraqi dinar (IQD).
- Loan term: 14 days; up to 3 active loans per member.
- Late return: configurable 1,000 IQD per overdue day.
- Payment: simulated only; do not collect or persist payment credentials.
- Local runtime: continue implementation while XAMPP is unavailable; do not start the stopped MySQL80 service.
- Catalogue source: official Open Library editions dump; seed exactly 20 titles per each of ten configured shelves (200 titles total). Use public cover URLs and local fallback artwork.
- Design: warm editorial bookstore identity with paper, ink, evergreen, and restrained copper; mobile responsive; purposeful motion that respects reduced-motion settings.
- Design workspace: Figma was requested. User said they would connect it; no project or file reference has been provided yet.

## Interface hierarchy

1. Make the book and its availability the primary object on catalogue pages.
2. Keep search, language, account, and shopping bag controls easy to find.
3. Make checkout, loan due dates, queue position, and late fees explicit before a customer commits.
4. Use a consistent account desk for orders, loans, reservations, wish list, profile, and addresses.
5. Keep librarian actions distinct from customer actions and protect every state-changing operation with role checks and CSRF validation.

## Viewports and accessibility

- Design targets: 1440px desktop, 768px tablet, and 375px mobile.
- Use semantic HTML, visible keyboard focus, associated form labels, readable error/status announcements, and logical CSS properties for Sorani.
- Preserve readable layouts with long English and Sorani labels; avoid horizontal page overflow.
- Respect reduced motion and maintain contrast in light and dark themes.

## Visual rationale

The warm paper and ink palette gives the catalogue the feel of a considered independent bookshop. Evergreen anchors navigation and primary actions; copper marks editorial emphasis. Covers remain the strongest color and image on book pages. Account and staff views use quieter tables and compact summaries because those pages support repeat tasks.
