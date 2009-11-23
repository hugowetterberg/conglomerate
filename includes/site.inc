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

  $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');
  $consumer = DrupalOAuthConsumerToken::load($consumer_key);
  $tokens = _conglomerate_oauth_tokens();
  $auth = new RestClientOAuth($consumer, reset($tokens['access']));
  $client = new RestClient($auth, new RestClientBaseFormatter(RestClientBaseFormatter::FORMAT_PHP));

  $client->post(sprintf('%s/conglomeratehostmaster/api/subsite'), array(
    'domain' => $domain,
    'config' => $config,
  ));
}