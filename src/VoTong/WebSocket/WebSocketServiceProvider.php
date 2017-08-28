<?php namespace VoTong\WebSocket;

use VoTong\WebSocket\Async;
use Illuminate\Support\ServiceProvider;
use \ZMQContext;

/**
 * Service provider to instantiate the service.
 *
 * @package  VoTong\WebSocket
 */
class WebSocketServiceProvider
    extends ServiceProvider {

    /**
     * Internal service prefix.
     *
     * @var string
     */
    const SERVICE_PREFIX = 'Laravel.VoTong.WebSocket';

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var boolean
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
//         $this->package('freestream/websocket');
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
		// async Interface
        $this->app->bind("VoTong\\WebSocket\\AsyncInterface", function() {
            return new Async(new \ZMQContext());
        });

		// WebSocket Server
//        $this->app->bind("Ratchet\\MessageComponentInterface", "App\\Providers\\GdmWebSocketEventListener");

        $this->app['command.gdm_websocket:start'] = $this->app->share(function($app)
        {
            return new WebSocketCommand(
				$this->app->make("VoTong\\WebSocket\\AsyncInterface")
//				,$this->app->make("Ratchet\\MessageComponentInterface")
			);
        });

        $this->commands('command.gdm_websocket:start');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('freestream_websocket','command.gdm_websocket:start');
    }

}
