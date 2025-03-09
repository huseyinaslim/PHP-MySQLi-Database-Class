<?php
/**
 * Mysqli Model wrapper
 *
 * @category  Database Access
 * @package   MysqliDb
 * @author    Alexander V. Butenko <a.butenka@gmail.com>
 * @copyright Copyright (c) 2015-2017
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      http://github.com/joshcam/PHP-MySQLi-Database-Class
 * @version   2.9-master
 *
 * @method int count ()
 * @method dbObject ArrayBuilder()
 * @method dbObject JsonBuilder()
 * @method dbObject ObjectBuilder()
 * @method mixed byId(string $id, mixed $fields)
 * @method mixed get(mixed $limit, mixed $fields)
 * @method mixed getOne(mixed $fields)
 * @method mixed paginate(int $page, array $fields)
 * @method dbObject query($query, $numRows = null)
 * @method dbObject rawQuery($query, $bindParams = null)
 * @method dbObject join(string $objectName, string $key, string $joinType, string $primaryKey)
 * @method dbObject with(string $objectName)
 * @method dbObject groupBy(string $groupByField)
 * @method dbObject orderBy($orderByField, $orderbyDirection = "DESC", $customFieldsOrRegExp = null)
 * @method dbObject where($whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND')
 * @method dbObject orWhere($whereProp, $whereValue = 'DBNULL', $operator = '=')
 * @method dbObject having($havingProp, $havingValue = 'DBNULL', $operator = '=', $cond = 'AND')
 * @method dbObject orHaving($havingProp, $havingValue = null, $operator = null)
 * @method dbObject setQueryOption($options)
 * @method dbObject setTrace($enabled, $stripPrefix = null)
 * @method dbObject withTotalCount()
 * @method dbObject startTransaction()
 * @method dbObject commit()
 * @method dbObject rollback()
 * @method dbObject ping()
 * @method string getLastError()
 * @method string getLastQuery()
 */
class dbObject {
    /**
     * Working instance of MysqliDb created earlier
     *
     * @var MysqliDb
     */
    private $db;
    /**
     * Models path
     *
     * @var modelPath
     */
    protected static $modelPath;
    /**
     * An array that holds object data
     *
     * @var array
     */
    public $data;
    /**
     * Flag to define is object is new or loaded from database
     *
     * @var boolean
     */
    public $isNew = true;
    /**
     * Return type: 'Array' to return results as array, 'Object' as object
     * 'Json' as json string
     *
     * @var string
     */
    public $returnType = 'Object';
    /**
     * An array that holds has* objects which should be loaded togeather with main
     * object togeather with main object
     *
     * @var string
     */
    private $_with = Array();
    /**
     * Per page limit for pagination
     *
     * @var int
     */
    public static $pageLimit = 20;
    /**
     * Variable that holds total pages count of last paginate() query
     *
     * @var int
     */
    public static $totalPages = 0;
    /**
     * Variable which holds an amount of returned rows during paginate queries
     * @var string
     */
    public static $totalCount = 0;	
    /**
     * An array that holds insert/update/select errors
     *
     * @var array
     */
    public $errors = null;
    /**
     * Primary key for an object. 'id' is a default value.
     *
     * @var stating
     */
    protected $primaryKey = 'id';
    /**
     * Table name for an object. Class name will be used by default
     *
     * @var stating
     */
    protected $dbTable;

	/**
	 * @var array name of the fields that will be skipped during validation, preparing & saving
	 */
    protected $toSkip = array();

    /**
     * @param array|null $data Data to preload on object creation
     */
    public function __construct (?array $data = null) {
        $this->db = MysqliDb::getInstance();
        if (empty ($this->dbTable))
            $this->dbTable = get_class ($this);

        if ($data)
            $this->data = $data;
    }

    /**
     * Magic setter function
     *
     * @return mixed
     */
    public function __set ($name, $value) {
        if (property_exists ($this, 'hidden') && array_search ($name, $this->hidden) !== false)
            return;
	    
        $this->data[$name] = $value;
    }

