<?php
/**
 * @package		CleverStyle CMS
 * @author		Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright	Copyright (c) 2011-2013, Nazar Mokrynskyi
 * @license		MIT License, see license.txt
 */
/**
 * Provides next triggers:<br>
 *  System/User/construct/before<br>
 *
 *  System/User/construct/after<br>
 *
 *  System/User/del_all_sessions<br>
 *  ['id'	=> <i>user_id</i>]<br>
 *
 *  System/User/registration/before<br>
 *  ['email'	=> <i>email</i>]<br>
 *
 *  System/User/registration/after<br>
 *  ['id'	=> <i>user_id</i>]<br>
 *
 *  System/User/registration/confirmation/before<br>
 *  ['reg_key'	=> <i>reg_key</i>]<br>
 *
 *  System/User/registration/confirmation/after<br>
 *  ['id'	=> <i>user_id</i>]<br>
 *
 *  System/User/del_user/before<br>
 *  ['id'	=> <i>user_id</i>]<br>
 *
 *  System/User/del_user/after<br>
 *  ['id'	=> <i>user_id</i>]<br>
 *
 *  System/User/add_bot<br>
 *  ['id'	=> <i>bot_id</i>]<br>
 *
 *  System/User/add_group<br>
 *  ['id'	=> <i>group_id</i>]
 *
 *  System/User/del_group/before<br>
 *  ['id'	=> <i>group_id</i>]
 *
 *  System/User/del_group/after<br>
 *  ['id'	=> <i>group_id</i>]
 *
 *  System/User/get_contacts<br>
 *  [
 * 		'id'		=> <i>user_id</i>,
 * 		'contacts'	=> <i>&$contacts</i>	//Array of user id
 *  ]
 */
