# Legacy file links — content-owner review queue

Deferred items from the §8.4 legacy-file remediation
(`docs/pantheon-cloudflare-preimplementation-validation.md`, tracker item 7).

The mechanical half of item 7 is done: eight redirects are live (rid 5157–5158, 5160–5165) and
verified. Everything below was **deliberately not changed** because it needs a human decision —
legal currency, a language-equity call, or a destination that cannot be established from evidence.

Nothing here should be actioned by an engineer alone.

**Volumes** are Cloudflare edge counts for 2026-07-26 → 2026-07-29 (3 days), the maximum span the
zone's GraphQL retention allows. They are *lower* than §8.4's 7-day figures for two reasons that
are not traffic decline: the `www`→apex Single Redirect (item 5) now answers a share of these
requests with a 301, and the WAF answers another share with a 403. Treat these as a floor.

**Linking page — read this before using the column.** §8.4's "Linking page: our site" was inferred
from Cloudflare's referrer *host*, not a verified link. A scan of every text, varchar and blob
column in the live database (1,660 columns, whole-DB) found **zero** current content anywhere on
the site containing `sites/idaholegalaid.org/files`. There is no page to edit for any row below.
The referrer host comes from stale browser history, external caches, links inside PDFs we have
distributed, and search-engine snapshots. Redirects are therefore the only available lever, and
the "current linking page" column reads "none (verified)" throughout.

---

## 1. Legal-currency review — destination exists but may be superseded

### 1.1 `MANUFACTURED HOMES.brochure.pdf`

| Field | Value |
|---|---|
| Broken URL | `/sites/idaholegalaid.org/files/MANUFACTURED%20HOMES.brochure.pdf` |
| Request volume | 26 (3d) · 39 (7d, §8.4) |
| Current linking page | none (verified) |
| Candidate replacement | `/sites/default/files/2025-12/advice-for-renters-manufactured-homes.pdf` **or** `/sites/default/files/2025-12/manufactured-homes-advice-renters.pdf` |
| Reason review is required | §8.4 rates this High confidence, but verification contradicts that. The proposed destination carries XMP `ModifyDate` **2013-09-05** — seven years *older* than the 2020-02-24 file the legacy URL used to serve (confirmed from the Wayback capture). A third, newer manufactured-homes document exists on the site: `manufactured-homes-advice-renters.pdf`, `ModifyDate` **2025-12-23**. Redirecting to a superseded edition of tenant-rights material is precisely what the brief forbids. |
| Lawyer must verify legal currency | **Yes.** Manufactured/mobile home tenancy rules under Idaho Code Title 55 Ch. 20 have changed since 2013. |
| Language-equity considerations | A Spanish counterpart exists (`advice-for-renters-manufactured-homes-spanish.pdf`, 135,509 B). Whichever English edition is chosen, the Spanish edition must be checked for parity and given its own redirect from `MANUFACTURED HOMES.Spanish brochure_0.doc` and related legacy names. |
| Recommended final action | Confirm which of the two English files is current, then add the redirect. If `manufactured-homes-advice-renters.pdf` (2025) is current, retire the 2013 file rather than leaving both reachable. |
| Status | A redirect to the 2013 file was created (rid 5159) and **removed** on verification. The path returns 404 pending this review. |

---

## 2. No replacement identified — restoration review

These have no candidate on the live site. §8.4 marks them Low confidence. Per the brief, an
obsolete legal document must **not** be restored merely to clear a 404 — each needs a lawyer's
read before anything is restored or repointed. `scripts/recover_wayback_files.py` already targets
this prefix and can recover the originals for review.

