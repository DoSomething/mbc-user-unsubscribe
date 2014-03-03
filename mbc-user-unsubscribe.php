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
$exchangeName = 'ds.direct_mailchimp_webhooks';

// Set the routing key that
$bindingKey = 'mailchimp-unsubscribe';

$channel = $connection->channel();
$channel->exchange_declare(
  $exchangeName,
  'direct',
  false,
  false,
  false
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
$callback = function($message) {
  if (isset($message->body)) {
    $unserializedData = unserialize($message->body);

    if (isset($unserializedData['data']) && isset($unserializedData['data']['merges'])) {
      // Extract user info from the message data
      $email = $unserializedData['data']['merges']['EMAIL'];
      $uid = $unserializedData['data']['merges']['UID'];
      $firstName = $unserializedData['data']['merges']['FNAME'];
      $lastName = $unserializedData['data']['merges']['LNAME'];
      $bday = $unserializedData['data']['merges']['BDAYFULL'];

      // @todo Update the DS user database with the subscription settings
    }
  }
};

// Start the queue consumer
$channel->basic_consume(
  $queueName, // Queue name
  '',         // Consumer tag
  false,      // no_local
  true,       // no_ack
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
