const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel application. By default, we are compiling the Sass
 | file for the application as well as bundling up all the JS files.
 |
 | OPTIMIZATION: PurgeCSS added for production builds to remove unused CSS
 | Expected savings: ~70-77% reduction in CSS bundle size
 |
 */

// =============================================================================
// PURGECSS CONFIGURATION FOR DRUPAL + BOOTSTRAP
// =============================================================================
const purgecssConfig = {
  content: [
    // Twig templates
    './templates/**/*.html.twig',
    './templates/**/*.twig',
    // JavaScript files (for dynamically added classes)
    './js/**/*.js',
    // PHP files (for classes added in preprocess)
    './*.theme',
    './src/**/*.php',
    // Drupal core and contrib templates that might be used
    '../../contrib/bootstrap5/templates/**/*.twig',
    '../../../core/themes/stable9/templates/**/*.twig',
    // Custom modules (templates, JS, PHP that reference CSS classes)
    '../../../modules/custom/*/templates/**/*.twig',
    '../../../modules/custom/*/js/**/*.js',
    '../../../modules/custom/*/src/**/*.php',
  ],
  // Drupal-safe safelist - patterns that should never be purged
  safelist: {
    standard: [
      // HTML elements
      'html', 'body',
      // Drupal core classes
      /^is-/,
      /^js-/,
      /^has-/,
      /^node--/,
      /^node-/,
      /^block--/,
      /^block-/,
      /^region--/,
      /^region-/,
      /^field--/,
      /^field-/,
      /^views-/,
      /^view-/,
      /^paragraph--/,
      /^paragraph-/,
      /^layout-/,
      /^contextual-/,
      /^toolbar-/,
      /^admin-/,
      /^drupal-/,
      /^ajax-/,
      /^path-/,
      /^user-/,
      /^role-/,
      /^page-/,
      /^entity-/,
      /^media--/,
      /^media-/,
      /^webform-/,
      /^form-item-/,
      // Bootstrap responsive utilities (all breakpoints)
      /^d-/,
      /^d-[a-z]+-/,
      /^col-/,
      /^row-cols-/,
      /^g-/,
      /^gx-/,
      /^gy-/,
      /^m[trblxy]?-/,
      /^p[trblxy]?-/,
      /^ms-/,
      /^me-/,
      /^mt-/,
      /^mb-/,
      /^mx-/,
      /^my-/,
      /^ps-/,
      /^pe-/,
      /^pt-/,
      /^pb-/,
      /^px-/,
      /^py-/,
      /^text-/,
      /^bg-/,
      /^border-/,
      /^rounded-/,
      /^shadow-/,
      /^flex-/,
      /^align-/,
      /^justify-/,
      /^order-/,
      /^float-/,
      /^position-/,
      /^top-/,
      /^bottom-/,
      /^start-/,
      /^end-/,
      /^translate-/,
      /^w-/,
      /^h-/,
      /^mw-/,
      /^mh-/,
      /^min-/,
      /^max-/,
      /^vw-/,
      /^vh-/,
      /^overflow-/,
      /^visible/,
      /^invisible/,
      /^opacity-/,
      /^z-/,
      /^fs-/,
      /^fw-/,
      /^lh-/,
      // Bootstrap state classes
      /^show$/,
      /^hide$/,
      /^active$/,
      /^disabled$/,
      /^collapsed$/,
      /^collapsing$/,
      /^fade$/,
      /^collapse$/,
      /^in$/,
      /^open$/,
      /^focus/,
      /^hover/,
      /^visited/,
      // Bootstrap component variants
      /^btn-/,
      /^alert-/,
      /^card-/,
      /^accordion-/,
      /^navbar-/,
      /^nav-/,
      /^dropdown-/,
      /^table-/,
      /^list-group/,
      /^badge-/,
      /^progress-/,
      /^input-group/,
      // Bootstrap form classes
      /^form-/,
      /^input-/,
      /^was-validated/,
      /^is-valid/,
      /^is-invalid/,
      /^valid-/,
      /^invalid-/,
      // Font Awesome — blanket safelist for all FA classes
      // Covers: base (.fa, .fas, .far, .fab), FA6 style prefixes (.fa-solid,
      // .fa-regular, .fa-brands), utilities (.fa-spin, .fa-fw, .fa-lg, .fa-2x),
      // and ALL icon classes (.fa-calendar, .fa-heart, .fa-comment-dots, etc.).
      // This is necessary because:
      //   1. Benefit-card paragraph uses admin-entered icon classes
      //      (e.g. {{ paragraph.field_benefit_icon.value }} = "fas fa-heart-pulse")
      //   2. Assistant widget renders data-driven icons (fa-{{ suggestion.icon }})
      //   3. Individual icon lists inevitably go stale as templates change
      // Cost: ~2000 tiny ::before content rules (~40-50KB gzipped). Acceptable
      // vs. the alternative of icons silently disappearing in production.
      /^fa$/,
      /^fas$/,
      /^far$/,
      /^fab$/,
      /^fa-/,
      // CKEditor block styles / callout classes (applied via CKEditor, not in templates)
      'callout-info',
      'callout-highlight',
      'callout-warning',
      'callout-danger',
      'callout-stat',
      'pull-quote',
      'check-list',
      'stat-number',
      'stat-label',
      'quote-author',
      // Dynamic Twig variants (constructed via ~ operator, invisible to PurgeCSS)
      'quick-fact--gray',
      'quick-fact--blue',
      'content-section--highlighted',
      // CKEditor classes
      /^ck-/,
      /^cke_/,
      // Print utilities
      /^print-/,
      // Accessibility
      /^visually-/,
      /^sr-/,
      // Animation classes
      /^animate-/,
      /^animation-/,
      // Custom theme classes that are added dynamically via JavaScript
      'mobile-menu-open',
      'show-overlay',
      'is-flipped',
      'mobile-card',
      'loading',
      'loaded',
      'error',
      'success',
      'visible',
      'hidden',
    ],
    // Deep patterns (for nested selectors like .collapse.show)
    deep: [
      /collapse/,
      /accordion/,
      /dropdown/,
      /modal/,
      /tooltip/,
      /popover/,
      /alert/,
      /badge/,
      /btn/,
      /nav/,
      /navbar/,
      /card/,
      /form/,
      /input/,
      /table/,
      /progress/,
      /list-group/,
    ],
    greedy: [],
  },
  // Keep CSS variables
  variables: true,
  // Keep keyframe animations
  keyframes: true,
  // Keep font-face declarations
  fontFace: true,
};

