<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Tests\Unit\Deletion;

use stdClass;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use F3lix\MessageBundle\Deletion\DeletionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use F3lix\MessageBundle\Deletion\DeletionRequestSerializer;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

final class DeletionRequestSerializerTest extends TestCase
{
    public function testDecodesIdAndTicketIdAndIgnoresUnknownFields(): void
    {
        $message = (new DeletionRequestSerializer())
            ->decode(['body' => '{"id":"018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a","ticketId":"ret-1","extra":true}'])
            ->getMessage();

        self::assertInstanceOf(DeletionRequest::class, $message);
        self::assertSame('018f2c3a-9a1b-7d2e-8f3a-1b2c3d4e5f6a', $message->id);
        self::assertSame('ret-1', $message->ticketId);
    }

    public function testPassesAnIdThatIsNoUuidOn(): void
    {
        $message = (new DeletionRequestSerializer())
            ->decode(['body' => '{"id":"keine-uuid","ticketId":"ret-1"}'])
            ->getMessage();

        self::assertInstanceOf(DeletionRequest::class, $message);
        self::assertSame('keine-uuid', $message->id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function structurallyInvalidBodies(): iterable
    {
        yield 'leer' => ['', 'Das Payload ist leer.'];
        yield 'nur Leerraum' => [" \n\t", 'Das Payload ist leer.'];
        yield 'kein JSON' => ['{id:', 'Das Payload ist kein gültiges JSON.'];
        yield 'Liste statt Objekt' => ['["id"]', 'Das Payload ist kein JSON-Objekt.'];
        yield 'id fehlt' => ['{"ticketId":"ret-1"}', 'Das Feld "id" fehlt.'];
        yield 'ticketId kein String' => ['{"id":"x","ticketId":1}', 'Das Feld "ticketId" ist kein String.'];
        yield 'id leer' => ['{"id":"","ticketId":"ret-1"}', 'Das Feld "id" ist leer.'];
        yield 'ticketId zu lang' => [
            sprintf('{"id":"x","ticketId":"%s"}', str_repeat('ä', 256)),
            'Das Feld "ticketId" ist länger als 255 Zeichen.',
        ];
    }

    #[DataProvider('structurallyInvalidBodies')]
    public function testReportsAStructurallyInvalidRequestAsNotDecodable(string $body, string $reason): void
    {
        $message = (new DeletionRequestSerializer())->decode(['body' => $body])->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $message);
        self::assertSame('Strukturell ungültiger Löschauftrag: ' . $reason, $message->getMessage());
    }

    public function testEncodesARequest(): void
    {
        $encoded = (new DeletionRequestSerializer())->encode(new Envelope(new DeletionRequest('x', 'ret-1')));

        self::assertSame('{"id":"x","ticketId":"ret-1"}', $encoded['body']);
    }

    public function testKeepsTheRawFormOfANotDecodableMessage(): void
    {
        $serializer = new DeletionRequestSerializer();
        $failed = $serializer->decode(['body' => '{id:', 'headers' => ['type' => 'deletion.requested']]);

        self::assertSame(
            ['body' => '{id:', 'headers' => ['type' => 'deletion.requested']],
            $serializer->encode($failed),
        );
    }

    public function testRefusesOtherMessages(): void
    {
        $this->expectException(LogicException::class);

        (new DeletionRequestSerializer())->encode(new Envelope(new stdClass()));
    }
}