    /**
     * Magic getter function
     *
     * @param $name Variable name
     *
     * @return mixed
     */
    public function __get ($name) {
        if (property_exists ($this, 'hidden') && array_search ($name, $this->hidden) !== false)
	    return null;
		
	if (isset ($this->data[$name]) && $this->data[$name] instanceof dbObject)
            return $this->data[$name];

        if (property_exists ($this, 'relations') && isset ($this->relations[$name])) {
            $relationType = strtolower ($this->relations[$name][0]);
            $modelName = $this->relations[$name][1];
            switch ($relationType) {
                case 'hasone':
                    $key = isset ($this->relations[$name][2]) ? $this->relations[$name][2] : $name;
                    $obj = new $modelName;
                    $obj->returnType = $this->returnType;
                    return $this->data[$name] = $obj->byId($this->data[$key]);
                    break;
                case 'hasmany':
                    $key = $this->relations[$name][2];
                    $obj = new $modelName;
                    $obj->returnType = $this->returnType;
                    return $this->data[$name] = $obj->where($key, $this->data[$this->primaryKey])->get();
                    break;
                default:
                    break;
            }
        }

        if (isset ($this->data[$name]))
            return $this->data[$name];

        if (property_exists ($this->db, $name))
            return $this->db->$name;
    }

    public function __isset ($name) {
        if (isset ($this->data[$name]))
            return isset ($this->data[$name]);

        if (property_exists ($this->db, $name))
            return isset ($this->db->$name);
    }

    public function __unset ($name) {
        unset ($this->data[$name]);
    }

    /**
     * Helper function to create dbObject with Json return type
     *
     * @return dbObject
     */
    private function JsonBuilder () {
        $this->returnType = 'Json';
        return $this;
    }

    /**
     * Helper function to create dbObject with Array return type
     *
     * @return dbObject
     */
    private function ArrayBuilder () {
        $this->returnType = 'Array';
        return $this;
    }

    /**
     * Helper function to create dbObject with Object return type.
     * Added for consistency. Works same way as new $objname ()
     *
     * @return dbObject
     */
    private function ObjectBuilder () {
        $this->returnType = 'Object';
        return $this;
    }

    /**
     * Helper function to create a virtual table class
     *
     * @param string tableName Table name
     * @return dbObject
     */
    public static function table ($tableName) {
        $tableName = preg_replace ("/[^-a-z0-9_]+/i",'', $tableName);
        if (!class_exists ($tableName))
            eval ("class $tableName extends dbObject {}");
        return new $tableName ();
    }
    /**
     * @return mixed insert id or false in case of failure
     */
    public function insert () {
        if (!empty ($this->timestamps) && in_array ("createdAt", $this->timestamps))
            $this->createdAt = date("Y-m-d H:i:s");
        $sqlData = $this->prepareData ();
        if (!$this->validate ($sqlData))
            return false;

        $id = $this->db->insert ($this->dbTable, $sqlData);
        if (!empty ($this->primaryKey) && empty ($this->data[$this->primaryKey]))
            $this->data[$this->primaryKey] = $id;
        $this->isNew = false;
	    $this->toSkip = array();
        return $id;
    }

    /**
     * @param array|null $data Optional update data to apply to the object
     */
    public function update (?array $data = null) {
        if (!empty ($this->errors)) return false;
        if (empty ($this->primaryKey))
            throw new Exception ("Primary key not defined.");

        if ($data) {
            foreach ($data as $k => $v)
                if (!$this->toSkip($k))
                    $this->$k = $v;
        }

        if (!$this->validate($this->modelName))
            return false;

        $sqlData = $this->prepareData();
        if (!$this->db->update ($this->dbTable, $sqlData, [$this->primaryKey => $this->data[$this->primaryKey]]))
            return false;

        $this->isNew = false;
        $this->_with = Array();
        return true;
    }

    /**
     * Save or Update object
     *
     * @param array|null $data Optional data to update
     *
     * @return bool
     */
    public function save (?array $data = null) {
        if (!empty ($this->errors)) return false;
        if (empty ($this->primaryKey))
            throw new Exception ("Primary key not defined.");

        if ($data) {
            foreach ($data as $k => $v)
                if (!$this->toSkip($k))
                    $this->$k = $v;
        }

        if (!$this->validate($this->modelName))
            return false;

        $sqlData = $this->prepareData();
        if (isset ($this->data[$this->primaryKey]) && $this->data[$this->primaryKey]) {
            if (!$this->db->update ($this->dbTable, $sqlData, [$this->primaryKey => $this->data[$this->primaryKey]]))
                return false;
        } else {
            if (!$id = $this->db->insert ($this->dbTable, $sqlData))
                return false;
            $this->data[$this->primaryKey] = $id;
        }

        $this->isNew = false;
        $this->_with = Array();
        return true;
    }

