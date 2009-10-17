<?php
// $Id$

class ConglomerateContentFeedModel extends NodeResourceFeedModel {
  public function __construct($data, $arguments) {
    parent::__construct($data, $arguments);
  }

  public static function alterArguments(&$arguments, $args) {
    $fields = $arguments[1];

    if (is_string($fields)) {
      $fields = preg_split('/,\s?/', $fields);
    }
    $fields = array_fill_keys($fields, TRUE);
    $fields['nid'] = TRUE;
    $fields['title'] = TRUE;
    
    switch ($args['item_length']) {
      case 'fulltext':
        $fields['body'] = TRUE;
      break;
      case 'teaser':
        $fields['teaser'] = TRUE;
      break;
    }
    
    $fields = array_keys($fields);
    $arguments[1] = $fields;
  }
}