| Broken URL | Vol (3d) | Linking page | Candidate replacement | Why review | Lawyer? | Language equity | Recommended action | Owner |
|---|---:|---|---|---|---|---|---|---|
| `Assistive_Animal_Brochure.pdf` | 21 | none (verified) | none — 404 on both prefixes | Fair-housing assistive-animal guidance; HUD guidance changed in 2020 | **Yes** | No Spanish variant found; if restored, commission one | Recover from Wayback, review, then republish or retire | Housing / Fair Housing lead |
| `Grandparent Visitation Rights Guide - Final.pdf` | 17 | none (verified) | none | Idaho grandparent-visitation case law is unsettled; an outdated guide is a liability | **Yes** | No Spanish variant found | Recover, review, republish or retire | Family Law lead |
| `Divorce - No Minor Children - Court Process.pdf` | 26 | none (verified) | none | Court process/forms change; must match current Idaho Supreme Court forms | **Yes** | Spanish divorce material exists elsewhere — check parity | Recover, review against current court forms | Family Law lead |
| `EXECUTION OF JUDGMENTS brochure.pdf` (undated) | 7 (§8.4) | none (verified) | consolidate with the dated `…brochure_May 2020.pdf` variant | Two variants of the same brochure; consolidating first prevents handing the State a URL that later moves | **Yes** | Check for a Spanish counterpart | Consolidate to one canonical file, then redirect both legacy names to it | Consumer / Litigation lead |

---

## 3. Page-not-document redirects — editorial judgement

The destination is a topic page or index, not the document the user asked for. §8.4 rates these
Medium. Sending a reader who wanted a specific brochure to a general index is a content decision.

| Broken URL | Vol (3d) | Linking page | Candidate replacement | Why review | Lawyer? | Language equity | Recommended action | Owner |
|---|---:|---|---|---|---|---|---|---|
| `Process of a Civil Lawsuit Generally.pdf` | 109 | none (verified) | `/resources/senior-rights-and-information` | Highest-volume deferred row. Destination is a page, not the document; confirm it actually covers civil-lawsuit process | No | Spanish translation of the destination exists (`/resources/derechos-e-informacion-de-las-personas-mayores`) — the redirect should not strand Spanish readers | Confirm the page covers the topic, or publish a replacement document | Senior Legal Hotline lead |
| `Expungement brochure final.pdf` | 23 | none (verified) | `/forms` | Expungement eligibility changed materially; a generic forms index may be the honest answer, or a new brochure may be warranted | **Yes** | Check for a Spanish expungement resource | Decide index-vs-document, then redirect | Criminal Records lead |
| `Resource_and_Referral_Guide.pdf` | 15 | none (verified) | `/forms` | A referral guide is inherently perishable; redirecting to a forms index loses the referral content entirely | No | Referral guides are high-value for LEP users; a Spanish version should be confirmed | Consider a current referral page rather than `/forms` | Intake / Outreach lead |
| `Landlord Tenant Rights and Responsibilities - List Format - updated 8.19.20.pdf` | 35 | none (verified) | `/resources/landlord-and-tenant` | 2020 edition; landlord-tenant law has changed | **Yes** | Spanish counterpart must be confirmed | Confirm currency, then redirect to the topic page or a current document | Housing lead |
| `Landlord Tenant Rights and Responsibilities Brochure - updated 8.19.20.pdf` | 17 | none (verified) | same as above | **Not in §8.4's table** — surfaced during this work. A sibling of the row above; both must be resolved together | **Yes** | As above | Resolve alongside the List Format variant | Housing lead |

---

## 4. Language equity

### 4.1 `Spanish Senior Guidebook_0.pdf`

