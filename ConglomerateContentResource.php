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
    global $user, $language;
    $oauth_consumer = services_get_server_info('oauth_consumer');
    $source = conglomerate_source_from_consumer($oauth_consumer);

    $attr = array(
      'title' => array('required' => TRUE),
      'text' => array(
        'to' => 'body',
        'required' => TRUE,
      ),
      'position' => array(
        'to' => 'simple_geo_position',
        'required' => TRUE,
      ),
      'tags' => array(
        'required' => FALSE,
        'adapt' => 'adaptTags',
      ),
      'picture' => array('required' => FALSE),
      'url' => array('required' => TRUE),
      'type' => array('required' => TRUE),
      'language' => array('required' => TRUE),
      'comments' => array('required' => FALSE),
    );
    switch ($data['type']) {
      case 'event':
        $attr['starts'] = array('required' => TRUE);
        break;
    }

    $node = (object)array(
      'created' => time(),
      'modified' => time(),
      'uid' => $user->uid,
      'taxonomy' => array(),
      'language' => $language->language,
    );

    // Transfer attributes from data
    foreach ($attr as $name => $info) {
      if (isset($text->$name)) {
        $to = $name;
        if (!empty($info['to'])) {
          $to = $info['to'];
        }
        if (!isset($info['adapt'])) {
          $node->$to = $data->$name;
        }
        else {
          call_user_func($node, $info, $data->$name);
        }
      }
      else if ($info['required']) {
        return services_error("Missing attribute {$name}", 406);
      }
    }

    // Add information about the conglomerate source
    $node->conglomerate_source = $source->sid;

    var_export($node); die;

    node_save($node);

    return (object)array(
      'nid' => $node->nid,
      'uri' => services_resource_uri(array('docuwalk-text', $node->nid)),
      'url' => url('node/' . $node->nid, array('absolute' => TRUE))
    );
  }

  /**
   * Adapt tag information to the expected taxonomy format.
   */
  public static function adaptTags(&$node) {
    $tag_vid = variable_get('conglomerate_tag_vid', 1);
    if ($tag_vid && isset($node->tags)) {
      $tags = preg_split('/(?:,?\s+)|(?:[,])/', $node->tags);
      $node->taxonomy['tags'][$tag_vid] = join($tags, ', ');
    }
    unset($node->tags);
  }

  /**
   * Format the start date so that it's understood by CCK.
   */
  public static function adaptStartTime(&$node) {
    $node->field_starts = array(array(
      'value' => date('c', $node->starts)
    ));
    unset($node->starts);
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
}