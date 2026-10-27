# Message Bundle

Symfony 8 bundle for the messaging infrastructure of microservices: transactional outbox, RabbitMQ connection, retries when consuming, deletion requests with feedback, and logging over AMQP.

## Purpose

Microservices that exchange domain events over RabbitMQ keep needing the same parts:

- an outbox that stores events in the same transaction as the state change;
- a publisher that delivers them reliably and in the right order;
- consumers that retry technical failures and send broken messages to the dead-letter queue;
- logging that never makes a request fail.

The bundle implements these parts once so that no service has to rebuild them.

Principles:

- Fixed rules (number of attempts, waiting times, AMQP properties, table schema) are built in and not configurable. Only what differs per service can be configured.
- The core of a service does not depend on the bundle. The bundle is used only from the infrastructure and interface layers.
- For the event format and the handling of deletion requests, the bundle provides assertions and a contract test, so that every service checks its behaviour against the same rules.

## Features

- **Transactional outbox:** events are written to the table `outbox_entries` in the same database transaction as the state change; a console command delivers them with publisher confirms.
- **Ordered delivery:** per aggregate in the order the events were created; an undelivered entry holds back later entries of the same aggregate.
- **Error handling when publishing:** an unreachable broker does not count as a failed attempt; after 10 rejected attempts an entry gets the status `failed` and can be retried with a command.
- **Retries when consuming:** up to 10 attempts in the same process with fixed waiting times, then rejection without requeue into the dead-letter queue.
- **Deletion requests:** structural validation of incoming requests; structurally invalid requests go to the dead-letter queue without retries.
- **Logging over AMQP:** a Monolog handler that connects lazily, catches every failure and pauses after a failure; plus a processor that keeps sensitive fields out of the logs.
- **Test helpers:** assertions for the event and message format, a contract test for deletion requests, test doubles for the outbox and transports.

## Layer boundary

| Layer of the service | Uses the bundle | Stays in the service |
|---|---|---|
| Domain | no | Domain events, building the `delta`, processed deletion requests and their repository interface |
| Application | no | Outbox interface, transaction interface, handling of a deletion request |
| Infrastructure | yes | Adapter that implements the outbox interface via `OutboxWriter`; transaction, ID generation, repository of processed deletion requests |
| Interface | yes | Event consumer: builds a command from the message and calls the service via `RetryingExecutor` |

## Structure

```
src/
  MessageBundle.php
  DependencyInjection/
    Configuration.php              configuration under the key message
    MessageExtension.php           loads services, prepends framework.messenger and doctrine
  Resources/config/
    services.php                   connection, serializers, retries, logging
    outbox.php                     outbox, only with message.outbox.enabled
  Connection/
    RabbitMqConfig.php             RABBITMQ_* including TLS and heartbeat
    RabbitMqTransportFactory.php   Messenger transport for the DSN rabbitmq-env://
  Message/
    AmqpProperties.php             AMQP properties of every message
    RawMessage.php                 message body as it is sent to RabbitMQ
    BodySerializer.php             body is the payload, no headers
  Persistence/
    UtcDateTimeMsType.php          custom type message_utc_datetime_ms, DATETIME(3) in UTC
  Outbox/
    Entity/OutboxEntry.php         table outbox_entries
    Entity/OutboxStatus.php        pending, failed
    OutboxWriter.php               append() without flush
    ExchangeResolver.php           event type -> exchange
    OutboxEntryStore.php           interface
    DoctrineOutboxEntryStore.php
    OutboxSender.php               interface
    MessengerOutboxSender.php      publisher confirms, failure classification
    BrokerUnavailableException.php
    MessageRejectedException.php
    OutboxPublisher.php
    PublishResult.php
    Command/PublishOutboxCommand.php       <namespace>:publish
    Command/RetryFailedOutboxCommand.php   <namespace>:retry-failed
  Consumer/
    RetryingExecutor.php           retries in the same process
  Deletion/
    DeletionRequest.php            id, ticketId
    DeletionRequestSerializer.php  structural validation
  Logging/
    AmqpExchangeFactoryInterface.php
    AmqpExchangeFactory.php        connection for the log events
    AmqpLogHandler.php             handler for Monolog
    LogEventAmqpHandler.php        native AmqpHandler with routing key and properties (internal)
    SensitiveFieldRedactionProcessor.php
  Testing/
    EventFormatAssertions.php      trait, event format
    AmqpMessageAssertions.php      trait, message format
    DeletionRequestContract.php    trait, contract test for deletion requests
    InMemoryOutboxEntryStore.php
    ScriptedOutboxSender.php
    RecordingMessengerSender.php
tests/
  Unit/                            per component
  Integration/                     Symfony kernel with Doctrine (SQLite), Monolog and in-memory transports
```

