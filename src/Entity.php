<?php
// php-entity by Leon, MIT License

namespace Le;

Exception::register(30, [
	11 => "Entity already initialized",
	12 => "Invalid DB supplied, expecting object",
	13 => "Invalid table name, expecting string",
	14 => "Entitiy not initialized",
	15 => "Invalid entity ID supplied",
	16 => "Can't initialize the unextended entity",
	17 => "Can't load entity with ID ? - not found in database",
	18 => "Data column '?' missing in table '?'",
	19 => "Invalid column name, expecting non-empty string",
	20 => "Invalid value for column '?', expecting string, number, bool or null",
	21 => "Can't call method from the unextended entity",
	22 => "Can't set column '?'",
	23 => "Invalid indexes, expecting array (can be empty)",
	24 => "Invalid schema provided, expecting array",
	25 => "Can't create entity, missing or invalid index '?'",
	26 => "Can't create entity, missing or invalid parent '?'"
]);

abstract class Entity {
	public static $schema = []; // child => [parent1, parent2]
	
	public static function schema($schema){
		if(!is_array($schema)) throw new Exception(30, 24);
		// assuming the format of the array is right
		self::$schema = array_merge(self::$schema, $schema);
	}
	
	public static function init($db, $argoptions = []){
		// don't allow initializing the Entity class, must be extended to initialize
		if(get_called_class() === 'Le\Entity') throw new Exception(30, 16);
		
		if(isset(static::$options['db'])) throw new Exception(30, 11); // already initialized
		
		$options = ['table' => 'entity', 'column_hash' => 'hash', 'column_data' => 'data', 'indexes' => []]; // indexes shouldn't contain data and hash columns
		$options = array_replace($options, $argoptions);
		
		// checking arguments
		if(!($db instanceof DB)) throw new Exception(30, 12);
		if(!is_string($options['table']) || empty($options['table'])) throw new Exception(30, 13);
		if(!is_array($options['indexes'])) throw new Exception(30, 23);

		// saving options
		static::$options = $options;
		static::$options['db'] = $db;
	}
	
	public static function hash($id){
		// don't allow calling from the Entity class, expects calling through extended class
		if(get_called_class() === 'Le\Entity') throw new Exception(30, 21);
		
		if(!is_numeric($id) || $id < 1) throw new Exception(30, 15);
		
		$column_hash = static::$options['column_hash'];
		
		$result = static::$options['db']->get(static::$options['table'], $column_hash, [], ['single' => true]);
		if(!$result['count']) throw new Exception(30, 17, [$id]);
		
		return $result['data'][$column_hash];
	}
	
	public static function create($data){
		// don't allow calling from the Entity class, expects calling through extended class
		if(get_called_class() === 'Le\Entity') throw new Exception(30, 21);
		
		$table = static::$options['table'];
		
		// check if any indexes are missing
		foreach(static::$options['indexes'] as $index)
			if(!array_key_exists($index, $data)) throw new Exception(30, 25, [$index]);
		
		// check if any parent ids are missing
		if(isset(self::$schema[$table])){
			foreach(self::$schema[$table] as $parent){
				$parent = '_' . $parent;
				if(!isset($data[$parent]) || !is_numeric($data[$parent]) || $data[$parent] < 1)
					throw new Exception(30, 26, [$parent]);
			}
		}
		
		try{
			static::$options['db']->transactionBegin();
			$result = static::$options['db']->insert($table, [static::$options['column_data'] => '[]']);
			
			$entity = new static($result['id']);
			$entity->set($data);
			
			static::$options['db']->commit();
			
			return $entity;
		}catch(Exception $e){
			static::$options['db']->rollback();
			throw $e;
		}
	}
	
	public static function find($conditions = [], $additional = [], $return_objects = false){
		// don't allow calling from the Entity class, expects calling through extended class
		if(get_called_class() === 'Le\Entity') throw new Exception(30, 21);
		
		$result = static::$options['db']->get(static::$options['table'], 'id, ' . static::$options['column_hash'], $conditions, $additional);
		
		if($return_objects && $result['count']){
			if(isset($additional['single']) && $additional['single']) $result['data'] = new static($result['data']['id']);
			else foreach($result['data'] as $key => $entity) $result['data'][$key] = new static($entity['id']);
		}
		
		return (isset($additional['single']) && $additional['single']) ? $result : $result['data'];
	}
	
