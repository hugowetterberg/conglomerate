<?php

/**
 * Implementation of hook_content_default_fields().
 */
function conglomerate_content_default_fields() {
  module_load_include('inc', 'conglomerate', 'conglomerate.defaults');
  $args = func_get_args();
  return call_user_func_array('_conglomerate_content_default_fields', $args);
}

/**
 * Implementation of hook_node_info().
 */
function conglomerate_node_info() {
  module_load_include('inc', 'conglomerate', 'conglomerate.features.node');
  $args = func_get_args();
  return call_user_func_array('_conglomerate_node_info', $args);
}
