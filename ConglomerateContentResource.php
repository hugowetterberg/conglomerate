<?php
// $Id$

/*
title
body
position
language
tags

  * upload image
  / list images

# Event
starts

*/

function db_query_debug($sql, $parameters) {
  global $db_prefix;
  $sql = preg_replace('/\{([^\}]+)\}/', $db_prefix . '$1', $sql);

  if (!is_array($parameters)) {
    $parameters = array_slice(func_get_args(), 1);
  }
  $args = array_merge(array($sql), $parameters);
  $q = call_user_func_array('sprintf', $args);

  return $q;
}

class ConglomerateContentResource {

  /**
   * Creates a content item
   *
   * @param object $data ["data"]
   * @return object
   *
   * @Access(callback='ConglomerateContentResource::access', args={'create'}, appendArgs=true)
   */
  public static function create($data) {
    $nid = NULL;

    try {
      $form_state = self::executeNodeForm('create', $data);
    }
    catch (Exception $e) {
      return services_error($e->getMessage(), $e->getCode());
    }
    
    // Fetch $nid out of $form_state
    $nid = $form_state['nid'];

    $result = array(
      'nid' => $nid,
      'uri' => services_resource_uri(array('content', $nid)),
      'url' => url('node/' . $nid, array('absolute' => TRUE))
    );
    drupal_alter('conglomerate_api_created_result', $result);
    return (object)$result;
  }

  /**
   * Retrieves a content item
   *
   * @param int $nid ["path","0"]
   *  The id of the item to get
   * @return object
   *
   * @Access(callback='ConglomerateContentResource::access', args={'view'}, appendArgs=true)
   */
  public static function retrieve($nid) {
    $node = node_load($nid);
    if ($node->type==$type) {
      $node->uri = services_resource_uri(array($type, $node->nid));
      return $node;
    }
  }

  /**
   * Updates a content item
   *
   * @param int $nid ["path","0"]
   *  The id of the item to update
   * @param object $data ["data"]
   *  The item data object
   * @return object
   *
   * @Access(callback='ConglomerateContentResource::access', args={'update'}, appendArgs=true)
   */
  public static function update($nid, $data) {
    $data->nid = $nid;

    try {
      $form_state = self::executeNodeForm('update', $data);
    }
    catch (Exception $e) {
      return services_error($e->getMessage(), $e->getCode());
    }

    $result = array(
      'nid' => $nid,
      'uri' => services_resource_uri(array('content', $nid)),
      'url' => url('node/' . $nid, array('absolute' => TRUE))
    );
    drupal_alter('conglomerate_api_updated_result', $result);
    return $result;
  }

  /**
   * Deletes a content item
   *
   * @param int $nid ["path","0"]
   *  The nid of the content item to delete
   * @return bool
   *
   * @Access(callback='ConglomerateContentResource::access', args={'delete'}, appendArgs=true)
   */
  public static function delete($nid) {
    node_delete($nid);
  }

  /**
   * Retrieves a listing of content items
   *
   * @param int $page ["param","page"]
   * @param string $fields ["param","fields"]
   * @param array $parameters ["param"]
   * @return array
   *
   * @Access(callback='user_access', args={'access content'}, appendArgs=false)
   * @Model(class='ResourceFeedModel', implementation='ConglomerateContentFeedModel', arguments={mode = 'raw', item_length='fulltext'}, allow_overrides={'mode', 'item_length'})
   */
  public static function index($page=0, $fields=array(), $parameters=array()) {
    $builder = new QueryBuilder();

    if ($parameters['__action']=='describe') {
      return $builder->describe();
    }

    if (empty($fields)) {
      $fields = array('nid', 'type', 'title', 'teaser', 'language');
    }
    else {
      if (is_string($fields)) {
        $fields = preg_split('/,\s?/', $fields);
      }
      $fields = array_fill_keys($fields, TRUE);
      $fields['nid'] = TRUE;
      $fields['type'] = TRUE;
      $fields = array_keys($fields);
    }

    // Always enforce the node status condition
    $parameters['status'] = 1;

    // Generate and execute the sql
    list($sql, $params) = $builder->query($fields, $parameters);
    $res = db_query_range($sql, $params, $page*20, 20);

    error_log(var_export(db_query_debug($sql, $params), TRUE));

    $nodes = array();
    while ($node = db_fetch_object($res)) {
      $node->url = url('node/' . $node->nid, array(
        'absolute' => TRUE,
      ));
      $node->uri = services_resource_uri(array($node->type, $node->nid));
      $nodes[] = $node;
    }
    return $nodes;
  }

