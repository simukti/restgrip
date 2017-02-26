<?php
namespace Restgrip\Micro;

use Phalcon\Config;
use Phalcon\Di;
use Phalcon\DiInterface;
use Phalcon\Filter;
use Phalcon\Http\Request;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;
use Restgrip\Event\EventsManager;
use Restgrip\Handler\ErrorHandler;
use Restgrip\Handler\NotFoundHandler;
use Restgrip\Module\ModuleInterface;
use Restgrip\Router\EventListener\RouterEventListener;
use Restgrip\Router\Route;
use Restgrip\Router\RouteCollection;
use Restgrip\Router\Router;

/**
 * @package   Restgrip\Micro
 * @author    Sarjono Mukti Aji <me@simukti.net>
 */
class Application extends Micro
{
    /**
     * @var Config
     */
    protected $configs;
    
    /**
     * Application constructor.
     *
     * @param array    $config
     * @param \array[] ...$configs
     */
    public function __construct(array $config, array ...$configs)
    {
        $config = new Config($config);
        foreach ($configs as $override) {
            $config = $config->merge(new Config($override));
        }
        
        $this->configs = $config;
        $this->setupContainer($this->configs);
    }
    
    /**
     * @param Config $config
     *
     * @return void
     */
    protected function setupContainer(Config $config)
    {
        $container = new Di();
        $container->setShared(
            'eventsManager',
            function () {
                $instance = new EventsManager();
                $instance->enablePriorities(true);
                
                return $instance;
            }
        );
        
        $container->setShared(
            'configs',
            function () use ($config) {
                return $config;
            }
        );
        
        $container->setShared(
            'filter',
            function () {
                $instance = new Filter();
                
                return $instance;
            }
        );
        
        $this->setDI($container);
        $this->setEventsManager($container->getShared('eventsManager'));
    }
    
    /**
     * @param DiInterface $container
     */
    protected function loadModules(DiInterface $container)
    {
        /* @var $modules Config */
        $modules = $this->configs->get('modules', []);
        if ($modules->count()) {
            foreach ($modules as $module) {
                /* @var $module ModuleInterface */
                $module = $container->getShared($module);
                $module->register($this);
            }
        }
    }
    
    /**
     * @return mixed
     * @throws \Exception
     */
    public function serveConsole()
    {
        $this->loadModules($this->getDI());
        
        // This must be instance of symfony console from restgrip extra-modules
        if (!$this->getDI()->has('console')) {
            throw new \Exception('Module console not found');
        }
        
        $consoleApp = $this->getDI()->getShared('console');
        
        return $consoleApp->run();
    }
    
    /**
     * @return mixed
     */
    public function serveHttp()
    {
        $container = $this->getDI();
        
        $container->setShared(
            'router',
            function () use ($container) {
                $instance = new Router();
                
                /**
                 * @link https://github.com/phalcon/cphalcon/blob/v3.0.4/phalcon/mvc/router.zep#L167
                 */
                $instance->setUriSource(Router::URI_SOURCE_SERVER_REQUEST_URI);
                $instance->removeExtraSlashes(true);
                
                /**
                 * @link https://docs.phalconphp.com/en/latest/reference/events.html#listener-priorities
                 */
                $evm = $container->getShared('eventsManager');
                $evm->attach('router', new RouterEventListener(), 10000);
                $instance->setEventsManager($evm);
                
                return $instance;
            }
        );
        
        $container->setShared(
            'request',
            function () {
                $instance = new Request();
                
                return $instance;
            }
        );
        
        $container->setShared(
            'response',
            function () {
                $instance = new Response();
                
                return $instance;
            }
        );
        
        $container->setShared(
            'notFoundHandler',
            function () {
                $instance = new NotFoundHandler();
                
                return $instance;
            }
        );
        
        $container->setShared(
            'errorHandler',
            function () {
                $instance = new ErrorHandler();
                
                return $instance;
            }
        );
        
        $this->notFound($container->getShared('notFoundHandler'));
        $this->error($container->getShared('errorHandler'));
        
        $this->loadModules($container);
        
        return $this->handle();
    }
    
    /**
     * @param Micro\CollectionInterface $collection
     *
     * @link https://github.com/phalcon/cphalcon/blob/v3.0.4/phalcon/mvc/micro.zep#L365-L441
     * @return $this
     * @throws \Exception
     */
    public function mount(Micro\CollectionInterface $collection)
    {
        /**
         * Get the main handler
         */
        $mainHandler = $collection->getHandler();
        if (!$mainHandler) {
            throw new \Exception('Route collection requires a main handler class');
        }
        
        $handlers = $collection->getHandlers();
        if (!count($handlers)) {
            throw new \Exception('There are no handlers to mount');
        }
        
        if (is_array($handlers)) {
            /**
             * Check if handler is lazy
             */
            if ($collection->isLazy()) {
                $lazyHandler = new Micro\LazyLoader($mainHandler);
            } else {
                $lazyHandler = $mainHandler;
            }
            
            /**
             * Get the main prefix for the collection
             */
            $prefix = $collection->getPrefix();
            
            foreach ($handlers as $handler) {
                if (!is_array($handler)) {
                    throw new \Exception('Handler definition must be an array');
                }
                
                /**
                 * @see RouteCollection::addRoute()
                 */
                $methods         = $handler[0];
                $pattern         = $handler[1];
                $subHandler      = $handler[2];
                $name            = $handler[3] ?? '';
                $validationClass = $handler[4] ?? '';
                $visible         = $handler[5] ?? true;
                $auth            = $handler[6] ?? '';
                $scope           = $handler[7] ?? '';
                $role            = $handler[8] ?? '';
                
                $actionClass = $lazyHandler;
                if ($collection->isLazy()) {
                    $actionClass = $lazyHandler->getDefinition();
                }
                
                /**
                 * Create a real handler
                 */
                $realHandler = [
                    $lazyHandler,
                    $subHandler,
                ];
                
                if (!empty($prefix)) {
                    if ($pattern == '/') {
                        $prefixedPattern = $prefix;
                    } else {
                        $prefixedPattern = $prefix.$pattern;
                    }
                } else {
                    $prefixedPattern = $pattern;
                }
                
                /**
                 * Map the route manually
                 *
                 * @var $route Route
                 */
                $route = $this->map($prefixedPattern, $realHandler);
                
                if ((is_string($methods) && $methods != '') || is_array($methods)) {
                    $route->via($methods);
                }
                
                $groupName = $prefix;
                if (!$prefix || $prefix == '/') {
                    $groupName = 'default';
                }
                
                $route->setActionClass($actionClass);
                $route->setActionMethod($subHandler);
                $route->setGroupName($groupName);
                $route->setName($name);
                $route->setValidation($validationClass);
                $route->setAuthType($auth);
                $route->setScope($scope);
                $route->setRole($role);
                $route->setVisible($visible);
            }
        }
        
        return $this;
    }
}