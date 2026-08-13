# Codex State

## Branch and base

- Branch: `codex/multi-retailer-identity`.
- Known base: `b7eb11d` (main after PR #6 / Milestone 2).

## Implemented milestones

- Browser Radar 0.4.1 with multi-retailer discovery and backend idempotency.
- Browser Companion dedupe/in-flight protection.
- Radar auto-track with `paused_by_user`, default 28,800-second refresh, and conservative scheduling.
- First-discovery concurrency protection and preserved price history.
- PC component catalog, builds, Deal Hunter, and stabilized admin UI.
- PriceFetchOrchestrator with HTTP price-extractor first and existing SeleniumBase/store HTTP fallback.
- PriceFetchResult/PriceFetchStatus common contract and canonical availability handling.
- Amazon current-offer/title fixes and Newegg Buy New/stock extraction.
- Fetch-integrity hardening and typed scheduler/retry outcomes from Milestones 1 and 2.

## Current milestone

- Additive multi-retailer hardware identity infrastructure.
- Separate physical `HardwareIdentity` from `RetailerListing`, tracked `Product`, and operational `Url`.
- Deterministic retailer identifiers and component-specific evidence resolution.
- Fail closed on capacity/model/component conflicts and ambiguous evidence.
- Dry-run-only reconciliation; no Product merge or history movement.

## Invariants

- Do not activate the complete catalog.
- Track a Product only when relevant.
- Preserve custom refresh intervals and future `next_check_at` values.
- Explicit user pause wins; Radar does not force `favourite`.
- Browser discovery does not trigger immediate job fanout.
- Preserve real price history.
- Do not implement challenge evasion.
- Identity enrichment must never block ordinary tracking.
- Probable/ambiguous/conflicting evidence must not create a verified link.

## Pending

- Director review of M3 schema, resolver, ingestion, and dry-run output.
- Future reviewed reconciliation of historical duplicates; never a title-based mass merge.
- Cross-retailer comparison UI using shared identities only after identity quality is measured.
- Amazon seller cleanup and `regenerate-price-cache` optimization.
- Deal Score and historical cross-retailer comparison.
- Complete localization of advanced upstream/system screens.

## Risks

- Backend discovery dedupe window remains 300 seconds.
- Pauses created before `paused_by_user` have no recorded provenance.
- Attempt diagnostics are request-scoped until a later observability sink is approved.
- Legacy catalog MPN arrays contain ambiguous aliases; claims remain inspectable and false negatives are preferred.
- Existing duplicate Products/Urls remain intentionally untouched by M3.

## Next exact action

Complete M3 quality gates, then stop for Director review before any commit/push/PR.
