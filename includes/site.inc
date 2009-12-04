<?php
// $Id$

function _conglomerate_site_register_submit($form, $form_state) {
  global $user;
  module_load_include('inc', 'conglomerate', 'includes/admin');
  $values = $form_state['values'];
  $domain = sprintf('%s.%s', $values['subdomain'], variable_get('conglomerate_domain', ''));

  // Create consumer and access token that the subsite can use to communicate with the conglomerate
  // server.
  $consumer = new DrupalOAuthConsumer(user_password(32), user_password(32), sprintf('%s/oauth/authorized', $domain), array(
    'uid' => $user->uid,
    'name' => $domain,
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

  $server_url = url('', array('absolute' => TRUE));
  // Create a config array that will be passed to the subsite
  $config = array(
    'title' => $values['title'],
    'tags' => $values['tags'],
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
    'domain' => $domain,
    'config' => $config,
  ));

  $source = array(
    'nid' => $response['nid'],
    'oauth_consumer' => $consumer->key,
    'title' => $values['title'],
    'description' => $values['description'],
    'url' => 'http://' . $domain,
  );
  drupal_write_record('conglomerate_source', $source);

  drupal_set_message(t('Your site will be created shortly. You will receive an email when it\'s ready.'));
}