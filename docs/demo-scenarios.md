# Demo Scenarios - Clinical Visit Library

DrJSK AfterCare demo scenarios for the plastic and reconstructive surgery pilot
(practice of Dr James Southwell-Keely). The authoritative definitions live in
`config/demo-scenarios.php`; each scenario's patient data, transcript, and notes
live under `demo/visits/plastic-*`, and are materialised on demand by
`App\Services\Demo\DemoScenarioSeeder` when a demo session starts.

The legacy cardiology/multi-specialty scenarios and the standalone `DemoSeeder`
were retired in mission attalis-missions#1709.

> **Disclaimer:** All patient photographs are AI-generated and do not depict real
> individuals. Clinical scenarios are representative plastic-surgery cases and do
> not represent actual patients. All names, demographics, and medical data are
> entirely fictional.

## Scenarios

| Key | Scenario | Patient | Clinical Context |
|-----|----------|---------|------------------|
| `diep-flap` | DIEP Flap Reconstruction | Helen Whitfield | Day 5 recovery after right DIEP flap breast reconstruction; flap healthy, one abdominal drain in place; antibiotic prophylaxis, regular paracetamol, DVT prophylaxis |
| `abdominoplasty` | Abdominoplasty (Mummy Makeover) | Sophie Marchetti | Post-operative abdominoplasty with rectus diastasis repair; expected swelling/bruising, drain in place |
| `breast-reduction` | Breast Reduction / Mastopexy | Priya Ramanathan | Post-operative bilateral breast reduction for breast hypertrophy; expected swelling/bruising |

The `diep-flap` scenario is the default surfaced by the generic
`POST /api/v1/demo/start` endpoint.

## File Structure

```
demo/visits/
  plastic-01-diep-flap/
    patient-profile.json    # Demographics + conditions
    soap-note.json          # Structured visit note
    raw-transcript.txt      # Consultation transcript
    medical-terms.json      # Extracted medical terminology
  plastic-02-abdominoplasty/
    ...
  plastic-03-breast-reduction/
    ...
```

## Usage

Demo sessions are created via the demo API (no auth, rate-limited):

- `GET  /api/v1/demo/scenarios` - list the three plastic scenarios
- `POST /api/v1/demo/start-scenario` `{ "scenario": "diep-flap" }` - start a specific scenario
- `POST /api/v1/demo/start` - start the default scenario (`diep-flap`)

Each call materialises the scenario (patient, single treating surgeon, visit,
notes, observations) idempotently via `DemoScenarioSeeder`. There is no global
`db:seed` step for demo content.
