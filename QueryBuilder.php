<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\cloudant;

use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\helpers\Json;

/**
 * QueryBuilder builds an cloudant query based on the specification given as a [[Query]] object.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class QueryBuilder extends \yii\base\Object
{
    /**
     * @var Connection the database connection.
     */
    public $db;


    /**
     * Constructor.
     * @param Connection $connection the database connection.
     * @param array $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct($connection, $config = [])
    {
        $this->db = $connection;
        parent::__construct($config);
    }

    /**
     * Generates query from a [[Query]] object.
     * @param Query $query the [[Query]] object from which the query will be generated
     * @return array the generated SQL statement (the first array element) and the corresponding
     * parameters to be bound to the SQL statement (the second array element).
     */
    public function build($query)
    {
        $parts = [];

        if ($query->fields === []) {
            $parts['fields'] = [];
        } elseif ($query->fields !== null) {
            $fields = [];
            foreach ($query->fields as $key => $field) {
                if (is_int($key)) {
                    // $fields[] = $field;
                } else {
                    $fields[$key] = $field;
                }
            }
            if (!empty($fields)) {
                $parts['fields'] = $fields;

                // always include id and rev
                if (!in_array("_rev", $parts)) {
                    array_unshift($parts, "_rev");
                }
                if (!in_array("_id", $parts)) {
                    array_unshift($parts, "_id");
                }
            } else {
                $parts['fields'] = ["_id", "_rev"];
            }
        }
        if ($query->source !== null) {
            $parts['_source'] = $query->source;
        }
        if ($query->limit !== null && $query->limit >= 0) {
            $parts['limit'] = $query->limit;
        }
        if ($query->offset > 0) {
            $parts['skip'] = (int) $query->offset;
        }

        if (empty($query->query)) {
            // $parts['query'] = ["match_all" => (object) []];
        } else {
            $parts['query'] = $query->query;
        }

        $whereSelector = $this->buildCondition($query->where);
        if (is_string($query->selector)) {
            if (empty($whereSelector)) {
                $parts['selector'] = $query->selector;
            } else {
                $parts['selector'] = '{"and": [' . $query->selector . ', ' . Json::encode($whereSelector) . ']}';
            }
        } elseif ($query->selector !== null) {
            if (empty($whereSelector)) {
                $parts['selector'] = $query->selector;
            } else {
                $parts['selector'] = ['and' => [$query->selector, $whereSelector]];
            }
        } elseif (!empty($whereSelector)) {
            $parts['selector'] = $whereSelector;
        } else {
            $parts['selector'] = ["_id" => [ '$gt' => 0]];
        }

        $sort = $this->buildOrderBy($query->orderBy);
        if (!empty($sort)) {
            $parts['sort'] = $sort;
            // Add sort fields to selector so Cloudant can use them for sorting.
            // NOTE this needs a sorting index set up for these fields at Cloudant!
            // @see https://docs.cloudant.com/cloudant_query.html#creating-an-index
            foreach ($sort as $key => $value) {
                foreach ($value as $k => $v) {
                    // skip sort keys which are already used in selector
                    if (empty($parts['selector'][ $k ])) {
                        $parts['selector'][ $k ] = [ '$gt' => 0 ];
                    }
                }
            }
        }

        $options = [];
        if ($query->timeout !== null) {
            $options['timeout'] = $query->timeout;
        }
// var_dump($query);
// var_dump($parts); exit; //FIXME

        return [
            'queryParts' => $parts,
            'index' => $query->index,
            'type' => $query->type,
            'database' => $query->database,
            'options' => $options,
        ];
    }

    /**
     * adds order by condition to the query
     */
    public function buildOrderBy($columns)
    {
        if (empty($columns)) {
            return [];
        }
        $orders = [];
        foreach ($columns as $name => $direction) {
            if (is_string($direction)) {
                $column = $direction;
                $direction = SORT_ASC;
            } else {
                $column = $name;
            }

            // allow cloudant extended syntax as described in http://www.cloudant.org/guide/reference/api/search/sort/
            if (is_array($direction)) {
                $orders[] = [$column => $direction];
            } else {
                $orders[] = [$column => ($direction === SORT_DESC ? 'desc' : 'asc')];
            }
        }

        return $orders;
    }

    /**
     * Converts "\yii\db\*" quick condition keyword into actual Mongo condition keyword.
     * @param string $key raw condition key.
     * @return string actual key.
     * based on yii2-mongodb/Collection.php
     */
    protected function normalizeConditionKeyword($key)
    {
        static $map = [
            'AND' => '$and',
            'OR' => '$or',
            'IN' => '$in',
            'NOT IN' => '$nin',
        ];
        $matchKey = strtoupper($key);
        if (array_key_exists($matchKey, $map)) {
            return $map[$matchKey];
        } else {
            return $key;
        }
    }

    /**
     * Parses the condition specification and generates the corresponding Mongo condition.
     * @param array $condition the condition specification. Please refer to [[Query::where()]]
     * on how to specify a condition.
     * @return array the generated Mongo condition
     * @throws InvalidParamException if the condition is in bad format
     */
    public function buildCondition($condition)
    {
        static $builders = [
            'AND' => 'buildAndCondition',
            'OR' => 'buildOrCondition',
            'BETWEEN' => 'buildBetweenCondition',
            'NOT BETWEEN' => 'buildBetweenCondition',
            'IN' => 'buildInCondition',
            'NOT IN' => 'buildInCondition',
            'REGEX' => 'buildRegexCondition',
            'LIKE' => 'buildLikeCondition',
        ];

        if (!is_array($condition)) {
            throw new NotSupportedException('String conditions in where() are not supported by cloudant.');
            // simple scalar value: return without further processing?
            // return $condition;
        } elseif (empty($condition)) {
            return [];
        }
        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            if (isset($builders[$operator])) {
                $method = $builders[$operator];
                array_shift($condition);
                return $this->$method($operator, $condition);
            } else {
                throw new InvalidParamException('Found unknown operator in query: ' . $operator);
            }
        } else {
            // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            return $this->buildHashCondition($condition);
        }
    }



    /**
     * Creates a condition based on column-value pairs.
     * @param array $condition the condition specification.
     * @return array the generated condition.
     * based on MongoDB/Collection.php
     */
    private function buildHashCondition($condition)
    {
        $result = [];
        foreach ($condition as $name => $value) {
            if (strncmp('$', $name, 1) === 0) {
                // Native Cloudant Query condition:
                $result[$name] = $value;
            } else {
                if (is_array($value)) {
                    if (array_key_exists(0, $value)) {
                        // Quick IN condition:
                        $result = array_merge($result, $this->buildInCondition('IN', [$name, $value]));
                    } else {
                        // Mongo complex condition:
                        $result[$name] = $value;
                    }
                } else {
                    // Direct match:
                    $result[$name] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Connects two or more conditions with the `AND` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the Mongo conditions to connect.
     * @return array the generated Mongo condition.
     */
    private function buildAndCondition($operator, $operands)
    {
        $operator = $this->normalizeConditionKeyword($operator);
        $parts = [];
        foreach ($operands as $operand) {
            $parts[] = $this->buildCondition($operand);
        }

        return [$operator => $parts];
    }

    /**
     * Connects two or more conditions with the `OR` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the Mongo conditions to connect.
     * @return array the generated Mongo condition.
     */
    private function buildOrCondition($operator, $operands)
    {
        $operator = $this->normalizeConditionKeyword($operator);
        $parts = [];
        foreach ($operands as $operand) {
            $parts[] = $this->buildCondition($operand);
        }

        return [$operator => $parts];
    }

    /**
     * Creates an Mongo condition, which emulates the `BETWEEN` operator.
     * @param string $operator the operator to use
     * @param array $operands the first operand is the column name. The second and third operands
     * describe the interval that column value should be in.
     * @return array the generated Mongo condition.
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    private function buildBetweenCondition($operator, $operands)
    {
        if (!isset($operands[0], $operands[1], $operands[2])) {
            throw new InvalidParamException("Operator '$operator' requires three operands.");
        }
        list($column, $value1, $value2) = $operands;
        if (strncmp('NOT', $operator, 3) === 0) {
            return [
                $column => [
                    '$lt' => $value1,
                    '$gt' => $value2,
                ]
            ];
        } else {
            return [
                $column => [
                    '$gte' => $value1,
                    '$lte' => $value2,
                ]
            ];
        }
    }

    /**
     * Creates an Mongo condition with the `IN` operator.
     * @param string $operator the operator to use (e.g. `IN` or `NOT IN`)
     * @param array $operands the first operand is the column name. If it is an array
     * a composite IN condition will be generated.
     * The second operand is an array of values that column value should be among.
     * @return array the generated Mongo condition.
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    private function buildInCondition($operator, $operands)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        $values = (array) $values;

        if (!is_array($column)) {
            $columns = [$column];
            $values = [$column => $values];
        } elseif (count($column) < 2) {
            $columns = $column;
            $values = [$column[0] => $values];
        } else {
            $columns = $column;
        }

        $operator = $this->normalizeConditionKeyword($operator);
        $result = [];
        foreach ($columns as $column) {
            $inValues = $values[$column];
            $result[$column][$operator] = array_values($inValues);
        }

        return $result;
    }

    /**
     * Creates a regular expression condition.
     * @param string $operator the operator to use
     * @param array $operands the first operand is the column name.
     * The second operand is a single value that column value should be compared with.
     * @return array the generated Mongo condition.
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildRegexCondition($operator, $operands)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }
        list($column, $value) = $operands;
        if (preg_match("/${value}/", null) === false) {
            throw new InvalidParamException("Invalid regex: '$value'");
        }
        return [$column => [ '$regex' => $value ]];
    }

    /**
     * Creates a Regexp condition, which emulates the `LIKE` operator.
     * @param string $operator the operator to use
     * @param array $operands the first operand is the column name.
     * The second operand is a single value that column value should be compared with.
     * @return array the generated Mongo condition.
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildLikeCondition($operator, $operands)
    {
        return $this->buildRegexCondition($operator, $operands);
    }

    protected function buildCompositeInCondition($operator, $columns, $values)
    {
        throw new NotSupportedException('composite in is not supported by cloudant.');
    }
}
