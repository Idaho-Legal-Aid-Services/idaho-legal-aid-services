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
      // Font Awesome - base classes only (icons specified below)
      /^fa$/,
      /^fas$/,
      /^far$/,
      /^fab$/,
      /^fa-solid$/,
      /^fa-brands$/,
      /^fa-regular$/,
      // Font Awesome utility classes
      'fa-spin',
      'fa-fw',
      'fa-lg',
      'fa-2x',
      'fa-3x',
      // Font Awesome ICONS USED (63 total - audited 2024-12-16)
      // Solid icons
      'fa-arrow-circle-right',
      'fa-arrow-down',
      'fa-arrow-left',
      'fa-arrow-right',
      'fa-arrow-right-from-bracket',
      'fa-arrow-right-long',
      'fa-arrow-up',
      'fa-balance-scale',
      'fa-briefcase',
      'fa-calendar',
      'fa-calendar-alt',
      'fa-calendar-week',
      'fa-chart-line',
      'fa-chart-pie',
      'fa-check-circle',
      'fa-chevron-down',
      'fa-chevron-left',
      'fa-chevron-right',
      'fa-chevron-up',
      'fa-clock',
      'fa-cloud-upload-alt',
      'fa-cog',
      'fa-dollar-sign',
      'fa-donate',
      'fa-download',
      'fa-exclamation-triangle',
      'fa-external-link-alt',
      'fa-file-alt',
      'fa-file-contract',
      'fa-file-invoice-dollar',
      'fa-folder',
      'fa-folder-open',
      'fa-gavel',
      'fa-globe',
      'fa-graduation-cap',
      'fa-hand-holding-heart',
      'fa-hands-helping',
      'fa-heart',
      'fa-info-circle',
      'fa-list',
      'fa-lock',
      'fa-magnifying-glass',
      'fa-map',
      'fa-map-marker-alt',
      'fa-paper-plane',
      'fa-percentage',
      'fa-phone-volume',
      'fa-plus',
      'fa-save',
      'fa-spinner',
      'fa-star',
      'fa-sync-alt',
      'fa-target',
      'fa-ticket-alt',
      'fa-times',
      'fa-user-check',
      'fa-users',
      'fa-xmark',
      // Brand icons
      'fa-bluesky',
      'fa-facebook',
      'fa-instagram',
      'fa-linkedin',
      'fa-youtube',
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
    // Greedy patterns (matches entire selector if pattern found anywhere)
    greedy: [
      // Font Awesome greedy pattern removed - specific icons listed in standard
    ],
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

// Compile JavaScript
mix.js('js/scripts.js', 'js/scripts.min.js');

// Copy Bootstrap JS bundle
mix.copy('node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', 'js/bootstrap.bundle.min.js');

// Copy Font Awesome webfonts (CSS is compiled via SCSS into style.css)
mix.copy('node_modules/@fortawesome/fontawesome-free/webfonts', 'webfonts');
// fontawesome.min.css no longer copied - styles included in style.css via SCSS imports

// Bootstrap Icons removed - not used in templates (saves ~87KB)
// If needed in future, uncomment these lines:
// mix.copy('node_modules/bootstrap-icons/font/bootstrap-icons.min.css', 'css/bootstrap-icons.min.css');
// mix.copy('node_modules/bootstrap-icons/font/fonts', 'css/fonts');

// Version files in production for cache busting
if (mix.inProduction()) {
  mix.version();
}

// Enable source maps in development
if (!mix.inProduction()) {
  mix.sourceMaps();
}

// =============================================================================
// BROWSERSYNC CONFIGURATION
// =============================================================================
// Only enable in development environment
// To use: Set BROWSERSYNC_PROXY environment variable to your local URL
// Example: BROWSERSYNC_PROXY=https://mysite.ddev.site npm run watch
if (!mix.inProduction() && process.env.BROWSERSYNC_PROXY) {
  mix.browserSync({
    proxy: process.env.BROWSERSYNC_PROXY,
    port: 3000,
    files: [
      'css/**/*.css',
      'js/**/*.js',
      'templates/**/*.twig',
      'scss/**/*.scss',
      '*.theme',
      '**/*.yml'
    ],
    open: false,
    notify: false,
    reloadDelay: 50,
    injectChanges: true,
    browser: 'default',
    https: {
      rejectUnauthorized: false
    }
  });
}

// For image optimization, run: node optimize-images.js
// This is separate from the build process to avoid webpack conflicts
