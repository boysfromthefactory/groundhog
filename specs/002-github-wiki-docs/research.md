# Phase 0 Research: Groundhog GitHub Wiki Documentation

Facts checked on 2026-10-05 against the repository (`boysfromthefactory/groundhog`, branch
`master`, commit `7709055`), the GitHub API and Packagist, and re-checked after the owner enabled
the wiki: the repository is public, the wiki is enabled, and `groundhog.wiki.git` exists (branch
`master`, one placeholder `Home.md` from the first-page step). No `NEEDS CLARIFICATION` items
remain.

## R1 — Where the page sources live

- **Decision**: Keep the pages as Markdown files in a top-level `wiki/` directory of the package
  repository, one file per wiki page, flat (no subdirectories). Add `/wiki export-ignore` to
  `.gitattributes` so the pages never ship in the installed package (FR-014).
- **Rationale**: A GitHub wiki is itself a Git repository (`<repo>.wiki.git`) whose files are
  pages; mirroring that layout makes publication a plain copy. Keeping the sources next to `src/`
  lets one pull request change behaviour and its documentation together. Page names must be
  unique across the wiki regardless of folder, so a flat directory avoids hidden collisions.
- **Alternatives considered**:
  - `docs/wiki/`: the skeleton's `.gitignore` ignores `/docs`, and `.gitattributes` already
    export-ignores it; reusing it would mean un-ignoring a path the skeleton reserves for
    generated output.
  - Editing the wiki directly in the GitHub UI: changes would not be reviewed with code and the
    wiki's history is separate from the package's (fails FR-014's intent).

## R2 — Page names, special pages and links

- **Decision**:
  - File names use hyphens for spaces (`Quick-Start.md` → page "Quick Start"); `Home.md` is the
    landing page; `_Sidebar.md` and `_Footer.md` are shown on every page (FR-012).
  - Links between pages use standard Markdown with the page slug and no extension:
    `[Quick Start](Quick-Start)`; section links add the heading anchor:
    `[horizon](Configuration#horizon)`.
  - Links to repository files use absolute GitHub URLs
    (`https://github.com/boysfromthefactory/groundhog/blob/master/README.md`).
- **Rationale**: GitHub wikis resolve extension-less relative links to pages and render
  `_Sidebar`/`_Footer` automatically. Standard Markdown links (unlike `[[Wiki Links]]`) also
  render as links when the sources are browsed in the main repository, which keeps review
  readable even though they only resolve on the wiki.
- **Alternatives considered**: `[[Page Name]]` syntax — wiki-only, shows as literal brackets in
  pull-request diffs and previews.

## R3 — Verifying the code examples (FR-007, SC-003)

- **Decision**: Every example states its starting data and its outcome, and each stated outcome
  is traced to an existing automated test that proves it
  ([contracts/example-coverage.md](contracts/example-coverage.md)). Where a stated outcome has no
  covering test, a focused test is added to the existing feature test file before the page is
  written. Examples reuse the test suite's data (the weekly Monday stand-up from 2 March 2026 at
  a frozen "now" of 1 March 2026) so the traced test checks exactly what the page claims.
- **Rationale**: The package has 143 behaviour tests across SQLite, MySQL and PostgreSQL that
  already pin almost every outcome the guide needs to state. Tracing examples to them gives
  lasting verification: when behaviour changes, the failing test points at the page that must
  change. Constitution III forbids duplicate tests, so a separate doc-test suite re-running the
  same behaviour would add maintenance without catching more bugs.
- **Alternatives considered**:
  - Extracting and executing code blocks automatically: examples are fragments that depend on
    surrounding data and a frozen clock; making them executable would turn prose into test
    fixtures and duplicate the suite.
  - Checking examples once by hand: no protection against drift (fails SC-003 over time).

## R4 — Installation before the first release

- **Decision**: The Quick Start shows `composer require boysfromthefactory/groundhog` as the
  install command, followed by a clearly marked "Before the first tagged release" note that adds
  the public GitHub repository as a Composer VCS repository and requires `dev-master`. No
  credentials are needed because the repository is public.
- **Rationale**: Packagist returns 404 for `boysfromthefactory/groundhog` and the repository has
  no tags. A Quick Start whose first command fails breaks SC-001; documenting only the VCS route
  would go stale on the first release.
