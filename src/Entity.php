<?php namespace Le;
// php-entity by Leon, MIT License

abstract class Entity {
	public static $debug = false;
	public static $initialized = []; // [Class, Class, ...]

	private static $store;
	protected static
		$column_id = 'id',
		$column_hash = false,
		$column_data = 'data',
		$indexes = [],
		$parents = []
	;

	final public static function load($id, $data_skip = null, $data_confirm = false){
		if(isset(self::$store[static::class][$id]))
			return self::$store[static::class][$id];

		$object = new static($id, $data_skip, $data_confirm);

		if(!isset(self::$store[static::class])) self::$store[static::class] = [];
		self::$store[static::class][$id] = $object;

		return $object;
	}

	public static function hash($id, $hash){
		if(self::$debug){
			if(get_called_class() === 'Le\Entity') throw new Error("DEBUG: can only be called from a class that extends the Entity class");
			if(!isset(static::$db) || !isset(static::$table)) throw new Error("DEBUG: entity class missing database or table");
			if(empty($id)) throw new Error("DEBUG: id empty");
		}

		if(empty(static::$column_hash)) return false;

		$entity = self::find([static::$column_id => $id], ['single' => true]);
		if(!$entity['count']) throw new EntityNotFoundException();
		return ($entity['data'][static::$column_hash] == $hash);
	}

	public static function create($data){
		if(self::$debug){
			if(get_called_class() === 'Le\Entity') throw new Error("DEBUG: can only be called from a class that extends the Entity class");
			if(!isset(static::$db) || !isset(static::$table)) throw new Error("DEBUG: entity class missing database or table");
			if(!is_array($data) || empty($data)) throw new Error("DEBUG: data array missing");

			// check if any parent ids are missing
			foreach(static::$parents as $parent => $uu)
				if(!isset($data[$parent]))
						throw new Error("DEBUG: missing parent id for entity");
		}

		try{
			static::$db::$instance->transactionBegin();
			$result = static::$db::$instance->insert(static::$table, [static::$column_data => '[]']);

			$entity = static::load($result[static::$column_id]);
			$entity->set($data);

			static::$db::$instance->commit();
			return $entity;
		}catch(Throwable $e){
			static::$db::$instance->rollback();
			throw $e;
		}
	}

	public static function find($conditions = [], $additional = [], $return_objects = false){
		if(self::$debug){
			if(get_called_class() === 'Le\Entity') throw new Error("DEBUG: can only be called from a class that extends the Entity class");
			if(!isset(static::$db) || !isset(static::$table)) throw new Error("DEBUG: entity class missing database or table");
		}

		$column_id = static::$column_id;
		$column_hash = static::$column_hash;
		$columns =
			( isset($additional['columns']) && !$return_objects )
			? $additional['columns']
			: ( $return_objects ? '*' : ( $column_id. ( $column_hash ? ', ' . $column_hash : '' ) ) )
		;
		$result = static::$db::$instance->get(static::$table, $columns, $conditions, $additional);

		if(isset($additional['single']) && $additional['single']){
			if(!$result['count']) return ['count' => 0];

			if($return_objects){
				$result['object'] = static::load($result['data'][$column_id], $result['data'], 'le_data_from_find');
				unset($result['data']);
			}
		}else{
			if($return_objects && $result['count']){
				foreach($result['data'] as $key => $entity)
					$result['data'][$key] = static::load($entity[$column_id], $entity, 'le_data_from_find');
			}
		}

		return $result;
	}

	public static function delete($id){
		if(self::$debug){
			if(get_called_class() === 'Le\Entity') throw new Error("DEBUG: can only be called from a class that extends the Entity class");
			if(!isset(static::$db) || !isset(static::$table)) throw new Error("DEBUG: entity class missing database or table");
		}

		$table = static::$table;
		$column_id = static::$column_id;

		try{
			static::$db::$instance->transactionBegin();
			static::$db::$instance->delete($table, [$column_id => $id], ['limit' => 1]);

			// iterate through all initialized entity classes and find those that the current is parent to
			foreach(self::$initialized as $entity){
				if(!in_array(static::class, $entity::$parents)) continue;

				// get the name of the parent id column in child class
				$parent_column = array_search(static::class, $entity::$parents);
				$child_column_id = $entity::$column_id;

				// fetching all child entities' ids
				$result = $entity::$db::$db->get($entity::$table, $child_column_id, [$parent_column => $id]);
				if(!$result['count']) continue;

				// recursively delete all child entities
				foreach($result['data'] as $child) $entity::delete($child[$child_column_id]);
			}

			static::$db::$instance->commit();

			return true;
		}catch(Throwable $e){
			static::$db::$instance->rollback();
			throw $e;
		}
	}

	// object method definitions, can only be used when the entity is loaded

	protected $data = [], $indexed_columns = [], $unindexed_columns = [];

