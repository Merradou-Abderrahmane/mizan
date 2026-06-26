# Mizan UI conventions

DaisyUI (`corporate` theme) on Tailwind v4 **is** the design system. These rules
constrain *how* DaisyUI is used so the operator panel is consistent by
construction — not polished screen-by-screen. **Every UI change MUST read and
conform to this file.** No custom components, no hand-rolled CSS, no new colour
tokens, no theme forks. If a rule here blocks you, stop and raise it — don't
work around it with bespoke markup.

**P0 — Colour is never the only signal.** Every status renders a unique icon +
text label alongside its hue. This keeps the panel colourblind-safe and lets
green/red stay meaningful.

## Spacing — one 4px scale, fixed roles
- Allowed layout spacing tokens: **`2, 3, 4, 6` only** (no `5`, no `8`).
- Page section gaps `space-y-6`; card padding `p-4`; in-card element gaps
  `gap-3`; list-row vertical padding `py-2`; inline/badge gaps `gap-2`.

## Cards — two depth levels (no flat stack of outlined boxes)
- **Primary card:** `bg-base-100 rounded-box shadow-sm` — **no border**; it floats
  on the `base-200` page.
- **Nested / secondary surface** (finalize band, evidence block, runner panel):
  `border border-base-300`, **no shadow**.
- Radius is the theme's `rounded-box` only — never hand-tune.

## Status colours — green/red belong to the operator verdict, nothing else
Hue carries meaning for exactly four things; everything else is greyscale + icon.
- **valide** → `badge-success`, **solid** · 🔒 ✓ "VALIDE"
- **non valide** → `badge-error`, **solid** · 🔒 ✕ "NON VALIDE"
- AI `semble valide` / `semble non valide` → outline + italic, **neutral (no
  hue)** · `≈✓` / `≈✕` + a trailing `ᵢ`
- AI **`à vérifier`** → outline + italic, **`badge-warning` (amber)** · `?` + `ᵢ`
- Run status → `processing` **`badge-info` (blue)** + spinner; `pending` ghost ⏳;
  `pass1_done` neutral ✓; `pass1_partial` neutral ◐; `error` neutral ⚠
- Runner checks → greyscale outline only: `pass` ✓, `fail` ✗, `skip` ghost ○
- An AI status is **never** solid and **never** green/red. A solid filled badge
  means operator-finalized — full stop.

## Finalize action — the primary element of every competence (R1)
- Finalize sits **directly under the competence header** (top of the card), as an
  elevated band: accent **left border** (`border-l-4`) + subtle `bg-base-200` tint.
  Never buried at the card bottom.
- **Unfinalized** → expanded and prominent, no verdict preselected. **Finalized**
  → collapses to a compact strip (verdict badge + Reopen).
- The finalize radios and button stay **comfortably sized** (`*-md`) even in dense
  mode — density never shrinks the R1 control.

## Text containment
- Widths: page shell `max-w-6xl`; forms `max-w-2xl`; running prose `max-w-prose`.
- Helper / hint text: `text-xs leading-snug`, must wrap (never `whitespace-nowrap`),
  ≤ ~140 chars, imperative voice, one idea per line.
- Paths / URLs / code: `break-words` (`break-all` for code) so nothing overflows
  its container.

## Data-heavy screens — summary header first
- Every run-detail screen opens with a **sticky compact header**: identity
  (repo · brief · run #) + run status, **finalize progress (X/Y + bar)**, and a
  **"jump to next unfinalized"** action.
- The runner report stays in a collapsed panel below the header; competence cards
  follow.

## Density — dense by default
- Compact padding (`p-4` / `p-3`, not `p-8`), `text-sm` secondary text, `table-sm`,
  `badge-sm`, tight vertical rhythm — this is a single-operator tool scanned fast.
- Exception: primary action targets (the finalize control) stay comfortable for
  safe clicking.
