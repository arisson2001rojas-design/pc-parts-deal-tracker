# Codex State

## Branch and base

- Branch: `codex/fetch-observability`.
- Known base: `3eb1777`.
- PR #2 (UI stabilization and Newegg fixes) is merged into `main`.

## Implemented milestones

- Browser Radar 0.4.1 with multi-retailer discovery and backend idempotency.
- Browser Companion dedupe/in-flight protection.
- Radar auto-track with `paused_by_user`, default 28,800-second refresh, and conservative scheduling.
- First-discovery concurrency protection and preserved price history.
- PC component catalog, builds, Deal Hunter, and stabilized admin UI.
- PriceFetchOrchestrator with HTTP price-extractor first and existing SeleniumBase/store HTTP fallback.
- PriceFetchResult/PriceFetchStatus common contract and canonical availability handling.
- Amazon current-offer/title fixes and Newegg Buy New/stock extraction.

## Current milestone

- Structured price-fetch observability by attempt.
- Explicit `rate_limited` status, separate from bot/CAPTCHA challenge.
- Preserve HTTP and fallback attempt diagnostics, metadata, and total latency.
- No new internal retries or change to fallback policy.

## Invariants

- Do not activate the complete catalog.
- Track a Product only when relevant.
- Preserve custom refresh intervals and future `next_check_at` values.
- Explicit user pause wins; Radar does not force `favourite`.
- Browser discovery does not trigger immediate job fanout.
- Preserve real price history.
- Do not implement challenge evasion.

## Pending

- Aggregate metrics by engine and retailer using the structured attempts.
- Decide durable retention/export for diagnostics; attempts currently travel with the fetch result.
- Evaluate Playwright only with evidence from failure metrics.
- Amazon seller cleanup and `regenerate-price-cache` optimization.
- Deal Score and historical cross-retailer comparison.
- Complete localization of advanced upstream/system screens.

## Risks

- Backend discovery dedupe window remains 300 seconds.
- Pauses created before `paused_by_user` have no recorded provenance.
- Attempt diagnostics are request-scoped until a later observability sink is approved.

## Next exact action

Validate this milestone, review its diff, then request authorization for commit/push/PR.
