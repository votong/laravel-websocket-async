<?php
namespace VoTong\WebSocket;

use Predis\Async\Connection\AbstractConnection;
use Predis\Async\Connection\State;
use Predis\Async\Connection\ConnectionException;
use Predis\Async\CommunicationException;

/**
 * Base class providing the common logic used by to communicate asynchronously
 * with Redis.
 *
 * @author Dung Nguyen
 */
abstract class CustomAbstractConnection extends AbstractConnection
{
    /**
     * Creates the underlying resource used to communicate with Redis.
     *
     * @return mixed
     */
    protected function createResource(callable $callback)
    {
        $parameters = $this->parameters;
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;

        if ($parameters->scheme === 'unix') {
            $uri = "unix://$parameters->path";
        } else {
            $uri = "$parameters->scheme://$parameters->host:$parameters->port";
        }

        if (!$stream = @stream_socket_client($uri, $errno, $errstr, 0, $flags)) {
            $this->onError(new ConnectionException($this, trim($errstr), $errno));

            return;
        }

        stream_set_blocking($stream, 0);

        $this->state->setState(State::CONNECTING);

        $this->loop->addWriteStream($stream, function ($stream) use ($callback) {
            if ($this->onConnect()) {
                call_user_func($callback, $this);
                $this->write();
            }
        });

        $this->timeout = $this->armTimeoutMonitor(
            $parameters->timeout, $this->errorCallback ?: function () { }
        );

        return $stream;
    }

	/**
     * {@inheritdoc}
     */
    protected function onError(\Exception $exception)
    {
        $this->disconnect();

		if ($exception instanceof ConnectionException || $exception instanceof CommunicationException) {
            throw new ConnectionException($this, $exception->getMessage());
        }

        if (isset($this->errorCallback)) {
            call_user_func($this->errorCallback, $this, $exception);
        }

        return false;
    }

	/**
     * Sets a timeout monitor to handle timeouts when connecting to Redis.
     *
     * @param float    $timeout  Timeout value in seconds
     * @param callable $callback Callback invoked upon timeout.
     */
    protected function armTimeoutMonitor($timeout, callable $callback)
    {
        $timer = $this->loop->addTimer($timeout, function ($timer) {
            list($connection, $callback) = $timer->getData();

            $connection->disconnect();

			// handle timeout
			throw new ConnectionException($connection, 'Connection timed out');

//            call_user_func($callback, $connection, new ConnectionException($connection, 'Connection timed out'));
        });

        $timer->setData([$this, $callback]);

        return $timer;
    }
}
