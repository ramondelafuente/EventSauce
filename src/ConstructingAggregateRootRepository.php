<?php

declare(strict_types=1);

namespace EventSauce\EventSourcing;

use function assert;
use function count;
use Generator;

final class ConstructingAggregateRootRepository implements AggregateRootRepository
{
    /**
     * @var string
     */
    private $aggregateRootClassName;

    /**
     * @var MessageRepository
     */
    private $repository;

    /**
     * @var MessageDecorator
     */
    private $decorator;

    /**
     * @var MessageDispatcher
     */
    private $dispatcher;

    public function __construct(
        string $aggregateRootClassName,
        MessageRepository $messageRepository,
        MessageDispatcher $dispatcher = null,
        MessageDecorator $decorator = null
    ) {
        $this->aggregateRootClassName = $aggregateRootClassName;
        $this->repository = $messageRepository;
        $this->dispatcher = $dispatcher ?: new SynchronousMessageDispatcher();
        $this->decorator = $decorator ?: new DefaultHeadersDecorator();
    }

    public function retrieve(AggregateRootId $aggregateRootId): object
    {
        /** @var AggregateRoot $className */
        $className = $this->aggregateRootClassName;
        $events = $this->retrieveAllEvents($aggregateRootId);

        return $className::reconstituteFromEvents($aggregateRootId, $events);
    }

    private function retrieveAllEvents(AggregateRootId $aggregateRootId): Generator
    {
        /** @var Message $message */
        foreach ($this->repository->retrieveAll($aggregateRootId) as $message) {
            yield $message->event();
        }
    }

    public function persist(object $aggregateRoot)
    {
        assert($aggregateRoot instanceof AggregateRoot, 'Expected $aggregateRoot to be an instance of ' . AggregateRoot::class);

        $this->persistEvents(
            $aggregateRoot->aggregateRootId(),
            $aggregateRoot->aggregateRootVersion(),
            ...$aggregateRoot->releaseEvents()
        );
    }

    public function persistEvents(AggregateRootId $aggregateRootId, int $aggregateRootVersion, object ...$events)
    {
        // decrease the aggregate root version by the number of raised events
        // so the version of each message represents the version at the time
        // of recording.
        $aggregateRootVersion = $aggregateRootVersion - count($events);
        $metadata = [Header::AGGREGATE_ROOT_ID => $aggregateRootId];
        $messages = array_map(function (object $event) use ($metadata, &$aggregateRootVersion) {
            return $this->decorator->decorate(new Message(
                $event,
                $metadata + [Header::AGGREGATE_ROOT_VERSION => ++$aggregateRootVersion]
            ));
        }, $events);

        $this->repository->persist(...$messages);
        $this->dispatcher->dispatch(...$messages);
    }
}
