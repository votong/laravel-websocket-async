<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2014 Anton Samuelsson
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
?>
<?php namespace VoTong\WebSocket;

use Log;
use Redis;
use Config;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Process\Process;
use VoTong\WebSocket\Async;
use Ratchet\MessageComponentInterface;
use App\Providers\GdmWebSocketEventListener;
use App\Helpers\ServerHelper;

/**
 * System artisan command class.
 *
 * @package  VoTong\WebSocket
 */
class WebSocketCommand
    extends Command
{
    /**
     * Default WebSocket port.
     *
     * @var integer
     */
    const DEFAULT_WEBSOCKET_PORT = 8080;

	/**
     * Max time try to restart websocket
     *
     * @var integer
     */
	const MAX_RESTART_TIME = 30;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'gdm_websocket:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts Gdm WebSocket server and runs event-driven applications with Laravel.';

	/** @var AsyncInterface */
    protected $loop;
	/** @var MessageComponentInterface */
    protected $webSocketListener;
	/** @var React\Socket\Server */
    protected $webSocket;
	/** @var \ZMQContext */
    protected $pull;

	/** Count restart times */
	protected $restartCount = 0;

    /**
     * Create a new command instance.
     */
    public function __construct(AsyncInterface $loop)
    {
        parent::__construct();
		$this->loop = $loop;
		$this->webSocketListener = GdmWebSocketEventListener::getInstance();
    }

    /**
     * Execute the console command.
     */
    public function fire($restart = false)
    {
        try {
			// Start
			if($restart) {
				// Check max restart
				if($this->restartCount > self::MAX_RESTART_TIME) {
					$this->error("Try to restart ".self::MAX_RESTART_TIME." times but unable to connect to Redis server.Exit");exit();
				}

				$this->restart();
			} else {
				$this->start();
			}

        } catch (Exception $exception) {
            Log::error('Something went wrong:', $exception);
            $this->error('Unable to establish a WebSocket server. Review the log for more information.');
        } catch (\Predis\Async\Connection\ConnectionException $exception) {
			$this->handleRedisConnectionException($exception);
        } catch (\Predis\Connection\ConnectionException $exception) {
            $this->handleRedisConnectionException($exception);
        }
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
            array(
                'port', null, InputOption::VALUE_OPTIONAL,
                "The port that the WebSocket server will run on (default: {self::DEFAULT_WEBSOCKET_PORT})",
                 self::DEFAULT_WEBSOCKET_PORT
            ),
        );
    }

	/**
	 * Pull ZeroMQ Messages
	 *
     * @param $loop \React\EventLoop\LoopInterface
     * @param $wamp \Ratchet\Wamp\WampServerInterface
     * @return void
     */
    protected function pull($loop)
    {
        $context = new \React\ZMQ\Context($loop);
        $this->pull = $context->getSocket(\ZMQ::SOCKET_PULL);
        $this->pull->bind(\Config::get('socket.socket_connection'));
        $this->pull->on('message', array($this->webSocketListener, 'onPublish'));
    }

	/**
	 * Start
	 *
     * @return void
     */
	protected function start()
	{
		$this->info('Reset Websocket Connection count in Redis');
		// Reset websocket connection count store in redis
		Redis::hset(ServerHelper::getGdmClientKey(),ServerHelper::getLocalIpAddress(),0);

		$loop = $this->loop->start();
		$this->info('Redis subscribe start');

		// Init ZeroMQ pull Connection
		$this->pull($loop);

		// Start ReactPHP socket server
		$this->startWebSocketServer($loop);

		$loop->run();
	}

	/**
	 * Restart
	 *
     * @return void
     */
	protected function restart()
	{
		// Shutdown old websocket
		$this->closeOldSocket();

		$this->info('Reset Websocket Connection count in Redis');
		// Reset websocket connection count store in redis
		Redis::hset(ServerHelper::getGdmClientKey(),ServerHelper::getLocalIpAddress(),0);

		// Reconnect other Redis server
		$this->info('Try to reconnect to other Redis server');
		$loop = $this->loop->reconnect();
		$this->info('Redis subscribe start');

		// Init ZeroMQ pull Connection
		$this->pull($loop);

		// Start new websocket server
		$this->startWebSocketServer($loop);

		$loop->run();
	}

	protected function startWebsocketServer($loop)
	{
		$port = $this->option('port');

		// reactPHP socket server
		$this->webSocket = new \React\Socket\Server($loop);
		$this->webSocket->listen($port, '0.0.0.0');

		$webSocketServer = new \Ratchet\Server\IoServer(
			new \Ratchet\Http\HttpServer(
				new \Ratchet\WebSocket\WsServer(
					$this->webSocketListener
				)
		), $this->webSocket);

		$this->info('websocket server boot');
		$this->comment('Listening on port ' . $port);
	}

	protected function closeOldSocket()
	{
		// Shutdown old websocket server
		if(!empty($this->webSocket)) {
			$this->info('-- Restart websocket');
			$this->webSocket->shutdown();
		}

		// Disconnect ZeroMQ socket
		if(!empty($this->pull)) {
			$this->pull->disconnect(\Config::get('socket.socket_connection'));
		}
	}

	protected function handleRedisConnectionException($exception)
	{
		Log::error('Redis connection error:', array($exception->getMessage()));
		$this->error('Redis connection error:'. $exception->getMessage());

		sleep(10);

		// Restart
		$this->restartCount++;
		$this->fire(true);
	}
}
