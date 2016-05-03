<?php

use Core\Resolver\ControllerResolver;
use Core\Service\ImageManager;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;
use League\Flysystem\Cached\Storage\Predis as Cache;
use Predis\Client;

$loader = require_once __DIR__ . '/vendor/autoload.php';

$app = new Silex\Application();

/**
 * Define Constants && Load parameters files
 */
define('ROOT_DIR', __DIR__);
define('UPLOAD_DIR', ROOT_DIR . '/var/upload/');
define('TMP_DIR', ROOT_DIR . '/var/tmp/');
define('LOG_DIR', ROOT_DIR . '/var/log  /');

$app['params'] = Yaml::parse(file_get_contents(ROOT_DIR . '/config/parameters.yml'));

if (
    !mkdir(UPLOAD_DIR, 0777, true) ||
    !mkdir(TMP_DIR, 0777, true) ||
    !mkdir(LOG_DIR, 0777, true)
) {
    exit('Failed to create folders...');
}

/**
 * Routes
 */
$app['routes'] = $app->extend('routes', function (RouteCollection $routes) {
    $loader = new YamlFileLoader(new FileLocator(__DIR__ . '/config'));
    $collection = $loader->load('routes.yml');
    $routes->addCollection($collection);
    return $routes;
});


/**
 * Register Fly System Provider
 */
if (getenv('cache') == 0 || !$app['params']['cache']) {
    $adapter = 'League\Flysystem\Adapter\Local';
    $args = [UPLOAD_DIR];
} else {
    $client = new Client('tcp://redis-service:6379');
    $adapter = 'League\Flysystem\Cached\CachedAdapter';
    $args = [
        new League\Flysystem\Adapter\Local(UPLOAD_DIR),
        new Cache($client)
    ];
}

$app->register(new WyriHaximus\SliFly\FlysystemServiceProvider(), [
    'flysystem.filesystems' => [
        'upload_dir' => [
            'adapter' => $adapter,
            'args' => $args
        ],
    ],
]);

/**
 * Monolog Service
 */
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.name' => 'fly-image',
    'monolog.logfile' => LOG_DIR . 'dev.log',
));

$app['resolver'] = $app->share(function () use ($app) {
    return new ControllerResolver($app, $app['logger']);
});

$app['image.manager'] = $app->share(function ($app) {
    return new ImageManager($app['params'], $app['flysystems']['upload_dir'], $app['monolog']);
});

$app['debug'] = true;

return $app;