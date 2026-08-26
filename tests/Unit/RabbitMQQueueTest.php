<?php

namespace Tests\Unit;

use Assegai\Common\Exceptions\QueueException;
use Assegai\Common\Interfaces\Queues\QueueJobCodecInterface;
use Assegai\Common\Queues\JsonQueueJobCodec;
use Assegai\Rabbitmq\RabbitMQQueue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
    self::assertFalse($queue->captured['noAcknowledgement']);
    self::assertTrue($queue->captured['requeueOnFailure']);
  }

  public function testCreateForwardsDeliveryAndPublishingConfiguration(): void
  {
    $codec = new JsonQueueJobCodec();
    $queue = InspectableRabbitMQQueue::create([
      'name' => 'emails',
      'exchange_name' => 'domain-events',
      'consumer_tag' => 'email-worker',
      'no_local' => true,
      'no_acknowledgement' => true,
      'no_wait' => true,
      'job_codec' => $codec,
      'requeue_on_failure' => false,
      'routing_key' => 'emails.send',
      'exchange_type' => 'topic',
      'exchange_durable' => false,
      'exchange_auto_delete' => true,
    ]);

    self::assertSame('domain-events', $queue->captured['exchangeName']);
    self::assertSame('email-worker', $queue->captured['consumerTag']);
    self::assertTrue($queue->captured['noLocal']);
    self::assertTrue($queue->captured['noAcknowledgement']);
    self::assertTrue($queue->captured['noWait']);
    self::assertSame($codec, $queue->captured['jobCodec']);
    self::assertFalse($queue->captured['requeueOnFailure']);
    self::assertSame('emails.send', $queue->captured['routingKey']);
    self::assertSame('topic', $queue->captured['exchangeType']);
    self::assertFalse($queue->captured['exchangeDurable']);
    self::assertTrue($queue->captured['exchangeAutoDelete']);
  }

  public function testAddPublishesPersistentJsonAndIncrementsTheJobCount(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_publish'])->getMock();

    $channel->expects($this->once())
      ->method('basic_publish')
      ->with(
        $this->callback(function (AMQPMessage $message): bool {
          $payload = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

          return $payload['_assegai_queue']['version'] === JsonQueueJobCodec::VERSION
            && $payload['_assegai_queue']['job'] === RabbitMQTestJob::class
            && $payload['payload']['task'] === 'send-email'
            && $message->get('content_type') === 'application/json'
            && $message->get('delivery_mode') === AMQPMessage::DELIVERY_MODE_PERSISTENT;
        }),
        'domain-events',
        'emails.send'
      );

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel, exchangeName: 'domain-events', routingKey: 'emails.send');

    $queue->add(new RabbitMQTestJob('send-email'));

    self::assertSame(1, $queue->getTotalJobs());
  }

  public function testProcessHydratesOneJobAndAcknowledgesAfterSuccess(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_get'])->getMock();
    $message = $this->getMockBuilder(AMQPMessage::class)->onlyMethods(['getBody', 'ack', 'nack'])->getMock();

    $message->method('getBody')->willReturn('{"task":"send-email"}');
    $message->expects($this->once())->method('ack');
    $message->expects($this->never())->method('nack');
    $channel->expects($this->once())->method('basic_get')->with('emails', false)->willReturn($message);

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel, totalJobs: 1);

    $result = $queue->process(static fn (RabbitMQTestJob $job): string => strtoupper($job->task));

    self::assertTrue($result->isOk());
    self::assertSame('SEND-EMAIL', $result->getData());
    self::assertInstanceOf(RabbitMQTestJob::class, $result->getJob());
    self::assertSame('send-email', $result->getJob()?->task);
    self::assertSame(0, $queue->getTotalJobs());
  }

  public function testProcessReturnsAnEmptyResultWhenNoMessageIsAvailable(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_get'])->getMock();
    $channel->expects($this->once())->method('basic_get')->with('emails', false)->willReturn(null);

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel);

    $result = $queue->process(static fn (RabbitMQTestJob $job): null => null);

    self::assertTrue($result->isOk());
    self::assertNull($result->getJob());
  }

  public function testProcessNacksAndRequeuesEveryThrowableFromTheProcessor(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_get'])->getMock();
    $message = $this->getMockBuilder(AMQPMessage::class)->onlyMethods(['getBody', 'ack', 'nack'])->getMock();

    $message->method('getBody')->willReturn('{"task":"send-email"}');
    $message->expects($this->never())->method('ack');
    $message->expects($this->once())->method('nack')->with(true);
    $channel->method('basic_get')->willReturn($message);

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel, totalJobs: 1);

    $result = $queue->process(static function (RabbitMQTestJob $job): never {
      throw new \TypeError('processor contract failed');
    });

    self::assertTrue($result->isError());
    self::assertInstanceOf(QueueException::class, $result->getNextError());
    self::assertInstanceOf(\TypeError::class, $result->getNextError()?->getPrevious());
    self::assertInstanceOf(RabbitMQTestJob::class, $result->getJob());
    self::assertSame(1, $queue->getTotalJobs());
  }

  public function testProcessDecrementsTheJobCountWhenAFailedDeliveryIsDiscarded(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_get'])->getMock();
    $message = $this->getMockBuilder(AMQPMessage::class)->onlyMethods(['getBody', 'ack', 'nack'])->getMock();

    $message->method('getBody')->willReturn('{invalid-json');
    $message->expects($this->never())->method('ack');
    $message->expects($this->once())->method('nack')->with(false);
    $channel->method('basic_get')->willReturn($message);

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel, totalJobs: 1, requeueOnFailure: false);

    $result = $queue->process(static fn (RabbitMQTestJob $job): null => null);

    self::assertTrue($result->isError());
    self::assertSame(0, $queue->getTotalJobs());
  }

  public function testProcessDecrementsTheJobCountWhenAutoAcknowledgementPrecedesFailure(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_get'])->getMock();
    $message = $this->getMockBuilder(AMQPMessage::class)->onlyMethods(['getBody', 'ack', 'nack'])->getMock();

    $message->method('getBody')->willReturn('{invalid-json');
    $message->expects($this->never())->method('ack');
    $message->expects($this->never())->method('nack');
    $channel->expects($this->once())->method('basic_get')->with('emails', true)->willReturn($message);

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel, totalJobs: 1, noAcknowledgement: true);

    $result = $queue->process(static fn (RabbitMQTestJob $job): null => null);

    self::assertTrue($result->isError());
    self::assertSame(0, $queue->getTotalJobs());
  }

  public function testProcessNacksMalformedDeliveriesBeforeCallingTheProcessor(): void
  {
    $connection = $this->getMockBuilder(AMQPStreamConnection::class)->disableOriginalConstructor()->onlyMethods(['close'])->getMock();
    $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->onlyMethods(['basic_get'])->getMock();
    $message = $this->getMockBuilder(AMQPMessage::class)->onlyMethods(['getBody', 'ack', 'nack'])->getMock();

    $message->method('getBody')->willReturn('{invalid-json');
    $message->expects($this->never())->method('ack');
    $message->expects($this->once())->method('nack')->with(true);
    $channel->method('basic_get')->willReturn($message);

    $queue = new TestRabbitMQQueue();
    $queue->prime('emails', $connection, $channel);
    $processorCalled = false;

    $result = $queue->process(static function (RabbitMQTestJob $job) use (&$processorCalled): void {
      $processorCalled = true;
    });

    self::assertFalse($processorCalled);
    self::assertTrue($result->isError());
    self::assertInstanceOf(QueueException::class, $result->getNextError());
    self::assertNull($result->getJob());
  }
}

