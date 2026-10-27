<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Deletion;

/**
 * Löschauftrag (deletion.requested) des Löschdienstes mit dem Payload id und ticketId. Welcher Auftrag
 * gemeint ist, ergibt sich aus der Queue bzw. dem Transport, über den er eingeht; der Event-Consumer wählt ihn
 * über #[AsMessageHandler(fromTransport: ...)].
 */
final readonly class DeletionRequest
{
    /**
     * Erzeugt den Löschauftrag.
     *
     * @param string $id       id aus dem Payload, wie sie übermittelt wurde; muss keine gültige UUID v7 sein
     * @param string $ticketId vom Löschdienst vergebene ticketId, wird für die Rückmeldung übernommen
     */
    public function __construct(
        public string $id,
        public string $ticketId,
    ) {
    }
}
