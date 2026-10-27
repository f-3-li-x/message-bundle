# Message Bundle

Symfony-8-Bundle für die Messaging-Infrastruktur von Microservices: Transactional Outbox, Anbindung an RabbitMQ, Retry beim Konsum, Löschaufträge mit Rückmeldung und Logging über AMQP.

## Zweck

Microservices, die Domain Events über RabbitMQ austauschen, brauchen immer dieselben Teile: eine Outbox, die Events in derselben Transaktion wie die Zustandsänderung festhält, einen Publisher, der sie zuverlässig und in der richtigen Reihenfolge zustellt, Consumer, die technische Fehler wiederholen und kaputte Nachrichten in die Dead-Letter-Queue schicken, und ein Logging, das nie eine Anfrage scheitern lässt. Das Bundle setzt diese Teile einmal um, damit nicht jeder Service sie selbst nachbaut.

Grundsätze:

- Feste Regeln (Anzahl der Versuche, Wartezeiten, AMQP-Properties, Tabellenschema) sind eingebaut und nicht konfigurierbar. Konfigurierbar ist nur, was sich je Service unterscheidet.
- Der Core eines Service hängt nicht vom Bundle ab. Das Bundle wird nur aus der Infrastruktur- und der Schnittstellenschicht genutzt.
- Für das Event-Format und das Verhalten bei Löschaufträgen liefert das Bundle Assertions und einen Vertragstest, damit jeder Service sein Verhalten gegen dieselben Regeln prüft.

## Funktionen

- **Transactional Outbox:** Events landen in derselben Datenbanktransaktion wie die Zustandsänderung in der Tabelle `outbox_entries`; ein Konsolenkommando stellt sie mit Publisher Confirms zu.
- **Zustellung in Reihenfolge:** je Aggregat in der Reihenfolge der Entstehung; ein nicht zugestellter Eintrag hält spätere Einträge desselben Aggregats zurück.
- **Fehlerbehandlung beim Veröffentlichen:** ein nicht erreichbarer Broker zählt nicht als Fehlversuch; nach 10 abgelehnten Versuchen erhält ein Eintrag den Status `failed` und lässt sich per Kommando wieder anstoßen.
- **Retry beim Konsum:** bis zu 10 Versuche im selben Prozess mit festen Wartezeiten, danach Ablehnung ohne Requeue in die Dead-Letter-Queue.
- **Löschaufträge:** Strukturprüfung eingehender Aufträge, strukturell ungültige Aufträge gehen ohne Wiederholung in die Dead-Letter-Queue.
- **Logging über AMQP:** Monolog-Handler, der erst bei Bedarf verbindet, jeden Fehler abfängt und nach einem Fehler eine Pause einlegt; dazu ein Processor, der sensible Felder aus den Logs hält.
- **Test-Hilfen:** Assertions für Event- und Nachrichtenformat, Vertragstest für Löschaufträge, Test-Doubles für Outbox und Transports.

## Schichtgrenze

| Schicht des Service | Nutzt das Bundle | Bleibt im Service |
|---|---|---|
| Domain | nein | Domain Events, Erzeugung des `delta`, verarbeitete Löschaufträge und ihr Repository-Interface |
| Application | nein | Outbox-Interface, Transaktions-Interface, Ablauf eines Löschauftrags |
| Infrastructure | ja | Adapter, der das Outbox-Interface über `OutboxWriter` umsetzt; Transaktion, ID-Erzeugung, Repository der verarbeiteten Löschaufträge |
| Schnittstelle | ja | Event-Consumer: bildet aus der Nachricht ein Command und ruft den Service über `RetryingExecutor` auf |

## Aufbau

