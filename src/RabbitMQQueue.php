<?php

namespace Assegaiphp\Rabbitmq;

use Assegai\Common\Exceptions\QueueException;
use Assegai\Common\Interfaces\Queues\QueueInterface;
use Assegai\Common\Interfaces\Queues\QueueProcessResultInterface;
use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class RabbitMQQueue
 *
 * Represents a RabbitMQ queue implementation.
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
   * @param bool $noAcknowledgement Indicates whether messages should be acknowledged automatically.
   * @param bool $noWait Indicates whether the consumer should not wait for a response from the server.
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
    protected bool $noAcknowledgement = true,
    protected bool $noWait = false,
  )
  {
    try {
      $this->logger = new ConsoleLogger(new ConsoleOutput());
      $this->connection = new AMQPStreamConnection(
        $this->host,
        $this->port ?? self::DEFAULT_PORT,
        $this->username,
        $this->password,
        $this->vhost ?? '/'
      );

      $this->connection->channel();
      $this->channel = $this->connection->channel();
      $this->channel->queue_declare($this->name, $this->passive, $this->durable, $this->exclusive, $this->autoDelete);
    } catch (Exception $exception) {
      throw new QueueException($exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * RabbitMQQueue destructor.
   *
   * Closes the channel and connection when the object is destroyed.
   * @throws Exception
   */
  public function __destruct()
  {
    $this->channel->close();
    $this->connection->close();
  }

  /**
   * @inheritDoc
   */
  public function process(callable $callback): QueueProcessResultInterface
  {
    $this->channel->basic_consume($this->name, $this->consumerTag, $this->noLocal, $this->noAcknowledgement, $this->exclusive, $this->noWait, $callback);

    try {
      $this->channel->consume();
      $this->totalJobs--;
      $result = new RabbitMQQueueProcessResult(
        ['channelId' => $this->channel->getChannelId()],
      );
    } catch (Exception $exception) {
      $result = new RabbitMQQueueProcessResult(
        errors: [new QueueException("Queue processing failed!", $exception->getCode(), $exception)]
      );
    }

    return $result;
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
    if (isset($options)) {
      if ($options['debug'] ?? $options->debug ?? false) {
        $this->logger->debug("Adding job to queue '$this->name': " . json_encode($job));
      }
    }

    $message = new AMQPMessage(json_encode($job) ?: throw new QueueException('Failed to convert Job to JSON string.'), $messageProperties);
    $this->channel->basic_publish($message, $this->exchangeName, $this->name);
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
    $name ??= $config['name'] ?? throw new QueueException('Queue name is required.');
    if (!is_string($name)) {
      throw new QueueException('Queue name must be a string.');
    }

    return new self(
      $name,
      $config['host'] ?? null,
      $config['port'] ?? RabbitMQQueue::DEFAULT_PORT,
      $config['username'] ?? null,
      $config['password'] ?? null,
      $config['vhost'] ?? null,
      $config['passive'] ?? false,
      $config['durable'] ?? true,
      $config['exclusive'] ?? false,
      $config['auto_delete'] ?? false
    );
  }
}