<?php
/**
 * User: ethanhayon
 * Date: 10/27/12
 * Time: 12:28 AM
 */

/**
 * Notes on associations:
 * Requirements:
 * has_one: local entity requires id_<entity>
 *  for example: parkinglot has_one comment
 *  ParkingLots.id_comment && Comments.id_comment is required
 *
 */


/**
 * TODO: __get for an association entity
 * TODO: has_many, belongs_to has_many_through
 */

require_once(realpath(dirname(__FILE__)).'/db/database_interface.php');
require_once(realpath(dirname(__FILE__)).'/db/mysql.php');

abstract class Model {

	protected static $_db = null;
	protected static $table_name;
	public $data;
	public $is_new;
	private static $method_table = array();
	private static $associations = array();
	public $validation_errors = array();

	/**
	 * Intercept any method calls and hand over to the method_handler with php magic __call method
	 * @param $method Method to call
	 * @param $args
	 */
	public function __call($method, $args)
	{
		static::method_handler($this, $method, $args);
	}

	/**
	 * @param $scope Calling instance
	 * @param $method Method to be called
	 * @param $args Arguments to pass to method $method
	 */
	private static function method_handler($scope, $method, $args) {
		$table =& self::$method_table;
		$class = get_class($scope);
		if(array_key_exists($class, $table) && array_key_exists($method, $table[$class])) {
			array_unshift($args, $scope);
			return call_user_func_array($table[$class][$method], $args);
		} else {
			throw new Exception("Undefined method $method");
		}
		return null;
	}

	/**
	 * @param array $d Data
	 */
	public function __construct($d=array()) {
		$this->data = $d;
		$this->is_new = true;
	}

	/**
	 * @param $tbl Table name in DataStore
	 */
	public static function set_table_name($tbl) {
		static::$table_name = $tbl;
	}

	/**
	 * @param $attr PHP magic method attribute
	 * @return data for attribute $attr
	 */
	public function __get($attr) {
		// is this attribute part of an association
		$associations =& self::$associations;
		$class = get_called_class();

		if(array_key_exists($class, $associations) && array_key_exists($attr, $associations[$class])) {
			// this is an association, handle the __get a little bit differently.
			return static::get_association($this, $attr, $associations[$class][$attr]);
		}
		return static::__isset($attr) ? $this->data[$attr] : null;
	}

	/**
	 * @param $attr PHP magic isset attribute
	 * @return bool Whether or not $attr is set in $this->data
	 */
	public function __isset($attr) {
		return isset($this->data[$attr]);
	}

	/**
	 * @param $attr PHP magic set attribute
	 * @param $val Value to set for attribute $attr
	 */
	public function __set($attr, $val) {
		// check first to see if this is an association
		$associations =& self::$associations;
		$class = get_called_class();
		// is this $attr part of an association?
		if(array_key_exists($class, $associations) && array_key_exists($attr, $associations[$class])) {
			static::save_association($this, $attr, $val, $associations[$class][$attr]);
		} else {
			$this->data[$attr] = $val;
		}
	}

	/**
	 * @param $attr PHP magic unset attribute
	 */
	public function __unset($attr) {
		unset($this->data[$attr]);
	}

	/**
	 * @param DatabaseInterface $db DataSource for this ObjectMapper
	 * Set up the mapper with the DataSource $db
	 */
	public static function setDB(DatabaseInterface $db) {
		static::$_db = $db;
	}

	/**
	 * @return DataSource
	 */
	public static function getDB() {
		return static::$_db;
	}

	/**
	 * @return string Name of the object's db table
	 * For example, if the name of the class is ParkingLots, name will resolve to parkinglots
	 * By default, this is also assumed to be the name of the db table
	 */
	public static function getName() {
		if(isset(static::$table_name)) {
			return static::$table_name;
		} else {
			return strtolower(get_called_class());
		}
	}

	/**
	 * @param $conditions
	 * @param $order
	 * @param $limit
	 * @return ObjectMapper
	 */
	public static function find($conditions, $order, $limit) {
		$res = static::getDB()->find(static::getName(), $conditions, $order, $limit);
		$results = array();
		foreach($res as $result) {
			$obj = new static($result);
			$obj->is_new = false;
			$results[] = $obj;
		}
		return $results;
	}

	/**
	 * @param $conditions
	 * @param $order
	 * @return static::object
	 */
	public static function findOne($conditions, $order) {
		$res = static::find($conditions, $order, 1);
		return (count($res) >= 1) ? $res[0] : null;
	}

