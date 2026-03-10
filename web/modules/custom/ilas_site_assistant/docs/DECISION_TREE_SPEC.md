# ILAS Site Assistant Intent Routing Decision Tree Specification

**Version:** 2.0
**Date:** 2026-01-23
**Status:** Active

## Overview

This document specifies the ILAS Site Assistant decision flow. The system first evaluates `PreRoutingDecisionEngine` for safety, out-of-scope, policy, and urgency overrides, then routes continue-path messages to primary intents with optional disambiguation.

## Supported Routing Intents

| Intent ID | Description | Primary Action |
|-----------|-------------|----------------|
| `apply_for_help` | User wants to apply for legal services | Route to /apply-for-help |
| `legal_advice_line` | User wants to call/speak to someone | Route to /Legal-Advice-Line |
| `offices_contact` | User needs office location/hours/contact | Route to /contact-us |
| `forms_finder` | User needs to find legal forms | Route to /forms |
| `guides_finder` | User needs self-help guides | Route to /guides |
| `donations` | User wants to donate | Route to /donate |
| `feedback` | User wants to give feedback/complaint | Route to /get-involved/feedback |
| `faq` | User has general/definitional questions | Search FAQ index |
| `risk_detector` | User interested in senior risk assessment | Route to /senior-risk-detector |
| `services_overview` | User wants to know what ILAS does | Route to /services |

Pre-routing outcomes such as safety exits, out-of-scope exits, policy exits, and urgency overrides are resolved before intent routing and are owned by `PreRoutingDecisionEngine`, not by `IntentRouter`.

## Decision Tree Flow

```
                    +-------------------+
                    |   User Message    |
                    +--------+----------+
                             |
                             v
                    +--------+----------+
                    | 1. PRE-ROUTING    |
                    | Decision Engine   |
                    +--------+----------+
                             |
              +--------------+--------------+
              |                             |
        [EXIT]                      [CONTINUE]
              |                             |
              v                             v
    +---------+---------+        +----------+----------+
    | Safety / OOS /    |        | 2. PRIMARY INTENT   |
    | policy response   |        |    Detection        |
    +-------------------+        +----------+----------+
                                                          |
                                            +-------------+-------------+
                                            |                           |
                                       [CONFIDENT]                 [AMBIGUOUS]
                                            |                           |
                                            v                           v
                                  +---------+---------+      +----------+----------+
                                  | Route to intent   |      | 4. DISAMBIGUATION   |
                                  | handler           |      |    (1 question max) |
                                  +-------------------+      +----------+----------+
                                                                        |
                                                                        v
                                                              +---------+---------+
                                                              | Present options   |
                                                              | to clarify intent |
                                                              +-------------------+
```

## 1. Pre-Routing Decision Contract

**Purpose:** Resolve safety exits, out-of-scope exits, policy exits, and urgency overrides from a single authoritative contract before intent routing begins.

### Precedence Order
1. `safety_exit`
2. `oos_exit`
3. `policy_exit`
4. `continue` with optional urgency override

The trigger categories below are enforced by `PreRoutingDecisionEngine` via `SafetyClassifier`, `OutOfScopeClassifier`, `PolicyFilter`, and engine-owned urgency evaluation.

### Trigger Categories

#### 1.1 Domestic Violence (`urgent_dv`)
**Triggers (English):**
- "hitting me", "hit me", "hits me", "beat me", "beating me"
- "abusive partner/husband/wife/boyfriend/girlfriend"
- "domestic violence", "dv"
- "threatened to kill", "kill me"
- "scared for my life", "fear for my life", "afraid for my life"
- "stalking", "being followed"
- "protection order", "restraining order" (+ urgency indicators)

**Triggers (Spanish):**
- "me pega", "me golpea", "pegando"
- "abusivo", "abusiva"
- "violencia domestica"
- "tengo miedo", "miedo por mi vida"
- "amenazado"

