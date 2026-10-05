# Feature Specification: Groundhog GitHub Wiki Documentation

**Feature Branch**: `002-github-wiki-docs`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "Generate a documentation for the package that can be used GitHub wiki, and give an overview, a quick start, and a detailed guide on the usage of the package with code examples."

> **Audience note**: The readers of this documentation are application developers who are
> evaluating or using Groundhog in their Laravel applications. The GitHub wiki is the publishing
> target named in the request; everything else about how the pages are produced is left to the
> plan.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Understand the package and get a first result (Priority: P1)

A developer who has never used Groundhog opens the wiki. The home page tells them in a few
paragraphs what the package does, which problem it solves, when it is (and is not) a good fit,
and the handful of terms they need (series, occurrence, exception, cancellation). From there they
follow the Quick Start, which takes them from installation to a working recurring model whose
occurrences appear in an ordinary paginated query, and shows how to change and cancel a single
occurrence.

**Why this priority**: Without an overview and a working first result, nobody adopts the package;
everything else in the wiki builds on these two pages.

**Independent Test**: Give the Home and Quick Start pages to a Laravel developer unfamiliar with
the package and a fresh application; they reach a paginated list of occurrences, then edit and
cancel one occurrence, using only those two pages.

**Acceptance Scenarios**:

1. **Given** a developer reading only the Home page, **When** asked what Groundhog does and whether
   it fits "a weekly meeting that sometimes moves", **Then** they can answer correctly and name the
   page to read next.
2. **Given** a fresh Laravel application, **When** the developer follows every Quick Start step in
   order, copying the examples as written, **Then** each step produces the outcome the page states
   and the last step shows the expected occurrences in a paginated list.
3. **Given** the finished Quick Start, **When** the developer edits one occurrence and cancels
   another as shown, **Then** the next query reflects both changes exactly as the page describes.

---

### User Story 2 - Accomplish a specific task from the usage guide (Priority: P1)

A developer already using the package needs to do something specific: declare custom column
names, assign a rule in a time zone, query only next month's occurrences, sort and paginate a
calendar, move one occurrence, change the whole series, soft-delete a series, or update records
in bulk. They open the usage guide, find the section for that task from the navigation, read an
explanation of how the package behaves, and adapt the code example to their application.

**Why this priority**: Day-to-day usage questions are the main reason developers return to
documentation; the request names "a detailed guide on the usage of the package with code
examples" explicitly.

**Independent Test**: Pick each capability listed in the package's public contract; locate its
section from the wiki navigation and confirm the section explains the behaviour and contains a
code example whose stated outcome matches what the package actually does.

**Acceptance Scenarios**:

1. **Given** the usage guide, **When** a developer looks for how to change a single occurrence
   without affecting the series, **Then** they find a section with an example and a statement of
   what is stored and what later queries return.
2. **Given** the usage guide, **When** a developer looks for what happens to edited occurrences
   when the series' rule changes, **Then** the guide states the outcome and shows it in an example.
3. **Given** any code example in the guide, **When** it is run against the package as documented,
   **Then** it behaves as the surrounding text says.

---

### User Story 3 - Look up configuration, errors and limitations (Priority: P2)

A developer hits an error such as "occurrence limit exceeded", or wonders why a relation is empty
on an occurrence or why `chunkById()` is rejected. They open the reference pages: configuration
options with defaults and effects, every error the package raises with its cause and remedy, and
the known limitations with their workarounds.

**Why this priority**: Reference and troubleshooting content prevents support requests and
misuse, but it is consulted after a developer has started using the package.

**Independent Test**: For each configuration option, each error the package can raise and each
documented limitation, find its entry in the reference pages and confirm it states cause, effect
and remedy or workaround.

**Acceptance Scenarios**:

1. **Given** the error reference, **When** a developer looks up an error the package raised,
   **Then** they find when it occurs and what to change to avoid it.
2. **Given** the configuration reference, **When** a developer looks up an option, **Then** they
   find its default, its effect and an example of changing it.
3. **Given** the limitations page, **When** a developer looks up why a feature behaves unexpectedly
   with occurrences, **Then** they find the limitation and the recommended workaround.

---

### User Story 4 - Publish and maintain the wiki (Priority: P3)

A maintainer publishes the documentation to the repository's GitHub wiki and later updates it
when the package changes. The pages are kept in the package's own repository so changes to
behaviour and documentation travel together, and a short procedure explains how to publish them
to the wiki.

**Why this priority**: Needed for the documentation to reach readers and stay accurate, but it is
a one-off procedure compared with the reader-facing content.

**Independent Test**: Follow the publishing procedure into an empty wiki; every page appears with
working navigation and links, and repeating it after an edit updates only the changed pages.

**Acceptance Scenarios**:

1. **Given** the documentation sources and an enabled wiki, **When** the maintainer follows the
   publishing procedure, **Then** all pages appear with the sidebar navigation and every internal
   link resolves.
2. **Given** a published wiki, **When** a page source is changed and the procedure is repeated,
   **Then** the wiki shows the change.

---

### Edge Cases

- **Wiki not yet initialised**: GitHub only creates a wiki repository after its first page exists;
  the publishing procedure must say how to handle a wiki with no pages yet.
- **Clock-dependent examples**: examples involving open-ended rules depend on "now" and the
  expansion horizon; they must state the assumed current date so the stated results are
  reproducible.
- **Database differences**: where behaviour or performance differs by database (e.g. the per-page
  time budgets of SQLite, MySQL and PostgreSQL), the pages must say so instead of implying one
  result for all.
