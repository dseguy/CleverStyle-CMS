<?php
/**
 * @package   WebSockets
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
list($protocol, $host) = explode('://', $argv[1], 2);
list($host, $url) = explode('/', $host, 2);
$ROOT    = realpath(__DIR__.'/../../..');
/**
 * Simulate headers of regular request
 */
$_SERVER = [
	'HTTP_HOST'              => $host,
	'HTTP_USER_AGENT'        => 'CleverStyle CMS WebSockets module',
	'SERVER_NAME'            => $host,
	'REMOTE_ADDR'            => '127.0.0.1',
	'DOCUMENT_ROOT'          => $ROOT,
	'SERVER_PROTOCOL'        => 'HTTP/1.1',
	'REQUEST_METHOD'         => 'GET',
	'QUERY_STRING'           => '',
	'REQUEST_URI'            => "/$url",
	'HTTP_X_FORWARDED_PROTO' => $protocol
];
require "$ROOT/index.php";