**Response:**
```
If you are in immediate danger, please call 911.

For domestic violence support:
- National DV Hotline: 1-800-799-7233
- Idaho DV Hotline: 1-800-669-3176

Idaho Legal Aid can help with protection orders and safety planning.

[Apply for Help] [Legal Advice Line]
```

#### 1.2 Imminent Eviction (`urgent_eviction`)
**Triggers (English):**
- "eviction notice" + "today/tomorrow"
- "evicted today", "evicted tomorrow"
- "sheriff coming", "sheriff is coming"
- "locked out", "changed the locks"
- "3 day notice", "three day notice", "5 day notice"
- "court date tomorrow", "court date today"
- "thrown out", "nowhere to go"

**Triggers (Spanish):**
- "aviso de desalojo"
- "me estan echando", "echando hoy/manana"
- "cambiaron las cerraduras"
- "no tengo donde ir"

**Response:**
```
If you have an eviction hearing today or tomorrow, act now:

1. Go to court at the scheduled time
2. Ask for a continuance if you need more time
3. Call our Legal Advice Line immediately

We may be able to help you respond to your eviction.

[Legal Advice Line] [Apply for Help] [Eviction Forms]
```

#### 1.3 Active Scam/Fraud (`urgent_scam`)
**Triggers (English):**
- "identity theft" + "just happened/today/ongoing"
- "got scammed", "being scammed", "scam in progress"
- "gave them my" + (bank/social security/credit card)
- "fake contractor" + "paid them"
- "social security scam", "irs scam"

**Triggers (Spanish):**
- "robaron mi identidad"
- "me estafaron", "me estan estafando"
- "fraude"

**Response:**
```
If you're experiencing identity theft or a scam:

1. Contact your bank immediately if you shared financial info
2. Report to FTC: reportfraud.ftc.gov or 1-877-438-4338
3. Place a fraud alert: Contact Equifax, Experian, or TransUnion

Idaho Legal Aid can help with consumer fraud cases.

[Apply for Help] [Legal Advice Line] [Consumer Guide]
```

#### 1.4 Deadline Crisis (`urgent_deadline`)
**Triggers (English):**
- "deadline tomorrow", "deadline today"
- "due tomorrow", "due today"
- "file by" + (today/tomorrow/date)
- "respond by" + (today/tomorrow/date)
- "court date tomorrow", "court date today"
- "have to respond today"

**Triggers (Spanish):**
- "fecha limite manana", "fecha limite hoy"
- "tengo que responder hoy/manana"
- "corte manana"

**Response:**
```
If you have a legal deadline today or tomorrow:

1. Don't ignore the deadline - this can result in a default judgment
2. Call our Legal Advice Line now for urgent guidance
3. If you can't meet the deadline, you may be able to request an extension

[Legal Advice Line] [Apply for Help]
```

## 2. Out-of-Scope Detection

**Purpose:** Politely redirect users whose needs are outside ILAS services.

### 2.1 Criminal Matters
**Triggers:** criminal, felony, misdemeanor, arrested, jail, prison, DUI, DWI, public defender, bail, probation violation

**Response:**
```
Idaho Legal Aid handles civil legal matters only. For criminal cases:

- If you can't afford an attorney, ask the court to appoint a public defender
- Idaho State Bar Lawyer Referral: (208) 334-4500

If you have a civil legal issue related to your situation, we may be able to help.

[Our Services] [Legal Advice Line]
```

### 2.2 Immigration
**Triggers:** immigration, deportation, visa, green card, asylum, citizenship, undocumented

**Response:**
```
Idaho Legal Aid doesn't currently handle immigration cases. For immigration help:

- Catholic Charities of Idaho: (208) 345-6031
- ACLU of Idaho: (208) 344-9750

[Our Services]
```

### 2.3 Out-of-State
**Triggers:** Oregon, Washington state, Montana, Nevada, Utah, Wyoming, California, out of state

**Response:**
```
Idaho Legal Aid serves Idaho residents only. For legal help in other states:

- LawHelp.org can connect you with legal aid in your state
- LSC.gov has a directory of all legal aid organizations

[LawHelp.org]
```