## Requirements

- PHP 8.5+, extensions `amqp`, `json`, `openssl`
- Symfony 8.0+ (`framework-bundle`, `messenger`, `amqp-messenger`, `clock`, `console`, `uid`, `doctrine-bridge`)
- Doctrine ORM 3.7+, DBAL 4.5+, DoctrineBundle 3.3+
- Monolog 3.12+, MonologBundle 4.1+

## Installation

Via the VCS repository:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/f-3-li-x/message-bundle.git" }
],
"require": {
    "acat/message-bundle": "^1.0"
}
```

Register the bundle in `config/bundles.php`:

```php
ACAT\MessageBundle\MessageBundle::class => ['all' => true],
```

The table `outbox_entries` is created from the bundle's mapping with `doctrine:migrations:diff` in the service's migrations.

## Configuration

### Environment variables

| Variable | Meaning | Default |
|---|---|---|
| `RABBITMQ_HOST` | RabbitMQ host name | – |
| `RABBITMQ_PORT` | Port | – |
| `RABBITMQ_USER` | User name at the broker | – |
| `RABBITMQ_PASSWORD` | Password | – |
| `RABBITMQ_VHOST` | Virtual host | – |
| `RABBITMQ_SECURE` | `true` for TLS (`amqps`) | – |
| `RABBITMQ_HEARTBEAT` | Heartbeat interval in seconds | `60` |
| `RABBITMQ_RETRY` | Attempts per consumed message, limited to 1–10 | `10` |

### Bundle

`config/packages/message.yaml`:

```yaml
message:
    service: person-service
    outbox:
        central_exchange: domain-events
    consumers:
        deletion_requests:
            queue: '%env(RABBITMQ_DELETION_QUEUE)%'
    logging:
        redact_fields: [firstName, lastName, dateOfBirth]
```

| Option | Default | Meaning |
|---|---|---|
| `service` | – (required) | Name of the service; name of its own exchange, AMQP property `app_id` and part of the routing key of the log events |
| `outbox.enabled` | `true` | Outbox, publisher, commands and the transports for publishing |
| `outbox.central_exchange` | `null` | Central exchange for change events; without it, all events go to the service's exchange |
| `outbox.central_event_types` | `['*.created', '*.updated']` | Event types for the central exchange; `*` stands for exactly one word |
| `outbox.command_namespace` | `outbox` | Namespace of the commands, e.g. `billing:outbox` for `billing:outbox:publish` |
| `consumers.<transport>.queue` | – | Queue of the transport; one entry per queue |
| `consumers.<transport>.serializer` | `DeletionRequestSerializer` | Transport serializer; use your own for other incoming messages |
| `logging.enabled` | `true` | `AmqpLogHandler`; always disabled in the `test` environment |
| `logging.redact_fields` | `[]` | Fields whose values must not reach the log; empty means no redaction processor |

The bundle prepends:

- `framework.messenger`:
  - the default bus without `reject_redelivered_message_middleware`, so that a redelivered message does not end up in the dead-letter queue without being processed;
  - one transport per exchange for publishing, named after the exchange in snake_case (e.g. `person_service`, `domain_events`), type `topic`, `queues: []`, `confirm_timeout: 10`, `auto_setup: false`;
  - one transport per entry under `consumers` with `max_retries: 0` and `auto_setup: false`;
  - `in-memory://` for all transports in the `test` environment.
