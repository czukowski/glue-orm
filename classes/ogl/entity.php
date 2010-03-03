<?php defined('SYSPATH') OR die('No direct access allowed.');

class OGL_Entity {
	// Entity cache :
	static protected $entities = array();

	// Properties that may NOT be set in children classes (either passed to constructor or deduced from other properties) :
	protected $name;
	protected $pk;
	protected $fk;
	protected $joins;

	// Properties that may be set in children classes :
	protected $model;
	protected $tables ;
	protected $fields;
	protected $db;

	// Internal details :
	private $partial;

	// Identity map :
	protected $map = array();

	protected function __construct($name) {
		// Set properties :
		$this->name	= $name;

		// Init properties (order matters !!!) :
		if ( ! isset($this->db))		$this->db		= $this->default_db();
		if ( ! isset($this->model))		$this->model	= $this->default_model();
		if ( ! isset($this->tables))	$this->tables	= $this->default_tables();
		if ( ! isset($this->fields))	$this->fields	= $this->default_fields();

		// Check fields array format :
		foreach($this->fields as $name => $data) {
			// Check columns property :
			if ( ! isset($data['columns']) || ! is_array($data['columns']))
				throw new Kohana_Exception("No 'columns' property found for field '".$this->name.".".$name."' or property not an array.");
			foreach ($data['columns'] as $col)
				if (strpos($col, '.') === false)
					throw new Kohana_Exception("Format 'table.column' expected, '".$col."' found instead.");
		}

		// Extract pk and fk from fields array for efficiency purposes :
		$this->pk = array();
		$this->fk = array();
		foreach($this->fields as $name => $data) {
			if (isset($data['pk'])) {
				$this->pk[]			= $name;
				$this->fk[$name]	= $this->name.'_'.$name;
			}
		}

		// Build joins array :
		$this->joins = array();
		if (count($this->tables) > 1) {
			foreach($this->fields as $name => $data) {
				$columns	= $data['columns'];
				$count		= count($columns);
				if ($count > 1) {
					for ($i = 0; $i < $count; $i++) {
						list($table_i, $col_i) = explode('.', $columns[$i]);
						for ($j = 0; $j < $count; $j++) {
							if ($j !== $i) {
								list($table_j, $col_j) = explode('.', $columns[$j]);
								$this->joins[$table_i][$table_j][$col_i] = $col_j;
							}
						}
					}
				}
			}
		}
	}

	protected function default_db() {
		return 'default';
	}

	protected function default_model() {
		$model = 'OGL_Model_'.ucfirst($this->name);
		return class_exists($model) ? $model : 'stdClass'; // no upper case !
	}

	protected function default_tables() {
		return array(inflector::plural($this->name));
	}

	protected function default_fields() {
		// Init fields array :
		$fields  = array();

		// Loop on tables, look up columns properties by database introspection and populate fields array :
		foreach($this->tables as $table) {
			$cols = Database::instance()->list_columns($table);
			foreach($cols as $col => $data) {
				if ( ! isset($fields[$col])) {
					// Field doesn't exist yet ? Create it :
					$fields[$col] = array(
						'columns'	=> array($table.'.'.$col),
						'phptype'	=> $data['type'],
						'property'	=> $col
					);
					if (isset($data['key']) && $data['key'] === 'PRI')
						$fields[$col]['pk'] = $this->name.'_'.$col;
				}
				else
					// Otherwise add current column to its columns list :
					$fields[$col]['columns'][] = $table.'.'.$col;
			}
		}

		return $fields;
	}

	public function query_from($query, $alias) {
		$partial = $this->query_partial($alias);
		$query->from(DB::expr($partial));
	}

	public function query_join($query, $alias, $type = 'LEFT') {
		$partial = $this->query_partial($alias);
		$query->join(DB::expr($partial), $type);
	}

	public function query_on($query, $alias, $field, $op, $expr) {
		$col = $this->query_field_expr($alias, $field);
		$query->on($col, $op, $expr);
	}

	public function query_where($query, $alias, $field, $op, $expr) {
		$col = $this->query_field_expr($alias, $field);
		$query->where($col, $op, $expr);
	}

	public function query_partial($alias) {
		// Init partial cache :
		if ( ! isset($this->partial)) {
			// Init fake select query (we need this hack because query builder doesn't support nested joins) :
			$fake = DB::select();

			// Add tables :
			$count = count($this->tables);
			for ($i = 0; $i < $count; $i++) {
				// Join :
				$table1			= $this->tables[$i];
				$table1_alias	= '%ALIAS%'.'__'.$table1;
				if ($i === 0)
					$fake->from(array($table1, $table1_alias));
				else
					$fake->join(array($table1, $table1_alias), 'INNER');

				// Add internal join conditions :
				for($j = $i - 1; $j >= 0; $j--) {
					$table2			= $this->tables[$j];
					$table2_alias	= '%ALIAS%'.'__'.$table2;
					if (isset($this->joins[$table1][$table2])) {
						foreach($this->joins[$table1][$table2] as $col1 => $col2)
							$fake->on($table1_alias.'.'.$col1, '=', $table2_alias.'.'.$col2);
					}
				}
			}

			// Capture partial query :
			$sql			= $fake->compile(Database::instance());
			list(, $sql)	= explode('FROM ', $sql);
			$this->partial	= ($count > 1) ? '('.$sql.')' : $sql;
		}

		return str_replace('%ALIAS%', $alias, $this->partial);
	}