- **Version drift**: readers of an older package version must be able to tell which version the
  pages describe.
- **Page names and links**: page titles that contain spaces or punctuation must still produce
  working wiki links.
- **Overlap with the README**: content repeated from the README must not contradict it; the README
  points to the wiki for depth.

## Requirements *(mandatory)*

### Functional Requirements

**Overview and Quick Start**

- **FR-001**: The documentation MUST include a Home page that explains what Groundhog does, the
  problem it solves, when to use it and when not to, the supported platforms, and defines the core
  terms: recurring model, series, recurrence rule, occurrence (virtual occurrence), exception,
  cancellation and horizon.
- **FR-002**: The Home page MUST link to the Quick Start, every usage guide section and every
  reference page.
- **FR-003**: The documentation MUST include a Quick Start that, in numbered steps, covers
  installation, publishing and running the migration, declaring a recurring model, creating a
  series, querying and paginating its occurrences, editing one occurrence and cancelling one
  occurrence, each step with a code example and its expected outcome.

**Usage guide**

- **FR-004**: The documentation MUST include a usage guide with task-oriented sections covering:
  declaring recurring models (including custom start/end columns and models without an end);
  assigning, replacing and removing rules (all accepted input forms, time zones, validation);
  reading the rule and its human-readable description; querying occurrences (time windows,
  non-time constraints, the expansion horizon, relationships, joins and `whereHas`); pagination,
  counting, aggregates, ordering, chunking and collections; editing single occurrences (save,
  update, increment, moving, events); cancelling occurrences and deleting exceptions; managing the
  whole series (edits, rule and start changes, duration changes, deleting and soft deleting);
  working with stored records only (key lookups, route model binding, bulk writes,
  `withoutOccurrences()`); and identifying occurrences in APIs and forms.
- **FR-005**: Every capability in the package's public contract (trait members, the rule
  attribute, builder methods, configuration keys and exceptions) MUST be documented with at least
  one code example and a statement of its observable outcome.
- **FR-006**: Every usage example MUST be self-consistent with the others: the guide MUST use one
  running example domain (a meeting model with a weekly series) and state the data each example
  starts from.
- **FR-007**: Every code example's stated outcome MUST match the package's actual behaviour.

**Reference**

- **FR-008**: The documentation MUST include a configuration reference listing every option with
  its default, its effect and an example of changing it.
- **FR-009**: The documentation MUST include an error reference listing every error the package
  raises, the situations that cause it and how to resolve or avoid it.
- **FR-010**: The documentation MUST include a limitations page listing every known limitation and
  unsupported feature with its recommended workaround, consistent with the README caveats.
- **FR-011**: The documentation MUST include a page explaining how the package works at a level
  sufficient to reason about results and performance (expansion into occurrences, the stored
  occurrence index, exclusions, horizon and limits), including the measured performance per
  supported database.

**Navigation, format and maintenance**

- **FR-012**: The documentation MUST be organised as GitHub wiki pages with a sidebar that lists
  every page in reading order and a footer linking back to the repository and the Home page.
- **FR-013**: All links between pages MUST resolve when the pages are published to the GitHub
  wiki.
- **FR-014**: The page sources MUST be kept in the package repository so documentation changes can
  be reviewed with the code they describe, and MUST NOT ship in the installed package.
- **FR-015**: The documentation MUST include a maintainer procedure for publishing the pages to
  the GitHub wiki, including the first publication into a wiki with no pages yet.
- **FR-016**: The documentation MUST state which package version (or unreleased state) it
  describes.
- **FR-017**: The README MUST link to the wiki, and no statement in the wiki may contradict the
  README or the CHANGELOG.

### Key Entities

- **Wiki page**: one topic (overview, quick start, a guide section or a reference); has a title, a
  position in the reading order and links to related pages.
- **Code example**: a snippet within a page, with the data it starts from and the outcome it
  produces; must be reproducible against the package.
- **Navigation**: the sidebar and footer shared by all pages, plus the Home page's table of
  contents.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A Laravel developer new to the package reaches a paginated list of occurrences, and
  edits and cancels one occurrence, in under 15 minutes using only the Home and Quick Start pages.
- **SC-002**: 100% of the capabilities in the package's public contract appear in the
  documentation with at least one code example.
- **SC-003**: 100% of code examples produce the outcome their page states when checked against
  the package.
- **SC-004**: Every page is reachable from the Home page in at most two clicks, and 0 internal
  links are broken after publication.
- **SC-005**: A review comparing the wiki with the README and CHANGELOG finds 0 contradictions.
- **SC-006**: For each error the package can raise and each documented limitation, a developer
  finds its cause and remedy in under 2 minutes starting from the Home page.

## Assumptions

- **Audience**: Laravel developers comfortable with Eloquent; the documentation is written in
  English and does not teach Laravel itself.
- **Version described**: the current state of the package on the main branch (unreleased); the
  pages say so and are updated with each release.
- **Publication**: the wiki of the package's GitHub repository is the only publishing target; no
  separate documentation website is in scope. The wiki may need to be enabled and initialised by
  a repository owner before the first publication.
- **README stays**: the README remains the concise entry point and links to the wiki for detail.
- **Running example**: examples reuse the meeting domain from the package's specification and test
  suite (a weekly Monday stand-up from 2 March 2026), so stated results can be checked against the
  existing behaviour.
- **No behaviour changes**: this feature documents the package; any package defect found while
  checking examples is reported separately rather than fixed as part of the documentation.
