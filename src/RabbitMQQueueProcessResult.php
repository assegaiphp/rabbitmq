<?php

namespace Assegai\Rabbitmq;

use Assegai\Common\Interfaces\Queues\QueueProcessResultInterface;
use Throwable;

/**
 * Class RabbitMQQueueProcessResult
 *
 * Represents the result of processing a job in a RabbitMQ queue.
 * Implements the QueueProcessResultInterface.
 * @template T
 */
class RabbitMQQueueProcessResult implements QueueProcessResultInterface
{
  /**
   * RabbitMQQueueProcessResult constructor.
   *
   * @param mixed $data The result data of processing the job.
   * @param array $errors An array of errors encountered during processing.
   * @param object<T>|null $job The job that was processed, or null if no job was processed.
   */
  public function __construct(
    protected mixed $data = null,
    protected array $errors = [],
    protected ?object $job = null
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function getData(): mixed
  {
    return $this->data;
  }

  /**
   * @inheritDoc
   */
  public function isOk(): bool
  {
    return !$this->isError();
  }

  /**
   * @inheritDoc
   */
  public function isError(): bool
  {
    return !empty($this->errors);
  }

  /**
   * @inheritDoc
   */
  public function getErrors(): array
  {
    return $this->errors;
  }

  /**
   * @inheritDoc
   */
  public function getNextError(): ?Throwable
  {
    return $this->errors[0] ?? null;
  }

  /**
   * @inheritDoc
   */
  public function getJob(): ?object
  {
    return $this->job;
  }
}