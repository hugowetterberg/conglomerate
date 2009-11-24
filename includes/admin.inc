<?php
// $Id$

function _conglomerate_settings($form_state) {
  $form = array();

  $form['conglomerate_domain'] = array(
    '#type' => 'textfield',
    '#title' => t('Domain'),
    '#description' => t('The root domain for the subsites.'),
    '#size' => 40,
    '#maxlength' => 255,
    '#default_value' => variable_get('conglomerate_domain', ''),
  );

  $form['conglomerate_reserved_subdomains'] = array(
    '#type' => 'textarea',
    '#title' => t('Reserved subdomains'),
    '#description' => t('Enter all reserved subdomains on separate lines'),
    '#cols' => 60,
    '#rows' => 5,
    '#default_value' => variable_get('conglomerate_reserved_subdomains', ''),
  );

  return system_settings_form($form);
}

function _conglomerate_hostmaster_settings($form_state) {
  $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');

  $form = array();
  $form['hostmaster_url'] = array(
    '#type' => 'textfield',
    '#title' => t('Hostmaster url'),
    '#description' => t('The url to the hostmaster'),
    '#required' => TRUE,
    '#size' => 40,
    '#maxlength' => 255,
  );
  
  $form['consumer_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Consumer key'),
    '#description' => t('The consumer key that the conglomerate server should use to identify itself to the hostmaster.'),
    '#required' => TRUE,
    '#size' => 32,
    '#maxlength' => 32,
  );

  $form['consumer_secret'] = array(
    '#type' => 'textfield',
    '#title' => t('Consumer secret'),
    '#description' => t('The consumer secret that the conglomerate server should use to sign requests to the hostmaster.'),
    '#required' => TRUE,
    '#size' => 32,
    '#maxlength' => 32,
  );

  if (!empty($consumer_key)) {
    $token = DrupalOAuthConsumerToken::load($consumer_key);
    if ($token) {
      $form['consumer_key']['#default_value'] = $token->key;
      $form['consumer_secret']['#default_value'] = $token->secret;
      $form['hostmaster_url']['#default_value'] = $token->provider_url;
    }
    
    $access = db_result(db_query_range("SELECT token_key FROM {oauth_common_token} 
      WHERE provider_token = 0 
      AND type='access'
      AND consumer_key='%s'", 0, 1, array(
        ':consumer_key' => $consumer_key,
      )));
    if (!empty($access)) {
      $form['consumer_key']['#description'] .= '<br/>' . t('Changing the consumer key will delete any existing access tokens for the hostmaster.');
      $form['consumer_key_authorize_change'] = array(
        '#type' => 'checkbox',
        '#title' => t('Authorize consumer key change'),
        '#default_value' => -1,
        '#description' => t('As there is a existing access token that uses this consumer key you have to check this box to confirm any changes you want to do to the consumer key or secret.')
      );
    }
  }

  $form['save'] = array(
    '#type' => 'submit',
    '#value' => t('Save'),
  );

  return $form;
}

function _conglomerate_hostmaster_settings_validate($form, $form_state) {
  $values = $form_state['values'];

  $hostmaster_url = drupal_strtolower($values['hostmaster_url']);
  if (!preg_match('/^https?:\/\/[a-z\.]+(:\d+)?(\/[a-z\.\/]+)?$$/', $hostmaster_url)) {
    form_set_error('subdomain', t('The hostmaster url must be a valid url without a query string.'));
  }

  if (isset($form['consumer_key_authorize_change']) && !$values['consumer_key_authorize_change']) {
    $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');
    $token = DrupalOAuthConsumerToken::load($consumer_key);
    if ($token->key !== $values['consumer_key'] || $token->secret !== $values['consumer_secret']) {
      form_set_error('consumer_key_authorize_change', t('You have to tick the "!name" checkbox to update the consumer key or secret.', array(
        '!name' => t('Authorize consumer key change'),
      )));
    }
  }

  if (!preg_match('/^[a-zA-Z0-9]{32}$/', $values['consumer_key'])) {
    form_set_error('consumer_key', t('The consumer key should be a 32-character alpha-numeric string'));
  }

  if (!preg_match('/^[a-zA-Z0-9]{32}$/', $values['consumer_secret'])) {
    form_set_error('consumer_secret', t('The consumer secret should be a 32-character alpha-numeric string'));
  }
}

function _conglomerate_hostmaster_settings_submit($form, $form_state) {
  $values = $form_state['values'];
  $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');

  // If the consumer key has changed
  if (!empty($consumer_key) && $consumer_key !== $values['consumer_key']) {
    // ..delete old tokens
    db_query("DELETE FROM {oauth_common_token} WHERE consumer_key='%s'", array(
      ':consumer_key' => $consumer_key,
    ));
    // ..delete the consumer token
    DrupalOAuthConsumerToken::deleteToken($consumer_key);
    $consumer_key = '';

    drupal_set_message(t('Deleted old authorization, go to !link to get a new one.', array(
      '!link' => l(t('Hostmaster authorization'), 'admin/settings/conglomerate/authorization'),
    )));
  }

  $consumer = DrupalOAuthConsumerToken::load($values['consumer_key']);
  $update = TRUE;
  if (!$consumer) {
    $consumer = new DrupalOAuthConsumerToken($values['consumer_key'], $values['consumer_secret']);
    $update = FALSE;
  }
  else {
    $consumer->secret = $values['consumer_secret'];
  }
  $consumer->provider_url = $values['hostmaster_url'];
  $consumer->write($update);
  variable_set('conglomerate_hostmaster_consumer_key', $consumer->key);
  
  drupal_set_message('Consumer information updated.');
}

