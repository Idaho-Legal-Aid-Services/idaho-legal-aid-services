<?php

/**
 * @file
 * Intentionally inert. (No "#ddev-generated" marker: user-owned.)
 *
 * The ddev-redis add-on ships a version of this file that routes lock,
 * flood, and the cache-tags checksum through Redis via the module's
 * example.services.yml. This site keeps lock and flood on the database
 * (assistant rate-limit guards and LlmAdmissionCoordinator must not lose
 * records to LRU eviction), so all Redis wiring — Pantheon and DDEV — lives
 * in settings.redis.php instead.
 *
 * If `ddev add-on get ddev/ddev-redis` is ever re-run it will NOT overwrite
 * this file (no marker); delete this file first if you actually want the
 * add-on's stock behavior.
 */
