<?php
/**
 * @package   CleverStyle CMS
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
namespace cs\server;
use
	cs\Language,
	cs\Index,
	cs\Page,
	cs\User;
class Request {
	public $__request_id;
	/**
	 * @param string               $data
	 * @param \React\Http\Request  $request
	 * @param \React\Http\Response $response
	 */
	function __construct ($data, $request, $response) {
		// To clean result of previous execution
		http_response_code(200);
		$this->__request_id = md5(openssl_random_pseudo_bytes(100));
		$_SERVER            = [];
		// TODO: Parse cookie header
		foreach ($request->getHeaders() as $key => $value) {
			if ($key == 'Content-Type') {
				$_SERVER['CONTENT_TYPE'] = $value;
			} else {
				$_SERVER['HTTP_'.strtoupper(strtr($key, '-', '_'))] = $value;
			}
		}
		$_SERVER['REQUEST_METHOD']  = $request->getMethod();
		$_SERVER['REQUEST_URI']     = $request->getPath();
		$_SERVER['QUERY_STRING']    = http_build_query($request->getQuery());
		$_GET                       = $request->getQuery();
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/'.$request->getHttpVersion();
		switch (explode(';', @$_SERVER['HTTP_'])) {
			case 'application/json':
				$_POST = json_decode($data, true);
				break;
			default:
				parse_str($data, $_POST);
		}
		ob_start();
		$_SERVER = new _SERVER($_SERVER);
		Language::instance();
		Index::instance()->__finish();
		Page::instance()->__finish();
		User::instance(true)->__finish();
		$headers = [];
		array_map(function ($header) use (&$headers) {
			$header              = explode(':', $header, 2);
			$headers[$header[0]] = ltrim($header[1]);
		}, headers_list());
		header_remove();
		$response->writeHead(http_response_code(), $headers);
		$response->end(ob_get_clean());
		// TODO: probably, better solution in future
		objects_pool([]);
	}
}