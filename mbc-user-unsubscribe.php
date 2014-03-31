<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPConnection;

// Load configuration settings common to the Message Broker system.
// Symlinks in the project directory point to the actual location of the files.
require('mb-secure-config.inc');
require('mb-config.inc');

// Pull RabbitMQ credentials from environment vars. Otherwise, default to local settings.
$credentials = array();
$credentials['host'] = getenv('RABBITMQ_HOST') ? getenv('RABBITMQ_HOST') : 'localhost';
$credentials['port'] = getenv('RABBITMQ_PORT') ? getenv('RABBITMQ_PORT') : '5672';
$credentials['username'] = getenv('RABBITMQ_USERNAME') ? getenv('RABBITMQ_USERNAME') : 'guest';
$credentials['password'] = getenv('RABBITMQ_PASSWORD') ? getenv('RABBITMQ_PASSWORD') : 'guest';
$credentials['vhost'] = getenv('RABBITMQ_VHOST') ? getenv('RABBITMQ_VHOST') : '';

$config = array(
  // Routing key
  'routingKey' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_ROUTING_KEY'),

  // Consume options
  'consume' => array(
    'consumer_tag' => '',
    'no_local' => FALSE,
    'no_ack' => FALSE,
    'exclusive' => FALSE,
    'nowait' => FALSE,
  ),

  // Exchange options
  'exchange' => array(
    'name' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_EXCHANGE'),
    'type' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_EXCHANGE_TYPE'),
    'passive' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_EXCHANGE_PASSIVE'),
    'durable' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_EXCHANGE_DURABLE'),
    'auto_delete' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_EXCHANGE_AUTO_DELETE'),
  ),

  // Queue options
  'queue' => array(
    'mailchimp-webhook' => array(
      'name' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE'),
      'passive' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_PASSIVE'),
      'durable' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_DURABLE'),
      'exclusive' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_EXCLUSIVE'),
      'auto_delete' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_AUTO_DELETE'),
      'bindingKey' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_ROUTING_KEY'),
    ),
  ),
);

try {
  $mb = new MessageBroker($credentials, $config);
}
catch (Exception $e) {
  echo "Unable to establish a connection with the Message Broker. Exiting...\n";
  exit;
}

// Create callback to handle messages received by this consumer
$callback = function($payload) {
  if (isset($payload->body)) {
    $unserializedData = unserialize($payload->body);

    if (isset($unserializedData['data']) && isset($unserializedData['data']['merges'])) {
      // Extract user info from the message data
      $email = $unserializedData['data']['merges']['EMAIL'];
      $uid = $unserializedData['data']['merges']['UID'];
      $firstName = $unserializedData['data']['merges']['FNAME'];
      $lastName = $unserializedData['data']['merges']['LNAME'];
      $bday = $unserializedData['data']['merges']['BDAYFULL'];

      $updateArgs = array();
      $updateArgs['email'] = $email;
      $updateArgs['subscribed'] = 0;

      if (!empty($uid)) {
        $updateArgs['drupal_uid'] = $uid;
      }
      if (!empty($firstName)) {
        $updateArgs['first'] = $firstName;
      }
      if (!empty($lastName)) {
        $updateArgs['last'] = $lastName;
      }
      if (!empty($bday)) {
        $updateArgs['bday'] = $bday;
      }

      // POST subscription update to the user API
      $userApiHost = getenv('DS_USER_API_HOST') ? getenv('DS_USER_API_HOST') : 'localhost';
      $userApiPort = getenv('DS_USER_API_PORT') ? getenv('DS_USER_API_PORT') : 4722;
      $userApiUrl = $userApiHost . ':' . $userApiPort . '/user';

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $userApiUrl);
      curl_setopt($ch, CURLOPT_POST, count($updateArgs));
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($updateArgs));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      $result = curl_exec($ch);
      curl_close($ch);

      if ($result == TRUE) {
        echo "Updated subscription for email: $email\n";
      }
      else {
        echo "FAILED to update subscription for emai: $email\n";
      }

      // Send acknowledgement
      $payload->delivery_info['channel']->basic_ack($payload->delivery_info['delivery_tag']);
    }
  }
};

// Start consuming messages
$mb->consumeMessage($callback);

?>
