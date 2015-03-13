# mbc-user-unsubscribe

This is a Message Broker consumer that handles email unsubscription events. For a given email address, this consumer will update that user's subscription status via the User API.

Likely being used in conjunction with: https://github.com/DoSomething/mbp-mailchimp-webhooks

## Setup
#### Prerequisites
- Install Composer: https://getcomposer.org/doc/00-intro.md#installation-nix
- Setup configs:
  - Clone the messagebroker-config repository: https://github.com/DoSomething/messagebroker-config.
  - Create a symlink in the root of `mbc-user-unsubscribe` to `mb-config.inc` in `messagebroker-config`.
  - Create a symlink in the root of `mbc-user-unsubscribe` to wherever the `mb-secure-config.inc` file is.

#### Start the Consumer
- Install dependencies: `composer install`
- If applicable, set the environment variable `DS_USER_API_HOST` and `DS_USER_API_PORT` to specify where the user API is.
- Run the consumer: `php mbc-user-unsubscribe.php`


[![Bitdeli Badge](https://d2weczhvl823v0.cloudfront.net/DoSomething/mbc-user-unsubscribe/trend.png)](https://bitdeli.com/free "Bitdeli Badge")

