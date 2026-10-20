# b5subtheme — building the CSS

Verified 2026-09-22 against `webpack.mix.js`, `package.json` and the git
history of `css/style.css`. This is the one place the build is documented;
`README.md` points here.

## TL;DR

```bash
cd web/themes/custom/b5subtheme
npm ci --omit=optional          # never `npm install`: package-lock.json is the pin
npm run build                   # production build + source-map strip + critical check
git add css/style.css css/critical.css mix-manifest.json   # commit WITH the scss change
```

Run it on the host with Node 20 (what CI uses: `.github/workflows/observability-release.yml`).
DDEV's Node version is unpinned in `.ddev/config.yaml` (it defaults to 22), so
prefer the host for anything you intend to commit.

## Why the compiled CSS is committed

Pantheon deploys through Composer and never runs npm. The files in `css/`
are what the site serves. An SCSS edit that is not followed by a rebuild is
a **silent no-op in production** — nothing errors, the site simply does not
change. That happened in `a0709f167b` (assistant sources styles added to SCSS,
CSS not rebuilt); commit `9a3977655e` shipped the missing build and the
guard below now catches it.

## Toolchain

| Piece | Value |
|---|---|
| Bundler | Laravel Mix 6.0.49 (`webpack.mix.js`) — unmaintained upstream; retiring it is a separate follow-up |
| Sass | `sass` 1.89.2 (dart-sass) |
| PurgeCSS | `@fullhuman/postcss-purgecss` 6.0.0, production builds only |
| Node | 20 on the host and in CI |
| Dependency pin | `package-lock.json`. Ranges in `package.json` are `^`, so always `npm ci`. See `package.json` → `_overrides_note` before touching any dependency, and the acceptance gate below |

## Inputs → outputs

| Source | Output | Notes |
|---|---|---|
| `scss/style.scss` (imports every `_*.scss` partial, incl. `_assistant-widget.scss`) | `css/style.css` | minified; PurgeCSS in production |
| `scss/critical.scss` | `css/critical.css` | minified; **deliberately not purged** (`webpack.mix.js` comment); loaded in `<head>` as a linked stylesheet via the `critical-styles` library, not inlined (keeps CSP without `unsafe-inline`) |
| `node_modules/@fortawesome/fontawesome-free/webfonts` | `webfonts/` | copied |
| — | `mix-manifest.json` | written by Mix; nothing reads it at runtime, but it is tracked, so commit its churn |
| `js/scripts.js` | (not bundled) | shipped as-is |

Not produced by this build (known gaps):

- `css/ck5style.css` — tracked and loaded by `b5subtheme.info.yml` (`ckeditor5-stylesheets`), but there is no `mix.sass('scss/ck5style.scss', …)` entry, so `npm run build` does not regenerate it. `scss/ck5style.scss` exists; how the CSS was last produced is unrecorded.
- `css/lazy-loading.css` — hand-written, no SCSS source.
- `web/modules/custom/ilas_site_assistant/css/assistant-widget.css` — **dead**. No library loads it (the widget is styled by `scss/_assistant-widget.scss` in this theme; see the comment in `ilas_site_assistant.libraries.yml`). It is kept only because two PHPUnit contract tests read it as a text fixture. Do not add styles there expecting them to ship.

## Commands

| Command | Use it for | Output committable? |
|---|---|---|
| `npm run build` | anything you will commit — `prod` + `check:critical` | **yes** |
| `npm run prod` | same build without the critical.css sanity grep | yes |
| `npm run dev` / `watch` / `hot` | local iteration only. Unpurged, unminified, with source maps | **never** |
| `npm run check:critical` | asserts `css/critical.css` is non-empty and still carries `site-header` and `utility-bar` | — |

`npm run prod` ends with `scripts/strip-css-sourcemaps.js`, which removes the
`sourceMappingURL` comment and deletes every `css/*.css.map`. After a correct
production build there are no `.map` files in `css/`.

