<?php
/**
 * @package   CleverStyle CMS
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2011-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
namespace cs;

/**
 * @method static DB instance($check = false)
 */
class DB {
	use
		Singleton;
	const CONNECTIONS_ALL           = null;
	const CONNECTIONS_FAILED        = 0;
	const CONNECTIONS_SUCCESSFUL    = 1;
	const CONNECTIONS_MIRRORS       = 'mirror';
	const MASTER_MIRROR             = -1;
	const MIRROR_MODE_MASTER_MASTER = 0;
	const MIRROR_MODE_MASTER_SLAVE  = 1;
	/**
	 * @var DB\_Abstract[]
	 */
	protected $connections = [];
	/**
	 * @var array
	 */
	protected $successful_connections = [];
	/**
	 * @var array
	 */
	protected $failed_connections = [];
	/**
	 * @var array
	 */
	protected $mirrors = [];
	/**
	 * Get list of connections of specified type
	 *
	 * @param bool|null|string $type One of constants `self::CONNECTIONS_*`
	 *
	 * @return array For `self::CONNECTIONS_ALL` array of successful connections with corresponding objects as values of array<br>
	 *               Otherwise array where keys are database ids and values are strings with information about database
	 */
	function get_connections_list ($type = self::CONNECTIONS_ALL) {
		if ($type == self::CONNECTIONS_FAILED) {
			return $this->failed_connections;
		}
		if ($type == self::CONNECTIONS_SUCCESSFUL) {
			return $this->successful_connections;
		}
		if ($type == self::CONNECTIONS_MIRRORS) {
			return $this->mirrors;
		}
		return $this->connections;
	}
	/**
	 * Total number of executed queries
	 *
	 * @return int
	 */
	function queries () {
		$queries = 0;
		foreach ($this->connections as $c) {
			$queries += $c->queries()['num'];
		}
		return $queries;
	}
	/**
	 * Total time spent on all queries and connections
	 *
	 * @return float
	 */
	function time () {
		$time = 0;
		foreach ($this->connections as $c) {
			$time += $c->connecting_time() + $c->time();
		}
		return $time;
	}
	/**
	 * Get database instance for read queries
	 *
	 * @param int $database_id
	 *
	 * @return DB\_Abstract|False_class Returns instance of False_class on failure
	 */
	function db ($database_id) {
		return $this->generic_connecting($database_id, true);
	}
	/**
	 * Get database instance for read queries
	 *
	 * @param int $database_id
	 *
	 * @return DB\_Abstract|False_class Returns instance of False_class on failure
	 */
	function __get ($database_id) {
		return $this->db($database_id);
	}
	/**
	 * Get database instance for write queries
	 *
	 * @param int $database_id
	 *
	 * @return DB\_Abstract|False_class Returns instance of False_class on failure
	 */
	function db_prime ($database_id) {
		return $this->generic_connecting($database_id, false);
	}
	/**
	 * Get database instance for read queries or process implicit calls to methods of main database instance
	 *
	 * @param int|string $method
	 * @param array      $arguments
	 *
	 * @return DB\_Abstract|False_class Returns instance of False_class on failure
	 */
	function __call ($method, $arguments) {
		if (is_int($method) || $method == '0') {
			return $this->db_prime($method);
		} elseif (method_exists('cs\\DB\\_Abstract', $method)) {
			return call_user_func_array([$this->db(0), $method], $arguments);
		} else {
			return False_class::instance();
		}
	}
	/**
	 * @param int  $database_id
	 * @param bool $read_query
	 *
	 * @return DB\_Abstract|False_class
	 *
	 * @throws \ExitException
	 */
	protected function generic_connecting ($database_id, $read_query) {
		if (!is_int($database_id) && $database_id != '0') {
			return False_class::instance();
		}
		/**
		 * Establish wne connection to the database
		 */
		$connection = $this->connecting($database_id, $read_query);
		/**
		 * If connection fails - try once more
		 */
		if (!$connection) {
			$connection = $this->connecting($database_id, $read_query);
		}
		/**
		 * If failed twice - show error
		 */
		if (!$connection) {
			error_code(500);
			throw new \ExitException;
		}
		return $connection;
	}
	/**
	 * Processing of all DB request
	 *
	 * @param int  $database_id
	 * @param bool $read_query
	 *
	 * @return DB\_Abstract|False_class
	 */
	protected function connecting ($database_id, $read_query = true) {
		/**
		 * If connection found in list of failed connections - return instance of False_class
		 */
		if (isset($this->failed_connections[$database_id])) {
			return False_class::instance();
		}
		/**
		 * If connection to DB mirror already established
		 */
		if (
			$read_query &&
			isset($this->mirrors[$database_id])
		) {
			return $this->mirrors[$database_id];
		}
		/**
		 * If connection already established
		 */
		if (isset($this->connections[$database_id])) {
			return $this->connections[$database_id];
		}
		$Core = Core::instance();
		list($database_settings, $is_mirror) = $this->get_db_connection_settings($database_id, $read_query);
		/**
		 * Establish new connection
		 *
		 * @var DB\_Abstract $connection
		 */
		$engine_class    = "cs\\DB\\$database_settings[type]";
		$connection      = new $engine_class(
			$database_settings['name'],
			$database_settings['user'],
			$database_settings['password'],
			$database_settings['host'],
			$database_settings['charset'],
			$database_settings['prefix']
		);
		$connection_name = ($database_id == 0 ? "Core DB ($Core->db_type)" : $database_id)."/$database_settings[host]/$database_settings[type]";
		unset($engine_class, $database_settings);
		/**
		 * If successfully - add connection to the list of success connections and return instance of DB engine object
		 */
		if (is_object($connection) && $connection->connected()) {
			$this->successful_connections[] = $connection_name;
			$this->$database_id             = $connection;
			if ($is_mirror) {
				$this->mirrors[$database_id] = $connection;
			} else {
				$this->connections[$database_id] = $connection;
			}
			return $connection;
		}
		/**
		 * If failed - add connection to the list of failed connections and log error
		 */
		$this->failed_connections[$database_id] = $connection_name;
		trigger_error(
			$database_id == 0 ? 'Error connecting to core DB of site' : "Error connecting to DB $connection_name",
			E_USER_ERROR
		);
		return False_class::instance();
	}
	/**
	 * Get database connection settings, depending on query type and system configuration settings of main db or one of mirrors might be returned
	 *
	 * @param int  $database_id
	 * @param bool $read_query
	 *
	 * @return array
	 */
	protected function get_db_connection_settings ($database_id, $read_query) {
		$Config = Config::instance();
		$Core   = Core::instance();
		/**
		 * Choose right mirror depending on system configuration
		 */
		$is_mirror    = false;
		$mirror_index = $this->choose_mirror($database_id, $read_query);
		if ($mirror_index === self::MASTER_MIRROR) {
			if ($database_id == 0) {
				$database_settings = [
					'type'     => $Core->db_type,
					'name'     => $Core->db_name,
					'user'     => $Core->db_user,
					'password' => $Core->db_password,
					'host'     => $Core->db_host,
					'charset'  => $Core->db_charset,
					'prefix'   => $Core->db_prefix
				];
			} else {
				$database_settings = $Config->db[$database_id];
			}
		} else {
			$database_settings = $Config->db[$database_id]['mirrors'][$mirror_index];
			$is_mirror         = true;
		}
		return [
			$database_settings,
			$is_mirror
		];
	}
	/**
	 * Choose index of DB mirrors among available
	 *
	 * @param int  $database_id
	 * @param bool $read_query
	 *
	 * @return int
	 */
	protected function choose_mirror ($database_id, $read_query = true) {
		$Config = Config::instance(true);
		/**
		 * $Config might be not initialized, so, check also for `$Config->core`
		 */
		if (
			!$Config->core ||
			!$Config->core['db_balance'] ||
			!isset($Config->db[$database_id]['mirrors'])
		) {
			return self::MASTER_MIRROR;
		}
		$mirrors_count = count($Config->db[$database_id]['mirrors'] ?: []);
		if ($mirrors_count) {
			/**
			 * Main db should be excluded from read requests if writes to mirrors are not allowed
			 */
			$selected_mirror = mt_rand(
				0,
				$read_query && $Config->core['db_mirror_mode'] == self::MIRROR_MODE_MASTER_SLAVE ? $mirrors_count - 1 : $mirrors_count
			);
			/**
			 * Main DB assumed to be in the end of interval, that is why `$select_mirror < $mirrors_count` will correspond to one of available mirrors,
			 * and `$select_mirror == $mirrors_count` to master DB itself
			 */
			if ($selected_mirror < $mirrors_count) {
				return $selected_mirror;
			}
		}
		return self::MASTER_MIRROR;
	}
	/**
	 * Test connection to the DB
	 *
	 * @param int[]|string[] $data Array or string in JSON format of connection parameters
	 *
	 * @return bool
	 */
	function test ($data) {
		$Core = Core::instance();
		if (empty($data)) {
			return false;
		} elseif (is_array_indexed($data)) {
			$Config = Config::instance();
			if (isset($data[1])) {
				$db = $Config->db[$data[0]]['mirrors'][$data[1]];
			} elseif (isset($data[0])) {
				if ($data[0] == 0) {
					$db = [
						'type'     => $Core->db_type,
						'host'     => $Core->db_host,
						'name'     => $Core->db_name,
						'user'     => $Core->db_user,
						'password' => $Core->db_password,
						'charset'  => $Core->db_charset
					];
				} else {
					$db = $Config->db[$data[0]];
				}
			} else {
				return false;
			}
		} else {
			$db = $data;
		}
		if (is_array($db)) {
			errors_off();
			$engine_class = '\\cs\\DB\\'.$db['type'];
			$test         = new $engine_class(
				$db['name'],
				$db['user'],
				$db['password'],
				$db['host'] ?: $Core->db_host,
				$db['charset'] ?: $Core->db_charset
			);
			errors_on();
			return $test->connected();
		} else {
			return false;
		}
	}
}
