<div align="center">
    <a href="https://assegaiphp.com/" target="blank"><img src="https://assegaiphp.com/images/logos/logo-cropped.png" width="200" alt="Assegai Logo"></a>
</div>

<p align="center">
  <a href="https://github.com/assegaiphp/rabbitmq/releases"><img alt="Latest release" src="https://img.shields.io/github/v/release/assegaiphp/rabbitmq?display_name=tag&sort=semver&style=flat-square"></a>
  <a href="https://github.com/assegaiphp/rabbitmq/actions/workflows/php.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/assegaiphp/rabbitmq/php.yml?branch=main&label=tests&style=flat-square"></a>
  <img alt="PHP 8.3+" src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white">
  <a href="https://github.com/assegaiphp/rabbitmq/blob/main/LICENSE"><img alt="License" src="https://img.shields.io/github/license/assegaiphp/rabbitmq?style=flat-square"></a>
  <img alt="Status active" src="https://img.shields.io/badge/status-active-10b981?style=flat-square">
</p>

<p align="center">RabbitMQ queue driver for AssegaiPHP applications.</p>

# AssegaiPHP RabbitMQ Queue Integration

This package provides **RabbitMQ queue support** for the [AssegaiPHP](https://assegaiphp.com/) framework. It enables asynchronous job handling using AMQP through PhpAmqpLib.

---

## 📦 Installation

Install via Composer:

```bash
composer require assegaiphp/rabbitmq
```

### RabbitMQ extension requirement

This package currently requires the PHP `amqp` extension.

If Composer stops with an error like this:

```text
Root composer.json requires PHP extension ext-amqp * but it is missing from your system.
```

the problem is usually your local PHP CLI setup, not RabbitMQ itself.

Check the PHP environment Composer is using:

```bash
php --ini
php -m | grep amqp
```

If `amqp` is missing, install or enable it for the same PHP version that runs Composer.

On Debian or Ubuntu, that is often:

```bash
sudo apt install php-amqp
```

or a version-specific package such as:

```bash
sudo apt install php8.5-amqp
```

If your distribution does not provide a package yet, `pecl` is often the fallback:

```bash
sudo pecl install amqp
```

After that, confirm the extension is loaded with `php -m | grep amqp` and rerun Composer.

Avoid installing with `--ignore-platform-req=ext-amqp` for normal development. That bypasses Composer's check, but it does not make the extension available at runtime.

---

## ⚙️ Configuration

Update your application's `config/queues.php` file to register the RabbitMQ driver and define your connections:

```php
<?php

use Assegai\Rabbitmq\RabbitMQQueue;

return [
  'drivers' => [
    'rabbitmq' => RabbitMQQueue::class,
  ],
  'connections' => [
    'rabbitmq' => [
      'notifications' => [
        'host' => 'localhost',
        'port' => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        'exchange_name' => 'notifications',
        'routing_key' => 'notifications',
        'passive' => false,
        'durable' => true,
        'exclusive' => false,
        'auto_delete' => false,
      ],
    ],
  ],
];
```

> 📝 **Note**:
>
> * The `drivers` key maps queue driver names (like `'rabbitmq'`) to their fully qualified class names.
> * The `connections` key defines queue configurations by driver and queue name (e.g., `'rabbitmq.notifications'`).

---

## ✨ Usage

### Producing Jobs

Inject the queue connection using the `#[InjectQueue]` attribute:

```php
use Assegai\Common\Interfaces\Queues\QueueInterface;
use Assegai\Core\Attributes\Injectable;
use Assegai\Core\Queues\Attributes\InjectQueue;

#[Injectable]
readonly class NotificationsService
{
  public function __construct(
    #[InjectQueue('rabbitmq.notifications')] private QueueInterface $queue
  ) {
  }

  public function send(object $payload): void
  {
    $this->queue->add($payload);
  }
}
```

---

### Consuming Jobs

Define an injectable processor class for the queue:

```php
use Assegai\Core\Attributes\Injectable;
use Assegai\Core\Queues\Attributes\QueueProcessor;

#[Injectable]
#[QueueProcessor('rabbitmq.notifications')]
final class NotificationsProcessor
{
  public function process(object $job): void
  {
    // Handle the job here.
    // For example: send an email, call another service, or write to the database.
  }
}
```

Register that processor in your module's provider list so the CLI can discover it.

If you want a starter file instead of writing the class from scratch, you can scaffold one with:

```bash
assegai g qp notifications --queue=rabbitmq.notifications
```

If you already know the job class you want to handle, you can type the generated `process(...)` method too:

```bash
assegai g qp notifications --queue=rabbitmq.notifications --job=Jobs/NotificationJob
```

If the feature already has a local `Jobs` folder, you can also use a bare job name:

```bash
assegai g qp notifications --queue=rabbitmq.notifications --job=notification-job
```

---

### Running the Worker

Use the Assegai queue commands to discover and run processors:

```bash
assegai queue:list
assegai queue:work rabbitmq.notifications
```

If you want to process one job and exit, use:

```bash
assegai queue:work rabbitmq.notifications --once
```

If more than one processor is registered for the same queue, pass `--processor` to pick one explicitly.

For more information on running workers, refer to the [AssegaiPHP queue guide](https://assegaiphp.com/guide/advanced-topics/queues-and-background-jobs).

---

## 🧪 Testing

You can trigger jobs via your API or CLI and observe processing output in the worker terminal.

---

## 📚 Resources

* [RabbitMQ Documentation](https://www.rabbitmq.com/documentation.html)
* [PhpAmqpLib GitHub](https://github.com/php-amqplib/php-amqplib)
* [AssegaiPHP Framework](https://github.com/assegaiphp/framework)

---
## Support

Assegai is an MIT-licensed open source project. It can grow thanks to sponsors and support by the amazing backers. If you'd like to join them, please [read more here](https://assegaiphp.com/support).

## Stay in touch

* Author - [Andrew Masiye](https://twitter.com/feenix11)
* Website - [https://assegaiphp.com](https://assegaiphp.com/)
* Twitter - [@assegaiphp](https://twitter.com/assegaiphp)

## License

Assegai is [MIT licensed](LICENSE).