	public function delete() {
		$pk = static::getDB()->get_primary_key(static::$table_name);
		static::getDB()->delete(static::getName(), array($pk => $this->$pk));
	}

	/**
	 * Save the object to a non-volatile DataStore
	 */
	public function save() {
		if(isset(static::$validates_required)) {
			$this->validation_errors = array();
			// check to make sure every required variable is set
			foreach(static::$validates_required as $var) {
				if(!isset($this->data[$var])) {
					// required parameter is missing
					$err = array(
						'attr' => $var,
						'error' => ' is required'
					);
					array_push($this->validation_errors, $err);		
				}
			}
			if(count($this->validation_errors) > 0) return false;
		}
		if($this->is_new) {
			static::getDB()->insert(static::getName(), $this->data);
			$this->is_new = false;
		} else {
			static::getDB()->update(static::getName(), $this->data);
		}
		return true;
	}

	/**
	 * @param $name Name of the function to add
	 * @param $func the lambda function to store in $name
	 */
	public static function add_method($name, $func) {
		$table =& self::$method_table;
		// remember to stay in the scope of the calling class!
		// We need to keep track of the $class as well - these are static, so it is global to Model
		$class = get_called_class();
		if(!array_key_exists($class, $table))
			$table[$class] = array();
		$table[$class][$name] = $func;
	}

	/**********************************************
	 * Associations
	 **********************************************/

	public static function has_one($entity) {
		// TODO Add some examples and document better
		// we need to set up an associations array so we know how to __set and __get
		$associations =& self::$associations;
		$class = get_called_class();
		if(!array_key_exists($class, $associations))
			$associations[$class] = array();

		$associations[$class][strtolower(get_class($entity))] = array(
			'type'          =>  'has_one',
			'key'           =>  'id_'.strtolower(get_class($entity)),
			'class_name'    =>  get_class($entity)
		);
	}


	public static function belongs_to($entity) {
		// TODO Implement belongs_to association
		// set up a belongs_to relation with $entity
		// self belongs to $entity

	}

	public static function has_many($entities) {
		// TODO Implement has_many association
		// dynamically build some extensions
	}

	/**
	 * @param $entity Many entities to one self
	 * @param $tbl DataStore table name for join table
	 */
	public static function has_many_through($entity, $tbl) {
		$associations =& self::$associations;
		$class = get_called_class();
		if(!array_key_exists($class, $associations))
			$associations[$class] = array();

		$associations[$class][strtolower(get_class($entity))."s"] = array(
			'type' 			=> 'has_many_through',
			'key' 			=> 'id_'.strtolower(get_class($entity)),
			'class_name' 	=> get_class($entity),
			'through' 		=> $tbl

		);
	}

	public static function save_association($instance, $attr, $val, $association_info) {
		// we want to handle each type of association a little differently on save
		switch($association_info['type']) {
		case 'has_one':
			// just store the id of val in $instance->id_$attr
			$instance->__set("id_".$attr, $val->id);
			break;
		case 'has_many':
			print "<br />SAVING THE HAS_MANY RELATIONSHIP<br />";
			break;
		case 'has_many_through':
			// remove all elements from the through table with $instance->id
			$pk = static::getDB()->get_primary_key(static::$table_name);
			static::getDB()->delete(
				$association_info['through'],
				array($pk => $instance->$pk));
			// re-add all the new items
			foreach($val as $obj) {
				if(isset($obj)) {
					$d = array(
						$pk => $instance->$pk,
						$association_info['key'] => $obj->id);

					static::getDB()->insert(
						$association_info['through'],
						&$d
					);
				}
			}
			break;
		}
	}

	protected static function get_association($instance, $attr, $association_info) {
		// TODO Implement getter functions for has_many and belongs_to
		switch($association_info['type']) {
		case 'has_one':
			$entity = $association_info['class_name']::findOne(array(
				$association_info['key'] => $instance->__get($association_info['key'])
			), null);
			return $entity;
			break;
		case 'has_many':
			break;
		case 'has_many_through':
			$pk = static::getDB()->get_primary_key(static::$table_name);

			// select all comments with id_comment where 
			$res = static::getDB()->find(
				$association_info['through'],
				array($pk => $instance->$pk));

			$objs = array();
			foreach($res as $r) {
				$item = $association_info['class_name']::findOne(array(
					$association_info['key'] => $r[$association_info['key']]
				), null);
				array_push($objs, $item);
			}
			return $objs;
			break;

		}
	}
}