```
src/
  MessageBundle.php
  DependencyInjection/
    Configuration.php              Konfiguration unter dem Schlüssel message
    MessageExtension.php           Services laden, framework.messenger und doctrine vorbelegen
  Resources/config/
    services.php                   Verbindung, Serializer, Retry, Logging
    outbox.php                     Outbox, nur bei message.outbox.enabled
  Connection/
    RabbitMqConfig.php             RABBITMQ_* einschließlich TLS und Heartbeat
    RabbitMqTransportFactory.php   Messenger-Transport für den DSN rabbitmq-env://
  Message/
    AmqpProperties.php             AMQP-Properties jeder Nachricht
    RawMessage.php                 Body einer Nachricht, wie er an RabbitMQ geht
    BodySerializer.php             Body ist das Payload, keine Header
  Persistence/
    UtcDateTimeMsType.php          Custom Type message_utc_datetime_ms, DATETIME(3) in UTC
  Outbox/
    Entity/OutboxEntry.php         Tabelle outbox_entries
    Entity/OutboxStatus.php        pending, failed
    OutboxWriter.php               append() ohne flush
    ExchangeResolver.php           Event-Typ -> Exchange
    OutboxEntryStore.php           Interface
    DoctrineOutboxEntryStore.php
    OutboxSender.php               Interface
    MessengerOutboxSender.php      Publisher Confirms, Fehlerklassifikation
    BrokerUnavailableException.php
    MessageRejectedException.php
    OutboxPublisher.php
    PublishResult.php
    Command/PublishOutboxCommand.php       <namensraum>:publish
    Command/RetryFailedOutboxCommand.php   <namensraum>:retry-failed
  Consumer/
    RetryingExecutor.php           Wiederholung im selben Prozess
  Deletion/
    DeletionRequest.php            id, ticketId
    DeletionRequestSerializer.php  Strukturprüfung
  Logging/
    AmqpExchangeFactoryInterface.php
    AmqpExchangeFactory.php        Verbindung für die Log-Events
    AmqpLogHandler.php             Handler für Monolog
    LogEventAmqpHandler.php        nativer AmqpHandler mit Routing-Key und Properties (intern)
    SensitiveFieldRedactionProcessor.php
  Testing/
    EventFormatAssertions.php      Trait, Event-Format
    AmqpMessageAssertions.php      Trait, Nachrichtenformat
    DeletionRequestContract.php    Trait, Vertragstest für Löschaufträge
    InMemoryOutboxEntryStore.php
    ScriptedOutboxSender.php
    RecordingMessengerSender.php
tests/
  Unit/                            je Komponente
  Integration/                     Symfony-Kernel mit Doctrine (SQLite), Monolog und In-Memory-Transports
```

## Voraussetzungen

- PHP 8.5+, Extensions `amqp`, `json`, `openssl`
- Symfony 8.0+ (`framework-bundle`, `messenger`, `amqp-messenger`, `clock`, `console`, `uid`, `doctrine-bridge`)
- Doctrine ORM 3.7+, DBAL 4.5+, DoctrineBundle 3.3+
- Monolog 3.12+, MonologBundle 4.1+

## Installation

Über das VCS-Repository:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/f-3-li-x/message-bundle.git" }
],
"require": {
    "f3lix/message-bundle": "^1.0"
}
```

Bundle in `config/bundles.php` eintragen:

```php
F3lix\MessageBundle\MessageBundle::class => ['all' => true],
```

Die Tabelle `outbox_entries` entsteht aus dem Mapping des Bundles mit `doctrine:migrations:diff` in den Migrationen des Service.

## Konfiguration

### Umgebungsvariablen

| Variable | Bedeutung | Standard |
|---|---|---|
| `RABBITMQ_HOST` | Hostname von RabbitMQ | – |
| `RABBITMQ_PORT` | Port | – |
| `RABBITMQ_USER` | Benutzername am Broker | – |
| `RABBITMQ_PASSWORD` | Passwort | – |
| `RABBITMQ_VHOST` | Virtual Host | – |
| `RABBITMQ_SECURE` | `true` für TLS (`amqps`) | – |
| `RABBITMQ_HEARTBEAT` | Heartbeat-Intervall in Sekunden | `60` |
| `RABBITMQ_RETRY` | Versuche je konsumierter Nachricht, auf 1 bis 10 begrenzt | `10` |

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

| Option | Standard | Bedeutung |
|---|---|---|
| `service` | – (Pflicht) | Name des Service; Name des eigenen Exchange, AMQP-Property `app_id` und Teil des Routing-Keys der Log-Events |
| `outbox.enabled` | `true` | Outbox, Publisher, Kommandos und die Transports zum Veröffentlichen |
| `outbox.central_exchange` | `null` | Zentraler Exchange für die Änderungsereignisse; ohne ihn gehen alle Events an den Exchange des Service |
| `outbox.central_event_types` | `['*.created', '*.updated']` | Event-Typen für den zentralen Exchange; `*` steht für genau ein Wort |
| `outbox.command_namespace` | `outbox` | Namensraum der Kommandos, z. B. `billing:outbox` für `billing:outbox:publish` |
| `consumers.<transport>.queue` | – | Queue des Transports; ein Eintrag je Queue |
| `consumers.<transport>.serializer` | `DeletionRequestSerializer` | Transport-Serializer; für andere eingehende Nachrichten ein eigener |
| `logging.enabled` | `true` | `AmqpLogHandler`; in der Umgebung `test` immer abgeschaltet |
| `logging.redact_fields` | `[]` | Felder, deren Werte nicht ins Log gelangen; leer bedeutet ohne Redaction-Processor |

Das Bundle belegt per Prepend vor:

- `framework.messenger`:
  - Standardbus ohne `reject_redelivered_message_middleware`, damit eine erneut zugestellte Nachricht nicht ohne Verarbeitung in der Dead-Letter-Queue landet;
  - je Exchange ein Transport zum Veröffentlichen, benannt nach dem Exchange in snake_case (z. B. `person_service`, `domain_events`), Typ `topic`, `queues: []`, `confirm_timeout: 10`, `auto_setup: false`;
  - je Eintrag unter `consumers` ein Transport mit `max_retries: 0` und `auto_setup: false`;
  - in der Umgebung `test` `in-memory://` für alle Transports.
