<?php

declare(strict_types=1);

namespace F3lix\MessageBundle\Connection;

use SensitiveParameter;

/**
 * Verbindungsdaten von RabbitMQ aus den Variablen RABBITMQ_*, in der Form, die die Extension amqp und der
 * AMQP-Transport von Symfony Messenger erwarten. Messenger-Transports und AmqpLogHandler nutzen dieselben Daten.
 */
final readonly class RabbitMqConfig
{
    /**
     * Übernimmt die Werte der Variablen RABBITMQ_*.
     *
     * @param string $host      RABBITMQ_HOST
     * @param int    $port      RABBITMQ_PORT
     * @param string $user      RABBITMQ_USER
     * @param string $password  RABBITMQ_PASSWORD
     * @param string $vhost     RABBITMQ_VHOST
     * @param bool   $secure    RABBITMQ_SECURE: true, wenn die Verbindung verschlüsselt sein muss (TLS)
     * @param int    $heartbeat RABBITMQ_HEARTBEAT: Heartbeat-Intervall in Sekunden
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        #[SensitiveParameter]
        private string $password,
        private string $vhost,
        private bool $secure,
        private int $heartbeat,
    ) {
    }

    /**
     * Prüft, ob die Verbindung verschlüsselt sein muss.
     *
     * @return bool true bei TLS
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * Liefert das Heartbeat-Intervall; der Broker schließt eine Verbindung nach zwei Intervallen ohne Datenverkehr.
     *
     * @return int Intervall in Sekunden
     */
    public function heartbeat(): int
    {
        return $this->heartbeat;
    }

    /**
     * Liefert die Verbindungsdaten für AMQPConnection, zugleich gültig als Optionen des AMQP-Transports. Eine
     * verschlüsselte Verbindung erhält die CA-Zertifikate, gegen die der Server geprüft wird; die Extension amqp
     * verschlüsselt die Verbindung, sobald ein CA-Zertifikat angegeben ist.
     *
     * @param float $connectTimeout Zeitlimit für den Verbindungsaufbau in Sekunden
     *
     * @return array<string, string|int|float> die Verbindungsdaten
     */
    public function connectionOptions(float $connectTimeout): array
    {
        $options = [
            'host'            => $this->host,
            'port'            => $this->port,
            'vhost'           => $this->vhost,
            'login'           => $this->user,
            'password'        => $this->password,
            'heartbeat'       => $this->heartbeat,
            'connect_timeout' => $connectTimeout,
        ];

        if ($this->secure) {
            $options['cacert'] = self::caCertificates();
        }

        return $options;
    }

    /**
     * Liefert die CA-Zertifikate: die der Extension (amqp.cacert in der php.ini), sonst die des Systems.
     *
     * @return string Pfad der Datei mit den Zertifikaten
     */
    private static function caCertificates(): string
    {
        $configured = ini_get('amqp.cacert');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return openssl_get_cert_locations()['default_cert_file'];
    }
}
