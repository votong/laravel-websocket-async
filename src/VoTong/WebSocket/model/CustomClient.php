<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VoTong\WebSocket;

use Predis\Async\Client;
use Predis\ClientException;
use Predis\Configuration\OptionsInterface;
use Predis\Async\Connection\ConnectionInterface;
use Predis\Async\Connection\PhpiredisStreamConnection;
use VoTong\WebSocket\CustomStreamConnection;

/**
 *  Client class used for connecting and executing commands on Redis.
 *
 * @author Dung Nguyen <dungnt129@gmail.com>
 */
class CustomClient extends Client
{
    /**
     * Initializes a connection from various types of arguments or returns the
     * passed object if it implements Predis\Connection\ConnectionInterface.
     *
     * @param mixed            $parameters Connection parameters or instance.
     * @param OptionsInterface $options    Client options.
     *
     * @return ConnectionInterface
     */
    protected function createConnection($parameters, OptionsInterface $options)
    {
        if ($parameters instanceof ConnectionInterface) {
            if ($parameters->getEventLoop() !== $this->options->eventloop) {
                throw new ClientException('Client and connection must share the same event loop.');
            }

            return $parameters;
        }

        $eventloop = $this->options->eventloop;
        $parameters = $this->createParameters($parameters);

        if ($options->phpiredis) {
            $connection = new PhpiredisStreamConnection($eventloop, $parameters);
        } else {
            $connection = new CustomStreamConnection($eventloop, $parameters);
        }

        if (isset($options->on_error)) {
            $this->setErrorCallback($connection, $options->on_error);
        }

        return $connection;
    }
}
