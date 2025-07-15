<div align="center">
    <a href="https://assegaiphp.com/" target="blank"><img src="https://assegaiphp.com/images/logos/logo-cropped.png" width="200" alt="Assegai Logo"></a>
</div>

<p align="center">A progressive PHP framework for building efficient and scalable web applications.</p>


# AssegaiPHP RabbitMQ Queue Integration

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![AssegaiPHP](https://img.shields.io/badge/built%20for-AssegaiPHP-forestgreen)](https://github.com/assegaiphp/framework)

This package provides **RabbitMQ queue support** for the [AssegaiPHP](https://assegaiphp.com/) framework. It enables asynchronous job handling using AMQP through PhpAmqpLib.

---

## 📦 Installation

Install via Composer:

```bash
composer require assegaiphp/rabbitmq
````

Or use the Assegai CLI:

```bash
assegai add rabbitmq
```

---

## ⚙️ Configuration

Update your application's `config/queues.php` file to register the RabbitMQ driver and define your connections:

```php
<?php

return [
  'drivers' => [
    'rabbitmq' => 'Assegai\\RabbitMQ\\RabbitMQQueue'
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
use Assegai\Core\Queues\Attributes\InjectQueue;
use Assegai\Core\Queues\Interfaces\QueueInterface;

readonly class NotificationsService
{
  public function __construct(
    #[InjectQueue('rabbitmq.notifications')] private QueueInterface $queue
  ) {}

  public function send(array $payload): void
  {
    $this->queue->add($payload);
  }
}
```

---

### Consuming Jobs

Define a consumer class with the `#[Processor]` attribute:

```php
use Assegai\Core\Queues\Attributes\Processor;
use Assegai\Core\Queues\WorkerHost;
use Assegai\Core\Queues\QueueProcessResult;
use Assegai\Core\Queues\Interfaces\QueueProcessResultInterface;

#[Processor('rabbitmq.notifications')]
class NotificationsConsumer extends WorkerHost
{
  public function process(callable $callback): QueueProcessResultInterface
  {
    $job = $callback();
    $data = $job->data;

    echo "Processing notification: {$data->message}" . PHP_EOL;

    return new QueueProcessResult(data: ['status' => 'done'], job: $job);
  }
}
```

> ⚠️ Do **not** use `#[Injectable]` on consumers. The `process()` method must accept a `callable` and return a `QueueProcessResultInterface`.

---

### Running the Worker

If you have the Assegai CLI installed simply run the queue worker with:

```bash
assegai queue:work
```

This will automatically load and execute all consumer classes registered with the `#[Processor]` attribute.

You can also run a specific consumer directly:

```bash
assegai queue:work --consumer=NotificationsConsumer
```

This will start the worker for the `NotificationsConsumer` class, processing jobs from the `rabbitmq.notifications` queue.

For more information on running workers, refer to the [AssegaiPHP documentation](https://assegaiphp.com/guide/techniques/queues).

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
