<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Resolves user-provided city/county text to the nearest ILAS office.
 *
 * Standalone class with no Drupal dependencies. Office data is sourced from
 * ResponseGrounder::OFFICIAL_CONTACTS and hardcoded here for fast lookup.
 */
class OfficeLocationResolver {

  /**
   * ILAS office data keyed by internal slug.
   */
  const OFFICES = [
    'boise' => [
      'name' => 'Boise',
      'address' => '310 N 5th Street, Boise, ID 83702',
      'phone' => '(208) 345-0106',
      'hours' => 'Monday-Friday, 8:30 a.m.-4:30 p.m. (call to confirm current office hours).',
      'url' => '/contact/offices/boise',
    ],
    'pocatello' => [
      'name' => 'Pocatello',
      'address' => '201 N 8th Ave, Suite 100, Pocatello, ID 83201',
      'phone' => '(208) 233-0079',
      'hours' => 'Monday-Friday, 8:30 a.m.-4:30 p.m. (call to confirm current office hours).',
      'url' => '/contact/offices/pocatello',
    ],
    'twin_falls' => [
      'name' => 'Twin Falls',
      'address' => '496 Shoup Ave W, Twin Falls, ID 83301',
      'phone' => '(208) 734-7024',
      'hours' => 'Monday-Friday, 8:30 a.m.-4:30 p.m. (call to confirm current office hours).',
      'url' => '/contact/offices/twin-falls',
    ],
    'lewiston' => [
      'name' => 'Lewiston',
      'address' => '1424 Main Street, Lewiston, ID 83501',
      'phone' => '(208) 746-7541',
      'hours' => 'Monday-Friday, 8:30 a.m.-4:30 p.m. (call to confirm current office hours).',
      'url' => '/contact/offices/lewiston',
    ],
    'idaho_falls' => [
      'name' => 'Idaho Falls',
      'address' => '482 Constitution Way, Suite 101, Idaho Falls, ID 83402',
      'phone' => '(208) 524-3660',
      'hours' => 'Monday-Friday, 8:30 a.m.-4:30 p.m. (call to confirm current office hours).',
      'url' => '/contact/offices/idaho-falls',
    ],
  ];

  /**
   * City -> office slug map.
   */
  const CITY_MAP = [
    // Boise region.
    'boise' => 'boise',
    'nampa' => 'boise',
    'meridian' => 'boise',
    'eagle' => 'boise',
    'caldwell' => 'boise',
    'mountain home' => 'boise',
    'garden city' => 'boise',
    'star' => 'boise',
    'kuna' => 'boise',
    'emmett' => 'boise',
    'mccall' => 'boise',
    // Pocatello region.
    'pocatello' => 'pocatello',
    'blackfoot' => 'pocatello',
    'american falls' => 'pocatello',
    'soda springs' => 'pocatello',
    'preston' => 'pocatello',
    'malad' => 'pocatello',
    // Twin Falls region.
    'twin falls' => 'twin_falls',
    'jerome' => 'twin_falls',
    'burley' => 'twin_falls',
    'rupert' => 'twin_falls',
    'hailey' => 'twin_falls',
    'ketchum' => 'twin_falls',
    'gooding' => 'twin_falls',
    'shoshone' => 'twin_falls',
    // Lewiston region.
    'lewiston' => 'lewiston',
    'moscow' => 'lewiston',
    "coeur d'alene" => 'lewiston',
    'coeur dalene' => 'lewiston',
    'sandpoint' => 'lewiston',
    'post falls' => 'lewiston',
    'orofino' => 'lewiston',
    'grangeville' => 'lewiston',
    'wallace' => 'lewiston',
    'bonners ferry' => 'lewiston',
    'hayden' => 'lewiston',
    // Idaho Falls region.
    'idaho falls' => 'idaho_falls',
    'rexburg' => 'idaho_falls',
    'driggs' => 'idaho_falls',
    'salmon' => 'idaho_falls',
    'rigby' => 'idaho_falls',
    'st anthony' => 'idaho_falls',
    'arco' => 'idaho_falls',
    'challis' => 'idaho_falls',
  ];