namespace	cs;
use			cs\DB\Accessor;
class User extends Accessor {
	protected	$current				= [
					'session'		=> false,
					'is'			=> [
						'admin'			=> false,
						'user'			=> false,
						'bot'			=> false,
						'guest'			=> false,
						'system'		=> false
					]
				],
				$id						= false,	//id of current user
				$update_cache			= [],		//Do we need to update users cache
				$data					= [],		//Local cache of users data
				$data_set				= [],		//Changed users data, at the finish, data in db must be replaced by this data
				$cache					= [],		//Cache with some temporary data
				$init					= false,	//Current state of initialization
				$reg_id					= 0,		//User id after registration
				$users_columns			= [],		//Copy of columns list of users table for internal needs without Cache usage
				$permissions_table		= [];		//Array of all permissions for quick selecting
	/**
	 * Defining user id, type, session, personal settings
	 */
	function __construct () {
		global $Cache, $Config, $Key, $Core, $User;
		$User		= $this;
		$Core->run_trigger('System/User/construct/before');
		if (($this->users_columns = $Cache->{'users/columns'}) === false) {
			$this->users_columns = $Cache->{'users/columns'} = $this->db()->columns('[prefix]users');
		}
		/**
		 * Detecting of current user
		 * Last part in page path - key
		 */
		$rc			= $Config->route;
		if (
			$this->user_agent == 'CleverStyle CMS' &&
			(
				($this->login_attempts(hash('sha224', 0)) < $Config->core['login_attempts_block_count']) ||
				$Config->core['login_attempts_block_count'] == 0
			) &&
			count($rc) > 1 &&
			(
				$key_data = $Key->get(
					$Config->module('System')->db('keys'),
					$key = array_slice($rc, -1)[0],
					true
				)
			) &&
			is_array($key_data)
		) {
			if ($this->current['is']['system'] = ($key_data['url'] == $Config->server['host'].'/'.$Config->server['raw_relative_address'])) {
				$this->current['is']['admin'] = true;
				interface_off();
				$_POST['data'] = _json_decode($_POST['data']);
				$Core->run_trigger('System/User/construct/after');
				return;
			} else {
				$this->current['is']['guest'] = true;
				/**
				 * Simulate a bad sign in to block access
				 */
				$this->login_result(false, hash('sha224', 'system'));
				unset($_POST['data']);
				sleep(1);
			}
		}
		unset($key_data, $key, $rc);
		/**
		 * If session exists
		 */
		if (_getcookie('session')) {
			$this->id = $this->get_session_user();
		/**
		 * Try to detect bot
		 */
		} else {
			/**
			 * Loading bots list
			 */
			if (($bots = $Cache->{'users/bots'}) === false) {
				$bots = $this->db()->qfa(
					"SELECT
						`u`.`id`,
						`u`.`login`,
						`u`.`email`
					FROM `[prefix]users` AS `u`
						INNER JOIN `[prefix]users_groups` AS `g`
					ON `u`.`id` = `g`.`id`
					WHERE
						`g`.`group`		= 3 AND
						`u`.`status`	= 1"
				);
				if (is_array($bots) && !empty($bots)) {
					$Cache->{'users/bots'} = $bots;
				} else {
					$Cache->{'users/bots'} = [];
				}
			}
			/**
			 * For bots: login is user agent, email is IP
			 */
			$bot_hash	= hash('sha224', $this->user_agent.$this->ip);
			/**
			 * If list is not empty - try to find bot
			 */
			if (is_array($bots) && !empty($bots)) {
				/**
				 * Load data
				 */
				if (($this->id = $Cache->{'users/'.$bot_hash}) === false) {
					/**
					 * If no data - try to find bot in list of known bots
					 */
					foreach ($bots as $bot) {
						if (
							$bot['login'] &&
							(
								strpos($this->user_agent, $bot['login']) !== false ||
								_preg_match($bot['login'], $this->user_agent)
							)
						) {
							$this->id	= $bot['id'];
							break;
						}
						if (
							$bot['email'] &&
							(
								$this->ip == $bot['email'] ||
								_preg_match($bot['email'], $this->ip)
							)
						) {
							$this->id	= $bot['id'];
							break;
						}
					}
					unset($bots, $bot, $login, $email);
					/**
					 * If found id - this is bot
					 */
					if ($this->id) {
						$Cache->{'users/'.$bot_hash}	= $this->id;
						/**
						 * Searching for last bot session, if exists - load it, otherwise create new one
						 */
						$last_session					= $this->get_data('last_session');
						$id								= $this->id;
						if ($last_session) {
							$this->get_session_user($last_session);
						}
						if (!$last_session || $this->id == 1) {
							$this->add_session($id);
							$this->set_data('last_session', $this->get_session());
						}
						unset($id, $last_session);
					}
				}
			}
			unset($bots, $bot_hash);
		}
		if (!$this->id) {
			$this->add_session($this->id = 1);
		}
		$this->update_user_is();
		/**
		 * If not guest - apply some individual settings
		 */
		if ($this->id != 1) {
			if ($this->timezone) {
				date_default_timezone_set($this->timezone);
			}
			if ($this->language) {
				if (!_getcookie('language')) {
					_setcookie('language', $this->language);
				}
				global $L;
				$L->change($this->language);
			}
			if ($this->theme) {
				$theme = _json_decode($this->theme);
				if (
					!is_array($theme) &&
					$theme['theme'] &&
					$theme['color_scheme'] &&
					!_getcookie('theme') &&
					!_getcookie('color_scheme')
				) {
					_setcookie('theme', $theme['theme']);
					_setcookie('color_scheme', $theme['color_scheme']);
				}
			}
		}
		/**
		 * Security check for data, sent with POST method
		 */
		$session_id	= $this->get_session();
		if (!$session_id || !isset($_POST['session']) || $_POST['session'] != $session_id) {
			if (
				API &&
				!(
					defined('API_GET_ACCESS') && API_GET_ACCESS
				)
			) {
				global $Page;
				define('ERROR_CODE', 403);
				$Page->error('Invalid user session');
				__finish();
			}
			$_POST = [];
		}
		$this->init	= true;
		$Core->run_trigger('System/User/construct/after');
	}
	/**
	 * Updates information about who is user accessed by methods ::guest() ::bot() ::user() admin() ::system()
	 */
	protected function update_user_is () {
		$this->current['is']['guest']	= false;
		$this->current['is']['bot']		= false;
		$this->current['is']['user']	= false;
		$this->current['is']['admin']	= false;
		$this->current['is']['system']	= false;
		if ($this->id == 1) {
			$this->current['is']['guest'] = true;
		} else {
			global $Config;
			/**
			 * Checking of user type
			 */
			$groups = $this->get_user_groups() ?: [];
			if (in_array(1, $groups)) {
				$this->current['is']['admin']	= $Config->can_be_admin;
				$this->current['is']['user']	= true;
			} elseif (in_array(2, $groups)) {
				$this->current['is']['user']	= true;
			} elseif (in_array(3, $groups)) {
				$this->current['is']['guest']	= true;
				$this->current['is']['bot']		= true;
			}
			unset($groups);
		}
	}
	/**
	 * Get data item of specified user
	 *
	 * @param string|string[]						$item
	 * @param bool|int 								$user	If not specified - current user assumed
	 *
	 * @return bool|string|mixed[]|User_Properties			If <i>$item</i> is integer - User_Properties object will be returned
	 */
	function get ($item, $user = false) {
		if (is_scalar($item) && preg_match('/^[0-9]+$/', $item)) {
			return new User_Properties($item);
		}
		switch ($item) {
			case 'user_agent':
				return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
			case 'ip':
				return $_SERVER['REMOTE_ADDR'];
			case 'forwarded_for':
				return isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : false;
			case 'client_ip':
				return isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : false;
		}
		return $this->get_internal($item, $user);
	}
	/**
	 * Get data item of specified user
	 *
	 * @param string|string[]		$item
	 * @param bool|int 				$user		If not specified - current user assumed
	 * @param bool					$cache_only
	 *
	 * @return bool|string|mixed[]
	 */
	protected function get_internal ($item, $user = false, $cache_only = false) {
		$user = (int)($user ?: $this->id);
		if (!$user) {
			return false;
		}
		global $Cache;
		/**
		 * Reference for simpler usage
		 */
		$data = &$this->data[$user];
		/**
		 * If get an array of values
		 */
		if (is_array($item)) {
			$result = $new_items = [];
			/**
			 * Trying to get value from the local cache, or make up an array of missing values
			 */
			foreach ($item as $i) {
				if (in_array($i, $this->users_columns)) {
					if (($res = $this->get($i, $user, true)) !== false) {
						$result[$i] = $res;
					} else {
						$new_items[] = $i;
					}
				}
			}
			if (empty($new_items)) {
				return $result;
			}
			/**
			 * If there are missing values - get them from the database
			 */
			$new_items	= '`'.implode('`, `', $new_items).'`';
			$res = $this->db()->qf(
				"SELECT $new_items
				FROM `[prefix]users`
				WHERE `id` = '$user'
				LIMIT 1"
			);
			unset($new_items);
			if (is_array($res)) {
				$this->update_cache[$user] = true;
				$data = array_merge((array)$data, $res);
				$result = array_merge($result, $res);
				/**
				 * Sorting the resulting array in the same manner as the input array
				 */
				$res = [];
				foreach ($item as $i) {
					$res[$i] = &$result[$i];
				}
				return $res;
			} else {
				return false;
			}
		/**
		 * If get one value
		 */
		} elseif (in_array($item, $this->users_columns)) {
			/**
			 * Pointer to the beginning of getting the data
			 */
			get_data:
			/**
			 * If data in local cache - return them
			 */
			if (isset($data[$item])) {
				return $data[$item];
			/**
			 * Try to get data from the cache
			 */
			} elseif (!isset($new_data) && ($new_data = $Cache->{'users/'.$user}) !== false && is_array($new_data)) {
				/**
				 * Update the local cache
				 */
				if (is_array($new_data)) {
					$data = array_merge((array)$data, $new_data);
				}
				/**
				 * New attempt of getting the data
				 */
				goto get_data;
			} elseif (!$cache_only) {
				$new_data = $this->db()->qfs(
					"SELECT `$item`
					FROM `[prefix]users`
					WHERE `id` = '$user'
					LIMIT 1"
				);
				if ($new_data !== false) {
					$this->update_cache[$user] = true;
					return $data[$item] = $new_data;
				}
			}
		}
		return false;
	}
	/**
	 * Set data item of specified user
	 *
	 * @param array|string	$item
	 * @param mixed|null	$value
	 * @param bool|int		$user	If not specified - current user assumed
	 *
	 * @return bool
	 */
	function set ($item, $value = null, $user = false) {
		$user = (int)($user ?: $this->id);
		if (!$user) {
			return false;
		}
		if (is_array($item)) {
			foreach ($item as $i => &$v) {
				if (in_array($i, $this->users_columns) && $i != 'id') {
					$this->set($i, $v, $user);
				}
			}
		} elseif (in_array($item, $this->users_columns) && $item != 'id') {
			if ($item == 'login') {
				if ($this->get_id(hash('sha224', $value)) !== false) {
					return false;
				}
			} elseif ($item == 'language') {
				global $L;
				$L->change($value);
				$value	= $L->clanguage;
				_setcookie('language', $value);
			}
			$this->update_cache[$user] = true;
			$this->data[$user][$item] = $value;
			if ($this->init) {
				$this->data_set[$user][$item] = $this->data[$user][$item];
			}
			if ($item == 'login') {
				global $Cache;
				unset($Cache->{'users/'.hash('sha224', $this->$item)});
			} elseif ($item == 'password_hash') {
				$this->del_all_sessions($user);
			}
		}
		return true;
	}
	/**
	 * Get data item of current user
	 *
	 * @param string|string[]		$item
	 *
	 * @return array|bool|string
	 */
	function __get ($item) {
		return $this->get($item);
	}
	/**
	 * Set data item of current user
	 *
	 * @param array|string	$item
	 * @param mixed|null	$value
	 *
	 * @return bool
	 */
	function __set ($item, $value = null) {
		return $this->set($item, $value);
	}
	/**
	 * Getting additional data item(s) of specified user
	 *
	 * @param string|string[]		$item
	 * @param bool|int				$user	If not specified - current user assumed
	 *
	 * @return bool|string|mixed[]
	 */
	function get_data ($item, $user = false) {
		$user	= (int)($user ?: $this->id);
		if (!$user || $user == 1) {
			return false;
		}
		global $Cache;
		if (($data = $Cache->{'users/data/'.$user}) === false || !isset($data[$item])) {
			if (!is_array($data)) {
				$data	= [];
			}
			$data[$item]					= $this->db()->qfs([
				"SELECT `value`
				FROM `[prefix]users_data`
				WHERE
					`id`	= '$user' AND
					`item`	= '%s'",
				$item
			]);
			$Cache->{'users/data/'.$user}	= $data[$item];
		}
		return _json_decode($data[$item]);
	}
	/**
	 * Setting additional data item(s) of specified user
	 *
	 * @param array|string	$item
	 * @param mixed|null	$value
	 * @param bool|int		$user	If not specified - current user assumed
	 *
	 * @return bool
	 */
	function set_data ($item, $value = null, $user = false) {
		$user	= (int)($user ?: $this->id);
		if (!$user || $user == 1) {
			return false;
		}
		global $Cache;
		$result	= $this->db()->q(
			"INSERT INTO `[prefix]users_data`
				(
					`id`,
					`item`,
					`value`
				) VALUES (
					'$user',
					'%s',
					'%s'
				)
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
			$item,
			_json_encode($value)
		);
		unset($Cache->{'users/data/'.$user});
		return $result;
	}
	/**
	 * Deletion of additional data item(s) of specified user
	 *
	 * @param string|string[]		$item
	 * @param bool|int				$user	If not specified - current user assumed
	 *
	 * @return bool|string|string[]
	 */
	function del_data ($item, $user = false) {
		$user	= (int)($user ?: $this->id);
		if (!$user || $user == 1) {
			return false;
		}
		global $Cache;
		$result	= $this->db()->q(
			"DELETE FROM `[prefix]users_data`
			WHERE
				`id`	= '$user' AND
				`item`	= '%s'",
			$item
		);
		unset($Cache->{'users/data/'.$user});
		return $result;
	}
	/**
	 * Returns database index
	 *
	 * @return int
	 */
	protected function cdb () {
		global $Config;
		return $Config->module('System')->db('users');
	}
	/**
	 * Is admin
	 *
	 * @return bool
	 */
	function admin () {
		return $this->current['is']['admin'];
	}
	/**
	 * Is user
	 *
	 * @return bool
	 */
	function user () {
		return $this->current['is']['user'];
	}
	/**
	 * Is guest
	 *
	 * @return bool
	 */
	function guest () {
		return $this->current['is']['guest'];
	}
	/**
	 * Is bot
	 *
	 * @return bool
	 */
	function bot () {
		return $this->current['is']['bot'];
	}
	/**
	 * Is system
	 *
	 * @return bool
	 */
	function system () {
		return $this->current['is']['system'];
	}
	/**
	 * Returns user id by login or email hash (sha224)
	 *
	 * @param  string $login_hash	Login or email hash
	 *
	 * @return bool|int
	 */
	function get_id ($login_hash) {
		if (!preg_match('/^[0-9a-z]{56}$/', $login_hash)) {
			return false;
		}
		global $Cache;
		if (($id = $Cache->{'users/'.$login_hash}) === false) {
			$Cache->{'users/'.$login_hash} = $id = $this->db()->qfs([
				"SELECT `id` FROM `[prefix]users` WHERE `login_hash` = '%1\$s' OR `email_hash` = '%1\$s' LIMIT 1",
				$login_hash
			]);
		}
		return $id && $id != 1 ? $id : false;
	}
	/**
	 * Returns user name or login or email, depending on existed in DB information
	 *
	 * @param  bool|int $user	If not specified - current user assumed
	 *
	 * @return bool|int
	 */
	function username ($user = false) {
		$user = (int)($user ?: $this->id);
		return $this->get('username', $user) ?: ($this->get('login', $user) ?: $this->get('email', $user));
	}
	/**
	 * Search keyword in login, username and email
	 *
	 * @param string		$search_phrase
	 *
	 * @return int[]|bool
	 */
	function search_users ($search_phrase) {
		$search_phrase = trim($search_phrase, "%\n");
		$found_users = $this->db()->qfas([
			"SELECT `id`
			FROM `[prefix]users`
			WHERE
				(
					`login`		LIKE '%1\$s' OR
					`username`	LIKE '%1\$s' OR
					`email`		LIKE '%1\$s'
				) AND
				`status` != '-1'",
			$search_phrase
		]);
		return $found_users;
	}
	/**
	 * Returns permission state for specified user.<br>
	 * Rules: if not denied - allowed
	 *
	 * @param int		$group	Permission group
	 * @param string	$label	Permission label
	 * @param bool|int	$user	If not specified - current user assumed
	 *
	 * @return bool				If permission exists - returns its state for specified user, otherwise for admin permissions returns <b>false</b> and for
	 * 							others <b>true</b>
	 */
	function get_user_permission ($group, $label, $user = false) {
		$user = (int)($user ?: $this->id);
		if ($this->system() || $user == 2) {
			return true;
		}
		if (!$user) {
			return false;
		}
		if (!isset($this->data[$user])) {
			$this->data[$user] = [];
		}
		if (!isset($this->data[$user]['permissions'])) {
			$this->data[$user]['permissions']	= [];
			$permissions						= &$this->data[$user]['permissions'];
			if ($user != 1) {
				$groups							= $this->get_user_groups($user);
				if (is_array($groups)) {
					foreach ($groups as $group_id) {
						$permissions = array_merge($permissions ?: [], $this->get_group_permissions($group_id) ?: []);
					}
				}
				unset($groups, $group_id);
			}
			$permissions						= array_merge($permissions ?: [], $this->get_user_permissions($user) ?: []);
			unset($permissions);
		}
		if (isset($this->get_permissions_table()[$group], $this->get_permissions_table()[$group][$label])) {
			$permission = $this->get_permissions_table()[$group][$label];
			if (isset($this->data[$user]['permissions'][$permission])) {
				return (bool)$this->data[$user]['permissions'][$permission];
			} else {
				return $this->admin() ? true : strpos($group, 'admin/') !== 0;
			}
		} else {
			return true;
		}
	}
	/**
	 * Get array of all permissions state for specified user
	 *
	 * @param bool|int		$user	If not specified - current user assumed
	 *
	 * @return array|bool
	 */
	function get_user_permissions ($user = false) {
		$user = (int)($user ?: $this->id);
		if (!$user) {
			return false;
		}
		return $this->get_any_permissions($user, 'user');
	}
	/**
	 * Set user's permissions according to the given array
	 *
	 * @param array		$data
	 * @param bool|int	$user	If not specified - current user assumed
	 *
	 * @return bool
	 */
	function set_user_permissions ($data, $user = false) {
		$user = (int)($user ?: $this->id);
		if (!$user) {
			return false;
		}
		return $this->set_any_permissions($data, $user, 'user');
	}
	/**
	 * Delete all user's permissions
	 *
	 * @param bool|int	$user	If not specified - current user assumed
	 *
	 * @return bool
	 */
	function del_user_permissions_all ($user = false) {
		$user = (int)($user ?: $this->id);
		if (!$user) {
			return false;
		}
		return $this->del_any_permissions_all($user, 'user');
	}
	/**
	 * Get user groups
	 *
	 * @param bool|int		$user	If not specified - current user assumed
	 *
	 * @return array|bool
	 */
	function get_user_groups ($user = false) {
		$user = (int)($user ?: $this->id);
		if (!$user || $user == 1) {
			return false;
		}
		global $Cache;
		if (($groups = $Cache->{'users/groups/'.$user}) === false) {
			$groups = $this->db()->qfas(
				"SELECT `group`
				FROM `[prefix]users_groups`
				WHERE `id` = '$user'
				ORDER BY `priority` DESC"
			);
			return $Cache->{'users/groups/'.$user} = $groups;
		}
		return $groups;
	}
	/**
	 * Set user groups
	 *
	 * @param array	$data
	 * @param int	$user
	 *
	 * @return bool
	 */
	function set_user_groups ($data, $user) {
		$user		= (int)($user ?: $this->id);
		if (!$user) {
			return false;
		}
		if (!empty($data) && is_array_indexed($data)) {
			foreach ($data as $i => &$group) {
				if (!($group = (int)$group)) {
					unset($data[$i]);
				}
			}
		}
		unset($i, $group);
		$exitsing	= $this->get_user_groups($user);
		$return		= true;
		$insert		= array_diff($data, $exitsing);
		$delete		= array_diff($exitsing, $data);
		unset($exitsing);
		if (!empty($delete)) {
			$delete	= implode(', ', $delete);
			$return	= $return && $this->db_prime()->q("DELETE FROM `[prefix]users_groups` WHERE `id` ='$user' AND `group` IN ($delete)");
		}
		unset($delete);
		if (!empty($insert)) {
			$q		= [];
			foreach ($insert as $group) {
				$q[] = $user."', '".$group;
			}
			unset($group, $insert);
			$q		= implode('), (', $q);
			$return	= $return && $this->db_prime()->q("INSERT INTO `[prefix]users_groups` (`id`, `group`) VALUES ('$q')");
			unset($q);
		}
		$update		= [];
		foreach ($data as $i => $group) {
			$update[] = "UPDATE `[prefix]users_groups` SET `priority` = '$i' WHERE `id` = '$user.' AND `group` = '$group' LIMIT 1";
		}
		$return		= $return && $this->db_prime()->q($update);
		global $Cache;
		unset(
			$Cache->{'users/groups/'.$user},
			$Cache->{'users/permissions/'.$user}
		);
		return $return;
	}
	/**
	 * Add new group
	 *
	 * @param string $title
	 * @param string $description
	 *
	 * @return bool|int
	 */
	function add_group ($title, $description) {
		$title			= $this->db_prime()->s(xap($title, false));
		$description	= $this->db_prime()->s(xap($description, false));
		if (!$title || !$description) {
			return false;
		}
		if ($this->db_prime()->q(
			"INSERT INTO `[prefix]groups`
				(
					`title`,
					`description`
				) VALUES (
					'%s',
					'%s'
				)",
			$title,
			$description
		)) {
			global $Cache, $Core;
			unset($Cache->{'groups/list'});
			$id	= $this->db_prime()->id();
			$Core->run_trigger(
				'System/User/add_group',
				[
					'id'	=> $id
				]
			);
			return $id;
		} else {
			return false;
		}
	}
	/**
	 * Delete group
	 *
	 * @param int	$group
	 *
	 * @return bool
	 */
	function del_group ($group) {
		global $Core;
		$group = (int)$group;
		$Core->run_trigger(
			'System/User/del_group/before',
			[
				'id'	=> $group
			]
		);
		if ($group != 1 && $group != 2 && $group != 3) {
			$return = $this->db_prime()->q([
				"DELETE FROM `[prefix]groups` WHERE `id` = $group",
				"DELETE FROM `[prefix]users_groups` WHERE `group` = $group",
				"DELETE FROM `[prefix]groups_permissions` WHERE `id` = $group"
			]);
			global $Cache;
			unset(
				$Cache->{'users/groups/'.$group},
				$Cache->{'users/permissions'},
				$Cache->{'groups/'.$group},
				$Cache->{'groups/permissions/'.$group},
				$Cache->{'groups/list'}
			);
			$Core->run_trigger(
				'System/User/del_group/after',
				[
					'id'	=> $group
				]
			);
			return (bool)$return;
		} else {
			return false;
		}
	}
	/**
	 * Get list of all groups
	 *
	 * @return array|bool		Every item in form of array('id' => <i>id</i>, 'title' => <i>title</i>, 'description' => <i>description</i>)
	 */
	function get_groups_list () {
		global $Cache;
		if (($groups_list = $Cache->{'groups/list'}) === false) {
			$Cache->{'groups/list'} = $groups_list = $this->db()->qfa(
				"SELECT
					`id`,
					`title`,
					`description`
				FROM `[prefix]groups`"
			);
		}
		return $groups_list;
	}
	/**
	 * Get group data
	 *
	 * @param int					$group
	 * @param bool|string			$item	If <b>false</b> - array will be returned, if title|description|data - corresponding item
	 *
	 * @return array|bool|string
	 */
	function get_group_data ($group, $item = false) {
		global $Cache;
		$group = (int)$group;
		if (!$group) {
			return false;
		}
		if (($group_data = $Cache->{'groups/'.$group}) === false) {
			$group_data = $this->db()->qf(
				"SELECT
					`title`,
					`description`,
					`data`
				FROM `[prefix]groups`
				WHERE `id` = '$group'
				LIMIT 1"
			);
			$group_data['data'] = _json_decode($group_data['data']);
			$Cache->{'groups/'.$group} = $group_data;
		}
		if ($item !== false) {
			if (isset($group_data[$item])) {
				return $group_data[$item];
			} else {
				return false;
			}
		} else {
			return $group_data;
		}
	}
	/**
	 * Set group data
	 *
	 * @param array	$data
	 * @param int	$group
	 *
	 * @return bool
	 */
	function set_group_data ($data, $group) {
		$group = (int)$group;
		if (!$group) {
			return false;
		}
		$update = [];
		if (isset($data['title'])) {
			$update[] = '`title` = '.$this->db_prime()->s(xap($data['title'], false));
		}
		if (isset($data['description'])) {
			$update[] = '`description` = '.$this->db_prime()->s(xap($data['description'], false));
		}
		if (isset($data['data'])) {
			$update[] = '`data` = '.$this->db_prime()->s(_json_encode($data['data']));
		}
		$update	= implode(', ', $update);
		if (!empty($update) && $this->db_prime()->q("UPDATE `[prefix]groups` SET $update WHERE `id` = '$group' LIMIT 1")) {
			global $Cache;
			unset(
				$Cache->{'groups/'.$group},
				$Cache->{'groups/list'}
			);
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Get group permissions
	 *
	 * @param int		$group
	 *
	 * @return array
	 */
	function get_group_permissions ($group) {
		return $this->get_any_permissions($group, 'group');
	}
	/**
	 * Set group permissions
	 *
	 * @param array	$data
	 * @param int	$group
	 *
	 * @return bool
	 */
	function set_group_permissions ($data, $group) {
		return $this->set_any_permissions($data, (int)$group, 'group');
	}
	/**
	 * Delete all permissions of specified group
	 *
	 * @param int	$group
	 *
	 * @return bool
	 */
	function del_group_permissions_all ($group) {
		return $this->del_any_permissions_all((int)$group, 'group');
	}
	/**
	 * Common function for get_user_permissions() and get_group_permissions() because of their similarity
	 *
	 * @param int			$id
	 * @param string		$type
	 *
	 * @return array|bool
	 */
	protected function get_any_permissions ($id, $type) {
		if (!($id = (int)$id)) {
			return false;
		}
		switch ($type) {
			case 'user':
				$table	= '[prefix]users_permissions';
				$path	= 'users/permissions/';
				break;
			case 'group':
				$table	= '[prefix]group_permissions';
				$path	= 'groups/permissions/';
				break;
			default:
				return false;
		}
		global $Cache;
		if (($permissions = $Cache->{$path.$id}) === false) {
			$permissions_array = $this->db()->qfa(
				"SELECT
					`permission`,
					`value`
				FROM `$table`
				WHERE `id` = '$id'"
			);
			if (is_array($permissions_array)) {
				$permissions = [];
				foreach ($permissions_array as $permission) {
					$permissions[$permission['permission']] = (int)(bool)$permission['value'];
				}
				unset($permissions_array, $permission);
				return $Cache->{$path.$id} = $permissions;
			} else {
				return $Cache->{$path.$id} = false;
			}
		}
		return $permissions;
	}
	/**
	 * Common function for set_user_permissions() and set_group_permissions() because of their similarity
	 *
	 * @param array	$data
	 * @param int		$id
	 * @param string	$type
	 *
	 * @return bool
	 */
	protected function set_any_permissions ($data, $id, $type) {
		$id			= (int)$id;
		if (!is_array($data) || empty($data) || !$id) {
			return false;
		}
		switch ($type) {
			case 'user':
				$table	= '[prefix]users_permissions';
				$path	= 'users/permissions/';
				break;
			case 'group':
				$table	= '[prefix]groups_permissions';
				$path	= 'groups/permissions/';
				break;
			default:
				return false;
		}
		$delete = [];
		foreach ($data as $i => $val) {
			if ($val == -1) {
				$delete[] = (int)$i;
				unset($data[$i]);
			}
		}
		unset($i, $val);
		$return = true;
		if (!empty($delete)) {
			$delete	= implode(', ', $delete);
			$return	= $this->db_prime()->q(
				"DELETE FROM `$table` WHERE `id` = '$id' AND `permission` IN ($delete)"
			);
		}
		unset($delete);
		if (!empty($data)) {
			$exitsing	= $this->get_any_permissions($id, $type);
			if (!empty($exitsing)) {
				$update		= [];
				foreach ($exitsing as $permission => $value) {
					if (isset($data[$permission]) && $data[$permission] != $value) {
						$value		= (int)(bool)$data[$permission];
						$update[]	= "UPDATE `$table` SET `value` = '$value' WHERE `permission` = '$permission' AND `id` = '$id'";
					}
					unset($data[$permission]);
				}
				unset($exitsing, $permission, $value);
				if (!empty($update)) {
					$return = $return && $this->db_prime()->q($update);
				}
				unset($update);
			}
			if (!empty($data)) {
				$insert	= [];
				foreach ($data as $permission => $value) {
					$insert[] = $id.', '.(int)$permission.', '.(int)(bool)$value;
				}
				unset($data, $permission, $value);
				if (!empty($insert)) {
					$insert	= implode('), (', $insert);
					$return	= $return && $this->db_prime()->q("INSERT INTO `$table` (`id`, `permission`, `value`) VALUES ($insert)");
				}
			}
		}
		global $Cache;
		unset($Cache->{$path.$id});
		if ($type == 'group') {
			unset($Cache->{'users/permissions'});
		}
		return $return;
	}
	/**
	 * Common function for del_user_permissions_all() and del_group_permissions_all() because of their similarity
	 *
	 * @param int		$id
	 * @param string	$type
	 *
	 * @return bool
	 */
	protected function del_any_permissions_all ($id, $type) {
		$id			= (int)$id;
		if (!$id) {
			return false;
		}
		switch ($type) {
			case 'user':
				$table	= '[prefix]users_permissions';
				$path	= 'users/permissions/';
			break;
			case 'group':
				$table	= '[prefix]groups_permissions';
				$path	= 'groups/permissions/';
			break;
			default:
				return false;
		}
		$return = $this->db_prime()->q("DELETE FROM `$table` WHERE `id` = '$id'");
		if ($return) {
			global $Cache;
			unset($Cache->{$path.$id});
			return true;
		}
		return false;
	}
	/**
	 * Returns array of all permissions grouped by permissions groups
	 *
	 * @return array	Format of array: ['group']['label'] = <i>permission_id</i>
	 */
	function get_permissions_table () {
		if (empty($this->permissions_table)) {
			global $Cache;
			if (($this->permissions_table = $Cache->permissions_table) === false) {
				$this->permissions_table	= [];
				$data						= $this->db()->qfa(
					'SELECT
						`id`,
						`label`,
						`group`
					FROM `[prefix]permissions`'
				);
				foreach ($data as $item) {
					if (!isset($this->permissions_table[$item['group']])) {
						$this->permissions_table[$item['group']] = [];
					}
					$this->permissions_table[$item['group']][$item['label']] = $item['id'];
				}
				unset($data, $item);
				$Cache->permissions_table = $this->permissions_table;
			}
		}
		return $this->permissions_table;
	}
	/**
	 * Deletion of permission table (is used after adding, setting or deletion of permission)
	 */
	function del_permission_table () {
		$this->permissions_table = [];
		global $Cache;
		unset($Cache->permissions_table);
	}
	/**
	 * Add permission
	 *
	 * @param string	$group
	 * @param string	$label
	 *
	 * @return bool|int			Group id or <b>false</b> on failure
	 */
	function add_permission ($group, $label) {
		if ($this->db_prime()->q("INSERT INTO `[prefix]permissions` (`label`, `group`) VALUES ('%s', '%s')", xap($label), xap($group))) {
			$this->del_permission_table();
			return $this->db_prime()->id();
		} else {
			return false;
		}
	}
	/**
	 * Get permission data<br>
	 * If <b>$group</b> or/and <b>$label</b> parameter is specified, <b>$id</b> is ignored.
	 *
	 * @param int		$id
	 * @param string	$group
	 * @param string	$label
	 * @param string	$condition	and|or
	 *
	 * @return array|bool			If only <b>$id</b> specified - result is array of permission data,
	 * 								in other cases result will be array of arrays of corresponding permissions data.
	 */
	function get_permission ($id = null, $group = null, $label = null, $condition = 'and') {
		switch ($condition) {
			case 'or':
				$condition = 'OR';
			break;
			default:
				$condition = 'AND';
			break;
		}
		if ($group !== null && $group && $label !== null && $label) {
			return $this->db()->qfa([
				"SELECT
					`id`,
					`label`,
					`group`
				FROM `[prefix]permissions`
				WHERE
					`group` = '%s' $condition
					`label` = '%s'",
				$group,
				$label
			]);
		} elseif ($group !== null && $group) {
			return $this->db()->qfa([
				"SELECT
					`id`,
					`label`,
					`group`
				FROM `[prefix]permissions`
				WHERE `group` = '%s'",
				$group
			]);
		} elseif ($label !== null && $label) {
			return $this->db()->qfa([
				"SELECT
					`id`,
					`label`,
					`group`
				FROM `[prefix]permissions`
				WHERE `label` = '%s'",
				$label
			]);
		} else {
			$id		= (int)$id;
			if (!$id) {
				return false;
			}
			return $this->db()->qf(
				"SELECT
					`id`,
					`label`,
					`group`
				FROM `[prefix]permissions`
				WHERE `id` = '$id'
				LIMIT 1"
			);
		}
	}
	/**
	 * Set permission
	 *
	 * @param int		$id
	 * @param string	$group
	 * @param string	$label
	 *
	 * @return bool
	 */
	function set_permission ($id, $group, $label) {
		$id		= (int)$id;
		if (!$id) {
			return false;
		}
		$group	= $this->db_prime()->s(xap($group));
		$label	= $this->db_prime()->s(xap($label));
		if ($this->db_prime()->q("UPDATE `[prefix]permissions` SET `label` = '%s', `group` = '%s' WHERE `id` = '$id' LIMIT 1", $label, $group)) {
			$this->del_permission_table();
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Deletion of permission or array of permissions
	 *
	 * @param array|int	$id
	 *
	 * @return bool
	 */
	function del_permission ($id) {
		if (is_array($id) && !empty($id)) {
			foreach ($id as &$item) {
				$item = (int)$item;
			}
			$id = implode(',', $id);
			return $this->db_prime()->q([
				"DELETE FROM `[prefix]permissions` WHERE `id` IN ($id)",
				"DELETE FROM `[prefix]users_permissions` WHERE `permission` IN ($id)",
				"DELETE FROM `[prefix]groups_permissions` WHERE `permission` IN ($id)"
			]);
		}
		$id		= (int)$id;
		if (!$id) {
			return false;
		}
		if ($this->db_prime()->q([
			"DELETE FROM `[prefix]permissions` WHERE `id` = '$id' LIMIT 1",
			"DELETE FROM `[prefix]users_permissions` WHERE `permission` = '$id'",
			"DELETE FROM `[prefix]groups_permissions` WHERE `permission` = '$id'"
		])) {
			global $Cache;
			unset($Cache->{'users/permissions'}, $Cache->{'groups/permissions'});
			$this->del_permission_table();
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Returns current session id
	 *
	 * @return bool|string
	 */
	function get_session () {
		if ($this->bot() && $this->id == 1) {
			return '';
		}
		return $this->current['session'];
	}
	/**
	 * Find the session by id, and return id of owner (user), updates last_login, last_ip and last_online information
	 *
	 * @param string	$session_id
	 *
	 * @return int					User id
	 */
	function get_session_user ($session_id = '') {
		if ($this->bot() && $this->id == 1) {
			return 1;
		}
		if (!$session_id) {
			if (!$this->current['session']) {
				$this->current['session'] = _getcookie('session');
			}
			$session_id = $session_id ?: $this->current['session'];
		}
		if (!preg_match('/^[0-9a-z]{32}$/', $session_id)) {
			return false;
		}
		global $Cache, $Config;
		$result	= false;
		if ($session_id && !($result = $Cache->{'sessions/'.$session_id})) {
			$condition	= $Config->core['remember_user_ip'] ?
				"AND
				`ip`			= '".ip2hex($this->ip)."' AND
				`forwarded_for`	= '".ip2hex($this->forwarded_for)."' AND
				`client_ip`		= '".ip2hex($this->client_ip)."'"
				: '';
			$result	= $this->db()->qf([
				"SELECT
					`user`,
					`expire`,
					`user_agent`,
					`ip`,
					`forwarded_for`,
					`client_ip`
				FROM `[prefix]sessions`
				WHERE
					`id`			= '%s' AND
					`expire`		> '%s' AND
					`user_agent`	= '%s'
					$condition
				LIMIT 1",
				$session_id,
				TIME,
				$this->user_agent
			]);
			unset($condition);
			if ($result) {
				$Cache->{'sessions/'.$session_id} = $result;
			}
		}
		if (!(
			$session_id &&
			is_array($result) &&
			$result['expire'] > TIME &&
			(
				$Cache->{'users/'.$result['user']} ||
				$this->get('id', $result['user'])
			)
		)) {
			$this->add_session(1);
			$this->update_user_is();
			return 1;
		}
		$update	= [];
		/**
		 * Updating last online time
		 */
		if ($result['user'] != 0 && $this->get('last_online', $result['user']) < TIME - $Config->core['online_time'] * $Config->core['update_ratio'] / 100) {
			/**
			 * Updating last login time and ip
			 */
			$time	= TIME;
			if ($this->get('last_online', $result['user']) < TIME - $Config->core['online_time']) {
				$ip			= ip2hex($this->ip);
				$update[]	= "
					UPDATE `[prefix]users`
					SET
						`last_login`	= $time,
						`last_ip`		= '$ip',
						`last_online`	= $time
					WHERE `id` =$result[user]";
				$this->set(
					[
						'last_login'	=> TIME,
						'last_ip'		=> $ip,
						'last_online'	=> TIME
					],
					null,
					$result['user']
				);
				unset($ip);
			} else {
				$update[]	= "
					UPDATE `[prefix]users`
					SET `last_online` = $time
					WHERE `id` = $result[user]";
				$this->set(
					'last_online',
					TIME,
					$result['user']
				);
			}
			unset($time);
		}
		if ($result['expire'] - TIME < $Config->core['session_expire'] * $Config->core['update_ratio'] / 100) {
			$result['expire']	= TIME + $Config->core['session_expire'];
			$update[]			= "
				UPDATE `[prefix]sessions`
				SET `expire` = $result[expire]
				WHERE `id` = '$session_id'
				LIMIT 1";
			$Cache->{'sessions/'.$session_id} = $result;
		}
		if (!empty($update)) {
			$this->db_prime()->q($update);
		}
		$this->update_user_is();
		return $result['user'];
	}
	/**
	 * Create the session for the user with specified id
	 *
	 * @param int	$user
	 *
	 * @return bool
	 */
	function add_session ($user) {
		$user = (int)$user;
		if (!$user) {
			$user = 1;
		}
		if (preg_match('/^[0-9a-z]{32}$/', $this->current['session'])) {
			$this->del_session_internal(null, false);
		}
		/**
		 * Load user data
		 * Return point, runs if user is blocked, inactive, or disabled
		 */
		getting_user_data:
		$data		= $this->get(
			[
				'login',
				'username',
				'language',
				'timezone',
				'status',
				'block_until',
				'avatar'
			],
			$user
		);
		if (is_array($data)) {
			global $Page, $L;
			if ($data['status'] != 1) {
				/**
				 * If user is disabled
				 */
				if ($data['status'] == 0) {
					$Page->warning($L->your_account_disabled);
					/**
					 * Mark user as guest, load data again
					 */
					$this->del_session(null, false);
					goto getting_user_data;
				/**
				 * If user is not active
				 */
				} else {
					$Page->warning($L->your_account_is_not_active);
					/**
					 * Mark user as guest, load data again
					 */
					$this->del_session(null, false);
					goto getting_user_data;
				}
			/**
			 * If user if blocked
			 */
			} elseif ($data['block_until'] > TIME) {
				$Page->warning($L->your_account_blocked_until.' '.date($L->_datetime, $data['block_until']));
				/**
				 * Mark user as guest, load data again
				 */
				$this->del_session(null, false);
				goto getting_user_data;
			}
		} elseif ($this->id != 1) {
			/**
			 * If data was not loaded - mark user as guest, load data again
			 */
			$this->del_session(null, false);
			goto getting_user_data;
		}
		unset($data);
		global $Config;
		/**
		 * Generate hash in cycle, to obtain unique value
		 */
		for ($i = 0; $hash = md5(MICROTIME.uniqid($i, true)); ++$i) {
			if ($this->db_prime()->qf(
				"SELECT `id`
				FROM `[prefix]sessions`
				WHERE `id` = '$hash'
				LIMIT 1"
			)) {
				continue;
			}
			$this->db_prime()->q(
				"INSERT INTO `[prefix]sessions`
					(
						`id`,
						`user`,
						`created`,
						`expire`,
						`user_agent`,
						`ip`,
						`forwarded_for`,
						`client_ip`
					) VALUES (
						'%s',
						'%s',
						'%s',
						'%s',
						'%s',
						'%s',
						'%s',
						'%s'
					)",
				$hash,
				$user,
				TIME,
				TIME + $Config->core['session_expire'],
				$this->user_agent,
				$ip				= ip2hex($this->ip),
				$forwarded_for	= ip2hex($this->forwarded_for),
				$client_ip		= ip2hex($this->client_ip)
			);
			$time						= TIME;
			if ($user != 1) {
				$this->db_prime()->q("UPDATE `[prefix]users` SET `last_login` = $time, `last_online` = $time, `last_ip` = '$ip.' WHERE `id` ='$user'");
			}
			global $Cache;
			$this->current['session']	= $hash;
			$Cache->{'sessions/'.$hash}	= [
				'user'			=> $user,
				'expire'		=> TIME + $Config->core['session_expire'],
				'user_agent'	=> $this->user_agent,
				'ip'			=> $ip,
				'forwarded_for'	=> $forwarded_for,
				'client_ip'		=> $client_ip
			];
			_setcookie('session', $hash, TIME + $Config->core['session_expire']);
			$this->id					= $this->get_session_user();
			if (
				($this->db()->qfs(
					 "SELECT COUNT(`id`)
					 FROM `[prefix]sessions`"
				 ) % $Config->core['inserts_limit']) == 0
			) {
				$this->db_prime()->aq(
					"DELETE FROM `[prefix]sessions`
					WHERE `expire` < $time"
				);
			}
			return true;
		}
		return false;
	}
	/**
	 * Destroying of the session
	 *
	 * @param string	$session_id
	 *
	 * @return bool
	 */
	function del_session ($session_id = null) {
		return $this->del_session_internal($session_id);
	}
	/**
	 * Deletion of the session
	 *
	 * @param string	$session_id
	 * @param bool		$create_guest_session
	 *
	 * @return bool
	 */
	protected function del_session_internal ($session_id = null, $create_guest_session = true) {
		$session_id = $session_id ?: $this->current['session'];
		global $Cache;
		if (!preg_match('/^[0-9a-z]{32}$/', $session_id)) {
			return false;
		}
		unset($Cache->{'sessions/'.$session_id});
		$this->current['session'] = false;
		_setcookie('session', '');
		$result =  $this->db_prime()->q(
			"UPDATE `[prefix]sessions`
			SET
				`expire`	= 0,
				`data`		= ''
			WHERE `id` = '%s'
			LIMIT 1",
			$session_id
		);
		if ($create_guest_session) {
			return $this->add_session(1);
		}
		return $result;
	}
	/**
	 * Deletion of all user sessions
	 *
	 * @param bool|int	$user	If not specified - current user assumed
	 *
	 * @return bool
	 */
	function del_all_sessions ($user = false) {
		global $Cache, $Core;
		$Core->run_trigger(
			'System/User/del_all_sessions',
			[
				'id'	=> $user
			]
		);
		$user = $user ?: $this->id;
		_setcookie('session', '');
		$sessions = $this->db_prime()->qfas(
			"SELECT `id`
			FROM `[prefix]sessions`
			WHERE `user` = '$user'"
		);
		if (is_array($sessions)) {
			$delete = [];
			foreach ($sessions as $session) {
				$delete[] = 'sessions/'.$session;
			}
			$Cache->del($delete);
			unset($delete, $sessions, $session);
		}
		$result = $this->db_prime()->q(
			"UPDATE `[prefix]sessions`
			SET
				`expire`	= 0,
				`data`		= ''
			WHERE `user` = '$user'"
		);
		$this->add_session(1);
		return $result;
	}
	/**
	 * Get data, stored with session
	 *
	 * @param string	$item
	 * @param string	$session_id
	 *
	 * @return bool|mixed
	 *
	 */
	function get_session_data ($item, $session_id = null) {
		$session_id	= $session_id ?: $this->current['session'];
		if (!preg_match('/^[0-9a-z]{32}$/', $session_id)) {
			return false;
		}
		global $Cache;
		if (!($data = $Cache->{'sessions/data/'.$session_id})) {
			$data									= _json_decode(
				$this->db()->qfs([
					"SELECT `data`
					FROM `[prefix]sessions`
					WHERE `id` = '%s'
					LIMIT 1",
					$session_id
				])
			);
			$Cache->{'sessions/data/'.$session_id}	= $data;
		}
		return isset($data[$item]) ? $data[$item] : false;
	}
	/**
	 * Store data with session
	 *
	 * @param string	$item
	 * @param mixed		$value
	 * @param string	$session_id
	 *
	 * @return bool
	 *
	 */
	function set_session_data ($item, $value, $session_id = null) {
		$session_id	= $session_id ?: $this->current['session'];
		if (!preg_match('/^[0-9a-z]{32}$/', $session_id)) {
			return false;
		}
		global $Cache;
		if (!($data = $Cache->{'sessions/data/'.$session_id})) {
			$data									= _json_decode(
				$this->db()->qfs([
					"SELECT `data`
					FROM `[prefix]sessions`
					WHERE `id` = '%s'
					LIMIT 1",
					$session_id
				])
			);
		}
		if (!$data) {
			$data	= [];
		}
		$data[$item]	= $value;
		if ($this->db()->q(
			"UPDATE `[prefix]sessions`
			SET `data` = '%s'
			WHERE `id` = '%s'
			LIMIT 1",
			_json_encode($data),
			$session_id
		)) {
			unset($Cache->{'sessions/data/'.$session_id});
			return true;
		}
		return false;
	}
	/**
	 * Delete data, stored with session
	 *
	 * @param string	$item
	 * @param string	$session_id
	 *
	 * @return bool
	 *
	 */
	function del_session_data ($item, $session_id = null) {
		$session_id	= $session_id ?: $this->current['session'];
		if (!preg_match('/^[0-9a-z]{32}$/', $session_id)) {
			return false;
		}
		global $Cache;
		if (!($data = $Cache->{'sessions/data/'.$session_id})) {
			$data									= _json_decode(
				$this->db()->qfs([
					"SELECT `data`
					FROM `[prefix]sessions`
					WHERE `id` = '%s'
					LIMIT 1",
					$session_id
				])
			);
		}
		if (!isset($data[$item])) {
			return true;
		}
		unset($data[$item]);
		if ($this->db()->q(
			"UPDATE `[prefix]sessions`
			SET `data` = '%s'
			WHERE `id` = '%s'
			LIMIT 1",
			_json_encode($data),
			$session_id
		)) {
			unset($Cache->{'sessions/data/'.$session_id});
			return true;
		}
		return false;
	}
	/**
	 * Check number of login attempts
	 *
	 * @param bool|string	$login_hash
	 *
	 * @return int						Number of attempts
	 */
	function login_attempts ($login_hash = false) {
		$login_hash = $login_hash ?: (isset($_POST['login']) ? $_POST['login'] : false);
		if (!preg_match('/^[0-9a-z]{56}$/', $login_hash)) {
			return false;
		}
		if (isset($this->cache['login_attempts'][$login_hash])) {
			return $this->cache['login_attempts'][$login_hash];
		}
		$time	= TIME;
		$ip		= ip2hex($this->ip);
		$count	= $this->db()->qfs([
			"SELECT COUNT(`expire`)
			FROM `[prefix]logins`
			WHERE
				`expire` > $time AND
				(
					`login_hash` = '%s' OR `ip` = '%s'
				)",
			$login_hash,
			$ip
		]);
		return $count ? $this->cache['login_attempts'][$login_hash] = $count : 0;
	}
	/**
	 * Process login result
	 *
	 * @param bool			$result
	 * @param bool|string	$login_hash
	 */
	function login_result ($result, $login_hash = false) {
		$login_hash = $login_hash ?: (isset($_POST['login']) ? $_POST['login'] : false);
		if (!preg_match('/^[0-9a-z]{56}$/', $login_hash)) {
			return;
		}
		$ip	= ip2hex($this->ip);
		$time	= TIME;
		if ($result) {
			$this->db_prime()->q(
				"UPDATE `[prefix]logins`
				SET `expire` = 0
				WHERE
					`expire` > $time AND
					(
						`login_hash` = '%s' OR `ip` = '%s'
					)",
				$login_hash,
				$ip
			);
		} else {
			global $Config;
			$this->db_prime()->q(
				"INSERT INTO `[prefix]logins`
					(
						`expire`,
						`login_hash`,
						`ip`
					) VALUES (
						'%s',
						'%s',
						'%s'
					)",
				TIME + $Config->core['login_attempts_block_time'],
				$login_hash,
				$ip
			);
			if (isset($this->cache['login_attempts'][$login_hash])) {
				++$this->cache['login_attempts'][$login_hash];
			}
			global $Config;
			if ($this->db_prime()->id() % $Config->core['inserts_limit'] == 0) {
				$this->db_prime()->aq("DELETE FROM `[prefix]logins` WHERE `expire` < $time");
			}
		}
	}
	/**
	 * Processing of user registration
	 *
	 * @param string 				$email
	 * @param bool					$confirmation	If <b>true</b> - default system option is used, if <b>false</b> - registration will be
	 *												finished without necessity of confirmation, independently from default system option
	 *												(is used for manual registration).
	 * @param bool					$autologin		If <b>false</b> - no autologin, if <b>true</b> - according to system configuration
	 *
	 * @return array|bool|string					<b>exists</b>	- if user with such email is already registered<br>
	 * 												<b>error</b>	- if error occured<br>
	 * 												<b>false</b>	- if email is incorrect<br>
	 * 												<b>array(<br>
	 * 												&nbsp;'reg_key'		=> *,</b>	//Registration confirmation key, or <b>true</b>
	 * 																					if confirmation is not required<br>
	 * 												&nbsp;<b>'password'	=> *,</b>	//Automatically generated password<br>
	 * 												&nbsp;<b>'id'		=> *</b>	//Id of registered user in DB<br>
	 * 												<b>)</b>
	 */
	function registration ($email, $confirmation = true, $autologin = true) {
		global $Config, $Core, $Cache;
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return false;
		}
		$this->delete_unconfirmed_users();
		if (!$Core->run_trigger(
			'System/User/registration/before',
			[
				'email'	=> $email
			]
		)) {
			return false;
		}
		$email_hash		= hash('sha224', $email);
		$login			= strstr($email, '@', true);
		$login_hash		= hash('sha224', $login);
		if (in_array($login, _json_decode(file_get_contents(MODULES.'/System/index.json'))['profile']) || $this->get_id($login_hash) !== false) {
			$login		= $email;
			$login_hash	= $email_hash;
		}
		if ($this->db_prime()->qf([
			"SELECT `id`
			FROM `[prefix]users`
			WHERE `email_hash` = '%s'
			LIMIT 1",
			$email_hash
		])) {
			return 'exists';
		}
		$password		= password_generate($Config->core['password_min_length'], $Config->core['password_min_strength']);
		$password_hash	= hash('sha512', hash('sha512', $password).$Core->public_key);
		$reg_key		= md5($password.$this->ip);
		$confirmation	= $confirmation && $Config->core['require_registration_confirmation'];
		if ($this->db_prime()->q(
			"INSERT INTO `[prefix]users` (
				`login`,
				`login_hash`,
				`password_hash`,
				`email`,
				`email_hash`,
				`reg_date`,
				`reg_ip`,
				`reg_key`,
				`status`
			) VALUES (
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s'
			)",
			$login,
			$login_hash,
			$password_hash,
			$email,
			$email_hash,
			TIME,
			ip2hex($this->ip),
			$reg_key,
			!$confirmation ? 1 : -1
		)) {
			$this->reg_id = $this->db_prime()->id();
			if (!$confirmation) {
				$this->set_user_groups([2], $this->reg_id);
			}
			if (!$confirmation && $autologin && $Config->core['autologin_after_registration']) {
				$this->add_session($this->reg_id);
			}
			if ($this->reg_id % $Config->core['inserts_limit'] == 0) {
				$this->db_prime()->aq(
					"DELETE FROM `[prefix]users`
					WHERE
						`login_hash`	= '' AND
						`email_hash`	= '' AND
						`password_hash`	= '' AND
						`status`		= '-1' AND
						`id`			!= 1 AND
						`id`			!= 2"
				);
			}
			if (!$Core->run_trigger(
				'System/User/registration/after',
				[
					'id'	=> $this->reg_id
				]
			)) {
				$this->registration_cancel();
				return false;
			}
			if (!$confirmation) {
				$this->set_user_groups([2], $this->reg_id);
			}
			unset($Cache->{'users/'.$login_hash});
			return [
				'reg_key'	=> !$confirmation ? true : $reg_key,
				'password'	=> $password,
				'id'		=> $this->reg_id
			];
		} else {
			return 'error';
		}
	}
	/**
	 * Confirmation of registration process
	 *
	 * @param string		$reg_key
	 *
	 * @return array|bool				array('id' => <i>id</i>, 'email' => <i>email</i>, 'password' => <i>password</i>) or <b>fasle</b> on failure
	 */
	function registration_confirmation ($reg_key) {
		global $Config, $Core, $Cache;
		if (!preg_match('/^[0-9a-z]{32}$/', $reg_key)) {
			return false;
		}
		if (!$Core->run_trigger(
			'System/User/registration/confirmation/before',
			[
				'reg_key'	=> $reg_key
			]
		)) {
			$this->registration_cancel();
			return false;
		}
		$this->delete_unconfirmed_users();
		$data			= $this->db_prime()->qf(
			"SELECT
				`id`,
				`login_hash`,
				`email`
			FROM `[prefix]users`
			WHERE
				`reg_key`	= '$reg_key' AND
				`status`	= '-1'
			LIMIT 1"
		);
		if (!$data) {
			return false;
		}
		$this->reg_id	= $data['id'];
		$password		= password_generate($Config->core['password_min_length'], $Config->core['password_min_strength']);
		$this->set(
			[
				'password_hash'	=> hash('sha512', hash('sha512', $password).$Core->public_key),
				'status'		=> 1
			],
			null,
			$this->reg_id
		);
		$this->set_user_groups([2], $this->reg_id);
		$this->add_session($this->reg_id);
		if (!$Core->run_trigger(
			'System/User/registration/confirmation/after',
			[
				'id'	=> $this->reg_id
			]
		)) {
			$this->registration_cancel();
			return false;
		}
		unset($Cache->{'users/'.$data['login_hash']});
		return [
			'id'		=> $this->reg_id,
			'email'		=> $data['email'],
			'password'	=> $password
		];
	}
	/**
	 * Canceling of bad registration
	 */
	function registration_cancel () {
		if ($this->reg_id == 0) {
			return;
		}
		$this->add_session(1);
		$this->del_user($this->reg_id);
		$this->reg_id = 0;
	}
	/**
	 * Checks for unconfirmed registrations and deletes expired
	 */
	protected function delete_unconfirmed_users () {
		global $Config;
		$reg_date		= TIME - $Config->core['registration_confirmation_time'] * 86400;	//1 day = 86400 seconds
		$ids			= $this->db_prime()->qfas(
			"SELECT `id`
			FROM `[prefix]users`
			WHERE
				`last_login`	= 0 AND
				`status`		= '-1' AND
				`reg_date`		< $reg_date"
		);
		$this->del_user($ids);

	}
	/**
	 * Restoring of password
	 *
	 * @param int			$user
	 *
	 * @return bool|string			Key for confirmation or <b>false</b> on failure
	 */
	function restore_password ($user) {
		if ($user && $user != 1) {
			$reg_key		= md5(MICROTIME.$this->ip);
			if ($this->set('reg_key', $reg_key, $user)) {
				$data					= $this->get('data', $user);
				global $Config;
				$data['restore_until']	= TIME + $Config->core['registration_confirmation_time'] * 86400;
				if ($this->set('data', $data, $user)) {
					return $reg_key;
				}
			}
		}
		return false;
	}
	/**
	 * Confirmation of password restoring process
	 *
	 * @param string		$key
	 *
	 * @return bool|string			array('id' => <i>id</i>, 'password' => <i>password</i>) or <b>fasle</b> on failure
	 */
	function restore_password_confirmation ($key) {
		global $Config, $Core;
		if (!preg_match('/^[0-9a-z]{32}$/', $key)) {
			return false;
		}
		$id			= $this->db_prime()->qfs([
			"SELECT `id`
			FROM `[prefix]users`
			WHERE
				`reg_key`	= '%s' AND
				`status`	= '1'
			LIMIT 1",
			$key
		]);
		if (!$id) {
			return false;
		}
		$data		= $this->get('data', $id);
		if (!isset($data['restore_until'])) {
			return false;
		} elseif ($data['restore_until'] < TIME) {
			unset($data['restore_until']);
			$this->set('data', $data, $id);
			return false;
		}
		unset($data['restore_until']);
		$password	= password_generate($Config->core['password_min_length'], $Config->core['password_min_strength']);
		$this->set(
			[
				'password_hash'	=> hash('sha512', hash('sha512', $password).$Core->public_key),
				'data'			=> $data
			],
			null,
			$id
		);
		$this->add_session($id);
		return [
			'id'		=> $id,
			'password'	=> $password
		];
	}
	/**
	 * Delete specified user or array of users
	 *
	 * @param array|int	$user	User id or array of users ids
	 */
	function del_user ($user) {
		$this->del_user_internal($user);
	}
	/**
	 * Delete specified user or array of users
	 *
	 * @param array|int	$user
	 * @param bool		$update
	 */
	protected function del_user_internal ($user, $update = true) {
		global $Cache, $Core;
		$Core->run_trigger(
			'System/User/del_user/before',
			[
				'id'	=> $user
			]
		);
		if (is_array($user)) {
			foreach ($user as $id) {
				$this->del_user_internal($id, false);
			}
			$user = implode(',', $user);
			$this->db_prime()->q(
				"UPDATE `[prefix]users`
				SET
					`login`			= null,
					`login_hash`	= null,
					`username`		= 'deleted',
					`password_hash`	= null,
					`email`			= null,
					`email_hash`	= null,
					`reg_date`		= 0,
					`reg_ip`		= null,
					`reg_key`		= null,
					`status`		= '-1',
					`data`			= ''
				WHERE `id` IN ($user)"
			);
			unset($Cache->users);
			return;
		}
		$user = (int)$user;
		if (!$user) {
			return;
		}
		$this->set_user_groups([], $user);
		$this->del_user_permissions_all($user);
		if ($update) {
			unset(
				$Cache->{'users/'.hash('sha224', $this->get('login'), $user)},
				$Cache->{'users/'.$user}
			);
			$this->db_prime()->q(
				"UPDATE `[prefix]users`
				SET
					`login`			= null,
					`login_hash`	= null,
					`username`		= 'deleted',
					`password_hash`	= null,
					`email`			= null,
					`email_hash`	= null,
					`reg_date`		= 0,
					`reg_ip`		= null,
					`reg_key`		= null,
					`status`		= '-1'
				WHERE `id` = $user
				LIMIT 1"
			);
			$Core->run_trigger(
				'System/User/del_user/after',
				[
					'id'	=> $user
				]
			);
		}
	}
	/**
	 * Bots addition
	 *
	 * @param string	$name		Bot name
	 * @param string	$user_agent	User Agent string or regular expression
	 * @param string	$ip			IP string or regular expression
	 *
	 * @return bool|int				Bot <b>id</b> in DB or <b>false</b> on failure
	 */
	function add_bot ($name, $user_agent, $ip) {
		if ($this->db_prime()->q(
			"INSERT INTO `[prefix]users`
				(
					`username`,
					`login`,
					`email`,
					`status`
				) VALUES (
					'%s',
					'%s',
					'%s',
					1
				)",
			xap($name),
			xap($user_agent),
			xap($ip)
		)) {
			$id	= $this->db_prime()->id();
			$this->set_user_groups([3], $id);
			global $Core;
			$Core->run_trigger(
				'System/User/add_bot',
				[
					'id'	=> $id
				]
			);
			return $id;
		} else {
			return false;
		}
	}
	/**
	 * Bots editing
	 *
	 * @param int		$id			Bot it
	 * @param string	$name		Bot name
	 * @param string	$user_agent	User Agent string or regular expression
	 * @param string	$ip			IP string or regular expression
	 *
	 * @return bool|int				Bot <b>id</b> in DB or <b>false</b> on failure
	 */
	function set_bot ($id, $name, $user_agent, $ip) {
		$result	= $this->set(
			[
				'username'	=> $name,
				'login'		=> $user_agent,
				'email'		=> $ip
			],
			'',
			$id
		);
		global $Cache;
		unset($Cache->{'users/bots'});
		return $result;
	}
	/**
	 * Delete specified bot or array of bots
	 *
	 * @param array|int	$bot	Bot id or array of bots ids
	 */
	function del_bot ($bot) {
		$this->del_user($bot);
		global $Cache;
		unset($Cache->{'users/bots'});
	}
	/**
	 * Returns array of users columns, available for getting of data
	 *
	 * @return array
	 */
	function get_users_columns () {
		return $this->users_columns;
	}
	/**
	 * Do not track checking
	 *
	 * @return bool	<b>true</b> if tracking is not desired, <b>false</b> otherwise
	 */
	function dnt () {
		return isset($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] == 1;
	}
	/**
	 * Returns array of user id, that are associated as contacts by other modules
	 *
	 * @param	bool|int	$user	If not specified - current user assumed
	 *
	 * @return	int[]				Array of user id
	 */
	function get_contacts ($user = false) {
		$user = (int)($user ?: $this->id);
		if (!$user || $user == 1) {
			return [];
		}
		global $Core;
		$contacts	= [];
		$Core->run_trigger(
			'System/User/get_contacts',
			[
				'id'		=> $user,
				'contacts'	=> &$contacts
			]
		);
		return array_unique($contacts);
	}
	/**
	 * Cloning restriction
	 *
	 * @final
	 */
	function __clone () {}
	/**
	 * Saving changes of cache and users data
	 */
	function __finish () {
		global $Cache;
		/**
		 * Updating users data
		 */
		if (is_array($this->data_set) && !empty($this->data_set)) {
			$update = [];
			foreach ($this->data_set as $id => &$data_set) {
				$data = [];
				foreach ($data_set as $i => &$val) {
					if (in_array($i, $this->users_columns) && $i != 'id') {
						if ($i == 'about') {
							$val = xap($val, true);
						} else {
							$val = xap($val, false);
						}
						$data[] = '`'.$i.'` = '.$this->db_prime()->s($val);
					} elseif ($i != 'id') {
						unset($data_set[$i]);
					}
				}
				if (!empty($data)) {
					$data		= implode(', ', $data);
					$update[]	= "UPDATE `[prefix]users`
						SET $data
						WHERE `id` = '$id'";
					unset($i, $val, $data);
				}
			}
			if (!empty($update)) {
				$this->db_prime()->q($update);
			}
			unset($update);
		}
		/**
		 * Updating users cache
		 */
		foreach ($this->data as $id => &$data) {
			if (isset($this->update_cache[$id]) && $this->update_cache[$id]) {
				$data['id'] = $id;
				$Cache->{'users/'.$id} = $data;
			}
		}
		$this->update_cache = [];
		unset($id, $data);
		$this->data_set = [];
	}
}
/**
 * For IDE
 */
if (false) {
	global $User;
	$User = new User;
}
/**
 * Class for getting of user information
 */
class User_Properties {
	/**
	 * @var int
	 */
	protected	$id;
	/**
	 * Creating of object and saving user id inside
	 *
	 * @param int $user
	 */
	function __construct ($user) {
		$this->id	= $user;
	}
	/**
	 * Get data item of user
	 *
	 * @param string|string[]		$item
	 *
	 * @return bool|string|mixed[]
	 */
	function get ($item) {
		global $User;
		return $User->get($item, $this->id);
	}
	/**
	 * Set data item of specified user
	 *
	 * @param array|string	$item
	 * @param mixed|null	$value
	 *
	 * @return bool
	 */
	function set ($item, $value = null) {
		global $User;
		return $User->set($item, $value, $this->id);
	}
	/**
	 * Get data item of user
	 *
	 * @param string|string[]		$item
	 *
	 * @return array|bool|string
	 */
	function __get ($item) {
		global $User;
		return $User->get($item, $this->id);
	}
	/**
	 * Returns user name or login or email, depending on existed in DB information
	 *
	 * @return bool|int
	 */
	function username () {
		return $this->get('username') ?: ($this->get('login') ?: $this->get('email'));
	}
	/**
	 * Set data item of user
	 *
	 * @param array|string	$item
	 * @param mixed|null	$value
	 *
	 * @return bool
	 */
	function __set ($item, $value = null) {
		global $User;
		return $User->set($item, $value, $this->id);
	}
	/**
	 * Getting additional data item(s) of specified user
	 *
	 * @param string|string[]		$item
	 *
	 * @return bool|string|mixed[]
	 */
	function get_data ($item) {
		global $User;
		return $User->get_data($item, $this->id);
	}
	/**
	 * Setting additional data item(s) of specified user
	 *
	 * @param array|string	$item
	 * @param mixed|null	$value
	 *
	 * @return bool
	 */
	function set_data ($item, $value = null) {
		global $User;
		return $User->set_data($item, $value, $this->id);
	}
	/**
	 * Deletion of additional data item(s) of specified user
	 *
	 * @param string|string[]		$item
	 *
	 * @return bool|string|string[]
	 */
	function del_data ($item) {
		global $User;
		return $User->del_data($item, $this->id);
	}
}