	public function query_exec($query, $objects) {
		// No objects ? No result :
		if (count($objects) === 0)
			return array();

		// Get pk values :
		$pkvals = array_map(array($this, 'object_pk'), $objects);

		// Exec query :
		$result = array();
		if (count($this->pk) === 1) {
			// Use only one query :
			$result = $query->param(':_pks', $pkvals)->execute()->as_array();
		}
		else {
			// Use one query for each object and aggregate results :
			foreach($pkvals as $pkval) {
				foreach($pkval as $f => $val)
					$query->param( ':_'.$f, $val);
				$rows = $query->execute()->as_array();
				if (count($rows) >= 1)
					array_merge($result, $rows);
			}
		}
		return $result;
	}

	public function query_select($query, $alias, $fields) {
		// Validate fields :
		$this->fields_validate($fields);

		// Add fields to query :
		foreach ($fields as $name)
			$query->select(array($this->query_field_expr($alias, $name), $alias.':'.$name));
	}

	public function query_field_expr($alias, $field) {
		list($table, $column) = explode('.', $this->fields[$field]['columns'][0]);
		return $alias . '__' . $table . '.' . $column;
	}

	public function fields_validate($fields) {
		$errors = array_diff($fields, array_keys($this->fields));
		if (count($errors) > 0)
			throw new Kohana_Exception("The following fields do not belong to entity ".$this->name." : ".implode(',', $errors));
	}

	public function fields_opposite($fields) {
		return array_diff(array_keys($this->fields), $fields);
	}

	public function fields_all() {
		return array_keys($this->fields);
	}

	public function object_load(&$rows, $prefix = '') {
		// No rows ? Do nothing :
		if (count($rows) === 0) return;

		// Build columns => fields mapping :
		$mapping = array();
		$len_prefix	= strlen($prefix);
		foreach($rows[0] as $col => $val) {
			if (substr($col, 0, $len_prefix) === $prefix) {
				$field = substr($col, $len_prefix);
				if ( ! isset($this->fields[$field]))
					throw new Kohana_Exception("The following field doesn't belong to entity '".$this->name."' : '".$field."'");
				$mapping[$col] = $field;
			}
		}

		// Get pk string representations of each row :
		$arr	= array();
		$pks	= array();
		$pkcols	= array();
		foreach($this->pk as $f) $pkcols[] = $prefix.$f;
		foreach($rows as $row) {
			$no_obj = true;
			foreach($pkcols as $i => $col) {
				if (isset($row[$col]) && $row[$col] !== null) {
					$arr[$i] = $row[$col];
					$no_obj = false;
				}
				else
					$arr[$i] = null;
			}
			$pks[] = $no_obj ? 0 : json_encode($arr);
		}

		// Add new objects to id map :
		$indexes		= array_flip($pks); // Distinct pk => row index mapping
		unset($indexes[0]);					// Remove key that represents "no object".
		$field_names	= array_keys($this->fields);
		$diff			= array_diff_key($indexes, $this->map);
		foreach($diff as $pk => $index) {
			$vals	= array_intersect_key($rows[$index], $mapping);
			$array	= array_combine($mapping, $vals);
			$this->map[$pk] = $this->object_create($array);
		}

		// Build distinct objects list :
		$distinct = array_intersect_key($this->map, $indexes);

		// Load objects into result set :
		$key = $prefix.'__object';
		foreach($pks as $index => $pk) {
			$rows[$index][$key] = ($pk === 0) ? null : $this->map[$pk];
		}

		// Return distinct objects :
		return $distinct;
	}

	protected function object_create($array) {
		static $pattern;

		// Create pattern object :
		if ( ! isset($pattern)) {
			$class = $this->model;
			$pattern = new $class;
		}

		// Create object :
		$object = clone $pattern;

		// Set object properties :
		foreach($array as $field => $val) {
			$data = $this->fields[$field];
			$prop = $data['property'];
			$type = $data['phptype'];
			if ($type !== 'string')
				settype($val, $type);
			$object->$prop = $val;
		}

		return $object;
	}

	// For single column pk, returns the pk value.
	// For multiple columns pk, returns an associative array with pk field names and values.
	protected function object_pk($object) {
		// Efficiently deal with single column pk :
		static $prop;
		if (isset($prop)) return $object->$prop;
		if (count($this->pk) === 1) {
			$prop = $this->fields[$this->pk[0]]['property'];
			return $object->$prop;
		}

		// Multiple column pk :
		foreach($this->pk as $f)
			$pk[$f] = $object->{$this->fields[$f]['property']};
		return $pk;
	}

	// Return relationship $name of this entity.
	public function relationship($name) {
		return OGL_Relationship::get($this->name, $name);
	}

	// Getters :
	public function name()	{ return $this->name;	}
	public function pk()	{ return $this->pk;		}
	public function fk()	{ return $this->fk;		}
	public function db()	{ return $this->db;		}

	// Lazy loads an entity object, stores it in cache, and returns it :
	static public function get($name) {
		if( ! isset(self::$entities[$name]))
			self::$entities[$name] = self::create($name);
		return self::$entities[$name];
	}

	// Chooses the right entity class to use, based on the name of the entity and
	// the available classes.
	static protected function create($name) {
		$class = 'OGL_Entity_'.ucfirst($name);
		if (class_exists($class))
			$entity = new $class($name);
		else
			$entity	= new self($name);
		return $entity;
	}
}


