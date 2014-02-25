<?php
/*
  +------------------------------------------------------------------------+
  | PhalconEye CMS                                                         |
  +------------------------------------------------------------------------+
  | Copyright (c) 2013-2014 PhalconEye Team (http://phalconeye.com/)       |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconeye.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Author: Ivan Vorontsov <ivan.vorontsov@phalconeye.com>                 |
  +------------------------------------------------------------------------+
*/

namespace Engine;

use Engine\Api\Injector as ApiInjector;
use Engine\Asset\Manager as AssetsManager;
use Engine\Cache\Dummy;
use Engine\Db\Model\Annotations\Initializer as ModelAnnotationsInitializer;
use Engine\Exception\PrettyExceptions;
use Engine\Widget\Catalog;
use Phalcon\Annotations\Adapter\Memory as AnnotationsMemory;
use Phalcon\Cache\Frontend\Data as CacheData;
use Phalcon\Cache\Frontend\Output as CacheOutput;
use Phalcon\Db\Adapter;
use Phalcon\Db\Adapter\Pdo;
use Phalcon\Db\Profiler as DatabaseProfiler;
use Phalcon\DI;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Flash\Direct as FlashDirect;
use Phalcon\Flash\Session as FlashSession;
use Phalcon\Loader;
use Phalcon\Logger\Adapter\File;
use Phalcon\Logger;
use Phalcon\Logger\Formatter\Line as FormatterLine;
use Phalcon\Mvc\Application as PhalconApplication;
use Phalcon\Mvc\Model\Manager as ModelsManager;
use Phalcon\Mvc\Model\MetaData\Strategy\Annotations as StrategyAnnotations;
use Phalcon\Mvc\Model\Transaction\Manager as TxManager;
use Phalcon\Mvc\Router\Annotations as RouterAnnotations;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\Url;
use Phalcon\Registry;
use Phalcon\Session\Adapter as SessionAdapter;
use Phalcon\Session\Adapter\Files as SessionFiles;

