<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Connection;

use SensitiveParameter;

/**
 * Connection data of RabbitMQ from the RABBITMQ_* variables, in the form that the amqp extension and the AMQP
 * transport of Symfony Messenger expect. Messenger transports and the AmqpLogHandler use the same data.
 */
final readonly class RabbitMqConfig
{
    /**
     * Takes the values of the RABBITMQ_* variables.
     *
     * @param string $host      RABBITMQ_HOST
     * @param int    $port      RABBITMQ_PORT
     * @param string $user      RABBITMQ_USER
     * @param string $password  RABBITMQ_PASSWORD
     * @param string $vhost     RABBITMQ_VHOST
     * @param bool   $secure    RABBITMQ_SECURE: true if the connection must be encrypted (TLS)
     * @param int    $heartbeat RABBITMQ_HEARTBEAT: heartbeat interval in seconds
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
     * Checks whether the connection must be encrypted.
     *
     * @return bool true for TLS
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * Returns the heartbeat interval; the broker closes a connection after two intervals without traffic.
     *
     * @return int interval in seconds
     */
    public function heartbeat(): int
    {
        return $this->heartbeat;
    }

    /**
     * Returns the connection data for AMQPConnection, also valid as options of the AMQP transport. An encrypted
     * connection gets the CA certificates the server is verified against; the amqp extension encrypts the connection
     * as soon as a CA certificate is given.
     *
     * @param float $connectTimeout timeout for connecting in seconds
     *
     * @return array<string, string|int|float> the connection data
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
     * Returns the CA certificates: those of the extension (amqp.cacert in php.ini), otherwise those of the system.
     *
     * @return string path of the file with the certificates
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