final readonly class RabbitMQTestJob
{
  public function __construct(public string $task)
  {
  }
}

final class TestRabbitMQQueue extends RabbitMQQueue
{
  public function __construct()
  {
  }

  public function prime(
    string $name,
    AMQPStreamConnection $connection,
    AMQPChannel $channel,
    int $totalJobs = 0,
    string $exchangeName = '',
    ?string $routingKey = null,
    bool $noAcknowledgement = false,
    bool $requeueOnFailure = true,
    ?QueueJobCodecInterface $jobCodec = null,
  ): void {
    $this->name = $name;
    $this->connection = $connection;
    $this->channel = $channel;
    $this->logger = new NullLogger();
    $this->exchangeName = $exchangeName;
    $this->consumerTag = '';
    $this->noLocal = false;
    $this->noAcknowledgement = $noAcknowledgement;
    $this->noWait = false;
    $this->exclusive = false;
    $this->jobCodec = $jobCodec ?? new JsonQueueJobCodec();
    $this->requeueOnFailure = $requeueOnFailure;
    $this->routingKey = $routingKey;
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
    bool $noAcknowledgement = false,
    bool $noWait = false,
    ?QueueJobCodecInterface $jobCodec = null,
    bool $requeueOnFailure = true,
    ?string $routingKey = null,
    string $exchangeType = 'direct',
    bool $exchangeDurable = true,
    bool $exchangeAutoDelete = false,
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
      'jobCodec' => $jobCodec,
      'requeueOnFailure' => $requeueOnFailure,
      'routingKey' => $routingKey,
      'exchangeType' => $exchangeType,
      'exchangeDurable' => $exchangeDurable,
      'exchangeAutoDelete' => $exchangeAutoDelete,
    ];

    $this->name = $name;
  }

  public function __destruct()
  {
  }
}
