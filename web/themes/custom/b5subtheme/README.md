# B5 Subtheme - ILAS Theme Documentation

A custom Bootstrap 5 subtheme for the Idaho Legal Aid Services website, providing legal resources and assistance to Idaho residents.

## Table of Contents

- [Overview](#overview)
- [Installation](#installation)
- [Architecture](#architecture)
- [Components](#components)
- [Performance Optimizations](#performance-optimizations)
- [Development](#development)
- [Testing](#testing)

## Overview

The B5 subtheme extends the Bootstrap 5 base theme with custom components, optimized performance, and legal aid-specific functionality.

### Key Features

- **Responsive Design**: Mobile-first approach with Bootstrap 5
- **Performance Optimized**: Critical CSS, lazy loading, and asset aggregation
- **Accessibility**: WCAG 2.1 AA compliant with ARIA labels and keyboard navigation
- **Component Library**: Reusable components for legal resources

## Installation

1. Ensure the Bootstrap 5 base theme is installed:
   ```bash
   composer require drupal/bootstrap5
   ```

2. Enable the theme:
   ```bash
   drush theme:enable b5subtheme
   drush config:set system.theme default b5subtheme
   ```

3. Clear caches:
   ```bash
   drush cr
   ```

## Architecture

### Directory Structure

```
b5subtheme/
├── css/                 # Compiled CSS (do not edit)
├── scss/               # Source SCSS files
│   ├── _variables_theme.scss    # Theme variables
│   ├── _mixins.scss             # SCSS mixins
│   ├── _header.scss             # Header styles
│   ├── _footer.scss             # Footer styles
│   ├── _buttons.scss            # Button components
│   └── components/              # Component styles
├── js/                 # JavaScript files
│   ├── scripts.js               # Main theme scripts
│   ├── resources.js             # Resource filtering
│   └── lazy-loading.js          # Lazy loading implementation
├── templates/          # Twig templates
│   ├── page/                    # Page templates
│   ├── node/                    # Node templates
│   └── paragraph/               # Paragraph templates
└── images/             # Theme images and icons
```

## Components

### 1. Resource Cards (`_resource.scss`)

Interactive cards displaying legal resources with topic filtering.

**Usage:**
```twig
<div class="resource-card" data-topics="topic-123 topic-456">
  <div class="resource-topics">
    <span class="topic-item">Eviction</span>
  </div>
</div>
```

**Features:**
- Dynamic topic filtering
- Hover effects
- Responsive grid layout

### 2. Smart FAQ (`_smart-faq.scss`)

Collapsible FAQ sections with smooth animations.

**JavaScript:** `js/scripts.js`
- Keyboard accessible
- ARIA attributes
- Smooth expand/collapse

### 3. Search Overlay (`_search-overlay.scss`)

Full-screen search interface with auto-focus.

**Features:**
- Backdrop blur effect
- ESC key to close
- Mobile optimized

### 4. Mobile Menu (`_mobile-menu.scss`)

Off-canvas navigation for mobile devices.

**Breakpoint:** < 768px
- Touch-friendly
- Smooth slide animation
- Body scroll lock when open

### 5. Help Overlay (`_help-overlay.scss`)

Quick help interface for legal assistance options.

## Performance Optimizations

### 1. Critical CSS

Inline critical above-the-fold CSS in `templates/page/html.html.twig`:
- Prevents FOUC (Flash of Unstyled Content)
- Reduces render-blocking CSS
- Improves First Contentful Paint

### 2. Lazy Loading

Implementation in `js/lazy-loading.js`:
- Uses Intersection Observer API
- Supports images, iframes, and dynamic content
- Fallback for older browsers

### 3. Asset Aggregation

Configured in `system.performance.yml`:
```yaml
css:
  preprocess: true
  gzip: true
js:
  preprocess: true
  gzip: true
```

### 4. Font Preloading

```html
<link rel="preload" href="fonts.googleapis.com/..." as="style">
```

## Development

### SCSS Compilation

The theme is built with Laravel Mix and the compiled CSS in `css/` is
committed (Pantheon never runs npm). **See [BUILD.md](BUILD.md)** for the
verified command sequence, the byte-diff acceptance gate, the PurgeCSS rule,
and the pre-push/CI guard that fails when SCSS changes without a rebuild.

```bash
npm ci --omit=optional && npm run build     # the only committable build
npm run watch                               # local iteration only; never commit dev output
```

### CSS Variables

Key variables in `_variables_theme.scss`:
```scss
$color-primary: #1263a0;
$color-success: #38a169;
$color-gray-dark: #333333;
```

### Breakpoints

```scss
$mobile-breakpoint: 576px;
$tablet-breakpoint: 768px;
$desktop-breakpoint: 992px;
```

### JavaScript

All JavaScript uses Drupal behaviors pattern:
```javascript
Drupal.behaviors.myBehavior = {
  attach: function (context, settings) {
    // Code here
  }
};
```

## Testing

### Visual Regression Testing

1. Capture baseline screenshots
2. Run after changes
3. Compare results

### Accessibility Testing

- Use axe DevTools
- Test keyboard navigation
- Screen reader testing

### Performance Testing

- Lighthouse audits
- Core Web Vitals monitoring
- Bundle size analysis

## Troubleshooting

### Common Issues

1. **Styles not updating**
   - Clear Drupal cache: `drush cr`
   - Clear browser cache
   - Check aggregation settings

2. **JavaScript errors**
   - Check browser console
   - Verify jQuery loaded
   - Check for conflicts

3. **Mobile menu issues**
   - Verify viewport meta tag
   - Check z-index conflicts
   - Test touch events

## Contributing

1. Follow Drupal coding standards
2. Use BEM naming convention
3. Test on multiple devices
4. Document new components

## License

This theme is part of the ILAS project and follows the same license terms.