/**
 * Application class.
 *
 * @category  PhalconEye
 * @package   Engine
 * @author    Ivan Vorontsov <ivan.vorontsov@phalconeye.com>
 * @copyright 2013-2014 PhalconEye Team
 * @license   New BSD License
 * @link      http://phalconeye.com/
 *
 * @TODO      Refactor this.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends PhalconApplication
{
    const
        /**
         * Default module.
         */
        SYSTEM_DEFAULT_MODULE = 'core';

    /**
     * Application configuration.
     *
     * @var Config
     */
    protected $_config;

    /**
     * Loaders for different modes.
     *
     * @var array
     */
    private $_loaders =
        [
            'normal' => [
                'environment',
                'cache',
                'annotations',
                'database',
                'router',
                'session',
                'flash',
                'engine'
            ],
            'console' => [
                'environment',
                'database',
                'cache',
                'engine'
            ],
            'session' => [
                'cache',
                'database',
                'session'
            ],
        ];

    /**
     * Constructor.
     */
    public function __construct()
    {
        /**
         * Create default DI.
         */
        $di = new DI\FactoryDefault();

        /**
         * Get config.
         */
        $this->_config = Config::factory();

        if (!$this->_config->application->installed) {
            define('CHECK_REQUIREMENTS', true);
            require_once(PUBLIC_PATH . '/requirements.php');
        }

        /**
         * Setup Registry.
         */
        $registry = new Registry();
        $registry->modules = array_merge(
            [self::SYSTEM_DEFAULT_MODULE, 'user'],
            $this->_config->modules->toArray()
        );

        $registry->directories = (object)[
            'engine' => ROOT_PATH . '/app/engine/',
            'modules' => ROOT_PATH . '/app/modules/',
            'plugins' => ROOT_PATH . '/app/plugins/',
            'widgets' => ROOT_PATH . '/app/widgets/',
            'libraries' => ROOT_PATH . '/app/libraries/'
        ];

        $di->set('registry', $registry);

        // Store config in the DI container.
        $di->setShared('config', $this->_config);
        parent::__construct($di);
    }

    /**
     * Runs the application, performing all initializations.
     *
     * @param string $mode Mode name.
     *
     * @return void
     */
    public function run($mode = 'normal')
    {
        if (empty($this->_loaders[$mode])) {
            $mode = 'normal';
        }

        // Set application main objects.
        $di = $this->_dependencyInjector;
        $di->setShared('app', $this);
        $config = $this->_config;
        $eventsManager = new EventsManager();
        $this->setEventsManager($eventsManager);

        // Init base systems first.
        $this->_initLogger($di, $config);
        $this->_initLoader($di, $config, $eventsManager);

        $this->_attachEngineEvents($eventsManager, $config);

        // Init services and engine system.
        foreach ($this->_loaders[$mode] as $service) {
            $serviceName = ucfirst($service);
            $eventsManager->fire('init:before' . $serviceName, null);
            $result = $this->{'_init' . $serviceName}($di, $config, $eventsManager);
            $eventsManager->fire('init:after' . $serviceName, $result);
        }

        $di->setShared('eventsManager', $eventsManager);
    }

    /**
     * Init logger.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function _initLogger($di, $config)
    {
        if ($config->application->logger->enabled) {
            $di->set(
                'logger',
                function ($file = 'main', $format = null) use ($config) {
                    $logger = new File($config->application->logger->path . APPLICATION_STAGE . '.' . $file . '.log');
                    $formatter = new FormatterLine(($format ? $format : $config->application->logger->format));
                    $logger->setFormatter($formatter);

                    return $logger;
                },
                false
            );
        }
    }

    /**
     * Init loader.
     *
     * @param DI            $di            Dependency Injection.
     * @param Config        $config        Config object.
     * @param EventsManager $eventsManager Event manager.
     *
     * @return Loader
     */
    protected function _initLoader($di, $config, $eventsManager)
    {
        // Add all required namespaces and modules.
        $registry = $di->get('registry');
        $namespaces = [];
        $bootstraps = [];
        foreach ($registry->modules as $module) {
            $moduleName = ucfirst($module);
            $namespaces[$moduleName] = $registry->directories->modules . $moduleName;
            $bootstraps[$module] = $moduleName . '\Bootstrap';
        }

        $namespaces['Engine'] = $registry->directories->engine;
        $namespaces['Plugin'] = $registry->directories->plugins;
        $namespaces['Widget'] = $registry->directories->widgets;
        $namespaces['Library'] = $registry->directories->libraries;

        $loader = new Loader();
        $loader->registerNamespaces($namespaces);

        if ($config->application->debug && $config->application->installed) {
            $loader->setEventsManager($eventsManager);
        }

        $loader->register();
        $this->registerModules($bootstraps);
        $di->set('loader', $loader);

        return $loader;
    }


    /**
     * Init environment.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return Url
     */
    protected function _initEnvironment($di, $config)
    {
        set_error_handler(
            function ($errorCode, $errorMessage, $errorFile, $errorLine) {
                throw new \ErrorException($errorMessage, $errorCode, 1, $errorFile, $errorLine);
            }
        );

        set_exception_handler(
            function ($e) use ($di) {
                $errorId = Exception::logError(
                    'Exception',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                );

                if ($di->get('app')->isConsole()) {
                    echo 'Error <' . $errorId . '>: ' . $e->getMessage();
                    return true;
                }

                if (APPLICATION_STAGE == APPLICATION_STAGE_DEVELOPMENT) {
                    $p = new PrettyExceptions();
                    $p->setBaseUri('/assets/js/core/pretty-exceptions/');
                    return $p->handleException($e);
                }

                return true;
            }
        );

        if ($config->application->debug && $config->application->profiler && $config->application->installed) {
            $profiler = new Profiler();
            $di->set('profiler', $profiler);
        }

        /**
         * The URL component is used to generate all kind of urls in the
         * application
         */
        $url = new Url();
        $url->setBaseUri($config->application->baseUri);
        $di->set('url', $url);

        return $url;
    }

    /**
     * Init modules and register them.
     *
     * @param array $modules Modules bootstrap classes.
     * @param null  $merge   Merge with existing.
     *
     * @return $this
     */
    public function registerModules($modules, $merge = null)
    {
        $bootstraps = [];
        $di = $this->getDI();
        foreach ($modules as $moduleName => $moduleClass) {
            if (isset($this->_modules[$moduleName])) {
                continue;
            }

            $bootstrap = new $moduleClass($di, $this->getEventsManager());
            $bootstraps[$moduleName] = function () use ($bootstrap, $di) {
                $bootstrap->registerServices();

                return $bootstrap;
            };
        }

        return parent::registerModules($bootstraps, $merge);
    }

    /**
     * Attach required events.
     *
     * @param EventsManager $eventsManager Events manager object.
     * @param Config        $config        Application configuration.
     *
     * @return void
     */
    protected function _attachEngineEvents($eventsManager, $config)
    {
        // Attach modules plugins events.
        $events = $config->events->toArray();
        $cache = [];
        foreach ($events as $item) {
            list ($class, $event) = explode('=', $item);
            if (isset($cache[$class])) {
                $object = $cache[$class];
            } else {
                $object = new $class();
                $cache[$class] = $object;
            }
            $eventsManager->attach($event, $object);
        }
    }

    /**
     * Get application output.
     *
     * @return string
     */
    public function getOutput()
    {
        return $this->handle()->getContent();
    }

    /**
     * Clear application cache.
     *
     * @return void
     */
    public function clearCache()
    {
        $viewCache = $this->_dependencyInjector->get('viewCache');
        $cacheOutput = $this->_dependencyInjector->get('cacheOutput');
        $cacheData = $this->_dependencyInjector->get('cacheData');
        $modelsCache = $this->_dependencyInjector->get('modelsCache');
        $config = $this->_dependencyInjector->get('config');

        $keys = $viewCache->queryKeys();
        foreach ($keys as $key) {
            $viewCache->delete($key);
        }

        $keys = $cacheOutput->queryKeys();
        foreach ($keys as $key) {
            $cacheOutput->delete($key);
        }

        $keys = $cacheData->queryKeys();
        foreach ($keys as $key) {
            $cacheData->delete($key);
        }

        $keys = $modelsCache->queryKeys();
        foreach ($keys as $key) {
            $modelsCache->delete($key);
        }

        // Files deleter helper.
        $deleteFiles = function ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        };

        // Clear files cache.
        $deleteFiles(glob($config->application->cache->cacheDir . '*'));

        // Clear view cache.
        $deleteFiles(glob($config->application->view->compiledPath . '*'));

        // Clear metadata cache.
        if ($config->application->metadata && $config->application->metadata->metaDataDir) {
            $deleteFiles(glob($config->application->metadata->metaDataDir . '*'));
        }

        // Clear annotations cache.
        if ($config->application->annotations && $config->application->annotations->annotationsDir) {
            $deleteFiles(glob($config->application->annotations->annotationsDir . '*'));
        }

        // Clear assets.
        $this->_dependencyInjector->getShared('assets')->clear(true);
    }

    /**
     * Check if application is used from console.
     *
     * @return bool
     */
    public function isConsole()
    {
        return (php_sapi_name() == 'cli');
    }

    /**
     * Init annotations.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function _initAnnotations($di, $config)
    {
        $di->set(
            'annotations',
            function () use ($config) {
                if (!$config->application->debug && isset($config->application->annotations)) {
                    $annotationsAdapter = '\Phalcon\Annotations\Adapter\\' . $config->application->annotations->adapter;
                    $adapter = new $annotationsAdapter($config->application->annotations->toArray());
//                    $adapter = new Anot($config->application->annotations->toArray());
                } else {
                    $adapter = new AnnotationsMemory();
                }

                return $adapter;
            },
            true
        );
    }

    /**
     * Init router.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return Router
     */
    protected function _initRouter($di, $config)
    {
        $defaultModuleName = ucfirst(self::SYSTEM_DEFAULT_MODULE);

        // Check installation.
        if (!$config->application->installed) {
            $router = new RouterAnnotations(false);
            $router->setDefaultModule(self::SYSTEM_DEFAULT_MODULE);
            $router->setDefaultNamespace($defaultModuleName . '\Controller');
            $router->setDefaultController("Install");
            $router->setDefaultAction("index");
            $router->addModuleResource(self::SYSTEM_DEFAULT_MODULE, $defaultModuleName . '\Controller\Install');
            $di->set('installationRequired', true);
            $di->set('router', $router);

            return;
        }

        $routerCacheKey = 'router_data.cache';
        $cacheData = $di->get('cacheData');
        $router = $cacheData->get($routerCacheKey);

        if ($config->application->debug || $router === null) {
            $saveToCache = ($router === null);

            // Load all controllers of all modules for routing system.
            $modules = $di->get('registry')->modules;

            // Use the annotations router.
            $router = new RouterAnnotations(true);
            $router->setDefaultModule(self::SYSTEM_DEFAULT_MODULE);
            $router->setDefaultNamespace(ucfirst(self::SYSTEM_DEFAULT_MODULE) . '\Controller');
            $router->setDefaultController("Index");
            $router->setDefaultAction("index");

            // Read the annotations from controllers.
            foreach ($modules as $module) {
                $moduleName = ucfirst($module);

                // Get all file names.
                $files = scandir($di->get('registry')->directories->modules . $moduleName . '/Controller');

                // Iterate files.
                foreach ($files as $file) {
                    if ($file == "." || $file == ".." || strpos($file, 'Controller.php') === false) {
                        continue;
                    }

                    $controller = $moduleName . '\Controller\\' . str_replace('Controller.php', '', $file);
                    $router->addModuleResource(strtolower($module), $controller);
                }
            }
            if ($saveToCache) {
                $cacheData->save($routerCacheKey, $router, 2592000); // 30 days cache
            }
        }

        $di->set('router', $router);
        return $router;
    }

    /**
     * Init database.
     *
     * @param DI            $di            Dependency Injection.
     * @param Config        $config        Config object.
     * @param EventsManager $eventsManager Event manager.
     *
     * @return Pdo
     */
    protected function _initDatabase($di, $config, $eventsManager)
    {
        if (!$config->application->installed) {
            return;
        }

        $adapter = '\Phalcon\Db\Adapter\Pdo\\' . $config->database->adapter;
        /** @var Pdo $connection */
        $connection = new $adapter(
            [
                "host" => $config->database->host,
                "username" => $config->database->username,
                "password" => $config->database->password,
                "dbname" => $config->database->dbname,
            ]
        );

        if ($config->application->debug) {
            // Attach logger & profiler.
            $logger = new File($config->application->logger->path . "db.log");
            $profiler = new DatabaseProfiler();

            $eventsManager->attach(
                'db',
                function ($event, $connection) use ($logger, $profiler) {
                    if ($event->getType() == 'beforeQuery') {
                        $statement = $connection->getSQLStatement();
                        $logger->log($statement, Logger::INFO);
                        $profiler->startProfile($statement);
                    }
                    if ($event->getType() == 'afterQuery') {
                        // Stop the active profile.
                        $profiler->stopProfile();
                    }
                }
            );

            if ($config->application->profiler && $di->has('profiler')) {
                $di->get('profiler')->setDbProfiler($profiler);
            }
            $connection->setEventsManager($eventsManager);
        }

        $di->set('db', $connection);
        $di->set(
            'modelsManager',
            function () use ($config, $eventsManager) {
                $modelsManager = new ModelsManager();
                $modelsManager->setEventsManager($eventsManager);

                // Attach a listener to models-manager
                $eventsManager->attach('modelsManager', new ModelAnnotationsInitializer());

                return $modelsManager;
            },
            true
        );

        /**
         * If the configuration specify the use of metadata adapter use it or use memory otherwise.
         */
        $di->set(
            'modelsMetadata',
            function () use ($config) {
                if (!$config->application->debug && isset($config->application->metadata)) {
                    $metaDataConfig = $config->application->metadata;
                    $metadataAdapter = '\Phalcon\Mvc\Model\Metadata\\' . $metaDataConfig->adapter;
                    $metaData = new $metadataAdapter($config->application->metadata->toArray());
                } else {
                    $metaData = new \Phalcon\Mvc\Model\MetaData\Memory();
                }

                $metaData->setStrategy(new StrategyAnnotations());

                return $metaData;
            },
            true
        );

        return $connection;
    }

    /**
     * Init session.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return SessionAdapter
     */
    protected function _initSession($di, $config)
    {
        if (!isset($config->application->session)) {
            $session = new SessionFiles();
        } else {
            $adapterClass = 'Phalcon\Session\Adapter\\' . $config->application->session->adapter;
            $session = new $adapterClass($config->application->session->toArray());
        }

        $session->start();
        $di->setShared('session', $session);
        return $session;
    }

    /**
     * Init cache.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function _initCache($di, $config)
    {
        if (!$config->application->debug) {
            // Get the parameters.
            $cacheAdapter = '\Phalcon\Cache\Backend\\' . $config->application->cache->adapter;
            $frontEndOptions = ['lifetime' => $config->application->cache->lifetime];
            $backEndOptions = $config->application->cache->toArray();
            $frontOutputCache = new CacheOutput($frontEndOptions);
            $frontDataCache = new CacheData($frontEndOptions);

            // Cache:View.
            $di->set(
                'viewCache',
                function () use ($cacheAdapter, $frontOutputCache, $backEndOptions) {
                    return new $cacheAdapter($frontOutputCache, $backEndOptions);
                }
            );

            // Cache:Output.
            $cacheOutput = new $cacheAdapter($frontOutputCache, $backEndOptions);
            $di->set('cacheOutput', $cacheOutput, true);

            // Cache:Data.
            $cacheData = new $cacheAdapter($frontDataCache, $backEndOptions);
            $di->set('cacheData', $cacheData, true);

            // Cache:Models.
            $cacheModels = new $cacheAdapter($frontDataCache, $backEndOptions);
            $di->set('modelsCache', $cacheModels, true);

        } else {
            // Create a dummy cache for system.
            // System will work correctly and the data will be always current for all adapters.
            $dummyCache = new Dummy(null);
            $di->set('viewCache', $dummyCache);
            $di->set('cacheOutput', $dummyCache);
            $di->set('cacheData', $dummyCache);
            $di->set('modelsCache', $dummyCache);
        }
    }

    /**
     * Init flash messages.
     *
     * @param DI $di Dependency Injection.
     *
     * @return void
     */
    protected function _initFlash($di)
    {
        $di->set(
            'flash',
            function () {
                $flash = new FlashDirect(
                    [
                        'error' => 'alert alert-error',
                        'success' => 'alert alert-success',
                        'notice' => 'alert alert-info',
                    ]
                );

                return $flash;
            }
        );

        $di->set(
            'flashSession',
            function () {
                $flash = new FlashSession(
                    [
                        'error' => 'alert alert-error',
                        'success' => 'alert alert-success',
                        'notice' => 'alert alert-info',
                    ]
                );

                return $flash;
            }
        );
    }

    /**
     * Init engine.
     *
     * @param DI $di Dependency Injection.
     *
     * @return void
     */
    protected function _initEngine($di)
    {
        foreach ($di->get('registry')->modules as $module) {
            // Initialize module api.
            $di->setShared(
                strtolower($module),
                function () use ($module, $di) {
                    return new ApiInjector($module, $di);
                }
            );
        }

        $di->setShared(
            'transactions',
            function () {
                return new TxManager();
            }
        );
        $di->setShared('assets', new AssetsManager($di));
        $di->setShared('widgets', new Catalog());
    }
}