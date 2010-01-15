<?php

/**
 * Conglomerate user resource
 *
 * @Action(name='info', controller='userInfo')
 * @Action(name='roleUpdate', controller='roleUpdate')
 */
class ConglomerateUserResource {

  /**
   * Retrieves information about the current user
   *
   * @return object
   *
   * @Access(callback='user_access', args={'access content'}, appendArgs=false)
   */
  public static function userInfo() {
    global $user;

    $data = (array)$user;
    unset($data['pass']);

    return (object)$data;
  }

  /**
   * Retrieves information about the current user
   *
   * @param object $info ["data"]
   *
   * @return object
   *
   * @Access(callback='user_access', args={'access content'}, appendArgs=false)
   */
  public static function roleUpdate($info) {
    $return = array('error' => TRUE);

    // Extract the info we need from post data
    $uid = $info->euid;
    $consumer = services_get_server_info('oauth_consumer');

    // Get site ID
    $result = db_query("SELECT * FROM {conglomerate_source} WHERE oauth_consumer='%s' LIMIT 1", $consumer->key);
    if ($result !== FALSE) {
      $site = db_fetch_object($result);
      $sid = $site->nid;
    }

    if ($result !== FALSE) {
      // Delete old roles (if any) to avoid duplicates
      db_query("DELETE FROM {conglomerate_user_roles} WHERE uid=%d AND sid=%d", $uid, $sid);


      // If no roles were sent it means user is deleted. And also, the for loop won't be run.
      foreach ($info->roles as $role) {
        db_query("INSERT INTO {conglomerate_user_roles} VALUES(%d, '%s', %d)", $uid, $role, $sid);
      }

      $return = array('error' => FALSE);
    }

    return (object)$return;
  }
}