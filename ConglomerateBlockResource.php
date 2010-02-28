<?php
// $Id$

class ConglomerateBlockResource {

  /**
   * Retrieves a listing of blocks for a language
   *
   * @param string $language ["param","language"]
   * @return array
   *
   * @Access(callback='user_access', args={'access content'}, appendArgs=false)
   */
  public static function index($language) {
    $res = db_query("SELECT nid FROM {node} AS n
      WHERE n.type = 'conglomerate_block'
      AND n.language = '%s'
      AND n.status = 1", array(
        ':language' => $language,
      ));
    $blocks = array();
    while ($nid = db_result($res)) {
      $node = node_load($nid);
      if ($node) {
        $block = array(
          'id' => $nid,
          'title' => $node->title,
          'optional' => $node->field_optional[0]['value'],
        );
        foreach ($node->taxonomy as $tid => $term) {
          $block['terms'][] = array($term->tid, $term->name);
        }
        $blocks[] = $block;
      }
    }
    return $blocks;
  }
}