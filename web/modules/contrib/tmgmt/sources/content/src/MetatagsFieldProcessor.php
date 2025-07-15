<?php

namespace Drupal\tmgmt_content;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Element;

/**
 * Field processor for the metatags field.
 */
class MetatagsFieldProcessor extends DefaultFieldProcessor {

  /**
   * {@inheritdoc}
   */
  public function extractTranslatableData(FieldItemListInterface $field) {
    if (empty($field->value)) {
      return [];
    }

    $metatag_manager = \Drupal::service('metatag.manager');
    if (function_exists('metatag_data_decode')) {
      $meta_tag_values = metatag_data_decode($field->value);
    }
    else {
      $meta_tag_values = unserialize($field->value);
    }

    // If there are no meta tags or it is not an array, there is nothing to
    // do.
    if (empty($meta_tag_values) || !is_array($meta_tag_values)) {
      return [];
    }

    // Get the groups and tags information with their labels.
    $groups_and_tags = $metatag_manager->sortedGroupsWithTags();

    // @todo Use the schema instead of hardcoding this list once
    //   https://www.drupal.org/node/2907214 is fixed.
    $blacklist_tags = ['robots', 'referrer', 'twitter_cards_type'];

    $data = [];
    // Group the tags
    foreach ($groups_and_tags as $group_name => $group) {
      if (!isset($group['tags'])) {
        continue;
      }
      $tags = array_intersect_key($group['tags'], $meta_tag_values);
      if ($tags) {
        foreach ($tags as $tag_name => $tag) {
          $data[$group_name][$tag_name] = [
            // Non-string tags are not supported currently.
            '#translate' => is_string($meta_tag_values[$tag_name]) && !in_array($tag_name, $blacklist_tags),
            '#text' => $meta_tag_values[$tag_name],
            '#label' => $tag['label'],
          ];
        }

        // Only add the label if any tags were added for this group.
        if (!empty($data[$group_name])) {
          $data[$group_name]['#label'] = $group['label'];
        }
      }
    }

    if (!empty($data)) {
      $data['#label'] = $field->getFieldDefinition()->getLabel();
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function setTranslations($field_data, FieldItemListInterface $field) {
    $meta_tags_values = [];

    // Loop over the groups and tags, either use the translated text or the
    // original and then serialize the whole structure again.
    foreach (Element::children($field_data) as $group_name) {
      foreach (Element::children($field_data[$group_name]) as $tag_name) {

        $property_data = $field_data[$group_name][$tag_name];
        if (isset($property_data['#translation']['#text']) && $property_data['#translate']) {
          $meta_tags_values[$tag_name] = $property_data['#translation']['#text'];
        }
        else {
          $meta_tags_values[$tag_name] =$property_data['#text'];
        }
      }
    }

    if (function_exists('metatag_data_encode')) {
      $field->value = metatag_data_encode($meta_tags_values);
    }
    else {
      $field->value = serialize($meta_tags_values);
    }
  }

}
