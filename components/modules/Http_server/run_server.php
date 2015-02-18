<?php
/**
 * @package   Http server
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
/**
 * Before usage run:
 * $ git clone git@github.com:reactphp/http.git
 * $ cd http
 * $ composer install
 *
 * Start server with:
 * $ php server.php 8080
 * Where 8080 is desired port number
 */
use
	cs\modules\Http_server\Request;
/**
 * This is custom loader that includes basic files and defines constants,
 * but do not call any class to leave that all for test cases, and unregisters shutdown function
 */
if (version_compare(PHP_VERSION, '5.4', '<')) {
	exit('CleverStyle CMS require PHP 5.4 or higher');
}
/**
 * Time of start of execution, is used as current time
 */
define('MICROTIME', microtime(true));         //Time in seconds (float)
define('TIME', floor(MICROTIME));             //Time in seconds (integer)
define('DIR', realpath(__DIR__.'/../../..')); //Root directory
chdir(DIR);

require_once __DIR__.'/custom_loader.php';
require_once __DIR__.'/vendor/autoload.php';
$loop   = React\EventLoop\Factory::create();
$socket = new React\Socket\Server($loop);
$http   = new React\Http\Server($socket);

$http->on('request', function (\React\Http\Request $request, \React\Http\Response $response) {
	$request->on(
		'data',
		new Request($request, $response)
	);
});
$socket->listen($argv[1]);
$loop->run();