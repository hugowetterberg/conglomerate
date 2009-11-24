<?php
// $Id$

function _conglomerate_site_register($form_state) {
  $form = array();

  $form['subdomain'] = array(
    '#type' => 'textfield',
    '#title' => t('Subdomain'),
    '#required' => TRUE,
    '#description' => t('The subdomain for your site'),
    '#size' => 20,
    '#maxlength' => 20,
  );

  $form['description'] = array(
    '#type' => 'textarea',
    '#title' => t('Description'),
    '#required' => TRUE,
    '#description' => t('A short description of your site'),
    '#cols' => 40,
    '#rows' => 10,
  );

  $form['create'] = array(
    '#type' => 'submit',
    '#value' => t('Create'),
  );

  return $form;
}

function _conglomerate_site_register_validate($form, $form_state) {
  $subdomain = $form_state['values']['subdomain'];

  if (!preg_match('/^[a-z]+$/', $subdomain)) {
    form_set_error('subdomain', t('The subdomain name may only contain lowercase characters from a to z.'));
  }
  else {
    $reserved = drupal_strtolower(variable_get('conglomerate_reserved_subdomains', ''));
    if (!empty($reserved)) {
      $reserved = split("\n", $reserved);
      foreach ($reserved as $r) {
        if (drupal_strtolower($r) == $subdomain) {
          form_set_error('subdomain', t('The subdomain "!subdomain" is reserved and may not be registered.', array(
            '!subdomain' => $subdomain,
          )));
        }
      }
    }

    // Check that the subdomain hasn't been taken
    $url = sprintf('http://%s.%s', $values['subdomain'], variable_get('conglomerate_domain', ''));
    $exists = db_result(db_query("SELECT url FROM {conglomerate_source} WHERE url = '%s'", array(
      ':url' => $url,
    )));
    if (!empty($exists)) {
      form_set_error('subdomain', t('The subdomain "!subdomain" has already been registered.', array(
        '!subdomain' => $subdomain,
      )));
    }
  }
}

function _conglomerate_site_register_submit($form, $form_state) {
  global $user;
  module_load_include('inc', 'conglomerate', 'includes/admin');

  $values = $form_state['values'];
  $domain = sprintf('%s.%s', $values['subdomain'], variable_get('conglomerate_domain', ''));

  // Create consumer and access token that the subsite can use to communicate with the conglomerate
  // server.
  $consumer = new DrupalOAuthConsumer(user_password(32), user_password(32), array(
    'uid' => $user->uid,
    'name' => $domain,
    'context' => 'conglomerate',
    'callback_url' => sprintf('%s/oauth/authorized', $domain),
  ));
  $consumer->write();
  $access_token = new DrupalOAuthToken(user_password(32), user_password(32), array(
    'uid' => $user->uid,
    'consumer_key' => $consumer->key,
    'services' => array('*'),
    'authorized' => 1,
  ));
  $access_token->write();

  $server_url = url('', array('absolute' => TRUE));
  // Create a config array that will be passed to the subsite
  $config = array(
    'user' => array(
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
  $consumer = DrupalOAuthConsumerToken::load($consumer_key);
  $access_token = reset($tokens['access']);

  $auth = new RestClientOAuth($consumer, $access_token);
  $client = new RestClient($auth, new RestClientBaseFormatter(RestClientBaseFormatter::FORMAT_PHP));

  $response = (array)$client->post(sprintf('%s/conglomeratehostmaster/api/subsite', $consumer->provider_url), array(
    'domain' => $domain,
    'config' => $config,
  ));

  $source = array(
    'nid' => $response['nid'],
    'oauth_consumer' => $consumer->key,
    'title' => $domain,
    'url' => 'http://' . $domain,
  );
  drupal_write_record('conglomerate_source', $source);

  drupal_set_message(t('Your site will be created shortly. You will receive an email when it\'s ready.'));
}