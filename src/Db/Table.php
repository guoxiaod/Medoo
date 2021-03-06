<?php

namespace Medoo\Db;

/**
 * @method update($data, $where = null)
 * @method insert($datas, $shardKey = null)
 * @method get($join = null, $columns = null, $where = null)
 * @method last()
 * @method log()
 */
class Table
{
    protected $database;
    protected $table;
    protected $primary;

    protected $instance;
    protected $lastConnection;

    public function __construct($table = null, $database = null, $primary = null)
    {
        $this->table = $table ?? $this->table;
        $this->database = $database ?? $this->database;
        $this->primary = $primary ?? $this->primary;
        if ($this->database == null || $this->table == null) {
            throw new \Exception("Please first set database and table for " . static::class);
        }
        if ($this->primary == null) {
            // throw new \Exception("Please first set primary key for " . static::class); 
            // the default primary key is table_name + '_id'
            $this->primary = $this->table . '_id';
        }
        $this->primary = is_array($this->primary) ? $this->primary : [$this->primary];

        $this->instance = Database::getInstance($this->database);
    }

    /**
     * 子类实现该方法,将sql日志打印到别处.
     * log2somewhere将在sql执行后(成功后)触发
     */
    public function log2somewhere()
    {
        //TODO implement this method in child class
    }

    public function find($id, $where = [], $shardKey = null, $isWriter = null)
    {
        $id = is_array($id) ? $id : [$id];
        if (count($id) != count($this->primary)) {
            throw new \Exception("id count is not same as primary key count");
        }
        $where += [
            'AND' => isset($id[0]) ? array_combine($this->primary, $id) : $id,
        ];
        $results =  $this->get('*', $where);
        return $results;
    }

    public function findForUpdate($id, $shardKey = null, $isWriter = true)
    {
        $results = $this->find($id, ['LOCK' => 'UPDATE'], $shardKey);
        return $results;
    }

    public function getTable($shardKey = null)
    {
        return $this->instance->getTable($this->table, $shardKey);
    }

    public function connect($shardKey = null, $isWriter = null)
    {
        $this->lastConnection =  $this->instance->connect($shardKey, $isWriter);
        return $this->lastConnection;
    }

    public function query($query, $map = [], $shardKey = null, $isWriter = null)
    {
        if ($isWriter === null) {
            $isWriter = strncasecmp(ltrim($query), 'select', 6) !== 0;
        }
        $results = $this->connect($shardKey, $isWriter)->query($query, $map);
        $this->log2somewhere();
        return $results;
    }

    public function exec($query, $map = [], $shardKey = null, $isWriter = null)
    {
        if ($isWriter === null) {
            $isWriter = strncasecmp(ltrim($query), 'select', 6) !== 0;
        }
        $results = $this->connect($shardKey, $isWriter)->exec($query, $map);
        $this->log2somewhere();
        return $results;
    }

    public function quote($string, $shardKey = null, $isWriter = null)
    {
        $results = $this->connect($shardKey, $isWriter)->quote($string);
        $this->log2somewhere();
        return $results;
    }

    public function action($actions, $shardKey = null, $isWriter = true)
    {
        return $this->connect($shardKey, $isWriter = true)->action($actions);
    }

    public function begin($shardKey = null)
    {
        return $this->connect($shardKey, $isWriter = true)->begin();
    }

    public function commit($shardKey = null)
    {
        return $this->connect($shardKey, $isWriter = true)->commit();
    }

    public function rollback($shardKey = null)
    {
        return $this->connect($shardKey, $isWriter = true)->rollback();
    }

    public function insertEx($data, $shardKey = null)
    {
        $result = $this->connect($shardKey, $isWriter = true)->insert($data);
        $this->log2somewhere();
        if (isset($data[0])) {
            $id = $this->id($shardKey);
            // TODO if the data is multiple records, the result is undefined
            return $id;
        } else {
            if (count($this->primary) == 1) {
                $primary = $this->primary[0];
                if (isset($data[$primary])) {
                    return $data[$primary];
                }
                return $this->id($shardKey);
            }
            return array_intersect(array_flip($this->primary), $data);
        }
    }

    public function id($shardKey = null)
    {
        return $this->lastConnection->id();
    }

    /**
     * @return \Medoo\MysqlMedoo.pdo
     */
    public function getPdo()
    {
        if(!$this->lastConnection) {
            return null;
        }

        return $this->lastConnection->getPdo();
    }

    public function __call($method, $args)
    {
        $tableMethods = [
            'select' => 4,
            'get' => 4,
            'has' => 3,
            'rand' => 4,
            'count' => 4,
            'avg' => 4,
            'max' => 4,
            'min' => 4,
            'sum' => 4,
        ];
        if (isset($tableMethods[$method])) {
            $count = $tableMethods[$method];
            $isWriter = false;
            if (count($args) === $count + 1) {
                $isWriter = array_pop($args);
            }
            $shardKey = count($args) === $count ? array_pop($args) : null;
            $connect = $this->connect($shardKey, $isWriter);
            array_unshift($args, $this->getTable($shardKey));
            $results = call_user_func_array([$connect, $method], $args);
            $this->log2somewhere();
            return $results;
        }

        $tableWriterMethods = [
            'insert' => 2,
            'update' => 3,
            'delete' => 2,
            'replace' => 3,
        ];
        if (isset($tableWriterMethods[$method])) {
            $count = $tableWriterMethods[$method];
            $isWriter = true;
            if (count($args) === $count + 1) {
                $isWriter = array_pop($args);
            }
            $shardKey = count($args) === $count ? array_pop($args) : null;
            $connect = $this->connect($shardKey, $isWriter);
            array_unshift($args, $this->getTable($shardKey));
            $results = call_user_func_array([$connect, $method], $args);
            $this->log2somewhere();
            return $results;
        }

        $normalMethods = [
            'id' => 1,
            'debug' => 1,
            'error' => 1,
            'last' => 1,
            'log' => 1,
            'info' => 1,
        ];
        if (isset($normalMethods[$method])) {
            $count = $normalMethods[$method];
            $isWriter = true;
            if (count($args) === $count + 1) {
                $isWriter = array_pop($args);
            }
            $shardKey = count($args) === $count ? array_pop($args) : null;
            $connect = $this->lastConnection ?? $this->connect($shardKey, $isWriter);
            $results = call_user_func_array([$connect, $method], $args);
            return $results;
        }

        throw new \BadMethodCallException("Not implement method " . static::class . "::$method()");
    }
}