    /**
     * Delete method. Works only if object primaryKey is defined
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function delete () {
        if (empty ($this->data[$this->primaryKey]))
            return false;

        $this->db->where ($this->primaryKey, $this->data[$this->primaryKey]);
        $res = $this->db->delete ($this->dbTable);
        $this->toSkip = array();
        return $res;
    }

	/**
	 * chained method that append a field or fields to skipping
	 * @param mixed|array|false $field field name; array of names; empty skipping if false
	 * @return $this
	 */
    public function skip($field){
	    if(is_array($field)) {
		    foreach ($field as $f) {
			    $this->toSkip[] = $f;
		    }
	    } else if($field === false) {
	    	$this->toSkip = array();
	    } else{
	    	$this->toSkip[] = $field;
	    }
	    return $this;
    }

    /**
     * Find object by id
     *
     * @param int $id
     * @param array|null $fields Fields to select
     *
     * @return dbObject|false
     */
    private function byId ($id, ?array $fields = null) {
        $this->db->where ($this->primaryKey, $id);
        return $this->getOne ($fields);
    }

    /**
     * Get one record
     *
     * @param array|null $fields Fields to select
     *
     * @return dbObject|false
     */
    protected function getOne (?array $fields = null) {
        $this->processHasOneWith ();
        $results = $this->db->getOne ($this->dbTable, $fields);
        if ($results) {
            $this->processArrays ($results);
            $this->data = $results;
            $this->processAllWith ($results);
            $this->isNew = false;
        }
        return $results ? $this : false;
    }

    /**
     * A convenient SELECT COLUMN function to get a single column value from model object
     *
     * @param string $column    The desired column
     * @param int    $limit     Limit of rows to select. Use null for unlimited..1 by default
     *
     * @return mixed Contains the value of a returned column / array of values
     * @throws Exception
     */
    protected function getValue ($column, $limit = 1) {
        $res = $this->db->ArrayBuilder()->getValue ($this->dbTable, $column, $limit);
        if (!$res)
            return null;
        return $res;
    }

    /**
     * A convenient function that returns TRUE if exists at least an element that
     * satisfy the where condition specified calling the "where" method before this one.
     *
     * @return bool
     * @throws Exception
     */
    protected function has() {
        return $this->db->has($this->dbTable);
    }
	
    /**
     * Get records
     *
     * @param int|null $limit   Limit for the records
     * @param array|null $fields Fields to select
     *
     * @return array
     */
    protected function get ($limit = null, ?array $fields = null) {
        $this->processHasOneWith ();
        $results = $this->db->get ($this->dbTable, $limit, $fields);
        if (!$results) return array();
        $totalCount = count ($results);
        if ($totalCount && $this->returnType == 'Object') {
            $className = get_class ($this);
            for ($i = 0; $i < $totalCount; $i++) {
                $item = new $className ($results[$i]);
                $item->isNew = false;
                $results[$i] = $item;
            }
        }
        $this->_with = Array();
        if ($this->returnType == 'Object')
            return $results;

        if ($this->returnType == 'Json')
            return json_encode ($results);

        return $results;
    }

    /**
     * Function to set witch hasOne or hasMany objects should be loaded togeather with a main object
     *
     * @access public
     * @param string $objectName Object Name
     *
     * @return dbObject
     */
    private function with ($objectName) {
        if (!property_exists ($this, 'relations') || !isset ($this->relations[$objectName]))
            die ("No relation with name $objectName found");

        $this->_with[MysqliDb::$prefix.$objectName] = $this->relations[$objectName];

        return $this;
    }