// =============================================================================
// MIX CONFIGURATION
// =============================================================================

mix.setPublicPath('./');

// Compile SCSS with PurgeCSS in production
mix.sass('scss/style.scss', 'css/style.css')
   .options({
     processCssUrls: false,
     postCss: mix.inProduction() ? [
       require('@fullhuman/postcss-purgecss')(purgecssConfig)
     ] : [],
   });

// Critical (above-the-fold) CSS — compiled from scss/critical.scss so the
// rules and tokens stay in sync with _variables_theme.scss / _header.scss
// instead of drifting via a hand-maintained css/critical.css. Loaded via
// the b5subtheme/critical-styles library. Intentionally NOT run through
// PurgeCSS: the file is small and curated, and PurgeCSS would only see
// the early-paint window's selectors.
mix.sass('scss/critical.scss', 'css/critical.css')
   .options({ processCssUrls: false });

// JavaScript: scripts.js is loaded directly (not bundled through webpack)
// The file is already ES5-compatible and uses Drupal.behaviors pattern
// Removed: mix.js('js/scripts.js', 'js/scripts.min.js') - was building unused file

// Bootstrap JS: Provided by bootstrap5 base theme, no need to copy
// Removed: mix.copy('node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', ...)

// Copy Font Awesome webfonts (CSS is compiled via SCSS into style.css)
mix.copy('node_modules/@fortawesome/fontawesome-free/webfonts', 'webfonts');
// fontawesome.min.css no longer copied - styles included in style.css via SCSS imports

// Bootstrap Icons removed - not used in templates (saves ~87KB)
// If needed in future, uncomment these lines:
// mix.copy('node_modules/bootstrap-icons/font/bootstrap-icons.min.css', 'css/bootstrap-icons.min.css');
// mix.copy('node_modules/bootstrap-icons/font/fonts', 'css/fonts');

// Version files in production for cache busting
if (mix.inProduction()) {
  // Hidden source maps: emit .map files so scripts/observability/sentry-release.sh
  // can upload them to Sentry, but do NOT add a sourceMappingURL comment to the
  // public CSS/JS. Avoids exposing source structure to anonymous browsers while
  // keeping Sentry stack-trace symbolication working (Sentry CLI matches by
  // release artifact name, not by inline comment).
  mix.sourceMaps(true, 'hidden-source-map');
  mix.version();
}

// Enable source maps in development
if (!mix.inProduction()) {
  mix.sourceMaps();
}

// =============================================================================
// LIVE RELOAD
// =============================================================================
// BrowserSync was removed in 2026-07: it was opt-in behind BROWSERSYNC_PROXY,
// DDEV is the local environment, and it pulled immutable@3 plus the whole
// socket.io/engine.io tree (4 Dependabot alerts, incl. a forced two-major
// downgrade of the `immutable` that sass@1.89 depends on).
// For a watch loop use `npm run watch`; reload the DDEV page manually.

// =============================================================================
// IMAGE OPTIMIZATION
// =============================================================================
// Removed in 2026-07 along with optimize-images.js. The imagemin family was
// never referenced here, `npm run optimize-images` was invoked by no workflow
// or script, and images/optimized/ was never committed — but the tree carried
// ~12 advisories including decompress (CVSS 9.1, no patch will ever exist).
// To optimize an image now, do it once, by hand:
//   npx @squoosh/cli --mozjpeg auto --oxipng auto -d images/optimized images/*
// or use https://squoosh.app. Do not re-add imagemin as a dependency.
