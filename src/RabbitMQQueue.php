<?php

namespace Assegai\Rabbitmq;

use Assegai\Common\Exceptions\QueueException;
use Assegai\Common\Interfaces\Queues\QueueJobCodecInterface;
use Assegai\Common\Interfaces\Queues\QueueInterface;
use Assegai\Common\Interfaces\Queues\QueueProcessResultInterface;
use Assegai\Common\Queues\JsonQueueJobCodec;
use Assegai\Common\Queues\QueueJobTypeResolver;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Class RabbitMQQueue
 *
 * Represents a RabbitMQ queue implementation.
 * @implements QueueInterface<object>
 */
class RabbitMQQueue implements QueueInterface
{
  /**
   * @var int The default port for RabbitMQ.
   */
  public const int DEFAULT_PORT = 5672;
  /**
   * @var AMQPStreamConnection The connection to the RabbitMQ server.
   */
  protected AMQPStreamConnection $connection;
  /**
   * @var AMQPChannel The channel for communication with the RabbitMQ server.
   */
  protected AMQPChannel $channel;
  /**
   * @var int The total number of jobs in the queue.
   */
  protected int $totalJobs = 0;
  /**
   * @var LoggerInterface The logger for logging messages.
   */
  protected LoggerInterface $logger;

  /**
   * RabbitMQQueue constructor.
   *
   * @param string $name The name of the queue.
   * @param string|null $host The host of the RabbitMQ server.
   * @param int|null $port The port of the RabbitMQ server.
   * @param string|null $username The username for RabbitMQ authentication.
   * @param string|null $password The password for RabbitMQ authentication.
   * @param string|null $vhost The virtual host for RabbitMQ.
   * @param bool $passive Indicates whether the queue should be passive.
   * @param bool $durable Indicates whether the queue should be durable.
   * @param bool $exclusive Indicates whether the queue should be exclusive to the connection.
   * @param bool $autoDelete Indicates whether the queue should be automatically deleted when no longer in use.
   * @param string $exchangeName The name of the exchange to which messages will be published.
   * @param string $consumerTag The consumer tag for the queue consumer.
   * @param bool $noLocal Indicates whether the consumer should not receive messages published by itself.
   * @param bool $noAcknowledgement Indicates whether RabbitMQ should consider deliveries acknowledged immediately.
   * @param bool $noWait Indicates whether the consumer should not wait for a response from the server.
   * @param QueueJobCodecInterface|null $jobCodec Codec used to serialize and hydrate domain jobs.
   * @param bool $requeueOnFailure Whether failed deliveries should be returned to the queue.
   * @param string|null $routingKey Routing key used when publishing; defaults to the queue name.
   * @param string $exchangeType RabbitMQ exchange type used when exchangeName is configured.
   * @param bool $exchangeDurable Whether the configured exchange survives broker restarts.
   * @param bool $exchangeAutoDelete Whether the configured exchange is deleted after its last binding disappears.
   * @throws QueueException
   */
  public function __construct(
    protected string $name,
    protected ?string $host = null,
    protected ?int $port = null,
    protected ?string $username = null,
    protected ?string $password = null,
    protected ?string $vhost = null,
    protected bool $passive = false,
    protected bool $durable = true,
    protected bool $exclusive = false,
    protected bool $autoDelete = false,
    protected string $exchangeName = '',
    protected string $consumerTag = '',
    protected bool $noLocal = false,
    protected bool $noAcknowledgement = false,
    protected bool $noWait = false,
    ?QueueJobCodecInterface $jobCodec = null,
    protected bool $requeueOnFailure = true,
    protected ?string $routingKey = null,
    protected string $exchangeType = 'direct',
    protected bool $exchangeDurable = true,
    protected bool $exchangeAutoDelete = false,
  )
  {
    $this->jobCodec = $jobCodec ?? new JsonQueueJobCodec();

    try {
      $this->logger = new ConsoleLogger(new ConsoleOutput());
      $this->connection = new AMQPStreamConnection(
        $this->host,
        $this->port ?? self::DEFAULT_PORT,
        $this->username,
        $this->password,
        $this->vhost ?? '/'
      );

      $this->channel = $this->connection->channel();
      $this->channel->queue_declare($this->name, $this->passive, $this->durable, $this->exclusive, $this->autoDelete);

      if ($this->exchangeName !== '') {
        $this->channel->exchange_declare(
          $this->exchangeName,
          $this->exchangeType,
          false,
          $this->exchangeDurable,
          $this->exchangeAutoDelete,
        );
        $this->channel->queue_bind($this->name, $this->exchangeName, $this->routingKey ?? $this->name);
      }
    } catch (Throwable $throwable) {
      throw $this->queueException('Failed to connect to RabbitMQ.', $throwable);
    }
  }

  protected QueueJobCodecInterface $jobCodec;

