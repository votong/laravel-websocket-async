<?php namespace VoTong\WebSocket;

//use Predis\Async\Client as AsyncClient;
use VoTong\WebSocket\CustomClient as AsyncClient;
use Illuminate\Support\Facades\Config;
use App\Helpers\ServerHelper;
use Aws\ElastiCache\ElastiCacheClient;
use App;

/**
 * Class Async
 * @package VoTong\WebSocket
 */
class Async implements AsyncInterface
{
    /** @var array */
    protected $connection = [];
    /** @var \ZMQContext */
    protected $context;
    /**
     * @param \ZMQContext $context
     */
    public function __construct(\ZMQContext $context)
    {
        $this->connection = Config::get('database.redis.default');
        $this->context = $context;
    }
    /**
     * ReactPHP and Predis/Async
     * @return \React\EventLoop\LoopInterface
     * @throws \Exception
     */
    public function async($connection = null)
    {
		if(!empty($connection)) {
			$this->connection = $connection;
		}
		$client = new AsyncClient($this->connection);

		$client->connect(function ($client) {
			//
			$redis = new AsyncClient($this->connection, $client->getEventLoop());
			// subscribe channel
			$client->pubSubLoop(Config::get('socket.channel'), function ($event) use ($redis) {
				$socket = $this->context->getSocket(\ZMQ::SOCKET_PUSH, 'push');
				$socket->connect(Config::get('socket.socket_connection'));
				$socket->send($event->payload);
			});

			$client->pubSubLoop(ServerHelper::getPrivateChannel(), function ($event) use ($redis) {

			});
		});

//        if(!$client->isConnected()) {
//            throw new \Exception("redis not connect", 500);
//        }
        return $client->getEventLoop();
    }

	public function start()
	{
		return $this->async();
	}

	public function reconnect()
	{
		// Get avaiable connection
		$connection = ServerHelper::getAvaiablePrimaryRedisEndpoint();

		return $this->async($connection);
	}
}