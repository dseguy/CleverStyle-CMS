<?php
/**
 * @package   CleverStyle CMS
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2013-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
namespace cs;
use
	cs\DB\Accessor,
	cs\CRUD\Data_model_processing;

/**
 * CRUD trait
 *
 * Provides create/read/update/delete methods for faster development
 */
trait CRUD {
	use
		Accessor,
		Data_model_processing;
	/**
	 * Create item
	 *
	 * @param array $arguments First element `id` can be omitted if it is autoincrement field
	 *
	 * @return false|int|string Id of created item on success, `false` otherwise
	 */
	protected function create ($arguments) {
		//TODO remove in future versions
		if (func_num_args() === 3) {
			return call_user_func([$this, 'create_internal'], func_get_args());
		}
		return $this->create_internal($this->table, $this->data_model, $arguments);
	}
	/**
	 * Create item
	 *
	 * @param string              $table
	 * @param callable[]|string[] $data_model
	 * @param array               $arguments First element `id` can be omitted if it is autoincrement field
	 *
	 * @return false|int|string Id of created item on success (or specified primary key), `false` otherwise
	 */
	private function create_internal ($table, $data_model, $arguments) {
		$insert_id = count($data_model) == count($arguments) ? $arguments[0] : false;
		list($prepared_arguments, $joined_tables) = self::crud_arguments_preparation(
			$insert_id !== false ? $data_model : array_slice($data_model, 1),
			$arguments,
			$insert_id,
			$update_needed
		);
		$columns = "`".implode("`,`", array_keys($prepared_arguments))."`";
		$values  = implode(',', array_fill(0, count($prepared_arguments), "'%s'"));
		$return  = $this->db_prime()->q(
			"INSERT IGNORE INTO `$table`
				(
					$columns
				) VALUES (
					$values
				)",
			$prepared_arguments
		);
		$id      = $insert_id !== false ? $insert_id : $this->db_prime()->id();
		/**
		 * Id might be 0 if insertion failed or if we insert duplicate entry (which is fine since we use 'INSERT IGNORE'
		 */
		if (!$return || $id === 0) {
			return false;
		}
		$this->update_joined_tables($id, $joined_tables);
		$this->find_update_files_tags($id, [], $arguments);
		/**
		 * If on creation request without specified primary key and multilingual fields present - update needed
		 * after creation (there is no id before creation)
		 */
		if ($update_needed) {
			$this->update_internal($table, $data_model, array_merge([$id], $prepared_arguments), false);
		}
		return $id;
	}
	/**
	 * @param int|string $id
	 * @param array      $joined_tables
	 */
	private function update_joined_tables ($id, $joined_tables) {
		$clang = $this->db_prime()->s(Language::instance()->clang, false);
		/**
		 * At first we remove all old data
		 */
		foreach ($this->data_model as $table => $model) {
			if (!isset($model['data_model'])) {
				continue;
			}
			$id_field                 = array_keys($model['data_model'])[0];
			$language_field_condition = isset($model['language_field'])
				? "AND `$model[language_field]` = '$clang'"
				: '';
			$this->db_prime()->q(
				"DELETE FROM `{$this->table}_$table`
				WHERE
					`$id_field`	= '%s'
					$language_field_condition",
				$id
			);
		}
		$id = $this->db_prime()->s($id, false);
		/**
		 * Now walk through all tables and insert new valued
		 */
		foreach ($joined_tables as $table => $model) {
			if (!@$model['data']) {
				continue;
			}
			$fields = "`$model[id_field]`, ";
			/** @noinspection DisconnectedForeachInstructionInspection */
			$values = "'$id'";
			if (isset($model['language_field'])) {
				$fields .= "`$model[language_field]`, ";
				$values .= ",'$clang'";
			}
			$fields .= '`'.implode('`,`', array_keys($model['fields'])).'`';
			$values .= str_repeat(",'%s'", count($model['fields']));
			$this->db_prime()->insert(
				"INSERT INTO `{$this->table}_$table`
					(
						$fields
					) VALUES (
						$values
					)",
				$model['data']
			);
		}
	}
	/**
	 * @deprecated
	 * @todo remove in future versions
	 *
	 * @param array $arguments First element `id` can be omitted if it is autoincrement field
	 *
	 * @return false|int|string Id of created item on success, `false` otherwise
	 */
	protected function create_simple ($arguments) {
		return $this->create($arguments);
	}
	/**
	 * Read item
	 *
	 * @param int|int[]|string|string[] $id
	 *
	 * @return array|false
	 */
	protected function read ($id) {
		//TODO remove in future versions
		if (func_num_args() === 3) {
			return call_user_func([$this, 'read_internal'], func_get_args());
		}
		return $this->read_internal($this->table, $this->data_model, $id);
	}
	/**
	 * Read item
	 *
	 * @param string                    $table
	 * @param callable[]|string[]       $data_model
	 * @param int|int[]|string|string[] $id
	 *
	 * @return array|false
	 */
	private function read_internal ($table, $data_model, $id) {
		if (is_array($id)) {
			foreach ($id as &$i) {
				$i = $this->read_internal($table, $data_model, $i);
			}
			return $id;
		}
		$columns      = array_filter(
			$data_model,
			function ($column) {
				return !isset($column['data_model']);
			}
		);
		$columns      = "`".implode("`,`", array_keys($columns))."`";
		$first_column = array_keys($data_model)[0];
		$data         = $this->db()->qf(
			[
				"SELECT $columns
				FROM `$table`
				WHERE `$first_column` = '%s'
				LIMIT 1",
				$id
			]
		) ?: false;
		if (!$data) {
			return false;
		}
		foreach ($this->data_model as $field => $model) {
			if (isset($model['data_model'])) {
				$data[$field] = $this->read_joined_table($id, $field, $model);
				continue;
			}
			if (!is_string($model)) {
				continue;
			}
			/**
			 * Handle multilingual fields automatically
			 */
			if (strpos($model, 'ml:') === 0) {
				$data[$field] = Text::instance()->process($this->cdb(), $data[$field], true);
			}
			/**
			 * Decode JSON fields
			 */
			if (in_array($model, ['json', 'ml:json'])) {
				$data[$field] = _json_decode($data[$field]);
			}
		}
		return $data;
	}
	/**
	 * @param int|string  $id
	 * @param string      $table
	 * @param array       $model
	 * @param null|string $force_clang
	 *
	 * @return array
	 */
	private function read_joined_table ($id, $table, $model, $force_clang = null) {
		$clang                    = $force_clang ?: $this->db()->s(Language::instance()->clang, false);
		$id_field                 = array_keys($model['data_model'])[0];
		$language_field_condition = isset($model['language_field'])
			? "AND `$model[language_field]` = '$clang'"
			: '';
		$fields                   = '`'.implode('`,`', array_keys($model['data_model'])).'`';
		$rows                     = $this->db_prime()->qfa(
			[
				"SELECT $fields
				FROM `{$this->table}_$table`
				WHERE
						`$id_field`	= '%s'
						$language_field_condition",
				$id
			]
		) ?: [];
		$language_field           = isset($model['language_field']) ? $model['language_field'] : null;
		/**
		 * If no rows found for current language - find another language that should contain some rows
		 */
		if (!$rows) {
			$new_clang = $this->db_prime()->qfs(
				[
					"SELECT `$language_field`
					FROM `{$this->table}_$table`
					WHERE `$id_field`	= '%s'
					LIMIT 1",
					$id
				]
			);
			if ($new_clang && $new_clang != $clang) {
				return $this->read_joined_table($id, $table, $model, $force_clang);
			}
			return [];
		}
		foreach ($rows as &$row) {
			/**
			 * Drop language and id field since they are used internally, not specified by user
			 */
			unset(
				$row[$language_field],
				$row[$id_field]
			);
			foreach ($row as $field => &$value) {
				if ($model['data_model'][$field] == 'json') {
					$value = _json_decode($value);
				}
			}
			if (isset($model['indexed']) && $model['indexed']) {
				$row = array_values($row);
			} elseif (count($row) == 1) {
				/**
				 * If row is array that contains only one item - lets make resulting array flat
				 */
				$row = array_pop($row);
			}
		}
		return $rows;
	}
	/**
	 * @deprecated
	 * @todo remove in future versions
	 *
	 * @param int|int[]|string|string[] $id
	 *
	 * @return array|false
	 */
	protected function read_simple ($id) {
		return $this->read($id);
	}
	/**
	 * Update item
	 *
	 * @param array $arguments
	 *
	 * @return bool
	 */
	protected function update ($arguments) {
		//TODO remove in future versions
		if (func_num_args() === 3) {
			return call_user_func([$this, 'update_internal'], func_get_args());
		}
		return $this->update_internal($this->table, $this->data_model, $arguments);
	}
	/**
	 * Update item
	 *
	 * @param string              $table
	 * @param callable[]|string[] $data_model
	 * @param array               $arguments
	 * @param bool                $files_update
	 *
	 * @return bool
	 */
	private function update_internal ($table, $data_model, $arguments, $files_update = true) {
		$prepared_arguments = $arguments;
		$id                 = array_shift($prepared_arguments);
		if ($files_update) {
			$data_before = $this->read_internal($table, $data_model, $id);
		}
		list($prepared_arguments, $joined_tables) = self::crud_arguments_preparation(array_slice($data_model, 1), $prepared_arguments, $id);
		$columns              = implode(
			',',
			array_map(
				function ($column) {
					return "`$column` = '%s'";
				},
				array_keys($prepared_arguments)
			)
		);
		$prepared_arguments[] = $id;
		$first_column         = array_keys($data_model)[0];
		if (!$this->db_prime()->q(
			"UPDATE `$table`
			SET $columns
			WHERE `$first_column` = '%s'
			LIMIT 1",
			$prepared_arguments
		)
		) {
			return false;
		}
		if ($files_update) {
			$this->update_joined_tables($id, $joined_tables);
			/** @noinspection PhpUndefinedVariableInspection */
			$this->find_update_files_tags($id, $data_before, $arguments);
		}
		return true;
	}
	/**
	 * @deprecated
	 * @todo remove in future versions
	 *
	 * @param array $arguments
	 *
	 * @return bool
	 */
	protected function update_simple ($arguments) {
		return $this->update($arguments);
	}
	/**
	 * Delete item
	 *
	 * @param int|int[]|string|string[] $id
	 *
	 * @return bool
	 */
	protected function delete ($id) {
		//TODO remove in future versions
		if (func_num_args() === 3) {
			return call_user_func([$this, 'delete_internal'], func_get_args());
		}
		return $this->delete_internal($this->table, $this->data_model, $id);
	}
	/**
	 * Delete item
	 *
	 * @param string                    $table
	 * @param callable[]|string[]       $data_model
	 * @param int|int[]|string|string[] $id
	 *
	 * @return bool
	 */
	private function delete_internal ($table, $data_model, $id) {
		$id           = (array)$id;
		$result       = true;
		$multilingual = $this->is_multilingual();
		$first_column = array_keys($data_model)[0];
		foreach ($id as $i) {
			$result =
				$result &&
				$this->db_prime()->q(
					"DELETE FROM `$table`
					WHERE `$first_column` = '%s'
					LIMIT 1",
					$i
				);
			/**
			 * If there are multilingual fields - handle multilingual deleting of fields automatically
			 */
			if ($multilingual) {
				/** @noinspection ForeachOnArrayComponentsInspection */
				foreach (array_keys($this->data_model) as $field) {
					if (strpos($this->data_model[$field], 'ml:') === 0) {
						Text::instance()->del($this->cdb(), "$this->data_model_ml_group/$field", $i);
					}
				}
			}
			$this->update_joined_tables($i, []);
			$this->delete_files_tags($i);
		}
		return $result;
	}
	/**
	 * @deprecated
	 * @todo remove in future versions
	 *
	 * @param int|int[]|string|string[] $id
	 *
	 * @return bool
	 */
	protected function delete_simple ($id) {
		return $this->delete($id);
	}
}
