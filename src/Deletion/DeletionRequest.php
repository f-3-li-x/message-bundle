<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Deletion;

/**
 * Deletion request (deletion.requested) of the deletion service with the payload id and ticketId. Which request
 * is meant follows from the queue or transport it arrives through; the event consumer selects it with
 * #[AsMessageHandler(fromTransport: ...)].
 */
final readonly class DeletionRequest
{
    /**
     * Creates the deletion request.
     *
     * @param string $id       id from the payload as it was sent; need not be a valid UUID v7
     * @param string $ticketId ticketId assigned by the deletion service, taken over for the feedback
     */
    public function __construct(
        public string $id,
        public string $ticketId,
    ) {
    }
}
