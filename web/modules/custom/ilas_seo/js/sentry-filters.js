/**
 * @file
 * Sets Sentry SDK-level noise filters before raven.js calls Sentry.init().
 *
 * Everything here drops events that no site-owned code produced. The list
 * comes from the 2026-09-09 Sentry review (docs/sentry-active-errors-2026-09-09.md,
 * section C); each entry names the cluster it silences so it can be revisited.
 *
 * denyUrls matches the script URL of any frame; ignoreErrors matches the
 * error message. Both are evaluated inside the SDK before the event reaches
 * the observability.js event processor.
 */
((ds) => {
  if (!ds.raven) {
    return;
  }
  ds.raven.options.ignoreErrors = ds.raven.options.ignoreErrors || [];
  ds.raven.options.denyUrls = ds.raven.options.denyUrls || [];

  const denyUrls = [
    // Cloudflare RUM beacon on browsers without Array.prototype.at / findLast
    // (KaiOS, Amazon Silk, old Chrome Mobile). PHP-9S/AG/AH/AD/9R/9T.
    /\/beacon\.min\.js/i,
    // Browser extensions (MetaMask, ad-adjust, Safari runtime.sendMessage,
    // Affirm). PHP-92/9G/A3/9F.
    /^chrome-extension:\/\//i,
    /^moz-extension:\/\//i,
    /^safari-web-extension:\/\//i,
    /injectScriptAdjust\.js/i,
    // Facebook in-app browser (Android) navigation performance logger.
    // PHP-9B/A4.
    /^iabjs:\/\//i,
  ];

  const ignoreErrors = [
    // Bot-only: non-compliant rendering engines (Baiduspider-render) run the
    // aggregate before standalone jQuery has loaded. Sentry #7364900490.
    // Revisit once Cloudflare static-resource protection is off (A5): the same
    // message reaches real visitors when jquery.min.js is served a challenge.
    'jQuery is not defined',
    // Chrome-for-iOS / Google-app injected inline scripts throw bare minified
    // identifiers as the whole message. PHP-A1 and siblings.
    /^(La|Ba)$/,
    // Outlook SafeLinks / Edge read-aloud injected script. PHP-A0.
    /Object Not Found Matching Id/,
  ];

  denyUrls.forEach((pattern) => ds.raven.options.denyUrls.push(pattern));
  ignoreErrors.forEach((pattern) => ds.raven.options.ignoreErrors.push(pattern));
})(window.drupalSettings);