### 2.4 Business/IP
**Triggers:** patent, trademark, copyright, start a business, incorporate, LLC

**Response:**
```
Idaho Legal Aid focuses on civil legal issues for low-income individuals. For business matters:

- Idaho Secretary of State: sos.idaho.gov
- Idaho Small Business Development Center

[Our Services]
```

### 2.5 Emergency Override (911)
**Triggers:** 911, call 911, emergency + danger, someone dying, heart attack, being attacked

**Response:**
```
If this is an emergency, please call 911 immediately.

For non-emergency legal help, contact our Legal Advice Line.

[Legal Advice Line]
```

## 3. Primary Intent Detection

### Priority Order
Pre-routing outcomes are resolved before this stage. On `continue`, the routing priority is:
1. `greeting` (short messages only)
2. `apply_for_help` / `eligibility`
3. `legal_advice_line`
4. `offices_contact`
5. `services_overview`
6. `risk_detector`
7. `donations`
8. `feedback`
9. `faq`
10. `forms_finder`
11. `guides_finder`

### Intent Patterns

#### apply_for_help
**Strong Signals:**
- "apply for help", "sign me up", "get started"
- "need a lawyer", "need legal help", "need an attorney"
- "free lawyer", "free legal help", "legal aid"
- "como aplico", "necesito abogado", "ayuda legal"

**Negative Filters:**
- criminal terms, immigration terms, out-of-state references

#### legal_advice_line
**Strong Signals:**
- "call", "phone", "hotline", "advice line", "talk to someone"
- "speak to a real person", "phone number"
- "llamar", "telefono", "linea de ayuda"

#### offices_contact
**Strong Signals:**
- "office", "location", "address", "near me"
- "hours", "when open", "closed on saturday"
- "Boise office", "Pocatello office", "Twin Falls"
- "donde esta", "horario", "oficina"

#### forms_finder
**Strong Signals:**
- "form", "forms", "paperwork", "court papers"
- "divorce form", "custody form", "eviction response"
- "formulario", "documentos"

#### guides_finder
**Strong Signals:**
- "guide", "how to", "step by step", "self help"
- "tenant rights", "represent myself"
- "guia", "instrucciones"

#### donations
**Strong Signals:**
- "donate", "donation", "give money", "support"
- "tax deductible", "charitable contribution"
- "donar", "donacion"

#### feedback
**Strong Signals:**
- "feedback", "complaint", "grievance"
- "bad experience", "speak to supervisor"
- "queja", "comentario"

#### faq
**Strong Signals:**
- "faq", "frequently asked", "common questions"
- "what is [legal term]", "what does [term] mean"
- "preguntas frecuentes"

#### risk_detector
**Strong Signals:**
- "risk detector", "risk assessment", "legal checkup"
- "senior citizen" + "legal issues"
- "elder law", "older adult"

#### services_overview
**Strong Signals:**
- "what do you do", "what services", "types of help"
- "areas of law", "practice areas"
- "servicios que ofrecen"

## 4. Disambiguation

### When to Disambiguate
- Multiple strong intent signals present
- Confidence below 0.7 threshold
- Topic detected but action unclear

### Disambiguation Questions (Max 1)

#### Topic but No Action
**User:** "I have an eviction issue"
**Response:**
```
I can help with eviction issues. What would you like to do?

[Find eviction forms] [Read eviction guide] [Apply for legal help] [Call advice line]
```

#### Apply vs Information
**User:** "divorce"
**Response:**
```
Are you looking for information about divorce or do you need legal help?

[Find divorce information] [Apply for legal help with divorce]
```

#### Forms vs Guides
**User:** "child custody"
**Response:**
```
What type of help do you need with child custody?

[Find custody forms] [Read custody guide] [Apply for legal help]
```

#### Contact Disambiguation
**User:** "contact you"
**Response:**
```
How would you like to contact us?

[Call Legal Advice Line] [Find office locations] [Apply online]
```