- `doctrine`: the mapping of `OutboxEntry` and the custom type `message_utc_datetime_ms`.

The bundle does not declare exchanges, queues or bindings (`auto_setup: false`); the broker topology is maintained outside the service.

### Monolog

```yaml
monolog:
    handlers:
        amqp:
            type: service
            id: message.logging.amqp_handler
            channels: ['!doctrine']
```

## Components

### Message format

Every published message carries these AMQP properties:

- `message_id`: UUID v7 of the outbox entry, unchanged on redelivery; a new one per entry for log events
- `type`: the routing key
- `app_id`: name of the service
- `content_type`: `application/json`
- `timestamp`: Unix seconds
- `delivery_mode`: `2`

The body is the payload unchanged, without Symfony Messenger headers. Symfony 8.1 wraps every transport serializer in a `SigningSerializer`; it passes messages through unchanged as long as no signed message types are configured.

### Outbox

- `OutboxEntry` maps the table `outbox_entries`: `id` (`BINARY(16)`, UUID v7), `aggregate_id`, `event_type`, `exchange`, `payload` (`LONGTEXT`), `status`, `attempts`, `created_at` (`DATETIME(3)`), index `idx_outbox_entries_status_created_at`.
- The payload is stored as text. In a `JSON` column, MySQL would reorder the keys; as text, field order and empty objects are preserved.
- `OutboxWriter::append(string $eventType, string $aggregateId, array $payload): OutboxEntry`:
  - encodes the payload with fixed flags (`JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_SLASHES`, `JSON_UNESCAPED_UNICODE`, `JSON_PRESERVE_ZERO_FRACTION`);
  - persists the entry without `flush`, so it is written with the service's transaction;
  - sets `created_at` to the time from the UUID v7;
  - an empty object in the payload must be a `stdClass`, as an empty PHP array would be encoded as `[]`.
- `OutboxPublisher`:
  - reads all entries, ordered by `created_at`, then by `id`;
  - skips all later entries of an `aggregate_id` whose earlier entry failed or has the status `failed`;
  - aborts when RabbitMQ is unreachable, without increasing the attempt counters;
  - increases `attempts` when the broker rejects a message or does not confirm it within 10 seconds, and sets `failed` after the 10th failed attempt with a log entry at level `error`;
  - deletes an entry right after the confirmation; the log context never contains the payload.
- `<namespace>:publish` starts a run, e.g. hourly via cron. `<namespace>:retry-failed [<id>]` sets `failed` back to `pending` and `attempts` to 0, for all entries in one `UPDATE`.

### Consumer

`RetryingExecutor::run(callable $operation, array $context = [])`:

- at most `RABBITMQ_RETRY` attempts;
- waiting times of 1, 2, 4, 8, 8, 8, 8, 8, 8 seconds via `Symfony\Component\Clock\ClockInterface`, 55 seconds in total;
- every failed attempt is logged at level `warning`, together with `$context`;
- before each further attempt `ManagerRegistry::resetManager()`, since Doctrine closes the EntityManager after a failure;
- after the last failed attempt an `UnrecoverableMessageHandlingException`; the message is rejected without requeue and ends up in the dead-letter queue via the queue's dead-letter exchange.

`RABBITMQ_HEARTBEAT` should be at least 60 seconds so that the broker does not close the connection during the retries.

### Deletion requests

A deletion request has the payload `id` and `ticketId`. `DeletionRequestSerializer` rejects structurally invalid requests without retries:

- the payload is empty, not JSON or not a JSON object;
- `id` or `ticketId` is missing, empty, not a string or longer than 255 characters.

Unknown fields are ignored. An `id` that is not a UUID does not count as structurally invalid; it is passed to the service, which answers it as not found.

A service with several request types sets up one entry under `consumers` per queue. All of them deliver `DeletionRequest`; the handlers are told apart with `#[AsMessageHandler(fromTransport: ...)]`.

