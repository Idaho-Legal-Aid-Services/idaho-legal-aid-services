<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
  throw new RuntimeException(sprintf(
    'VC-PURE bootstrap could not find Composer autoload file at "%s".',
    $autoloadPath
  ));
}

require $autoloadPath;

$searchApiSourcePath = __DIR__ . '/web/modules/contrib/search_api/src';
if (!is_dir($searchApiSourcePath)) {
  throw new RuntimeException(sprintf(
    'VC-PURE bootstrap expected Search API sources at "%s".',
    $searchApiSourcePath
  ));
}

spl_autoload_register(static function (string $class) use ($searchApiSourcePath): void {
  $prefix = 'Drupal\\search_api\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
    return;
  }

  $relativeClass = substr($class, strlen($prefix));
  if ($relativeClass === false || $relativeClass === '') {
    return;
  }

  $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
  $resolvedPath = $searchApiSourcePath . DIRECTORY_SEPARATOR . $relativePath;
  if (is_file($resolvedPath)) {
    require_once $resolvedPath;
  }
});

$ravenSourcePath = __DIR__ . '/web/modules/contrib/raven/src';
if (is_dir($ravenSourcePath)) {
  spl_autoload_register(static function (string $class) use ($ravenSourcePath): void {
    $prefix = 'Drupal\\raven\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
      return;
    }

    $relativeClass = substr($class, strlen($prefix));
    if ($relativeClass === false || $relativeClass === '') {
      return;
    }

    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    $resolvedPath = $ravenSourcePath . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($resolvedPath)) {
      require_once $resolvedPath;
    }
  });
}

$keySourcePath = __DIR__ . '/web/modules/contrib/key/src';
if (is_dir($keySourcePath)) {
  spl_autoload_register(static function (string $class) use ($keySourcePath): void {
    $prefix = 'Drupal\\key\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
      return;
    }

    $relativeClass = substr($class, strlen($prefix));
    if ($relativeClass === false || $relativeClass === '') {
      return;
    }

    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    $resolvedPath = $keySourcePath . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($resolvedPath)) {
      require_once $resolvedPath;
    }
  });
}

