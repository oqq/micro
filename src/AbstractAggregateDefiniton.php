<?php
/**
 * This file is part of the prooph/micro.
 * (c) 2017-2017 prooph software GmbH <contact@prooph.de>
 * (c) 2017-2017 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\Micro;

use Iterator;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\Metadata\MetadataEnricher;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use RuntimeException;

abstract class AbstractAggregateDefiniton implements AggregateDefiniton
{
    public function identifierName(): string
    {
        return 'id';
    }

    public function versionName(): string
    {
        return 'version';
    }

    public function extractAggregateId(Message $message): string
    {
        $idKey = $this->identifierName();

        $payload = $message->payload();

        if (! array_key_exists($idKey, $payload)) {
            throw new RuntimeException(sprintf(
                'Missing aggregate id key "%s" in payload of message %s. Payload was %s',
                $idKey,
                $message->messageName(),
                json_encode($payload)
            ));
        }

        return $payload[$idKey];
    }

    public function extractAggregateVersion(array $state): int
    {
        $versionKey = $this->versionName();

        if (! array_key_exists($versionKey, $state)) {
            throw new RuntimeException(sprintf(
                'Missing aggregate version key "%s" in state. State was %s',
                $versionKey,
                json_encode($state)
            ));
        }

        return $state[$versionKey];
    }

    public function metadataMatcher(string $aggregateId, int $aggregateVersion): ?MetadataMatcher
    {
        return (new MetadataMatcher())
            ->withMetadataMatch('_aggregate_id', Operator::EQUALS(), $aggregateId)

            // this is only required when using a single stream for all aggregates
            ->withMetadataMatch('_aggregate_type', Operator::EQUALS(), $this->aggregateType())

            // this is only required when using one stream per aggregate type
            ->withMetadataMatch('_aggregate_version', Operator::GREATER_THAN_EQUALS(), $aggregateVersion);
    }

    public function metadataEnricher(string $aggregateId, int $aggregateVersion): ?MetadataEnricher
    {
        return new class($aggregateId, $this->aggregateType(), $aggregateVersion) implements MetadataEnricher {
            private $aggregateId;
            private $aggregateType;
            private $aggregateVersion;

            public function __construct(string $aggregateId, string $aggregateType, int $aggregateVersion)
            {
                $this->aggregateId = $aggregateId;
                $this->aggregateType = $aggregateType;
                $this->aggregateVersion = $aggregateVersion;
            }

            public function enrich(Message $message): Message
            {
                $message = $message->withAddedMetadata('_aggregate_id', $this->aggregateId);

                // this is only required when using a single stream for all aggregates
                $message = $message->withAddedMetadata('_aggregate_type', $this->aggregateType);

                // this is only required when using one stream per aggregate type
                $message = $message->withAddedMetadata('_aggregate_version', $this->aggregateVersion);

                return $message;
            }
        };
    }

    public function reconstituteState(array $state, Iterator $events): array
    {
        return $this->apply($state, ...$events);
    }
}
