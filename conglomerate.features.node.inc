<?php

/**
 * Helper to implementation of hook_node_info().
 */
function _conglomerate_node_info() {
  $items = array(
    'conglomerate_block' => array(
      'name' => t('Conglomerate Block'),
      'module' => 'features',
      'description' => t(''),
      'has_title' => '1',
      'title_label' => t('Titel'),
      'has_body' => '0',
      'body_label' => t(''),
    ),
  );
  return $items;
}
