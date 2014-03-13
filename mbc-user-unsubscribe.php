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
    'name' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE'),
    'passive' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_PASSIVE'),
    'durable' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_DURABLE'),
    'exclusive' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_EXCLUSIVE'),
    'auto_delete' => getenv('MB_MAILCHIMP_UNSUBSCRIBE_QUEUE_AUTO_DELETE'),
  ),
);

$mb = new MessageBroker($credentials, $config);

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

      // Create connection to the database using MeekroDB static methods
      DB::$dbName = getenv('MAILCHIMP_USERS_DB_NAME') ? getenv('MAILCHIMP_USERS_DB_NAME') : 'mailchimp_users';
      DB::$user = getenv('MAILCHIMP_USERS_DB_USER') ? getenv('MAILCHIMP_USERS_DB_USER') : 'root';
      DB::$password = getenv('MAILCHIMP_USERS_DB_PW') ? getenv('MAILCHIMP_USERS_DB_PW') : 'root';
      DB::$host = getenv('MAILCHIMP_USERS_DB_HOST') ? getenv('MAILCHIMP_USERS_DB_HOST') : 'localhost';
      DB::$port = getenv('MAILCHIMP_USERS_DB_PORT') ? getenv('MAILCHIMP_USERS_DB_PORT') : 8901;

      $updateArgs = array();
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

      // Update the 'users' table to indicate a user is unsubscribed
      $result = DB::update('users', $updateArgs, "email=%s", $email);
      if ($result == TRUE) {
        echo "Updated subscription for email: $email\n";
      }

      // Send acknowledgement
      $payload->delivery_info['channel']->basic_ack($payload->delivery_info['delivery_tag']);
    }
  }
};

// Start consuming messages
$mb->consumeMessage($callback);

?>