### Logging

- `AmqpLogHandler` (service ID `message.logging.amqp_handler`) wraps the native `Monolog\Handler\AmqpHandler`:
  - connects only with the first log entry, with a 3-second timeout for connecting and sending;
  - routing key `{level}.<service>`, regardless of the channel; the exchange is the service's own;
  - catches every failure when connecting and sending; if an open connection fails, it reconnects once for the entry, then sends no entries to RabbitMQ for 60 seconds;
  - replaces a connection that was idle for a heartbeat interval;
  - is always disabled in the `test` environment.
- `SensitiveFieldRedactionProcessor` recursively replaces the values of the configured fields in `context` and `extra` with `***REDACTED***`. It is registered for all channels as soon as `logging.redact_fields` lists any fields.

### Event format checked by the test helpers

`EventFormatAssertions` checks this format:

| Event | Fields in this order |
|---|---|
| `<entity>.created` | `id`, `actorId`, `timestamp` |
| `<entity>.updated` | `id`, `actorId`, `timestamp`, `delta` |
| `<entity>.deleted` | `id`, `timestamp`, `ticketId` |
| `<entity>.deletion.failed` | `id`, `timestamp`, `ticketId`, `reason` |
| `<subject>.deletion.processed` | `id`, `timestamp`, `ticketId`, then service-specific fields |

- `timestamp` in UTC per RFC 3339 with milliseconds and `Z`, e.g. `2026-07-21T10:00:00.000Z`.
- An entry in `delta` has `field`, `operation` (`changed`, `added`, `removed`), `oldValue` and `newValue`; for `added`, `oldValue` is `null`, for `removed`, `newValue` is `null`.
- A masked entry (`masked: true`) for a field change has only `field`, `operation` and `masked`.
- Feedback on deletion requests carries no `actorId`.

## Usage in a service

Outbox adapter (infrastructure):

```php
final readonly class DoctrineOutbox implements OutboxInterface
{
    public function __construct(private OutboxWriter $writer)
    {
    }

    public function add(DomainEvent $event): void
    {
        $this->writer->append($event->name(), $event->aggregateId(), $event->payload());
    }
}
```

Event consumer for deletion requests (interface):

```php
#[AsMessageHandler(fromTransport: 'deletion_requests')]
final readonly class DeletionRequestHandler
{
    public function __construct(private RetryingExecutor $retry, private PersonServiceInterface $service)
    {
    }

    public function __invoke(DeletionRequest $request): void
    {
        $this->retry->run(
            fn () => $this->service->delete(new DeletePersonCommand($request->id, $request->ticketId)),
            ['ticketId' => $request->ticketId, 'id' => $request->id],
        );
    }
}
```

Contract test for deletion requests (the service's tests):

```php
final class DeletionRequestTest extends KernelTestCase
{
    use DeletionRequestContract;

    protected function deletionEntityName(): string { return 'person'; }
    protected function createEntityForDeletion(): string { /* create a person, return its id */ }
    protected function handleDeletionRequest(DeletionRequest $request): void { /* call the handler */ }
    protected function publishedDeletionFeedback(): array { /* event name and body per feedback */ }
    protected function assertEntityDeleted(string $id): void { /* check the deletion */ }
}
```

A working example is in `tests/Unit/Testing/DeletionRequestContractTest.php`.

## Development

The tests need PHP 8.5 with the extensions `amqp` and `pdo_sqlite`. Without them locally, run the tests in a Docker image that has both:

```bash
docker run --rm -v "$PWD":/app -w /app --entrypoint composer <php-image-with-amqp> install
```

```bash
docker run --rm -v "$PWD":/app -w /app --entrypoint vendor/bin/phpunit <php-image-with-amqp>
```

```bash
docker run --rm -v "$PWD":/app -w /app --entrypoint vendor/bin/phpstan <php-image-with-amqp> analyse
```

PHPStan runs at level `max` over `src` and `tests`.