    /**
     * Join object with another object
     *
     * @param string $objectName
     * @param string|null $key
     * @param string $joinType
     * @param string|null $primaryKey
     *
     * @return dbObject
     */
    private function join ($objectName, ?string $key = null, string $joinType = 'LEFT', ?string $primaryKey = null) {
        $key = $key ?: $objectName . "Id";
        $primaryKey = $primaryKey ?: $this->primaryKey;
        if (!$this->isClass($objectName))
            die ("Class $objectName not found");
            
        $this->db->join ($objectName, "$objectName.$primaryKey = " . $this->dbTable . ".$key", $joinType);
        return $this;
    }

    /**
     * Function to get a total records count
     *
     * @return int
     */
    protected function count () {
        $res = $this->db->ArrayBuilder()->getValue ($this->dbTable, "count(*)");
        if (!$res)
            return 0;
        return $res;
    }

    /**
     * Paginate records
     *
     * @param int $page Page number
     * @param array|null $fields Fields to select
     *
     * @return array
     */
    private function paginate ($page, ?array $fields = null) {
        $this->processHasOneWith ();
        $objects = Array ();
        $this->db->pageLimit = self::$pageLimit;
        $results = $this->db->paginate ($this->dbTable, $page, $fields);
        self::$totalPages = $this->db->totalPages;
        self::$totalCount = $this->db->totalCount;
        if ($this->db->count == 0) return null;
	    
        foreach ($results as $k => &$r) {
            $this->processArrays ($r);
            $this->data = $r;
            $this->processAllWith ($r, false);
            if ($this->returnType == 'Object') {
                $item = new static ($r);
                $item->isNew = false;
                $objects[$k] = $item;
            }
        }
        $this->_with = Array();
        if ($this->returnType == 'Object')
            return $objects;

        if ($this->returnType == 'Json')
            return json_encode ($results);

        return $results;
    }

    /**
     * Catches calls to undefined methods.
     *
     * Provides magic access to private functions of the class and native public mysqlidb functions
     *
     * @param string $method
     * @param mixed $arg
     *
     * @return mixed
     */
    public function __call ($method, $arg) {
        if (method_exists ($this, $method))
            return call_user_func_array (array ($this, $method), $arg);

        call_user_func_array (array ($this->db, $method), $arg);
        return $this;
    }

    /**
     * Catches calls to undefined static methods.
     *
     * Transparently creating dbObject class to provide smooth API like name::get() name::orderBy()->get()
     *
     * @param string $method
     * @param mixed $arg
     *
     * @return mixed
     */
    public static function __callStatic ($method, $arg) {
        $obj = new static;
        $result = call_user_func_array (array ($obj, $method), $arg);
        if (method_exists ($obj, $method))
            return $result;
        return $obj;
    }

    /**
     * Converts object data to an associative array.
     *
     * @return array Converted data
     */
    public function toArray () {
        $data = $this->data;
        $this->processAllWith ($data);
        foreach ($data as &$d) {
            if ($d instanceof dbObject)
                $d = $d->data;
        }
        return $data;
    }

    /**
     * Converts object data to a JSON string.
     *
     * @return string Converted data
     */
    public function toJson () {
        return json_encode ($this->toArray());
    }

    /**
     * Converts object data to a JSON string.
     *
     * @return string Converted data
     */
    public function __toString () {
        return $this->toJson ();
    }

    /**
     * Function queries hasMany relations if needed and also converts hasOne object names
     *
     * @param array $data
     */
    private function processAllWith (&$data, $shouldReset = true) {
        if (count ($this->_with) == 0)
            return;

        foreach ($this->_with as $name => $opts) {
            $relationType = strtolower ($opts[0]);
            $modelName = $opts[1];
            if ($relationType == 'hasone') {
                $obj = new $modelName;
                $table = $obj->dbTable;
                $primaryKey = $obj->primaryKey;
				
                if (!isset ($data[$table])) {
                    $data[$name] = $this->$name;
                    continue;
                } 
                if ($data[$table][$primaryKey] === null) {
                    $data[$name] = null;
                } else {
                    if ($this->returnType == 'Object') {
                        $item = new $modelName ($data[$table]);
                        $item->returnType = $this->returnType;
                        $item->isNew = false;
                        $data[$name] = $item;
                    } else {
                        $data[$name] = $data[$table];
                    }
                }
                unset ($data[$table]);
            }
            else
                $data[$name] = $this->$name;
        }
        if ($shouldReset)
            $this->_with = Array();
    }

