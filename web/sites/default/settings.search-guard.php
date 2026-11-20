<?php

/**
 * @file
 * Pre-bootstrap test for facet-parameter requests to the search page.
 *
 * Loaded from the top of settings.php. Kept in its own file so the decision
 * can be unit tested without running the rest of settings.php.
 */

if (!function_exists('_ilas_is_facet_spam_request')) {

  /**
   * Tells whether a request is a faceted search request.
   *
   * The Facets module is not installed, so no page on this site links to a
   * search URL carrying f[...] parameters. Every such request is a crawler
   * walking filter combinations (110,471 of them in the 60 days to
   * 2026-11-20, 41% of all origin requests).
   *
   * The query string is URL-decoded and lower-cased before matching, so
   * `f[0]`, `f%5B0%5D` and `f%5b0%5d` are all recognised. The earlier inline
   * check compared the raw string against `f%5B` only and let the lower-case
   * encoding through.
   *
   * @param string $request_uri
   *   The raw request URI, path plus optional query string.
   *
   * @return bool
   *   TRUE when the request targets a search path and has a facet parameter.
   */
  function _ilas_is_facet_spam_request(string $request_uri): bool {
    if (strpos($request_uri, '/admin/') !== FALSE) {
      return FALSE;
    }

    $parts = explode('?', $request_uri, 2);
    $query = $parts[1] ?? '';
    if ($query === '') {
      return FALSE;
    }

    // /search, /search/..., and the same under one prefix segment such as a
    // language code (/es/search) or /index.php/search.
    $path = strtolower(rawurldecode($parts[0]));
    if (!preg_match('#^/(?:[^/]+/)?search(?:/|$)#', $path)) {
      return FALSE;
    }

    // A parameter whose name starts with "f[", at the start of the query or
    // after an ampersand. Matching the parameter name, not any occurrence of
    // "f[", keeps a keyword search for text such as "pdf[1]" working.
    return (bool) preg_match('/(?:^|&)f\[/', strtolower(rawurldecode($query)));
  }

}