| Field | Value |
|---|---|
| Broken URL | `/sites/idaholegalaid.org/files/Spanish%20Senior%20Guidebook_0.pdf` |
| Request volume | 6 (7d, §8.4) |
| Current linking page | none (verified) |
| Candidate replacement | `/forms` (§8.4's suggestion) |
| Reason review is required | This is the **Spanish** edition of a senior guidebook. Redirecting Spanish-language readers to an English forms index is a downgrade, not a fix. §8.4 itself flags: "verify a Spanish equivalent exists." |
| Lawyer must verify legal currency | **Yes** — and a qualified Spanish reviewer, not machine translation. Per the site's language policy, ES is human-translated; SW and NL are machine-translated and noindexed. |
| Language-equity considerations | The whole point of this row. If an English senior guidebook is current but the Spanish one is not, that gap is itself the finding and should be tracked as a translation task, not papered over with a redirect. |
| Recommended final action | Establish whether a current Spanish senior guidebook exists. If yes, redirect to it. If no, commission the translation and leave the 404 until it lands, rather than redirecting to English. |
| Owner | Senior Legal Hotline lead + Language Access coordinator |

### 4.2 The `/files/html/` interactive modules — 12 content rows

These are **not** part of the §8.4 404 population. They are live links in current content that
already resolve (301 → `/forms`), so no user hits a 404. They are queued here because the link
text promises something the destination does not deliver, and half the rows are Spanish.

**Evidence.** 70 redirect entities already route the entire `files/html/*` family to `/forms`,
including every mojibake-escalated variant of the accented Spanish slugs. Wayback shows these were
interactive training modules (`presentation_html5.html`, `amplaunch.html`), not documents. Node 58's
own accordion copy describes them as "a guided informational program relating to End of Life
Planning in Idaho … available in English and Spanish."

**Why an engineer must not decide this.** Repointing means choosing what replaces a retired
interactive module. There is no equivalent artifact on the site. And two of the four distinct links
are the Spanish counterparts, so the choice is a language-equity decision.

**Specific defects for the owner to weigh:**
- On node 52 the "Tenant Guide in English" link would point at the very page it sits on.
- Both Spanish links currently deposit Spanish-language readers on an English forms index.

Baseline captured in `docs/legacy-file-redirects-8-4-state.json` under `deferred_content_baseline`.

| Node | Alias (en) | Paragraph IDs | Revision IDs | Field | Link text → current destination |
|---|---|---|---|---|---|
| 52 `Housing Guide for Tenants` | `/resources/housing-guide-tenants` | 88 (en), 801 (es), 1064 (sw), 1327 (nl) | 4191, 3386, 3649, 3912 | `field_external_link` δ0 | "Tenant Guide in English" → `https://www.idaholegalaid.org/files/html/housing-guide-for-tenants` |
| 52 | as above | 88, 801, 1064, 1327 | as above | `field_external_link` δ1 | "Tenant Guide in Spanish/Espanol" → `…/files/html/guía-de-vivienda-para-inquilinos` |
| 58 `Senior Rights and Information` | `/resources/senior-rights-and-information` | 132, 760, 1023, 1286 (parents 135, 763, 1026, 1289) | 2797, 3345, 3608, 3871 | `field_accordion_body` ("End of Life Planning Brochure") | "End of Life Planning Module - English" → `…/files/html/end-of-life-planning` |
| 58 | as above | 132, 760, 1023, 1286 | as above | `field_accordion_body` | "End of Life Planning Module - Spanish" → `…/files/html/planificación-para-el-final-de-la-vida` |

| Field | Value |
|---|---|
| Lawyer must verify legal currency | Only if a replacement document is published. Removing a dead promise does not need legal review. |
| Recommended final action | Decide per link: (a) remove the link and the sentence promising a module, (b) point to a current equivalent, or (c) rebuild the module. Whichever is chosen, English and Spanish must be resolved together. |
| Owner | Housing lead (node 52) · Senior Legal Hotline lead (node 58) · Language Access coordinator (both) |

---

## 5. External backlink — State of Idaho

`courtselfhelp.idaho.gov` links to
`/sites/idaholegalaid.org/files/EXECUTION%20OF%20JUDGMENTS%20brochure_May%202020.pdf`
(9 requests, 7d). This is the only external government backlink §8.4 identified, and the brief
requires that it be preserved.

**Not remediated here.** §8.4 rates it Medium and flags it for review, and it is entangled with the
undated `EXECUTION OF JUDGMENTS brochure.pdf` variant in §2 above. Handing the State a URL before
that consolidation would mean asking them to change their link twice.

**Destination prepared, pending sign-off.** Once a lawyer confirms the brochure is legally current
and the two variants are consolidated to one canonical file, send the notification below. Do not
send it before then.

> **Subject:** Updated URL for the Idaho Legal Aid Services "Execution of Judgments" brochure
>
> Hello,
>
> Idaho Legal Aid Services has migrated its website and retired the old
> `/sites/idaholegalaid.org/files/` document path. A page on courtselfhelp.idaho.gov currently
> links to:
>
> `https://www.idaholegalaid.org/sites/idaholegalaid.org/files/EXECUTION%20OF%20JUDGMENTS%20brochure_May%202020.pdf`
>
> The current address is:
>
> `https://idaholegalaid.org/<CANONICAL URL — fill in after consolidation>`
>
> The old address now issues a permanent redirect, so existing links keep working, but updating to
> the address above avoids the extra hop and will stay stable.
>
> Thank you,
> Idaho Legal Aid Services

**Interim protection.** Until the row is resolved, the old URL must keep working. Adding a redirect
for it is gated on the legal-currency read — so if that read is going to take time, the safer
sequencing is to review this row first, precisely because an external government site depends on it.

| Field | Value |
|---|---|
| Owner | Consumer / Litigation lead, with the Executive Director for the outbound notification |
| Lawyer must verify legal currency | **Yes** — a State agency is pointing citizens at this document |
| Language equity | Check whether courtselfhelp.idaho.gov also links Spanish-language ILAS material |

---

## 6. Unmapped tail

§8.4 mapped the top 22 by volume and left roughly 150 paths unexamined ("The remaining 150
low-volume paths (1–5 requests each) are not yet mapped"). Today's Cloudflare pull shows 184
distinct legacy paths over 3 days totalling 1,631 requests, and several carry more volume than
rows §8.4 did map. None of these appear in §8.4's table.

| Path (under `/sites/idaholegalaid.org/files/`) | Vol (3d) | First-pass note |
|---|---:|---|
| `Common Financial Scams Brochure - Final - 12.16.22.pdf` | 30 | Likely has a modern equivalent; needs mapping |
| `ACLU Voting Rights Brochure - English.pdf` | 25 | Third-party (ACLU) document — may belong offsite; "English" implies a Spanish sibling |
| `Ex Parte Emergency Temporary Order Packet.pdf` | 22 | Court packet — must match current Idaho Supreme Court forms |
| `Answers to Common Bankruptcy Questions.pdf` | 22 | Modern set has `bankruptcy-basics-guide.pdf`, `bankruptcy-pro-se-guide.pdf` |
| `Custody Basics Guide - 7.1.21.pdf` | 20 | Dated 2021; currency check needed |
| `Contempt Guide - Last Updated 11.2021.pdf` | 15 | Dated 2021; currency check needed |
| `How to Change Gender on Birth Certificate Guide.pdf` | 10 | Idaho law on this has been litigated; **high** legal-currency risk |
| `Civil Protection Order Guide - 3.2.22.pdf` | 10 | Dated 2022; currency check needed |

**Leave 404 — no action.** Stale D7 image derivatives, consistent with the decision already taken
for the three image rows in §8.4: `styles/ui_front_page_carousel/public/Keys.jpg` (28),
`…/flickr-hang_in_there-doctor.jpg` (18), `…/Names.jpg` (16), `…/jasleen_kaur-Flickr.jpg` (15),
`…/Donate_Flickr_Mindful_One.jpg` (10), `favicon.ico` (16). Redirecting these has no user value.

**Recommended next step.** Run `ilas_redirect_automation`'s `redirect:analyze` / `redirect:preview`
over the full Cloudflare path list to generate candidate mappings, then bring the output back to
this queue for review rather than applying it. Do not blanket-rewrite the prefix.

---

## Related

- `docs/pantheon-cloudflare-preimplementation-validation.md` §8.4 — source analysis
- `docs/pantheon-cloudflare-implementation-tracker.md` — item 7, FU-16
- `docs/legacy-file-redirects-8-4-state.json` — pre-change snapshot and created rids
- `docs/legacy-file-redirects-8-4-rollback.md` — rollback procedure
- `scripts/seo/legacy-file-redirects-8-4.php` — the applied change
- `scripts/recover_wayback_files.py` — recovers originals for the restoration-review rows
