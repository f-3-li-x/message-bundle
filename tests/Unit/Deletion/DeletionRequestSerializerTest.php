<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Deletion;

use stdClass;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use ACAT\MessageBundle\Deletion\DeletionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use ACAT\MessageBundle\Deletion\DeletionRequestSerializer;
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
            ->decode(['body' => '{"id":"not-a-uuid","ticketId":"ret-1"}'])
            ->getMessage();

        self::assertInstanceOf(DeletionRequest::class, $message);
        self::assertSame('not-a-uuid', $message->id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function structurallyInvalidBodies(): iterable
    {
        yield 'empty' => ['', 'The payload is empty.'];
        yield 'whitespace only' => [" \n\t", 'The payload is empty.'];
        yield 'not JSON' => ['{id:', 'The payload is not valid JSON.'];
        yield 'list instead of object' => ['["id"]', 'The payload is not a JSON object.'];
        yield 'id missing' => ['{"ticketId":"ret-1"}', 'The field "id" is missing.'];
        yield 'ticketId not a string' => ['{"id":"x","ticketId":1}', 'The field "ticketId" is not a string.'];
        yield 'id empty' => ['{"id":"","ticketId":"ret-1"}', 'The field "id" is empty.'];
        yield 'ticketId too long' => [
            sprintf('{"id":"x","ticketId":"%s"}', str_repeat('ä', 256)),
            'The field "ticketId" is longer than 255 characters.',
        ];
    }

    #[DataProvider('structurallyInvalidBodies')]
    public function testReportsAStructurallyInvalidRequestAsNotDecodable(string $body, string $reason): void
    {
        $message = (new DeletionRequestSerializer())->decode(['body' => $body])->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $message);
        self::assertSame('Structurally invalid deletion request: ' . $reason, $message->getMessage());
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
