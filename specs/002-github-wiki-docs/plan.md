# Implementation Plan: Groundhog GitHub Wiki Documentation

**Branch**: `002-github-wiki-docs` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/002-github-wiki-docs/spec.md`

## Summary

The wiki is written as 16 Markdown pages plus a sidebar and a footer, kept in a new top-level
`wiki/` directory of the package repository and published by copying them into the GitHub wiki's
Git repository.

- **Pages**: Home (overview and core concepts) and Quick Start, then nine task-oriented guide
  pages, four reference pages (configuration, errors, limitations, how it works) and a maintainer
  page on publishing.
- **Examples**: Every example uses the test suite's running domain: the `Meeting` model, a weekly
  Monday stand-up from 2 March 2026, and a "now" of 1 March 2026. Each outcome an example states
  is traced to an existing passing test; six gaps get new tests.
- **Outside `wiki/`**: the README links to the wiki, the CHANGELOG records it, and
  `.gitattributes` keeps `wiki/` out of the installed package.

## Technical Context

**Language/Version**: GitHub-flavoured Markdown (wiki pages); PHP 8.4 for the examples and the
six new tests

**Primary Dependencies**: None new. The examples use the package as built in feature 001 (Laravel
13, rlanvin/php-rrule ^3.0).

**Storage**: Files in `wiki/`, published to `boysfromthefactory/groundhog.wiki.git`

**Testing**: The existing Pest 4 suite on SQLite, MySQL 8.4 and PostgreSQL for the traced
outcomes and the six new tests. One-off validation commands check the links and the fresh-app
Quick Start ([quickstart.md](quickstart.md)).

**Target Platform**: GitHub wiki of the public repository `boysfromthefactory/groundhog`. The
wiki repository `groundhog.wiki.git` exists (branch `master`) with a placeholder `Home.md` that
the first publication replaces (research R5).

**Project Type**: Documentation for a library (Composer package)

**Performance Goals**: The Quick Start can be completed in under 15 minutes (SC-001).

**Constraints**:
- No contradictions with the README or CHANGELOG.
- Pages are not shipped in the installed package.
- Every stated outcome is reproducible against the package.
- The package is not on Packagist yet, so the Quick Start documents a VCS install (research R4).

**Scale/Scope**: 16 pages plus sidebar and footer; 79 capability/outcome rows traced
([contracts/example-coverage.md](contracts/example-coverage.md)); 6 new tests

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Pre-research | Post-design |
|---|---|---|---|
| I. Readable code | Consistent conventions; intent-revealing content | ✅ | ✅ One running example domain and one page per task; the page map fixes structure and link anchors |
| II. Test-first | Behavioural changes proven by failing-first tests | ✅ N/A: no behaviour changes | ✅ Documentation only. Stated outcomes are traced to tests. The six new tests cover existing, untested behaviour (they pass on first run because nothing changes), so there is no red phase to show. |
| III. Meaningful tests | Behaviour through public API; no duplicates | ✅ | ✅ The new tests cover only outcomes that no existing test pins ([example-coverage](contracts/example-coverage.md) **NEW** rows); no doc-test suite that would re-run existing behaviour (research R3) |
| IV. Simplicity | No new abstractions or dependencies without need | ✅ | ✅ No tooling, scripts or CI actions. Publishing is a few documented Git commands (R5) and link checking is a one-off command (R10). |
| V. Clean change | Docs and changelog with user-visible changes; no placeholders | ✅ | ✅ README link, CHANGELOG entry and `.gitattributes` export-ignore are in scope. The no-placeholder rule is checked in V-2. |
| Quality standards | Formatter/analysis clean | ✅ | ✅ The new tests go through Pint and the existing suite; Markdown has no formatter in the project. |

Gate result: **PASS**, no violations.

## Project Structure

### Documentation (this feature)

```text
specs/002-github-wiki-docs/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── page-map.md
│   └── example-coverage.md
├── checklists/requirements.md
└── tasks.md            # /speckit.tasks
```

### Source (repository root)

```text
wiki/
├── Home.md
├── Quick-Start.md
├── Declaring-Recurring-Models.md
├── Recurrence-Rules.md
├── Querying-Occurrences.md
├── Pagination-and-Collections.md
├── Editing-Occurrences.md
├── Cancelling-Occurrences.md
├── Managing-a-Series.md
├── Stored-Records-and-Bulk-Writes.md
├── Identifying-Occurrences.md
├── Configuration.md
├── Errors.md
├── Limitations.md
├── How-It-Works.md
├── Maintaining-the-Wiki.md
├── _Sidebar.md
└── _Footer.md
tests/Feature/
├── RecurrenceRuleCastTest.php      # + recurrence() relation
├── EditOccurrenceTest.php          # + decrement(); cursor() identity
├── PaginationAndAggregatesTest.php # + collection find() with an occurrence
├── QueryOccurrencesTest.php        # + find an occurrence by its identity
└── EdgeCasesTest.php               # + whereKeyNot()
README.md                           # + Documentation link
CHANGELOG.md                        # + Unreleased documentation entry
.gitattributes                      # + /wiki export-ignore
```

**Structure Decision**: A flat `wiki/` directory mirrors the GitHub wiki repository, so
publishing is a copy (research R1). The new tests go into the existing feature files grouped by
story, the convention from feature 001.

## Phase 0 / Phase 1 outputs

- [research.md](research.md): R1–R10. Covers source location, links, example verification,
  installing before the first release, publication, page set, versioning, clock- and
  database-dependent statements, example style and link checking.
- [data-model.md](data-model.md): the wiki page, code example and navigation rules, plus the page
  lifecycle.
- [contracts/page-map.md](contracts/page-map.md): every page, its required sections, and the
  changes outside `wiki/`.
- [contracts/example-coverage.md](contracts/example-coverage.md): each public capability mapped
  to its page and to the test that proves its stated outcome; six **NEW** tests.
- [quickstart.md](quickstart.md): validation steps V-1 to V-7.

## Complexity Tracking

No constitution violations; nothing to justify.
