<?php

/**
 * Conglomerate user resource
 *
 * @Action(name='info', controller='userInfo')
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
}