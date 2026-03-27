/**
 * @file
 * Sets Sentry ignoreErrors before raven.js calls Sentry.init().
 *
 * Filters known bot-only noise where non-compliant rendering engines
 * (e.g. Baiduspider-render) execute aggregated scripts before standalone
 * jQuery has loaded. See Sentry #7364900490.
 */
((ds) => {
  if (!ds.raven) {
    return;
  }
  ds.raven.options.ignoreErrors = ds.raven.options.ignoreErrors || [];
  ds.raven.options.ignoreErrors.push('jQuery is not defined');
})(window.drupalSettings);
