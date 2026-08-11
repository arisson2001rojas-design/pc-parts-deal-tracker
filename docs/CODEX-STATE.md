# Codex State

## Branch

`codex/pc-parts-mvp`

## Known base

`a906796`

## Implemented

- Radar 0.4.1 multi-retailer.
- Amazon current-offer, variant, and title fixes.
- Extended PC component classification.
- Browser Companion dedupe and in-flight protection.
- Radar auto-track.
- `paused_by_user` support.
- Default refresh interval of 28,800 seconds.
- Conservative scheduling.
- Backend BrowserDiscovery idempotency.
- First-discovery concurrency fix.
- PriceFetchOrchestrator.
- HTTP price-extractor.
- SeleniumBase fallback.
- PriceFetchResult and PriceFetchStatus.
- Canonical availability resolution fix.

## Invariants

- Do not activate the complete catalog.
- A Product enters tracking only when relevant.
- Preserve custom refresh intervals.
- Radar visits must not postpone a future `next_check_at`.
- An explicit user pause wins over automation.
- Browser Radar does not force `favourite`.
- Browser discovery does not trigger immediate job fanout.
- Preserve real price history.

## Pending

- Metrics and observability by engine and retailer.
- Distinguish rate-limit HTTP 429 from challenge responses.
- Preserve diagnostics for attempts and fallback decisions.
- Evaluate Playwright only with supporting evidence.
- Amazon seller cleanup.
- Optimize `regenerate-price-cache`.
- Deal Score and historical comparison later.

## Risks

- Backend dedupe window is 300 seconds.
- Pauses created before `paused_by_user` have no recorded provenance.
- The working tree contains multiple uncommitted phases.

## Next exact action

Git checkpoint and CI, then PriceFetch observability.
