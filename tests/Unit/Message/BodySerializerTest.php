<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Tests\Unit\Message;

use stdClass;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use ACAT\MessageBundle\Message\RawMessage;
use ACAT\MessageBundle\Message\BodySerializer;
use ACAT\MessageBundle\Testing\AmqpMessageAssertions;

final class BodySerializerTest extends TestCase
{
    use AmqpMessageAssertions;

    public function testEncodesTheBodyUnchangedAndWithoutHeaders(): void
    {
        $encoded = (new BodySerializer())->encode(new Envelope(new RawMessage('{"id":"x","newValue":{}}')));

        self::assertEncodedWithoutHeaders($encoded);
        self::assertSame('{"id":"x","newValue":{}}', $encoded['body']);
    }

    public function testDecodesTheBody(): void
    {
        $message = (new BodySerializer())->decode(['body' => '{"id":"x"}'])->getMessage();

        self::assertInstanceOf(RawMessage::class, $message);
        self::assertSame('{"id":"x"}', $message->body);
    }

    public function testRefusesOtherMessages(): void
    {
        $this->expectException(LogicException::class);

        (new BodySerializer())->encode(new Envelope(new stdClass()));
    }
}