	// $data_confirm is supposed to prevent accidental passing of entity data
	final private function __construct($id, $data_skip = null, $data_confirm = false){
		if(self::$debug && (!isset(static::$db) || !isset(static::$table))) throw new Error("DEBUG: entity class missing database or table");

		$table = static::$table;
		$column_id = static::$column_id;
		$column_data = static::$column_data;

		// pulling entity row from database or using the argument if passed from find() method
		if(is_null($data_skip) || $data_confirm !== 'le_data_from_find'){
			$result = static::$db::$instance->get($table, '*', [$column_id => $id], ['single' => true]);
			if(!$result['count']) throw new EntityNotFoundException();
		}else{
			$result = ['data' => $data_skip];
		}

		// don't proceed if the unindexed data column isn't found in the table
		if(self::$debug && !isset($result['data'][$column_data])) throw new Error("DEBUG: unindexed data column not found in table");

		// decode the unindexed data
		$unindexed_data = json_decode($result['data'][$column_data], true);
		unset($result['data'][$column_data]);

		// store the names of indexed and unindexed columns
		$this->indexed_columns = array_keys($result['data']);
		$this->unindexed_columns = array_keys($unindexed_data);

		// merge the unindexed and indexed data
		$result['data'] = array_merge($result['data'], $unindexed_data);

		$this->data = $result['data'];
	}

	public function get($column = ''){
		if(self::$debug && !is_string($column) && !is_numeric($column)) throw new Error("DEBUG: invalid column name");

		if(is_string($column) && empty($column)) return $this->data; // returning the whole data array if column not provided
		if(!isset($this->data[$column])) return null; // if value isn't set (column doesn't exist)
		return $this->data[$column];
	}

	public function set($arg1, $arg2 = null, $reindex = false){
		if(is_array($arg1) && empty($arg1)) return true;

		if(!is_array($arg1)){
			$arg1 = [$arg1 => $arg2];
		}

		$table = static::$table;
		$column_id = static::$column_id;
		$column_data = static::$column_data;
		$column_hash = static::$column_hash;
		$indexes = static::$indexes;
		$parents = static::$parents;
		$unindexed_changes = false;
		$updates = [];

		foreach($arg1 as $column => $value){
			if(self::$debug){
				if(!is_string($column) && !is_numeric($column) || empty($column)) throw new Error("DEBUG: invalid column name");
				if($column === $column_id || $column === $column_hash) throw new Error("DEBUG: column name can't be id or hash column names");
				if(!is_string($value) && !is_numeric($value) && !is_null($value) && !is_bool($value)) throw new Error("DEBUG: invalid value");
			}

			if(is_bool($value)) $value = $value ? 1 : 0;

			// skipping if there's no actual change and if we're not reindexing
			if( (isset($this->data[$column]) && $this->data[$column] === $value && !$reindex) || (!isset($this->data[$column]) && is_null($value)) ) continue;

			$this->data[$column] = $value; // merge the update into the internal data array
			if(is_null($value)) unset($this->data[$column]); // if the new value is null, just unset it

			// if the changed column is in an indexed or parent column, add it to the staged updates
			if(in_array($column, $indexes) || array_key_exists($column, $parents)){
				if(in_array($column, $this->unindexed_columns)) $unindexed_changes = true; // if the column also exists in unindexed data, reconstruct the unindexed data
				$updates[$column] = $value;
			}else{
				$unindexed_changes = true;
			}
		}

		// if we have any changes in the unindexed data reconstruct the unindexed data column in staged updates
		if($unindexed_changes){
			$updates[$column_data] = [];

			foreach($this->data as $column => $value){
				// if the column belongs to the unindexed data
				if(!in_array($column, $indexes) && !array_key_exists($column, $parents) && $column !== $column_id && $column !== $column_hash){
					$updates[$column_data][$column] = $value;

					// if the column was also found within indexed columns, set its indexed value to null
					if(in_array($column, $this->indexed_columns)) $updates[$column] = null;
				}

				// if the data should be indexed but was stored within the unindexed data
				// by rebuilding the unindexed data we are losing this column, so stage it as indexed
				if(in_array($column, $indexes) && in_array($column, $this->unindexed_columns)) $updates[$column] = $value;
			}

			$updates[$column_data] = json_encode($updates[$column_data]);
		}

		// query the updates
		if(!empty($updates)){
			if($column_hash){
				unset($this->data[$column_hash]);
				$this->data[$column_hash] = crc32(json_encode($this->data));
				$updates[$column_hash] = $this->data[$column_hash];
			}

			static::$db::$instance->update($table, $updates, [$column_id => $this->data[$column_id]], ['limit' => 1]);
		}

		return true;
	}

	public function reindex(){
		$temp = $this->data;
		unset($temp[static::$column_id], $temp[static::$column_hash]);
		$this->set($temp, null, true);
		return true;
	}

	public function parent($class, $return_objects = false){
		// get name of the column in current entity class where the parent id is stored
		$column = array_search($class, static::$parents);

		return $class::find([$class::$column_id => $this->get($column)], ['single' => true], $return_objects);
	}

	public function children($class, $conditions = [], $additional = [], $return_objects = false){
		$id = $this->get(static::$column_id);
		// get name of the column in child entity class where the parent id is stored
		$child_column = array_search(static::class, $class::$parents);

		$conditions = array_merge($conditions, [$child_column => $id]);
		return $class::find($conditions, $additional, $return_objects);
	}
}

class EntityNotFoundException extends \Exception {};