  /**
   * County -> office slug map (all 44 Idaho counties).
   */
  const COUNTY_MAP = [
    // Boise region.
    'ada' => 'boise',
    'canyon' => 'boise',
    'gem' => 'boise',
    'boise' => 'boise',
    'elmore' => 'boise',
    'owyhee' => 'boise',
    'payette' => 'boise',
    'valley' => 'boise',
    'washington' => 'boise',
    'adams' => 'boise',
    // Pocatello region.
    'bannock' => 'pocatello',
    'bear lake' => 'pocatello',
    'bingham' => 'pocatello',
    'caribou' => 'pocatello',
    'franklin' => 'pocatello',
    'oneida' => 'pocatello',
    'power' => 'pocatello',
    // Twin Falls region.
    'twin falls' => 'twin_falls',
    'jerome' => 'twin_falls',
    'blaine' => 'twin_falls',
    'camas' => 'twin_falls',
    'cassia' => 'twin_falls',
    'gooding' => 'twin_falls',
    'lincoln' => 'twin_falls',
    'minidoka' => 'twin_falls',
    // Lewiston region.
    'nez perce' => 'lewiston',
    'latah' => 'lewiston',
    'lewis' => 'lewiston',
    'clearwater' => 'lewiston',
    'idaho' => 'lewiston',
    'kootenai' => 'lewiston',
    'benewah' => 'lewiston',
    'bonner' => 'lewiston',
    'boundary' => 'lewiston',
    'shoshone' => 'lewiston',
    // Idaho Falls region.
    'bonneville' => 'idaho_falls',
    'butte' => 'idaho_falls',
    'clark' => 'idaho_falls',
    'custer' => 'idaho_falls',
    'fremont' => 'idaho_falls',
    'jefferson' => 'idaho_falls',
    'lemhi' => 'idaho_falls',
    'madison' => 'idaho_falls',
    'teton' => 'idaho_falls',
  ];

  /**
   * Common abbreviations -> normalized city name.
   */
  const ABBREVIATIONS = [
    'cda' => "coeur d'alene",
    'if' => 'idaho falls',
    'tf' => 'twin falls',
    'mtn home' => 'mountain home',
  ];

  /**
   * Resolves a user message to the nearest ILAS office.
   *
   * @param string $message
   *   The user's message (e.g. "boise", "Ada County").
   *
   * @return array|null
   *   Office data array with name, address, phone, hours, url keys, or NULL.
   */
  public function resolve(string $message): ?array {
    $normalized = $this->normalize($message);

    if ($normalized === '') {
      return NULL;
    }

    // Check abbreviations first.
    if (isset(self::ABBREVIATIONS[$normalized])) {
      $normalized = self::ABBREVIATIONS[$normalized];
    }

    // Check city map.
    if (isset(self::CITY_MAP[$normalized])) {
      return self::OFFICES[self::CITY_MAP[$normalized]];
    }

    // Fuzzy city phrase match inside longer messages.
    foreach (self::CITY_MAP as $city => $office_slug) {
      if (preg_match('/\b' . preg_quote($city, '/') . '\b/u', $normalized)) {
        return self::OFFICES[$office_slug];
      }
    }

    // Check county map: handle "X county" and bare county names.
    $county = $normalized;
    if (preg_match('/^(.+?)\s+county$/', $normalized, $m)) {
      $county = $m[1];
    }
    if (isset(self::COUNTY_MAP[$county])) {
      return self::OFFICES[self::COUNTY_MAP[$county]];
    }

    // Fuzzy county phrase match inside longer messages.
    foreach (self::COUNTY_MAP as $county_name => $office_slug) {
      if (preg_match('/\b' . preg_quote($county_name, '/') . '\s*county\b/u', $normalized) ||
        preg_match('/\b' . preg_quote($county_name, '/') . '\b/u', $normalized)) {
        return self::OFFICES[$office_slug];
      }
    }

    return NULL;
  }

  /**
   * Returns all ILAS offices.
   *
   * @return array
   *   All 5 offices keyed by slug.
   */
  public function getAllOffices(): array {
    return self::OFFICES;
  }

  /**
   * Normalizes input text for lookup.
   *
   * @param string $text
   *   Raw user input.
   *
   * @return string
   *   Lowercased, trimmed, punctuation-stripped text.
   */
  protected function normalize(string $text): string {
    $text = mb_strtolower(trim($text));
    // Strip punctuation except apostrophes (for "coeur d'alene").
    $text = preg_replace("/[^\w\s']/u", '', $text);
    // Collapse whitespace.
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
  }

}
