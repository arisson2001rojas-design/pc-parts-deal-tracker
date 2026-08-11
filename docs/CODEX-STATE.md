# Codex State

## Branch

`codex/ui-stabilization`

## Known base

`a906796`

## Upstream integration

- Integrated `origin/main` at `cec656bae58dc686f4bd23e18a4cb5ead769d6ea`.
- Resolved `Url.php` by combining URL normalization and upstream stock handling with PriceBuddy image, guard, dedupe, and history behavior.
- Resolved `routes/api.php` as an additive union of Client Config, Browser Capture, Browser Discovery, and existing API routes.
- Preserved upstream soft-404 semantics through `PriceFetchResult` to URL persistence.

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
- UI stabilization for Dashboard, Products, PC Parts, PC Builds, Deal Hunter,
  Tags, Stores, and Product Sources.
- Responsive deal/component cards with stable image, title, metadata, price,
  and action regions.
- PC Build component-type filtering with server-side type validation.
- Dashboard low-price counts now exclude flat or insufficient histories.
- Product badges use warning, decision, and tracking priority.

## UI conventions

- Cards stack on mobile and keep price/actions visually distinct on desktop.
- Product titles wrap to two lines and retain a full-title tooltip.
- Charts appear only when comparable historical prices exist.
- Empty lists explain the next action and expose one clear CTA.
- User-facing PC shopping flows are Spanish; advanced upstream/system screens
  remain a documented localization debt.

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
- Complete localization of upstream System, Settings, Search, and core actions.
- Final visual acceptance pass on the supported desktop/tablet/mobile widths.

## Risks

- Backend dedupe window is 300 seconds.
- Pauses created before `paused_by_user` have no recorded provenance.
- The UI stabilization working tree remains uncommitted pending visual review.

## Next exact action

Complete the manual visual review, then prepare the UI milestone checkpoint.
