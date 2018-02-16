<?php
  if($_SERVER['REMOTE_ADDR'] === '0.0.0.0') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    $debug = new \Phalcon\Debug();
    $debug->listen();
  } else 

  if(!defined('APP_PATH'))
    define('APP_PATH', realpath('..') . '/');

  use Phalcon\Loader;
  use Phalcon\Mvc\Router;
  use Phalcon\DI\FactoryDefault;
  use Phalcon\Config\Adapter\Json;
  use Phalcon\Mvc\View;
  use Phalcon\Mvc\View\Engine\Volt;
  use Phalcon\Mvc\Dispatcher;
  use Phalcon\Cache\Backend\Redis;
  use Phalcon\Mvc\Model\MetaData\Redis as RedisMetaData;
  use Phalcon\Session\Adapter\Redis as RedisSession;
  use Phalcon\Db\Adapter\Pdo\Mysql as DbAdapter;
  use Phalcon\Cache\Frontend\Data as FrontData;

  use B\Library\Acl\Controller as AclController;
  use B\Library\System\Controller as System;
  use B\Library\Logger\Controller as Logger;
  use B\Library\Centrifugo\Controller as Centrifugo;
  use B\Library\Helper\RedisHelper;
  use Foolz\SphinxQL\Connection;

  class Application extends Phalcon\Mvc\Application
  {
      public function registerServices()
      {
          $di = new FactoryDefault();

          $di->setShared('modulesNames', function () {
              return [
                  'advert',
                  'mode',
                  'seo',
                  'admin',
                  'api',
                  'profile',
                  'system',
                  'ssi'
              ];
          });

          $di->setShared('config', function () {              

              if (in_array($_SERVER["SERVER_NAME"], ['xn--80abmue.com', 'белка.com']))
                  $json = new Json(APP_PATH . 'conf.json');
              else
                  $json = new Json(APP_PATH . 'conf.dev.json');

              return $json;
          });

          $di->setShared('lang', function () {
              return System::getLangFromRedis(APP_PATH . 'libs/Lang/lang.json');
          });

          $di->setShared('redis', function () {
              $config = $this->get('conf');

              $redis = new \Redis();

              $redis->connect($config->redis->host, $config->redis->port);

              return $redis;
          });

          $di->setShared('sphinx', function () {
              $config = $this->get('conf');

              $conn = new Connection();

              $conn->setParams([
                  'host' => $conf->sphinx->host,
                  'port' => $conf->sphinx->port
              ]);

              return $conn;
          });

          $di->setShared('session', function () {
              $config = $this->get('config');

              $session = new RedisSession([
                  'prefix' => $conf->session_redis->prefix,
                  'host' => $conf->session_redis->host,
                  'port' => $conf->session_redis->port,
                  'lifetime' => $conf->session_redis->lifetime
              ]);

              $session->start();

              return $session;
          });          

          $di->set('db', function () {
              $config = $this->get('config');

              return new DbAdapter([
                  'host' => $conf->host,
                  'port' => $conf->port,
                  'username' => $conf->username,
                  'password' => $conf->pswd,
                  'dbname' => $confi->name,
                  'charset' => 'utf8mb4', 
                  'collate' => 'utf8mb4_general_ci' 
              ]);
          });

          $di->setShared('modelsCache', function ($key = null, $value = null) {
              $frontCache = new FrontData(['lifetime' => 1]);

              $config = $this->get('config');

              $cache = new Redis(
                  $frontCache, [
                      "host" => $conf->redis->host,
                      "port" => $conf->redis->port,
                      "persistent" => false,
                      "index" => 0,
                      "prefix" => 'mo_'
                  ]
              );

              return $cache;
          });

          $di->setShared('evManager', function () {
              $evManager = $this->getShared('eventsManager');

              $evManager->attach('dispatch:beforeExecuteRoute', function ($event, $dispat) {
                  $moduleName = $dispat->getModuleName();
                  $controllerName = $dispat->getControllerName();
                  $actionName = $dispat->getActionName();

                  new AclController(
                      AclController::getCurrentRole(),
                      $moduleName,
                      $controllerName,
                      $actionName
                  );

                  $view = $this->getView();

                  if ($includeFiles = System::getIncludesFiles($moduleName, $controllerName, $actionName))
                      $view->setVars($includeFiles);

                  $arr = explode('\\', $dispatcher->getNamespaceName());
                  $count = count($arr);

                  if ($count == 4)
                      $view->pick("{$moduleName}/{$controllerName}/{$actionName}");
                  else {
                      $pathToView = "{$moduleName}/";

                      for ($i = 4; $i < $count; $i++)
                          $pathToView .= strtolower($arr[$i]) . '/';

                      $pathToView .= "{$controllerName}/{$actionName}";

                      $view->pick($pathToView);
                  }
              });

              return $evManager;
          });

          $di->setShared('modelsMetadata', function () {
              $config = $this->get('config');

              $cache = new RedisMetaData([
                  'prefix' => $conf->modelsMetadata->prefix,
                  'lifetime' => $conf->modelsMetadata->lifetime,
                  'host' => $conf->modelsMetadata->host,
                  'port' => $conf->modelsMetadata->port
              ]);

              return $cache;
          });

          $di->set('router', function () {
              $router = new Router();             

              foreach ($this->get('modulesNames') as $module)
                  require APP_PATH . "modules/{$module}/conf/routes.php";

              return $router;
          });

          $di->setShared('view', function () {
              $view = new View();

              $view->setViewsDir('../views');

              $view->registerEngines([
                  '.volt' => function ($view, $di) {
                      $volt = new Volt($view, $di);

                      $volt->setOptions([
                          'compiledPath' => '../views/system/compiled/',
                          'compiledExtension' => '.volt',
                          'compileAlways' => false
                      ]);

                      return $volt;
                  }
              ]);

              return $view;
          });

          $this->setDI($di);
      }

      public function registerNamespaces()
      {
          $loader = new Loader();

          $loader->registerNamespaces([
              'B\Library\Acl' => '../libs/Acl',
              'B\Library\System' => '../libs/System',
              'B\Library\Logger' => '../libs/Logger',
              'B\Modules\Profile\Models' => '../modules/profile/models'
          ]);

          $loader->register();
      }

      public function main()
      {
          $this->registerNamespaces();
          $this->registerServices();

          foreach ($this->di->get('modulesNames') as $moduleName)
              $modules[$moduleName] = [
                  'className' => 'B\Modules\\' . ucfirst($moduleName) . '\Module',
                  'path' => "../modules/{$moduleName}/Module.php"
              ];

          $this->registerModules($modules);

          try {
              echo $this->handle()->getContent();
          } catch (\Phalcon\Mvc\Dispatcher\Exception $e) {
              Logger::info('Ошибка 404', ['data_exeption' => [
                  'url' => $_SERVER['REQUEST_URI'],
                  'file' => $e->getFile(),
                  'code' => $e->getCode(),
                  'line' => $e->getLine(),
                  'message' => $e->getMessage(),
                  'trace' => $e->getTraceAsString()
              ]]);

              System::redirectTo('404');
          }

      }

  }