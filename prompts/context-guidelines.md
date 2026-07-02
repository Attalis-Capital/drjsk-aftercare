# Context Guidelines Template

## Role

This template defines how clinical guidelines are formatted and injected into the AI context window. It is not a prompt for a specific AI subsystem, but a formatting standard for reference material.

## Purpose

Clinical guidelines are loaded into the context window as reference material for the Q&A Assistant and Medical Explainer. This template ensures uniform formatting across different guideline sources for plastic and reconstructive surgery aftercare.

## Format

Each guideline block should follow this structure:

```
--- CLINICAL GUIDELINE ---
Source: [Organisation, e.g. ASPS, BAPRAS, ANZ guideline body]
Title: [Full guideline title]
Year: [Publication year]
DOI: [Digital Object Identifier]
PMID: [PubMed ID]
URL: [Direct link to publication]
Relevance: [Why this guideline is included for this visit]
Specialty: [plastic_surgery|reconstructive_surgery|general|...]

### Key Recommendations

[Extracted recommendations relevant to this patient's procedure]

### Evidence Level

[Grade of recommendation and level of evidence for each key point]

### Patient-Relevant Sections

[Sections specifically relevant to explaining the patient's procedure and recovery]

--- END GUIDELINE ---
```

## Citation Requirements

Every medical reference MUST include at least one of:
- **PMID** - PubMed ID (e.g. `37622666`)
- **DOI** - Digital Object Identifier

References without PMID or DOI are considered unverified and should not be cited in patient-facing responses.

Citation format for responses:
```
(Author AB et al., Plast Reconstr Surg 2023; PMID: 00000000)
```

## Usage Notes

- Guidelines are loaded once per chat session as static context
- Only guidelines relevant to the visit's procedure and diagnoses are included
- The large context window can accommodate several full guideline documents
- Guidelines should be pre-processed to extract the most relevant sections rather than loading entire documents
- Source citations must be preserved for transparency in patient-facing responses
- All PMID references can be verified at runtime via PubMed E-utilities API

## Available Guideline Topics (Demo - Plastic and Reconstructive Surgery)

Reference topics loaded as context when a plastic surgery visit is active:

1. **Enhanced Recovery After Surgery (ERAS) - Breast Reconstruction**
   - Perioperative care pathways, including autologous (DIEP flap) reconstruction.

2. **Venous Thromboembolism (VTE) Prophylaxis in Plastic Surgery**
   - Risk assessment and DVT prophylaxis for abdominoplasty and prolonged procedures.

3. **Surgical Antibiotic Prophylaxis**
   - Appropriate use of prophylactic antibiotics for clean and clean-contaminated procedures.

4. **Post-operative Wound Care and Scar Management**
   - Dressing care, monitoring for infection and dehiscence, and scar minimisation.

5. **Peri-operative Supplement and Anticoagulant Cessation**
   - Guidance on stopping agents that increase bleeding risk (fish oil, vitamin E, turmeric, arnica) before surgery.

Only include a guideline in patient-facing responses when it carries a verifiable PMID or DOI. Topic summaries above are reference scaffolding, not citable sources on their own.