  /**
   * Executes either a insert or update using the node form
   *
   * @param string $op 'create' or 'update'
   * @param string $data The data the we got from the caller
   * @return array Form state after submission
   */
  private static function executeNodeForm($op, $data) {
    // Setup form_state
    $values = self::adaptFormStateValues($op, $data);

    // $node should be null when inserting new content, but we need a
    // valid node object with a matching type when updating.
    $node = NULL;
    if ($op == 'update') {
      if (empty($values['nid'])) {
        throw new Exception(t("No id was given for the content that should be updated"), 406);
      }
      else {
        $node = node_load($nid);

        // Check that we got a node
        if (!$node || !$node->nid) {
          throw new Exception(t("There is no content with the id !nid", array(
            '!nid' => $values['!nid'],
          )), 404);
        }

        // Check that the node has the correct type
        if ($node->type != $values['type']) {
          throw new Exception(t('The nid of the submitted content didn\'t match a !type', array(
            '!type' => $type,
          )), 406);
        }
      }
    }

    // Load the required includes for drupal_execute
    module_load_include('inc', 'node', 'node.pages');
    $form_state = array();
    $form_state['values'] = $values;
    $form_state['values']['op'] = t('Save');
    $ret = drupal_execute($type . '_node_form', $form_state, $node);

    // TODO: Send information about which fields failed
    if ($errors = form_get_errors()) {
      throw new Exception(implode("\n", $errors), 400);
    }

    return $form_state;
  }

  /**
   * Adds allowed attributes from the data object to the
   * node array.
   *
   * @param object $data Input data.
   * @param array &$node Node info.
   * @param array $allowed An array of allowed values.
   * @return void
   */
  public static function filter($data, &$node, $allowed) {
    foreach ($allowed as $key => $allowed) {
      if ($allowed && isset($data->$key)) {
        $node[$key] = $data->$key;
      }
    }
  }

  /**
   * Performs all the changes that are needed for drupal_execute to
   * accept the form state.
   *
   * @param string $op 'create' or 'update'
   * @param object $values The values that should be adapted
   * @return array The form state values
   */
  private static function adaptFormStateValues($op, &$values) {
    $tag_vocabulary = variable_get('conglomerate_tag_vocabulary', 0);

    $allowed = array(
      '*' => array(
        'title' => TRUE, 
        'body' => TRUE, 
        'latitude' => TRUE,
        'longitude' => TRUE,
        'tags' => TRUE,
        'author' => TRUE,
      ),
      'event' => array(
        'starts' => TRUE,
      ),
      'location' => array(
      ),
      'news' => array(
      ),
    );
    // Allow a nid if we are dealing with a update
    if ($op == 'update') {
      $allowed['*']['nid'] = TRUE;
    }
    // Allow other modules to alter the allowed types and values
    drupal_alter('conglomerate_api_allowed', $allowed, $op);

    // Check that the content has a valid type
    if (empty($values->type)) {
      throw new Exception(t("You must provide a type for the content"), 400);
    }
    if (!isset($allowed[$values->type])) {
      throw new Exception(t("Unknown content type", 400));
    }

    // Filter the data so that we don't let
    // anything but the allowed values pass.
    $node = array('type' => $values->type);
    self::filter($values, $node, $allowed['*']);
    self::filter($values, $node, $allowed[$node['type']]);

    // Convert the position to the format that simple geo expects
    if (!empty($node['latitude']) && !empty($node['longitude'])) {
      $node['simple_geo_position'] = sprintf('%s %s', $node['latitude'], $node['longitude']);
    }
    unset($node['latitude'], $node['longitude']);

    // Adapt tag information to the expected taxonomy format
    if ($tag_vocabulary && isset($node['tags'])) {
      $tags = preg_split('/(?:,?\s+)|(?:[,])/', $node['tags']);
      $node['taxonomy']['tags'][$tag_vocabulary] = join($tags, ', ');
    }
    unset($node['tags']);

    // Format the start date so that it's understood by CCK
    if ($node['type'] == 'event') {
      if (isset($node['starts'])) {
        $node['field_starts'] = date('c', $node['starts']);
        unset($node['starts']);
      }
    }

    // Allow other modules to alter the values
    drupal_alter('conglomerate_api_form_state', $node, $op);

    return $node;
  }
}