function _conglomerate_hostmaster_authorization($form_state) {
  $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');

  $form = array();

  if (!empty($consumer_key)) {
    $consumer = DrupalOAuthConsumerToken::load($consumer_key);
    $tokens = _conglomerate_oauth_tokens();
    if (!empty($tokens['access'])) {
      $form['have_access'] = array(
        '#type' => 'item',
        '#title' => t('Access granted'),
        '#value' => t('We have a authorized access token.'),
      );
    }
    else if (!empty($tokens['request'])) {
      $client = new DrupalOAuthClient($consumer, reset($tokens['request']));
      $form['have_request_token'] = array(
        '#type' => 'item',
        '#title' => t('Have request token'),
        '#value' => t('We have a request token. !link.', array(
          '!link' => l(t('Click here to authorize'), $client->getAuthorizationUrl('/oauth/authorize')),
        )),
      );
      $form['get_access_token'] = array(
        '#type' => 'submit',
        '#value' => t('Get access token'),
        '#description' => t('Click here to retrieve a access token if the request token has been authorized.'),
      );
      $form['get_request_token'] = array(
        '#type' => 'submit',
        '#value' => t('Restart authorization process'),
        '#description' => t('Click here to get a new request token and restart the authorization process.'),
      );
    }
    else {
      $form['get_request_token'] = array(
        '#type' => 'submit',
        '#value' => t('Get request token'),
        '#description' => t('Click here to get a request token and start the authorization process.'),
      );
    }
  }
  else {
    $form['no_consumer_info'] = array(
      '#type' => 'item',
      '#title' => t('No consumer information'),
      '#value' => t('Missing consumer information, go to !link to enter the necessary information.', array(
        '!link' => l(t('Hostmaster settings'), 'admin/settings/conglomerate'),
      )),
    );
  }

  return $form;
}

function _conglomerate_oauth_tokens() {
  $tokens = array(
    'request' => array(),
    'access' => array(),
  );
  $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');
  if ($consumer_key) {
    $token_res = db_query("SELECT * FROM {oauth_common_token} 
      WHERE provider_token = 0 
      AND consumer_key='%s'
      ORDER BY created DESC", array(
        ':consumer_key' => $consumer_key,
      ));
    while ($token = DrupalOAuthToken::fromResult($token_res)) {
      $tokens[$token->type][] = $token;
    }
  }
  return $tokens;
}

function _conglomerate_hostmaster_authorization_submit($form, $form_state) {
  $values = $form_state['values'];
  $consumer_key = variable_get('conglomerate_hostmaster_consumer_key', '');
  $consumer = DrupalOAuthConsumerToken::load($consumer_key);
  $tokens = _conglomerate_oauth_tokens();

  if ($values['op'] == $form['get_request_token']['#value']) {
    $client = new DrupalOAuthClient($consumer);
    
    try {
      $token = $client->getRequestToken();
    }
    catch (Exception $e) {
      drupal_set_message($e->getMessage(), 'error');
    }

    if ($token) {
      // We got a request token, delete all old tokens
      db_query("DELETE FROM {oauth_common_token} 
        WHERE provider_token = 0 
        AND consumer_key='%s'", array(
          ':consumer_key' => $consumer_key,
        ));
      // Write the new token to the database
      $token->write(FALSE);
    }
    else {
      drupal_set_message(t('Failed to fetch request token. Make sure that the consumer details are correct.'), 'error');
    }
  }
  else if ($values['op'] == $form['get_access_token']['#value']) {
    $request_token = reset($tokens['request']);
    if ($request_token) {
      $client = new DrupalOAuthClient($consumer, $request_token);
      try {
        $token = $client->getAccessToken();
      }
      catch (Exception $e) {
        drupal_set_message($e->getMessage(), 'error');
      }

      if ($token) {
        // We got a access token, delete all old tokens
        db_query("DELETE FROM {oauth_common_token} 
          WHERE provider_token = 0 
          AND consumer_key='%s'", array(
            ':consumer_key' => $consumer_key,
          ));
        // Write the new token to the database
        $token->write(FALSE);
      }
      else {
        drupal_set_message(t('Failed to get access token. Make sure that the request has been authorized.'));
      }
    }
    else { // Shouldn't happen, but we could have lost our request token
      drupal_set_message(t('Tried to get a access token, but we no longer got a request token, please request a new one.'));
    }
  }
}