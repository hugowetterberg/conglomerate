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
  module_load_include('inc', 'conglomerate', 'includes/admin');

  $account = user_load(array('uid'=>$node->uid));

  // Create consumer and access token that the subsite can use to log in users.
  $login_consumer = new DrupalOAuthConsumer(user_password(32), user_password(32), sprintf('http://%s/oauth/authorized', $node->conglomerate_domain), TRUE, array(
    'uid' => $account->uid,
    'name' => $node->title,
    'context' => 'conglomerate_login',
  ));
  $login_consumer->write();

  // Create consumer and access token that the subsite can use to communicate with the conglomerate
  // server.
  $consumer = new DrupalOAuthConsumer(user_password(32), user_password(32), sprintf('http://%s/oauth/authorized', $node->conglomerate_domain), TRUE, array(
    'uid' => $account->uid,
    'name' => $node->title,
    'context' => 'conglomerate',
  ));
  $consumer->write();

  $access_token = new DrupalOAuthToken(user_password(32), user_password(32), array(
    'uid' => $account->uid,
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
    'default_language' => $node->default_language,
    'languages' => array_values($node->enabled_languages),
    'area' => $node->simple_geo_area,
    'user' => array(
      'uid' => $account->uid,
      'name' => $account->name,
      'email' => $account->mail,
    ),
    'server' => array(
      'name' => variable_get('site_name', $server_url),
      'url' => $server_url,
      'endpoint' => url('api', array('absolute' => TRUE)),
      'login_endpoint' => url('login-api', array('absolute' => TRUE)),
    ),
    'login_consumer' => array(
      'key' => $login_consumer->key,
      'secret' => $login_consumer->secret,
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
  $hm_consumer = DrupalOAuthConsumer::load($consumer_key, FALSE);
  $hm_access_token = reset($tokens['access']);

  $auth = new HttpClientOAuth($hm_consumer, $hm_access_token, new OAuthSignatureMethod_HMAC('sha1'));
  $client = new HttpClient($auth, new HttpClientBaseFormatter(HttpClientBaseFormatter::FORMAT_PHP));

  $data = array(
    'domain' => $node->conglomerate_domain,
    'config' => $config,
  );
  if ($node->conglomerate_devel_platform) {
    $data['platform'] = 'devel';
  }

  $response = (array)$client->post(sprintf('%s/conglomeratehostmaster/api/subsite.php', $hm_consumer->configuration['provider_url']), $data);
  drupal_set_message(t('The site will be created shortly. And an email will be sent when it\'s ready.'));
  return $response['nid'];
}