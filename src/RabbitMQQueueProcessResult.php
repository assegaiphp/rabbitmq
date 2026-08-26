<?php

namespace Assegai\Rabbitmq;

use Assegai\Common\Queues\QueueProcessResult;

/**
 * Class RabbitMQQueueProcessResult
 *
 * Represents the result of processing a job in a RabbitMQ queue.
 * Implements the QueueProcessResultInterface.
 * @template T of object
 * @extends QueueProcessResult<T>
 */
class RabbitMQQueueProcessResult extends QueueProcessResult
{
}
