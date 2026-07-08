# Mission 1850 — QA pre-James sweep (F1–F4)

Close the four findings from the 8 Jul staging QA before James's sign-off. ONE PR off fresh
`main` (`856b906`), DO-NOT-MERGE, claims-vs-evidence table in the PR body (PR #12). Merge is
John's `ymerge`. Tier AMBER.

This file documents the shipped approach and records the one deviation from the issue's
literal instructions (F4 asset list), per the mission's contract requirement.

## F1 — /demo/reset latent-destructive (priority)

`DemoController::reset()` called `Artisan::call('migrate:fresh')` with no `Artisan` facade
import (resolved to a non-existent controller-namespace class → 500 before executing). The
route was unauthenticated, sat **outside** `throttle:demo`, and its guard blocked only
`production` while staging runs `APP_ENV=staging`.

Shipped:
1. Import `Illuminate\Support\Facades\Artisan`.
2. Gate execution on `config('demo.reset_enabled')` → `env('DEMO_RESET_ENABLED', false)` in a
   new `config/demo.php` (council P3: explicit key/path, documented in `.env.example` incl.
   the `config:cache` toggling caveat). 403 when false/absent.
3. Production hard-block + Slack alert retained **first**, independent of the flag.
4. **[Council P1]** Moved the `reset` route **inside** the `throttle:demo` group so the env
   flag is not the sole barrier on an internet-reachable non-production host.
5. **[Council P4]** `DemoResetTest` asserts: 403 on absent flag, 403 on explicit false,
   success (200 + reset message, Artisan dispatched once) in `testing` when enabled, and the
   production hard-block firing the Slack alert **both** with the flag on and off.

## F2/F3 — attribution + stale model strings

- `Landing.vue` and `PatientLayout.vue` footers reworded from an app-level misattribution to
  "Built by Attalis Capital. Derived from PostVisit.ai by Michal Nedoszytko (MIT), created for
  Anthropic's Built with Opus 4.6 hackathon." (MIT NOTICE attribution retained; final wording
  is John's call).
- `Login.vue` → "Powered by Claude by Anthropic". `LegalPage.vue:124` AI Model → "Claude by
  Anthropic (currently Opus 4.8, served via a secured gateway)". `LegalPage.vue:118` historical
  derivation line left untouched (accurate for the upstream prototype).
- Out of patient-facing scope, documented in the PR body not changed: `Showcase*.vue`
  (`/showcase/*` pitch surfaces) and `settings.js:15` (empty-tiers fallback label).

## F4 — cosmetic sweep

1. `startScenario` validates `'role' => 'sometimes|in:patient'` → a stray `role: "doctor"`
   returns 422 instead of silently handing back a patient session. Covered by a
   `DemoScenarioTest` case.
2. **Asset removal — DEVIATION from the issue's list.** A grep across
   `app config resources tests database public routes bootstrap` shows most of the issue's
   "orphaned" candidates are live:

   | Asset | Referenced by | Action |
   |---|---|---|
   | `demo/doctors/{cardiologist-chen,endocrinologist,gastroenterologist,pulmonologist}/` | none (docblock example only) | **DELETE** |
   | `demo/apple-watch-alex.json` | none (byte-identical dup of the live `public/` copy) | **DELETE** |
   | matching entries in `demo/generate-doctor-photos.py` | regenerated deleted dirs | **PRUNE** |
   | `demo/doctors/default/` | doctorPhoto fallback | **RETAIN** (mandated) |
   | `demo/guidelines/**` | `ContextAssembler`, `GuidelinesRepository`, `GuidelinesRepositoryTest` | **RETAIN** — stale premise |
   | `public/data/apple-watch-alex.json` | `ContextAssembler.php:734`, `HealthDashboard.vue:112` | **RETAIN** — live, patient-facing |
   | `demo/transcript.txt` | `TranscriptController.php:53` | **RETAIN** — live feature |

   Deleting the retained assets would produce silent 404s and red CI (council P2). Flagged
   for John in the PR body in case a deeper refactor is intended.

## Exit criteria

- T1: F1 import + guard + throttle + passing feature test in one commit set.
- T2: F2/F3 copy landed; F4 role param rejected; only unreferenced assets removed with grep
  evidence in the PR body.
- T3: CI green at HEAD (no local PHP/Node on the runner box — CI is the verification gate);
  built bundle has no hackathon credit on Landing/PatientLayout and no stale patient-facing
  "Opus 4.6" outside `LegalPage:118` and the documented showcase/settings exclusions.

## Frozen surfaces — untouched, byte-identical

`config/triage.php`, `EscalationDetector` (keywords + `CRITICAL_RECOMMENDED_ACTION`),
`TriageConsensus`, `prompts/escalation-detector.md`, `prompts/wound-triage-*.md`, and the five
approved copy hotspots (ChatPanel header, print title/footer, share title, PatientLayout chat
button, Settings audit-log actor).