    /*
     * Function building hasOne joins for get/getOne method
     */
    private function processHasOneWith () {
        if (count ($this->_with) == 0)
            return;
        foreach ($this->_with as $name => $opts) {
            $relationType = strtolower ($opts[0]);
            $modelName = $opts[1];
            $key = null;
            if (isset ($opts[2]))
                $key = $opts[2];
            if ($relationType == 'hasone') {
                $this->db->setQueryOption ("MYSQLI_NESTJOIN");
                $this->join ($modelName, $key);
            }
        }
    }

    /**
     * @param array $data
     */
    private function processArrays (&$data) {
        if (isset ($this->jsonFields) && is_array ($this->jsonFields)) {
            foreach ($this->jsonFields as $key)
                $data[$key] = json_decode ($data[$key]);
        }

        if (isset ($this->arrayFields) && is_array($this->arrayFields)) {
            foreach ($this->arrayFields as $key)
                $data[$key] = explode ("|", $data[$key]);
        }
    }

    /**
     * @param array $data
     */
    private function validate ($data) {
        if (!$this->dbFields)
            return true;

        foreach ($this->dbFields as $key => $desc) {
        	if(in_array($key, $this->toSkip))
        		continue;

            $type = null;
            $required = false;
            if (isset ($data[$key]))
                $value = $data[$key];
            else
                $value = null;

            if (is_array ($value))
                continue;

            if (isset ($desc[0]))
                $type = $desc[0];
            if (isset ($desc[1]) && ($desc[1] == 'required'))
                $required = true;

            if ($required && strlen ($value) == 0) {
                $this->errors[] = Array ($this->dbTable . "." . $key => "is required");
                continue;
            }
            if ($value == null)
                continue;

            switch ($type) {
                case "text":
                    $regexp = null;
                    break;
                case "int":
                    $regexp = "/^[0-9]*$/";
                    break;
                case "double":
                    $regexp = "/^[0-9\.]*$/";
                    break;
                case "bool":
                    $regexp = '/^(yes|no|0|1|true|false)$/i';
                    break;
                case "datetime":
                    $regexp = "/^[0-9a-zA-Z -:]*$/";
                    break;
                default:
                    $regexp = $type;
                    break;
            }
            if (!$regexp)
                continue;

            if (!preg_match ($regexp, $value)) {
                $this->errors[] = Array ($this->dbTable . "." . $key => "$type validation failed");
                continue;
            }
        }
        return !count ($this->errors) > 0;
    }

    private function prepareData () {
        $this->errors = Array ();
        $sqlData = Array();
        if (count ($this->data) == 0)
            return Array();

        if (method_exists ($this, "preLoad"))
            $this->preLoad ($this->data);

        if (!$this->dbFields)
            return $this->data;

        foreach ($this->data as $key => &$value) {
        	if(in_array($key, $this->toSkip))
        		continue;

            if ($value instanceof dbObject && $value->isNew == true) {
                $id = $value->save();
                if ($id)
                    $value = $id;
                else
                    $this->errors = array_merge ($this->errors, $value->errors);
            }

            if (!in_array ($key, array_keys ($this->dbFields)))
                continue;

            if (!is_array($value) && !is_object($value)) {
                $sqlData[$key] = $value;
                continue;
            }

            if (isset ($this->jsonFields) && in_array ($key, $this->jsonFields))
                $sqlData[$key] = json_encode($value);
            else if (isset ($this->arrayFields) && in_array ($key, $this->arrayFields))
                $sqlData[$key] = implode ("|", $value);
            else
                $sqlData[$key] = $value;
        }
        return $sqlData;
    }

    private static function dbObjectAutoload ($classname) {
        $filename = static::$modelPath . $classname .".php";
        if (file_exists ($filename))
            include ($filename);
    }

    /*
     * Enable models autoload from a specified path
     *
     * Calling autoload() without path will set path to dbObjectPath/models/ directory
     *
     * @param string|null $path
     */
    public static function autoload (?string $path = null) {
        if ($path)
            static::$modelPath = $path . "/";
        else
            static::$modelPath = __DIR__ . "/models/";
        spl_autoload_register ("dbObject::dbObjectAutoload");
    }
}
