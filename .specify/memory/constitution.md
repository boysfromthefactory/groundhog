# Groundhog Constitution

## Core Principles

### I. Readable, Intentional Code

Code is written for the next maintainer first and the compiler second.

- Names MUST describe intent (what and why), not mechanics; abbreviations are allowed only when
  they are standard in the domain.
- Each function, module, and type MUST have one clearly stateable responsibility. If its purpose
  needs "and" to describe, it MUST be split.
- Comments MUST explain *why* (constraints, invariants, non-obvious decisions); comments that
  restate *what* the code does MUST be removed.
- Public interfaces MUST document inputs, outputs, error conditions, and invariants.
- Consistency beats preference: new code MUST follow the existing conventions of the module it
  touches; introducing a second convention for the same concern is prohibited.

**Rationale**: Code is read far more often than written. Clarity is the cheapest defense against
defects and the main driver of change velocity six months later.

### II. Test-First Verification (NON-NEGOTIABLE)

Every behavioral change MUST be proven by an automated test that fails before the change and
passes after it.

- Bug fixes MUST include a regression test that reproduces the bug before the fix is applied.
- New features MUST have acceptance tests derived from the spec's acceptance scenarios before
  implementation begins (Red → Green → Refactor).
- The full test suite MUST pass on every merge to the main branch; a failing or skipped test MUST
  NOT be merged without a linked, tracked issue and an expiry for the skip.
- Tests MUST be deterministic and isolated: no reliance on wall-clock time, network, execution
  order, or shared mutable state unless that dependency is the subject under test and is
  controlled.

**Rationale**: A test written first proves it can detect the defect it guards against. Untested
behavior is unverified behavior.

### III. Tests That Catch Real Bugs

Tests exist to catch plausible, consumer-visible defects — not to inflate coverage.

- Tests MUST assert observable behavior through public interfaces: outputs, state transitions,
  boundaries, error cases, and precedence rules.
- Tests MUST NOT pin incidental implementation details (private structure, exact log wording,
  call counts on internal collaborators, source text) unless that detail is a documented contract.
- Tautological tests (asserting a mock returns what it was told to return), bare "does not throw"
  tests, and duplicate tests of the same path MUST NOT be added.
- Test layers: unit tests for logic and edge cases; integration tests for module boundaries,
  persistence, and external contracts; end-to-end tests only for critical user journeys.
- Coverage is a signal, not a target. A coverage drop on changed code MUST be justified in review.

**Rationale**: Brittle or meaningless tests slow refactoring and create false confidence. A smaller
suite of behavior-focused tests is worth more than a large suite of implementation mirrors.

### IV. Simplicity Over Abstraction

The simplest design that satisfies current, specified requirements wins.

- New abstractions (interfaces, base classes, plugin points, generic helpers) MUST have at least
  two concrete, present-day consumers; speculative extensibility is prohibited (YAGNI).
- New dependencies MUST be justified in the plan: what they replace, maintenance health, license,
  and why a small in-repo implementation is inferior.
- Indirection layers that only forward calls MUST NOT be introduced.
- Any deviation from these rules MUST be recorded in the plan's Complexity Tracking table with the
  simpler alternative that was rejected and why.

**Rationale**: Every abstraction and dependency is a permanent maintenance cost. Boring, direct code
is easier to test, debug, and change.

### V. Clean Change & Long-Term Maintainability

Every change leaves the codebase in a state that needs no cleanup afterward.

- Changes MUST be complete cutovers: when behavior or an interface is replaced, all callers MUST be
  migrated and obsolete code, aliases, flags, and compatibility shims MUST be removed in the same
  change.
- Dead code, commented-out code, and unused exports MUST NOT be merged.
- Stubs, placeholders, `TODO: implement`, and fake fallbacks MUST NOT be merged as delivered
  functionality.
- User-visible or contract-visible changes MUST update the relevant documentation and changelog in
  the same change.
- Errors MUST be handled explicitly or propagated with context; silently swallowing errors or
  suppressing warnings to make checks pass is prohibited.

**Rationale**: Half-finished migrations and leftover scaffolding compound into the most expensive
form of technical debt: code nobody is sure is safe to delete.

## Quality Standards

- **Automated formatting**: All code MUST pass the project's configured formatter; formatting is
  never debated in review.
- **Static analysis**: Linters and, where the language supports it, strict type checking MUST run
  in CI and MUST pass with zero errors. New suppressions MUST carry an inline justification.
- **Complexity limits**: Functions exceeding the linter's configured complexity or length
  thresholds MUST be refactored or carry a justified suppression.
- **Dependency hygiene**: Dependencies MUST be pinned via a lockfile; unused dependencies MUST be
  removed.
- **Security baseline**: Secrets MUST NOT be committed; inputs crossing a trust boundary MUST be
  validated.

## Development Workflow & Quality Gates

1. **Spec → Plan → Tasks → Implement**: Features follow the Spec Kit flow. The plan's
   Constitution Check MUST pass before research and be re-checked after design.
2. **Small, focused changes**: Each pull request MUST address one concern and SHOULD be reviewable
   in a single sitting; unrelated refactors MUST be split into separate changes.
3. **Merge gates** (all MUST pass):
   - Formatter, linter, and type checker clean.
   - Full test suite green, including the new failing-before/passing-after tests (Principle II).
   - At least one reviewer approval verifying compliance with Principles I–V.
   - Documentation and changelog updated for user-visible changes.
4. **Review focus**: Reviewers MUST check correctness, test meaningfulness (Principle III), and
   unjustified complexity (Principle IV) before style.

## Governance

- This constitution supersedes all other development practices and guidance documents. Where a
  conflict exists, the constitution wins until amended.
- **Amendments** MUST be proposed as a pull request modifying this file, including the rationale,
  a Sync Impact Report, and a migration plan for existing code or artifacts that become
  non-compliant.
- **Versioning** follows semantic versioning:
  - MAJOR: removal or backward-incompatible redefinition of a principle or governance rule.
  - MINOR: new principle or section, or materially expanded guidance.
  - PATCH: clarifications, wording, and non-semantic refinements.
- **Compliance review**: Every plan MUST complete the Constitution Check; every pull request review
  MUST verify compliance. Justified exceptions MUST be documented in the plan's Complexity Tracking
  table; unjustified violations block merge.
- Compliance with this constitution SHOULD be reviewed whenever a MAJOR or MINOR amendment is
  ratified, and existing code brought into line as part of that amendment's migration plan.

**Version**: 1.0.0 | **Ratified**: 2026-10-04 | **Last Amended**: 2026-10-04