- **Alternatives considered**: Documenting only the Packagist command — fails today. Publishing
  to Packagist as part of this feature — a release decision outside documentation scope.

## R5 — Publishing to the wiki (FR-015)

- **Decision**: A maintainer page `Maintaining-the-Wiki.md` documents the procedure:
  1. One-time: create any first page in the wiki's web UI (GitHub creates `groundhog.wiki.git`
     only then); the wiki is already enabled.
  2. Clone `git@github.com:boysfromthefactory/groundhog.wiki.git`, copy `wiki/*.md` over the
     clone (deleting pages that no longer exist), commit and push.
  The procedure is a handful of documented Git commands; no script or CI action is added.
- **Rationale**: The wiki is enabled and the repository is public, so no plan or credential
  constraints apply beyond push access. The wiki's Git repository appears only after the first
  page is saved in the web UI, so the procedure starts there. A copy-and-push procedure is the
  simplest thing that works and adds no dependency (Constitution IV).
- **Alternatives considered**: A GitHub Action that syncs `wiki/` on every push — needs a
  third-party action or a token with wiki write access, both new dependencies whose need is not
  yet shown (YAGNI); can be added later if manual publishing proves error-prone.

## R6 — Page set and reading order

- **Decision**: 16 pages plus sidebar and footer, in this reading order (detail in
  [contracts/page-map.md](contracts/page-map.md)):
  - Start: Home, Quick Start
  - Guide: Declaring Recurring Models, Recurrence Rules, Querying Occurrences, Pagination and
    Collections, Editing Occurrences, Cancelling Occurrences, Managing a Series, Stored Records
    and Bulk Writes, Identifying Occurrences
  - Reference: Configuration, Errors, Limitations, How It Works
  - Contributing: Maintaining the Wiki
- **Rationale**: One task per guide page keeps each page short enough to scan and gives every
  public capability an obvious home (SC-002). Every page is linked from both Home and the sidebar,
  so it is one click from Home (SC-004).
- **Alternatives considered**: A single long "Usage" page — harder to navigate and to link to
  precisely; one page per public method — fragments tasks that use several methods together.

## R7 — Version marking, README and CHANGELOG (FR-016, FR-017)

- **Decision**: `_Footer.md` states "Documents Groundhog `dev-master` (unreleased)"; on each
  release the footer is updated to the tag. The README gains a "Documentation" link to
  `https://github.com/boysfromthefactory/groundhog/wiki` and keeps its current content as the
  short entry point. The CHANGELOG's `Unreleased` section gains a "Documentation" line.
- **Rationale**: The footer appears on every page, so readers always see the version. Constitution
  V requires user-visible changes to update documentation and the changelog.
- **Alternatives considered**: A version line on every page — repetitive and easy to miss on
  update.

## R8 — Clock-dependent and database-dependent statements

- **Decision**: Examples whose result depends on "now" (open-ended rules, the horizon, the
  materialisation ceiling) state the assumed current date (1 March 2026, as in the test suite).
  Performance statements quote the measured SC-003 results per database (PostgreSQL < 1 s,
  MySQL < 2 s, SQLite < 3 s for any page of 25 out of 365,000 occurrences).
- **Rationale**: Without the date, "365 occurrences" is not reproducible; without per-database
  numbers the documentation would imply one result for all (spec Edge Cases).

## R9 — Example style

- **Decision**: PHP code blocks fenced with `php`; the outcome of an example is shown as a trailing
  comment (`// => 5 meetings: 2, 9, 16, 23, 30 March 09:00–10:00`) or a short "Result:" sentence;
  Bash blocks for commands. All examples use the `Meeting` model (`title`, `location`,
  `capacity`, `room_id`, `starts_at`, `ends_at`, soft deletes) and, where a model without an end
  is needed, `Shift`. Namespaces are omitted except in the first full model declaration.
- **Rationale**: One running domain satisfies FR-006 and matches the traced tests (R3).

## R10 — Link checking (SC-004)

- **Decision**: Validate links with a one-off shell check during validation
  ([quickstart.md](quickstart.md)): every relative link target must match a file in `wiki/`, and
  every page must appear in `_Sidebar.md` and `Home.md`. No permanent link-check tooling.
- **Rationale**: 16 pages are few enough for a single command; a permanent checker would be a new
  tool to maintain (Constitution IV). Re-run the command whenever pages change.