	// if entity_table is set, we're deleting the entities with parent $id inside $entity_table,
	// otherwise we're getting the table from the current class
	public static function delete($id, $entity_table = ''){
		// don't allow calling from the Entity class, expects calling through extended class
		if(get_called_class() === 'Le\Entity') throw new Exception(30, 21);
		
		if(!is_numeric($id) || $id < 1) throw new Exception(30, 15);
		
		if(!$entity_table) $entity_table = static::$options['table'];
		
		try{
			static::$options['db']->transactionBegin();
			static::$options['db']->delete($entity_table, ['id' => $id], ['limit' => 1]);
			
			// iterate through the schema and find all children tables
			foreach(self::$schema as $table => $parents){
				if(!in_array($entity_table, $parents)) continue;
				
				// fetching all child entities' ids
				$result = static::$options['db']->get($table, 'id', [('_' . $entity_table) => $id]);
				if(!$result['count']) continue;
				
				// recursively delete all child entities
				foreach($result['data'] as $child) self::delete($child['id'], $table);
			}
			
			static::$options['db']->commit();
			
			return true;
		}catch(Exception $e){
			static::$options['db']->rollback();
			throw $e;
		}
	}
	
	// object method definitions, can only be used when the entity is loaded
	
	protected $data = [], $indexed_columns = [], $unindexed_columns = [];
	
	public function __construct($id){
		if(!isset(static::$options['db'])) throw new Exception(30, 14); // not initialized
		
		if(!is_numeric($id) || $id < 1) throw new Exception(30, 15);
		
		$table = static::$options['table'];
		$column_data = static::$options['column_data'];
		
		// pulling entity row from database
		$result = static::$options['db']->get($table, '*', ['id' => $id], ['single' => true]);
		if(!$result['count']) throw new Exception(30, 17, [$id]);
		
		// don't proceed if the 'unindexed data column' isn't found in the table
		if(!isset($result['data'][$column_data])) throw new Exception(30, 18, [$column_data, $table]);
		
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
		if(!is_string($column) && !is_numeric($column)) throw new Exception(30, 19);
		
		if(is_string($column) && empty($column)) return $this->data; // returning the whole data array if column not provided
		if(!isset($this->data[$column])) return null; // if value isn't set (column doesn't exist)
		return $this->data[$column];
	}
	
	public function set($arg1, $arg2 = null, $reindex = false){
		if(is_array($arg1) && empty($arg1)) return true;
		
		if(!is_array($arg1)){
			$arg1 = [$arg1 => $arg2];
		}
		
		$table = static::$options['table'];
		$column_data = static::$options['column_data'];
		$column_hash = static::$options['column_hash'];
		$indexes = static::$options['indexes'];
		$parents = isset(static::$schema[$table]) ? static::$schema[$table] : [];
		$unindexed_changes = false;
		$updates = [];
		
		foreach($arg1 as $column => $value){
			if(!is_string($column) && !is_numeric($column) || empty($column)) throw new Exception(30, 19);
			if($column === 'id' || $column === $column_hash) throw new Exception(30, 22, [$column]);
			if(!is_string($value) && !is_numeric($value) && !is_null($value) && !is_bool($value)) throw new Exception(30, 20, [$column]);
			
			if(is_bool($value)) $value = $value ? 1 : 0;
			
			if((isset($this->data[$column]) && $this->data[$column] === $value && !$reindex) || (!isset($this->data[$column]) && is_null($value))) continue; // skipping if there's no actual change and if we're not reindexing
			$this->data[$column] = $value; // merge the update into the internal data array
			if(is_null($value)) unset($this->data[$column]); // if the new value is null, just unset it
			
			// if the changed column is in an indexed or parent column, add it to the staged updates
			if(in_array($column, $indexes) || in_array(substr($column, 1), $parents)){
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
				if(!in_array($column, $indexes) && !in_array(substr($column, 1), $parents) && $column !== 'id' && $column !== $column_hash){
					$updates[$column_data][$column] = $value;
					
					// if the column was also found within indexed columns, set its value to null
					if(in_array($column, $this->indexed_columns)) $updates[$column] = null;
				}
				
				// if the column belongs to the indexed data but was stored within unindexed columns
				// we are losing this column by rebuilding the unindexed data so stage it as an indexed column
				if(in_array($column, $indexes) && in_array($column, $this->unindexed_columns)) $updates[$column] = $value;
			}
			
			$updates[$column_data] = json_encode($updates[$column_data]);
		}
		
		// query the updates
		if(!empty($updates)){
			unset($this->data[$column_hash]);
			$this->data[$column_hash] = crc32(json_encode($this->data));
			$updates[$column_hash] = $this->data[$column_hash];
			
			static::$options['db']->update($table, $updates, ['id' => $this->data['id']], ['limit' => 1]);
		}
		
		return true;
	}
	
	public function reindex(){
		$temp = $this->data;
		unset($temp['id'], $temp[static::$options['column_hash']]);
		$this->set($temp, null, true);
		
		return true;
	}
	
	public function parent($class, $return_objects = false){
		$class = 'Le\\' . $class;
		$table = '_' . $class::$options['table'];
		
		return $class::find(['id' => $this->get($table)], ['single' => true], $return_objects);
	}
	
	public function children($class, $conditions = [], $additional = [], $return_objects = false){
		$class = 'Le\\' . $class;
		$table = '_' . static::$options['table'];
		
		$conditions = array_merge($conditions, [$table => $this->get('id')]);
		return $class::find($conditions, $additional, $return_objects);
	}
}
