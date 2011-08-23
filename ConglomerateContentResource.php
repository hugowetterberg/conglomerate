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
    if($data->searchable != 'hidden') {
      global $user, $language;
      $node = (object)array(
        'created' => time(),
        'changed' => time(),
        'uid' => $user->uid,
        'taxonomy' => array(),
        'language' => $language->language,
      );
      return self::nodeWrite($node, $data);
    }
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
    $node->uri = services_resource_uri(array('conglomerate-content', $node->nid));
    return $node;
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
    if($data->searchable == 'hidden') {
      node_delete($nid);
    } else {
      $node = node_load($nid);
      $node->changed = time();
      return self::nodeWrite($node, $data);
    }
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
    return TRUE;
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

    $builder->add_table('p', 'LEFT OUTER JOIN {content_field_image_url} AS p ON p.nid=n.nid', 'n', array(
      'image' => array(
        'column' => 'field_image_url_url',
      ),
    ));

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
   * Helper function that maps incoming data to the proper node attributes
   *
   * @param object $node
   * @param object $data
   * @return object
   */
  private static function nodeWrite($node, $data) {
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
      'metadata' => array(
        'required' => FALSE,
        'to' => 'conglomerate_metadata',
      ),
      'picture' => array(
        'required' => FALSE,
        'adapt' => 'adaptPicture',
      ),
      'large_picture' => array(
        'required' => FALSE,
        'adapt' => 'adaptLargePicture',
      ),
      'url' => array(
        'required' => TRUE,
        'adapt' => 'adaptUrl',
      ),
      'fulltext' => array(
        'required' => FALSE,
        'adapt' => 'adaptFulltext',
      ),
      'type' => array('required' => TRUE),
      'language' => array('required' => TRUE),
      'comments' => array('required' => FALSE),
    );
    switch ($data->type) {
      case 'event':
        $attr['starts'] = array(
          'adapt' => 'adaptStartTime',
          'required' => TRUE,
        );
        break;
			case 'subpage':
				$attr['searchable'] = array(
					'adapt' =>'adaptSearchable',
					'required' => TRUE,
				);
				break;
    }
    drupal_alter('conglomerate_node_write_attributes', $attr, $data, $source);

    // Transfer attributes from data
    foreach ($attr as $name => $info) {
      if (isset($data->$name)) {
        $to = $name;
        if (!empty($info['to'])) {
          $to = $info['to'];
        }
        $node->$to = $data->$name;

        if (isset($info['adapt'])) {
          call_user_func('ConglomerateContentResource::' . $info['adapt'], $node);
        }
      }
      else if ($info['required']) {
        return services_error("Missing attribute {$name}", 406);
      }
    }

    // Add information about the conglomerate source
    $node->conglomerate_source = $source->sid;
    $the_language = $node->language;
    node_save($node);

    // Workaround to preserve language. TODO: Must find out what is happening to the language.
    if (!$node->language) {
      db_query("UPDATE {node} SET language='%s' WHERE nid = %d", array(
        ':language' => $the_language,
        ':nid' => $node->nid,
      ));
    }

    return (object)array(
      'nid' => $node->nid,
      'uri' => services_resource_uri(array('conglomerate-content', $node->nid)),
      'url' => url('node/' . $node->nid, array('absolute' => TRUE))
    );
  }

  public static function adaptUrl($node) {
    $node->field_page_url = array(array(
      'url' => $node->url,
    ));
    unset($node->url);
  }

  /**
   * Adapt tag information to the expected taxonomy format.
   */
  public static function adaptTags($node) {
    $tag_vid = variable_get('conglomerate_tag_vid', 1);
    if ($tag_vid && !empty($node->taxonomy)) {
      $remove = array();
      foreach ($node->taxonomy as $tid => $term) {
        if ($term->vid == $tag_vid) {
          $remove[] = $tid;
        }
      }
      foreach ($remove as $tid) {
        unset($node->taxonomy[$tid]);
      }
    }

    if ($tag_vid && isset($node->tags)) {
      $node->taxonomy['tags'][$tag_vid] = $node->tags;
    }
    unset($node->tags);
  }

  /**
   * Format the start date so that it's understood by CCK.
   */
  public static function adaptStartTime($node) {
    $starts = $node->starts;
    unset($node->starts);

    if (!is_array($starts)) {
      $starts = array($starts);
    }

    $node->field_starts = array();
    foreach ($starts as $time) {
      error_log(gmdate('c', $time));
      $node->field_starts[] = array(
        'value' => gmdate('c', $time),
      );
    }
  }

  /**
   * Format the full-text field so that it's understood by CCK.
   */
  public static function adaptFulltext($node) {
    $node->field_fulltext = array(array(
      'value' => $node->fulltext,
    ));
    unset($node->fulltext);
  }

  /**
   * Format the picture url so that it's understood by CCK.
   */
  public static function adaptPicture($node) {
    $node->field_image_url = array(array(
      'url' => $node->picture,
    ));
    unset($node->picture);
  }

  /**
   * Format the picture url so that it's understood by CCK.
   */
  public static function adaptLargePicture($node) {
    $node->field_large_image_url = array(array(
      'url' => $node->large_picture,
    ));
    unset($node->large_picture);
  }

  public static function access($op='view', $args=array()) {
    global $user;
    if ($op !== 'create') {
      $node = node_load($args[0]);
    }
    else {
      $node = $args[0];
    }
    return node_access($op, $node);
  }

	public static function adaptSearchable($node) {
    $node->field_searchable = array(array(
      'value' => $node->searchable,
    ));
    unset($node->searchable);
  }
}