### Disambiguation Rules
1. Maximum 1 clarifying question per conversation turn
2. Always include "Apply for legal help" as an option
3. Options should be 2-4 choices maximum
4. After disambiguation, route directly to selected intent

## 5. Safe Response Templates

### Template Requirements
1. **No legal advice** - Never tell users what they "should" do legally
2. **Grounded** - Only reference ILAS services and known resources
3. **Action-oriented** - Always provide clear next steps
4. **Safety-first** - Include emergency resources when relevant
5. **Accessible** - Plain language, avoid legal jargon
6. **Spanish support** - Key phrases available in Spanish

### Template Structure
```
[Acknowledgment] (1 sentence)

[Information/Context] (1-2 sentences, factual only)

[Call to Action] (Clear next steps)

[Buttons: Primary CTA] [Secondary option]
```

### Example Templates

#### apply_for_help
```
Idaho Legal Aid provides free legal help to eligible Idahoans.

To find out if you qualify and apply for assistance, you can complete our online application or call our Legal Advice Line.

[Apply for Help] [Legal Advice Line]
```

#### forms_finder (with topic)
```
Here are forms related to [topic]:

[Form 1 title]
[Form 2 title]
[Form 3 title]

Need help understanding the forms? Our guides can help.

[View all forms] [Find guides]
```

#### out_of_scope
This outcome is produced by the pre-routing decision layer, not by `IntentRouter`.
```
I understand you're looking for help with [topic].

Idaho Legal Aid focuses on civil legal issues for low-income Idahoans. For [topic], you may want to contact:

- [Resource 1]
- [Resource 2]

Is there another way I can help?

[Our Services] [Legal Advice Line]
```

## 6. Confidence Scoring

| Confidence Level | Range | Action |
|-----------------|-------|--------|
| High | 0.85-1.0 | Route directly |
| Medium | 0.70-0.84 | Route with soft confirmation |
| Low | 0.50-0.69 | Disambiguate |
| Very Low | <0.50 | Fallback to suggestions |

### Confidence Factors
- **+0.3** Exact phrase match
- **+0.2** Multiple signal keywords
- **+0.1** Topic context match
- **-0.2** Negative keyword present
- **-0.1** Very short message (<3 words)
- **-0.1** Multiple competing intents

## 7. Analytics Tracking

Track for each interaction:
- `intent_detected` - Final routed intent
- `confidence_score` - Confidence at routing
- `disambiguation_shown` - Whether disambiguation was needed
- `disambiguation_choice` - Which option user selected
- `pre_routing_decision_type` - safety/oos/policy/continue
- `pre_routing_winner_source` - safety/out_of_scope/policy/urgency/none
- `out_of_scope_type` - Category if out-of-scope
- `language_detected` - en/es/mixed

## 8. Test Coverage Requirements

| Category | Count | Description |
|----------|-------|-------------|
| Intent fixtures | 120 | 10 per intent (12 intents) |
| Multi-intent | 25 | Messages with competing signals |
| Spanish/Spanglish | 20 | Non-English variations |
| Prompt injection | 15 | Adversarial/manipulation attempts |
| **Total** | **180** | Minimum test cases |

### Accuracy Targets
- **Intent accuracy:** >= 90%
- **Clarification rate:** <= 15%
- **Misroute rate:** <= 5%
- **Safety compliance:** 100%

---

## Appendix A: Spanish Phrase Quick Reference

| English | Spanish |
|---------|---------|
| Apply for help | Aplicar para ayuda |
| Legal advice line | Linea de consejos legales |
| Find an office | Encontrar una oficina |
| Find forms | Encontrar formularios |
| I need a lawyer | Necesito un abogado |
| Eviction | Desalojo |
| Domestic violence | Violencia domestica |
| Child custody | Custodia de menores |
| Divorce | Divorcio |
| I'm scared | Tengo miedo |

## Appendix B: Changelog

| Version | Date | Changes |
|---------|------|---------|
| 2.0 | 2026-01-23 | Added urgent_safety fast-path, disambiguation flow, safe templates |
| 1.0 | 2025-xx-xx | Initial intent routing |