- `doctrine`: Mapping von `OutboxEntry` und der Custom Type `message_utc_datetime_ms`.

Exchanges, Queues und Bindings deklariert das Bundle nicht (`auto_setup: false`); die Topologie am Broker wird außerhalb des Service gepflegt.

### Monolog

```yaml
monolog:
    handlers:
        amqp:
            type: service
            id: message.logging.amqp_handler
            channels: ['!doctrine']
```

## Komponenten

### Nachrichtenformat

Jede veröffentlichte Nachricht trägt die AMQP-Properties `message_id` (UUID v7 des Outbox-Eintrags, bei erneuter Zustellung unverändert; bei Log-Events eine neue je Eintrag), `type` (Routing-Key), `app_id` (Name des Service), `content_type` (`application/json`), `timestamp` (Unix-Sekunden) und `delivery_mode` `2`. Der Body ist das Payload unverändert, ohne Header von Symfony Messenger. Der `SigningSerializer` von Symfony 8.1, der jeden Transport-Serializer umhüllt, reicht unverändert durch, solange keine signierten Nachrichtentypen konfiguriert sind.

### Outbox

- `OutboxEntry` bildet die Tabelle `outbox_entries` ab: `id` (`BINARY(16)`, UUID v7), `aggregate_id`, `event_type`, `exchange`, `payload` (`LONGTEXT`), `status`, `attempts`, `created_at` (`DATETIME(3)`), Index `idx_outbox_entries_status_created_at`.
- Das Payload liegt als Text vor. In einer `JSON`-Spalte würde MySQL die Schlüssel umsortieren; als Text bleiben Feldreihenfolge und leere Objekte erhalten.
- `OutboxWriter::append(string $eventType, string $aggregateId, array $payload): OutboxEntry` kodiert das Payload mit festen Flags (`JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_SLASHES`, `JSON_UNESCAPED_UNICODE`, `JSON_PRESERVE_ZERO_FRACTION`) und persistiert den Eintrag ohne `flush`; geschrieben wird mit der Transaktion des Service. `created_at` ist der Zeitpunkt aus der UUID v7. Ein leeres Objekt steht im Payload als `stdClass`, da ein leeres PHP-Array als `[]` kodiert würde.
- `OutboxPublisher`:
  - liest alle Einträge, sortiert nach `created_at`, bei Gleichstand nach `id`;
  - überspringt alle späteren Einträge einer `aggregate_id`, deren früherer Eintrag gescheitert ist oder auf `failed` steht;
  - bricht ab, wenn RabbitMQ nicht erreichbar ist, ohne die Versuchszähler zu erhöhen;
  - erhöht `attempts`, wenn der Broker eine Nachricht ablehnt oder nicht innerhalb von 10 Sekunden bestätigt, und setzt nach dem 10. Fehlversuch `failed` mit einem Eintrag im Log auf Level `error`;
  - löscht einen Eintrag unmittelbar nach der Bestätigung; der Log-Context enthält nie das Payload.
- `<namensraum>:publish` startet einen Lauf, z. B. stündlich per Cron. `<namensraum>:retry-failed [<id>]` setzt `failed` auf `pending` und `attempts` auf 0, für alle Einträge in einem `UPDATE`.

### Consumer

`RetryingExecutor::run(callable $operation, array $context = [])`:

- höchstens `RABBITMQ_RETRY` Versuche;
- Wartezeiten 1, 2, 4, 8, 8, 8, 8, 8, 8 Sekunden über `Symfony\Component\Clock\ClockInterface`, zusammen 55 Sekunden;
- jeder Fehlversuch mit Level `warning` im Log, zusammen mit `$context`;
- vor jedem weiteren Versuch `ManagerRegistry::resetManager()`, da Doctrine den EntityManager nach einem Fehler schließt;
- nach dem letzten Fehlversuch `UnrecoverableMessageHandlingException`; die Nachricht wird ohne Requeue abgelehnt und landet über den Dead-Letter-Exchange der Queue in der Dead-Letter-Queue.

