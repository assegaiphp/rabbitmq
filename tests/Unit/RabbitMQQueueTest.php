<?php

namespace Tests\Unit;

use Assegai\Common\Exceptions\QueueException;
use Assegai\Rabbitmq\RabbitMQQueue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class RabbitMQQueueTest extends TestCase
{
  public function testCreateRequiresAQueueName(): void
  {
    $this->expectException(QueueException::class);
    InspectableRabbitMQQueue::create([]);
  }

  public function testCreateNormalizesDefaults(): void
  {
    $queue = InspectableRabbitMQQueue::create(['name' => 'emails']);

    self::assertSame('emails', $queue->captured['name']);
    self::assertNull($queue->captured['host']);
    self::assertSame(RabbitMQQueue::DEFAULT_PORT, $queue->captured['port']);
    self::assertNull($queue->captured['username']);
    self::assertNull($queue->captured['password']);
    self::assertNull($queue->captured['vhost']);
    self::assertFalse($queue->captured['passive']);
    self::assertTrue($queue->captured['durable']);
    self::assertFalse($queue->captured['exclusive']);
    self::assertFalse($queue->captured['autoDelete']);
  }

  public function testAddPublishesPersistentJsonAndIncrementsTheJobCount(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_publish'])->getMock();

    $channel->expects($this->once())
      ->method('basic_publish')
      ->with(
        $this->callback(function (AMQPMessage $message): bool {
          $payload = json_decode($message->getBody(), true);

          return $payload === ['task' => 'send-email']
            && $message->get('content_type') === 'application/json'
            && $message->get('delivery_mode') === AMQPMessage::DELIVERY_MODE_PERSISTENT;
        }),
        '',
        'emails'
      );

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel);

    $queue->add((object) ['task' => 'send-email']);

    self::assertSame(1, $queue->getTotalJobs());
  }

  public function testProcessReturnsChannelMetadataAfterConsumption(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_consume', 'consume', 'getChannelId'])->getMock();

    $channel->expects($this->once())
      ->method('basic_consume')
      ->with(
        'emails',
        '',
        false,
        true,
        false,
        false,
        $this->callback('is_callable')
      );
    $channel->expects($this->once())->method('consume');
    $channel->expects($this->once())->method('getChannelId')->willReturn(17);

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel, totalJobs: 1);

    $result = $queue->process(static fn (): null => null);

    self::assertTrue($result->isOk());
    self::assertSame(['channelId' => 17], $result->getData());
    self::assertSame(0, $queue->getTotalJobs());
  }

  public function testProcessWrapsConsumerFailures(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_consume', 'consume'])->getMock();

    $channel->expects($this->once())->method('basic_consume');
    $channel->expects($this->once())->method('consume')->willThrowException(new RuntimeException('boom'));

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel, totalJobs: 2);

    $result = $queue->process(static fn (): null => null);

    self::assertTrue($result->isError());
    self::assertInstanceOf(QueueException::class, $result->getNextError());
    self::assertSame(2, $queue->getTotalJobs());
  }
}

final class TestRabbitMQQueue extends RabbitMQQueue
{
  public function __construct()
  {
  }

  public function prime(string $name, AMQPStreamConnection $connection, AMQPChannel $channel, int $totalJobs = 0): void
  {
    $this->name = $name;
    $this->connection = $connection;
    $this->channel = $channel;
    $this->logger = new NullLogger();
    $this->exchangeName = '';
    $this->consumerTag = '';
    $this->noLocal = false;
    $this->noAcknowledgement = true;
    $this->noWait = false;
    $this->exclusive = false;
    $this->totalJobs = $totalJobs;
  }

  public function __destruct()
  {
  }
}

final class InspectableRabbitMQQueue extends RabbitMQQueue
{
  public array $captured = [];

  public function __construct(
    string $name,
    ?string $host = null,
    ?int $port = null,
    ?string $username = null,
    ?string $password = null,
    ?string $vhost = null,
    bool $passive = false,
    bool $durable = true,
    bool $exclusive = false,
    bool $autoDelete = false,
    string $exchangeName = '',
    string $consumerTag = '',
    bool $noLocal = false,
    bool $noAcknowledgement = true,
    bool $noWait = false,
  ) {
    $this->captured = [
      'name' => $name,
      'host' => $host,
      'port' => $port,
      'username' => $username,
      'password' => $password,
      'vhost' => $vhost,
      'passive' => $passive,
      'durable' => $durable,
      'exclusive' => $exclusive,
      'autoDelete' => $autoDelete,
      'exchangeName' => $exchangeName,
      'consumerTag' => $consumerTag,
      'noLocal' => $noLocal,
      'noAcknowledgement' => $noAcknowledgement,
      'noWait' => $noWait,
    ];

    $this->name = $name;
  }

  public function __destruct()
  {
  }
}