## PurgeCSS: the rule that bites

Production builds drop every selector that PurgeCSS cannot find in the content
globs (`webpack.mix.js`, `purgecssConfig.content`). The globs include:

- this theme's `templates/**`, `js/**`, `*.theme`, `src/**`
- `web/modules/custom/*/templates/**/*.twig`, `web/modules/custom/*/js/**/*.js`, `web/modules/custom/*/src/**/*.php`

So a class that a custom module's JavaScript writes as a **plain string
literal** survives without a safelist entry. Proof: `.chat-jump-btn` exists
only in `web/modules/custom/ilas_site_assistant/js/assistant-widget.js` and
appears in `css/style.css`.

A class that is built by string concatenation, or that only exists in
database content / CKEditor output, **must** be added to the safelist in
`webpack.mix.js`. After every build, prove your new classes survived:

```bash
grep -o '\.your-new-class[a-z_-]*' css/style.css | sort -u
```

## Acceptance gate (byte-diff)

The compiled CSS is the artefact, so the check is on bytes, not on "the build
ran". Two variants:

**Dependency change** (lockfile, overrides, Mix/Sass bump) — output must be
byte-identical:

1. On the untouched tree: `npm ci --omit=optional && npm run prod`; `git diff --stat css/` must be empty (proves the toolchain reproduces the committed files — a **control build**).
2. Apply the dependency change, rebuild, diff again: must still be empty.
3. `npm run check:critical` passes, `npm ls` is clean.

**SCSS change** — output must differ by exactly the intended rules:

1. Control build first, as above, on the tree *before* your SCSS edit. If `css/` already differs, stop: either an earlier SCSS commit skipped its rebuild (rebuild and commit that first, on its own) or the toolchain drifted (investigate before trusting any diff).
2. Apply the SCSS edit, `npm run build`.
3. `git diff --stat` should touch `css/style.css`, `mix-manifest.json`, and `css/critical.css` only if `critical.scss` changed. Skim the diff (or split the minified file on `}` and diff the rule sets) and confirm every added or removed rule is yours.
4. `grep` your new selectors out of `css/style.css` (PurgeCSS, above); no `css/*.map`; no `sourceMappingURL` at the tail of `css/style.css`; `npm ls` clean.
5. Commit SCSS + CSS + `mix-manifest.json` together, as every prior theme commit does (`git log --stat -- css/style.css`).

## The guard

`scripts/ci/theme-css-drift-guard.sh <base> <head>` fails (exit 1) when any
`scss/**` file changed in the range but neither `css/style.css` nor
`css/critical.css` did, and prints the rebuild command. It is pure git and
takes well under a second, so it runs:

- in the pre-push hook on every push, including the "gate-safe paths" fast path (theme paths are exactly what that fast path admits);
- in `npm run gate:publish-local`;
- in the `quality-gate` GitHub workflow, over the PR's base..head.

It is registered as `theme_css_drift` in `scripts/ci/gates.lock.json`; self-test:
`bash tests/ci/theme-css-drift-guard.sh`.

Bypass, for a comment-only SCSS edit or a deliberate split commit:
`THEME_CSS_GUARD_SKIP=1 git push …`. The bypass is written into the gate
summary so it is auditable. It is not a way to skip a rebuild you owe.

What is still **not** gated: nothing rebuilds and compares the CSS bytes in
CI (that would need the npm toolchain on the runner), there is no stylelint,
and `gate:a11y-local` rebuilds the theme inside DDEV as a *side effect* of its
setup (`scripts/ci/setup-a11y-local.sh`) — it will dirty `css/` in your
working tree; that is not a check.

## Dependency changes

Read `package.json` → `_overrides_note` first (brace-expansion and minimatch
traps, never delete the whole lockfile). Then run the dependency variant of
the acceptance gate above. The 2026-07-29 remediation notes live in the
maintainer's project memory (`npm-dependency-remediation`).