  /**
   * RabbitMQQueue destructor.
   *
   * Closes the channel and connection when the object is destroyed.
   * @throws Throwable
   */
  public function __destruct()
  {
    if (isset($this->channel) && $this->channel->is_open()) {
      $this->channel->close();
    }

    if (isset($this->connection) && $this->connection->isConnected()) {
      $this->connection->close();
    }
  }

  /**
   * @inheritDoc
   */
  public function process(callable $callback): QueueProcessResultInterface
  {
    $message = null;
    $job = null;
    $callbackSucceeded = false;

    try {
      $message = $this->channel->basic_get($this->name, $this->noAcknowledgement);

      if (!$message instanceof AMQPMessage) {
        return new RabbitMQQueueProcessResult();
      }

      if ($this->noAcknowledgement) {
        $this->recordDeliveryRemoved();
      }

      $job = $this->jobCodec->decode(
        $message->getBody(),
        QueueJobTypeResolver::fromCallback($callback),
      );
      $data = $callback($job);
      $callbackSucceeded = true;

      if (!$this->noAcknowledgement) {
        $message->ack();
        $this->recordDeliveryRemoved();
      }

      return new RabbitMQQueueProcessResult(
        data: $data,
        job: $job,
      );
    } catch (Throwable $throwable) {
      $errors = [$this->queueException('Queue processing failed.', $throwable)];

      if ($message instanceof AMQPMessage && !$this->noAcknowledgement && !$callbackSucceeded) {
        try {
          $message->nack($this->requeueOnFailure);

          if (!$this->requeueOnFailure) {
            $this->recordDeliveryRemoved();
          }
        } catch (Throwable $settlementError) {
          $errors[] = $this->queueException('Failed to reject RabbitMQ delivery.', $settlementError);
        }
      }

      return new RabbitMQQueueProcessResult(errors: $errors, job: $job);
    }
  }

  /**
   * @inheritDoc
   * @throws QueueException
   */
  public function add(object $job, object|array|null $options = null): void
  {
    $messageProperties = [
      'content_type' => 'application/json',
      'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // Make message persistent
    ];
    if ($this->option($options, 'debug', false)) {
      $this->logger->debug("Adding job to queue '$this->name': " . json_encode($job));
    }

    $message = new AMQPMessage($this->jobCodec->encode($job), $messageProperties);
    $this->channel->basic_publish($message, $this->exchangeName, $this->routingKey ?? $this->name);
    $this->totalJobs++;
  }

  /**
   * @inheritDoc
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * @inheritDoc
   */
  public function getTotalJobs(): int
  {
    return $this->totalJobs;
  }

  /**
   * Creates a new instance of the RabbitMQQueue with the given configuration.
   *
   * @param array<string, mixed> $config Configuration options for the queue.
   * @return static A new instance of the RabbitMQQueue.
   * @throws QueueException
   */
  public static function create(array $config): self
  {
    $name = $config['name'] ?? throw new QueueException('Queue name is required.');
    if (!is_string($name)) {
      throw new QueueException('Queue name must be a string.');
    }

    $jobCodec = $config['job_codec'] ?? null;

    if ($jobCodec !== null && !$jobCodec instanceof QueueJobCodecInterface) {
      throw new QueueException('RabbitMQ job_codec must implement QueueJobCodecInterface.');
    }

    return new static(
      $name,
      $config['host'] ?? null,
      $config['port'] ?? RabbitMQQueue::DEFAULT_PORT,
      $config['username'] ?? null,
      $config['password'] ?? null,
      $config['vhost'] ?? null,
      $config['passive'] ?? false,
      $config['durable'] ?? true,
      $config['exclusive'] ?? false,
      $config['auto_delete'] ?? false,
      $config['exchange_name'] ?? '',
      $config['consumer_tag'] ?? '',
      $config['no_local'] ?? false,
      $config['no_acknowledgement'] ?? $config['no_ack'] ?? false,
      $config['no_wait'] ?? false,
      $jobCodec,
      $config['requeue_on_failure'] ?? true,
      $config['routing_key'] ?? null,
      $config['exchange_type'] ?? 'direct',
      $config['exchange_durable'] ?? true,
      $config['exchange_auto_delete'] ?? false,
    );
  }

  private function queueException(string $message, Throwable $throwable): QueueException
  {
    if ($throwable instanceof QueueException) {
      return $throwable;
    }

    return new QueueException($message . ' ' . $throwable->getMessage(), (int) $throwable->getCode(), $throwable);
  }

  private function recordDeliveryRemoved(): void
  {
    $this->totalJobs = max(0, $this->totalJobs - 1);
  }

  private function option(object|array|null $options, string $name, mixed $default): mixed
  {
    if (is_array($options)) {
      return $options[$name] ?? $default;
    }

    return $options?->{$name} ?? $default;
  }
}
