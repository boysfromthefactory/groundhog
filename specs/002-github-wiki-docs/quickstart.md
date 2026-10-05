# Quickstart & Validation Guide: Groundhog GitHub Wiki Documentation

Proves the wiki is complete, correct and publishable. Page contents:
[contracts/page-map.md](contracts/page-map.md); example checks:
[contracts/example-coverage.md](contracts/example-coverage.md); page rules:
[data-model.md](data-model.md).

## Prerequisites

- The package checkout with dependencies installed (`composer install`).
- Local MySQL 8.4 and PostgreSQL reachable for the full test matrix (as for feature 001).
- For V-3: Composer able to create a Laravel 13 application.
- For V-5: push access to `boysfromthefactory/groundhog` (wiki enabled; a first page must exist
  before the wiki repository can be cloned).

## V-1 — Every page exists and is listed

From the repository root: every page in the page map exists as `wiki/<Slug>.md`, and every page
file except `_Sidebar.md` and `_Footer.md` is linked from both `wiki/Home.md` and `wiki/_Sidebar.md`.

Expected: 16 pages plus `_Sidebar.md` and `_Footer.md`; no page missing from Home or the sidebar.

## V-2 — Every internal link resolves, no placeholders

For every relative link `](Slug)` or `](Slug#anchor)` in `wiki/*.md`: `wiki/Slug.md` exists, and
for an anchor, that page has a heading whose GitHub anchor (lower-case, spaces → `-`, punctuation
removed) equals it. Search all pages for `TODO`, `TBD` and `NEEDS CLARIFICATION`.

Expected: 0 unresolved links, 0 placeholders (SC-004).

## V-3 — Quick Start in a fresh application (SC-001)

In a scratch directory outside the repository, create a new Laravel 13 application on SQLite and
follow `wiki/Quick-Start.md` step by step, installing the package from the local checkout via a
Composer `path` repository (the documented VCS route is equivalent). Time the walkthrough.

Expected: every step produces the outcome the page states; the final query lists the March
occurrences with the 16 March one edited and the 23 March one gone; elapsed time under 15
minutes. Delete the scratch application afterwards.

## V-4 — Every stated outcome is proven

1. Every row of `contracts/example-coverage.md` names a check; the **NEW** tests exist and pass.
2. Every example in `wiki/` that states an outcome appears in the coverage table (review pass).
3. Run the suite on all databases:

```bash
composer test
DB_CONNECTION=mysql DB_DATABASE=groundhog composer test
DB_CONNECTION=pgsql DB_DATABASE=groundhog composer test
```

Expected: all tests pass on SQLite, MySQL and PostgreSQL; 100% of public capabilities in the
coverage table (SC-002, SC-003).

## V-5 — Publication (FR-013, FR-015)

Follow `wiki/Maintaining-the-Wiki.md` exactly, including the first-publication steps if the wiki
has no pages yet. Then open the wiki in a browser: the sidebar and footer appear on every page;
click every sidebar entry and every link on Home.

Expected: every page renders; 0 broken links; the footer shows the documented version. Repeat
after editing one page: only that page changes.

## V-6 — Consistency with README and CHANGELOG (SC-005, FR-017)

Compare `wiki/Limitations.md`, `wiki/Configuration.md` and `wiki/Quick-Start.md` with the README
sections on caveats, configuration and installation, and the CHANGELOG `Unreleased` entry.
Confirm the README links to the wiki and the CHANGELOG mentions the documentation.
`How-It-Works.md` quotes the SC-003 timings recorded in the 001 spec amendment.

Expected: 0 contradictions.

## V-7 — Errors and limitations are findable (SC-006)

For each exception class and each limitation, start at Home and reach its cause and remedy.

Expected: each found in at most two clicks from Home.