`RABBITMQ_HEARTBEAT` sollte mindestens 60 Sekunden betragen, damit der Broker die Verbindung während der Wiederholungen nicht schließt.

### Löschaufträge

Ein Löschauftrag hat das Payload `id` und `ticketId`. `DeletionRequestSerializer` lehnt strukturell ungültige Aufträge ohne Wiederholung ab: Payload leer, kein JSON oder kein JSON-Objekt; `id` oder `ticketId` fehlt, ist leer, kein String oder länger als 255 Zeichen. Unbekannte Felder werden ignoriert. Eine `id`, die keine UUID ist, gilt nicht als strukturell ungültig; sie geht an den Service, der sie fachlich als nicht vorhanden beantwortet.

Ein Service mit mehreren Auftragstypen richtet je Queue einen Eintrag unter `consumers` ein; alle liefern `DeletionRequest`, die Handler unterscheiden sich über `#[AsMessageHandler(fromTransport: ...)]`.

### Logging

- `AmqpLogHandler` (Service-ID `message.logging.amqp_handler`) kapselt den nativen `Monolog\Handler\AmqpHandler`:
  - baut die Verbindung erst beim ersten Log-Eintrag auf, Zeitlimit 3 Sekunden für Verbindungsaufbau und Senden;
  - Routing-Key `{level}.<service>`, unabhängig vom Channel; Exchange ist der des Service;
  - fängt jeden Fehler beim Verbindungsaufbau und beim Senden ab; scheitert eine offene Verbindung, wird für den Eintrag einmal neu verbunden, danach 60 Sekunden lang keine Einträge an RabbitMQ;
  - ersetzt eine Verbindung, die ein Heartbeat-Intervall lang ungenutzt war;
  - ist in der Umgebung `test` immer abgeschaltet.
- `SensitiveFieldRedactionProcessor` ersetzt in `context` und `extra` rekursiv die Werte der konfigurierten Felder durch `***REDACTED***` und wird für alle Channels registriert, sobald `logging.redact_fields` Felder nennt.

### Event-Format der Test-Hilfen

`EventFormatAssertions` prüft dieses Format:

| Event | Felder in dieser Reihenfolge |
|---|---|
| `<entität>.created` | `id`, `actorId`, `timestamp` |
| `<entität>.updated` | `id`, `actorId`, `timestamp`, `delta` |
| `<entität>.deleted` | `id`, `timestamp`, `ticketId` |
| `<entität>.deletion.failed` | `id`, `timestamp`, `ticketId`, `reason` |
| `<gegenstand>.deletion.processed` | `id`, `timestamp`, `ticketId`, danach servicespezifische Felder |

- `timestamp` in UTC nach RFC 3339 mit Millisekunden und `Z`, z. B. `2026-07-21T10:00:00.000Z`.
- Ein Eintrag in `delta` hat `field`, `operation` (`changed`, `added`, `removed`), `oldValue` und `newValue`; bei `added` ist `oldValue` `null`, bei `removed` `newValue` `null`.
- Ein maskierter Eintrag (`masked: true`) einer Feldänderung hat nur `field`, `operation` und `masked`.
- Rückmeldungen zu Löschaufträgen tragen kein `actorId`.

## Einbindung im Service

Adapter für die Outbox (Infrastruktur):

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

Event-Consumer für den Löschauftrag (Schnittstelle):

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

Vertragstest für Löschaufträge (Tests des Service):

```php
final class DeletionRequestTest extends KernelTestCase
{
    use DeletionRequestContract;

    protected function deletionEntityName(): string { return 'person'; }
    protected function createEntityForDeletion(): string { /* Person anlegen, id liefern */ }
    protected function handleDeletionRequest(DeletionRequest $request): void { /* Handler aufrufen */ }
    protected function publishedDeletionFeedback(): array { /* Event-Name und Body je Rückmeldung */ }
    protected function assertEntityDeleted(string $id): void { /* Löschung prüfen */ }
}
```

Ein lauffähiges Beispiel steht in `tests/Unit/Testing/DeletionRequestContractTest.php`.

## Entwicklung

Die Tests brauchen PHP 8.5 mit den Extensions `amqp` und `pdo_sqlite`. Ohne sie lokal laufen sie in einem Docker-Image, das beides mitbringt:

```bash
docker run --rm -v "$PWD":/app -w /app --entrypoint composer <php-image-mit-amqp> install
```

```bash
docker run --rm -v "$PWD":/app -w /app --entrypoint vendor/bin/phpunit <php-image-mit-amqp>
```

```bash
docker run --rm -v "$PWD":/app -w /app --entrypoint vendor/bin/phpstan <php-image-mit-amqp> analyse
```

PHPStan läuft auf Level `max` über `src` und `tests`.
