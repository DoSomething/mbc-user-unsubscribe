<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPConnection;

// Pull RabbitMQ credentials from environment vars. Otherwise, default to local settings.
$credentials = array();
$credentials['host'] = getenv('RABBITMQ_HOST') ? getenv('RABBITMQ_HOST') : 'localhost';
$credentials['port'] = getenv('RABBITMQ_PORT') ? getenv('RABBITMQ_PORT') : '5672';
$credentials['user'] = getenv('RABBITMQ_USERNAME') ? getenv('RABBITMQ_USERNAME') : 'guest';
$credentials['password'] = getenv('RABBITMQ_PASSWORD') ? getenv('RABBITMQ_PASSWORD') : 'guest';

// Create connection
$connection = new AMQPConnection(
  $credentials['host'],
  $credentials['port'],
  $credentials['user'],
  $credentials['password']
);

// Name the direct exchange the channel will connect to
$exchangeName = 'direct_mailchimp_webhooks';

// Set the binding key
$bindingKey = 'mailchimp-unsubscribe';

$channel = $connection->channel();
$channel->exchange_declare(
  $exchangeName,  // exchange name
  'direct',       // exchange type
  false,          // passive
  true,           // durable
  false           // auto_delete
);

// Get random queue name genereated by RabbitMQ
list($queueName, ,) = $channel->queue_declare(
    '',     // Empty queue name creates queue with generated name
    false,  // passive
    false,  // durable
    true,   // exclusive
    false   // auto_delete
  );

// Bind the queue to the exchange
$channel->queue_bind($queueName, $exchangeName, $bindingKey);

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

// Start the queue consumer
$channel->basic_consume(
  $queueName, // Queue name
  '',         // Consumer tag
  false,      // no_local
  false,      // no_ack
  false,      // exclusive
  false,      // nowait
  $callback   // callback
);

// Wait for messages on the channel
echo ' [*] Waiting for Unsubscribe messages. To exit press CTRL+C', "\n";
while (count($channel->callbacks)) {
  $channel->wait();
}

$channel->close();
$connection->close();

?>
