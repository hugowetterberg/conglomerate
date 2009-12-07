<?php
// $Id$

function _conglomerate_site_register() {
  global $user;

  if (!$user->uid) {
    drupal_goto('user/register', array('destination' => 'register-site'));
  }

  if (!user_access('create site content')) {
    return t('It looks like sites aren\'t open for public registration yet.');
  }
  else {
    drupal_goto('node/add/site');
  }
}


function _conglomerate_site_register_submit($node) {
  global $user;
  module_load_include('inc', 'conglomerate', 'includes/admin');

  // Create consumer and access token that the subsite can use to communicate with the conglomerate
  // server.
  $consumer = new DrupalOAuthConsumer(user_password(32), user_password(32), sprintf('%s/oauth/authorized', $node->conglomerate_domain), array(
    'uid' => $user->uid,
    'name' => $node->title,
    'context' => 'conglomerate',
  ));
  $consumer->write();
  $access_token = new DrupalOAuthToken(user_password(32), user_password(32), array(
    'uid' => $user->uid,
    'consumer_key' => $consumer->key,
    'type' => 'access',
    'services' => array('*'),
    'authorized' => 1,
  ));
  $access_token->write();

  // Set the consumer key for the site
  $node->conglomerate_oauth_consumer = $consumer->key;

  $server_url = url('', array('absolute' => TRUE));
  // Create a config array that will be passed to the subsite
  $config = array(
    'nid' => $node->nid,
    'title' => $node->title,
    'tags' => $node->taxonomy['tags'][variable_get('conglomorate_tag_vocabulary', 0)],
    'position' => $node->simple_geo_position,
    'area' => $node->simple_geo_area,
    'user' => array(
      'uid' => $user->uid,
      'name' => $user->name,
      'email' => $user->mail,
    ),
    'server' => array(
      'name' => variable_get('site_name', $server_url),
      'url' => $server_url,
      'endpoint' => url('api', array('absolute' => TRUE)),
    ),
    'consumer' => array(
      'key' => $consumer->key,
      'secret' => $consumer->secret,
    ),
    'access_token' => array(
      'key' => $access_token->key,
      'secret' => $access_token->secret,
    ),
  );

  // Load our credentials
  $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');
  $tokens = _conglomerate_oauth_tokens();
  $hm_consumer = DrupalOAuthConsumerToken::load($consumer_key);
  $hm_access_token = reset($tokens['access']);

  $auth = new RestClientOAuth($hm_consumer, $hm_access_token, new OAuthSignatureMethod_HMAC('sha1'));
  $client = new RestClient($auth, new RestClientBaseFormatter(RestClientBaseFormatter::FORMAT_PHP));

  $response = (array)$client->post(sprintf('%s/conglomeratehostmaster/api/subsite.php', $hm_consumer->provider_url), array(
    'domain' => $node->conglomerate_domain,
    'config' => $config,
  ));

  drupal_set_message(t('The site will be created shortly. And an email will be sent when it\'s ready.'));
  return $response['nid'];
}