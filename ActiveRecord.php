<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\cloudant;

use Yii;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\db\BaseActiveRecord;
use yii\db\StaleObjectException;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\helpers\Json;
use yii\helpers\StringHelper;

/**
 * ActiveRecord is the base class for classes representing relational data in terms of objects.
 *
 * This class implements the ActiveRecord pattern for the fulltext search and data storage
 * [cloudant](http://www.cloudant.org/).
 *
 * For defining a record a subclass should at least implement the [[attributes()]] method to define
 * attributes.
 * The primary key (the `_id` field in cloudant terms) is represented by `getId()` and `setId()`.
 * The primary key is not part of the attributes.
 *
 * The following is an example model called `Customer`:
 *
 * ```php
 * class Customer extends \yii\cloudant\ActiveRecord
 * {
 *     public function attributes()
 *     {
 *         return ['id', 'name', 'address', 'registration_date'];
 *     }
 * }
 * ```
 *
 * You may override [[index()]] and [[type()]] to define the index and type this record represents.
 *
 * @property array|null $highlight A list of arrays with highlighted excerpts indexed by field names. This
 * property is read-only.
 * @property float $score Returns the score of this record when it was retrieved via a [[find()]] query. This
 * property is read-only.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class ActiveRecord extends BaseActiveRecord
{
    private $_id;
    private $_score;
    private $_rev;
	protected $_attachments;
	protected static $database;
	protected static $type;     // default: inferred from model class

    /** example:
     * protected static $indexes = [
	 *	'count' => [
	 *		'design' => 'view-count',
	 *		'view' => 'count-opnames'],
	 * ];
     */
    protected static $indexes;

    /**
     * Returns the database connection used by this AR class.
     * By default, the "cloudant" application component is used as the database connection.
     * You may override this method if you want to use a different database connection.
     * @return Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return \Yii::$app->get('cloudant');
    }

    /**
	 * Defines a list of indexes defined at Cloudant
     * @return object the list of indexes for this record
     * usage: indexes()->name->design; indexes()->name->view;
     * NOTE: this method uses a static array $indexes from the model class.
     * This array must provide at least a design document and view for the 'count' query.
	 */
	public static function indexes() {
        if (empty(static::$indexes)) {
            throw new Exception('static Array $indexes must be defined in ' . get_called_class());
        }
		function arrayToObject($d) {
	        if (is_array($d)) {
	            /*
	            * Return array converted to object
	            * Using __FUNCTION__ (Magic constant)
	            * for recursive call
	            */
	            return (object) array_map(__FUNCTION__, $d);
	        }
	        else {
	            // Return object
	            return $d;
	        }
	    }
		return arrayToObject( static::$indexes );
    }

    /**
     * @inheritdoc
     * @return ActiveQuery the newly created [[ActiveQuery]] instance.
     * NOTE only records with a 'type' field with value modelclass (lowercase) are returned.
     */
    public static function find()
    {
        $query = Yii::createObject(ActiveQuery::className(), [get_called_class()]);
        $condition = ['type' => ['$eq' => static::type() ]];
        return $query->andWhere($condition);
    }

    /**
     * @inheritdoc
     */
    public static function findOne($condition)
    {
        $query = static::find();
        if (is_array($condition)) {
            return $query->andWhere($condition)->one();
        } else {
            return static::get($condition);
        }
    }

    /**
     * @inheritdoc
     */
    public static function findAll($condition)
    {
        $query = static::find();
        if (ArrayHelper::isAssociative($condition)) {
            return $query->andWhere($condition)->all();
        } else {
            return static::mget((array) $condition);
        }
    }

    /**
     * Gets a record by its primary key.
     *
     * @param mixed $primaryKey the primaryKey value
     * @param array $options options given in this parameter are passed to cloudant
     * as request URI parameters.
     * Please refer to the [cloudant documentation](http://www.cloudant.org/guide/en/cloudant/reference/current/docs-get.html)
     * for more details on these options.
     * @return static|null The record instance or null if it was not found.
     */
    public static function get($primaryKey, $options = [])
    {
        if ($primaryKey === null) {
            return null;
        }
        $command = static::getDb()->createCommand();
        $result = $command->get(static::database(), static::type(), $primaryKey, $options);

		if ($result['_id'] === $primaryKey) {
            $model = static::instantiate($result);
            static::populateRecord($model, $result);
            $model->afterFind();

            return $model;
        }

        return null;
    }

    /**
     * Gets a list of records by its primary keys.
     *
     * @param array $primaryKeys an array of primaryKey values
     * @param array $options options given in this parameter are passed to cloudant
     * as request URI parameters.
     *
     * Please refer to the [cloudant documentation](http://www.cloudant.org/guide/en/cloudant/reference/current/docs-get.html)
     * for more details on these options.
     * @return array The record instances, or empty array if nothing was found
     */
    public static function mget(array $primaryKeys, $options = [])
    {
        if (empty($primaryKeys)) {
            return [];
        }
        if (count($primaryKeys) === 1) {
            $model = static::get(reset($primaryKeys));
            return $model === null ? [] : [$model];
        }

        $command = static::getDb()->createCommand();
        $result = $command->mget(static::index(), static::type(), $primaryKeys, $options);
        $models = [];
        foreach ($result['docs'] as $doc) {
            if ($doc['found']) {
                $model = static::instantiate($doc);
                static::populateRecord($model, $doc);
                $model->afterFind();
                $models[] = $model;
            }
        }

        return $models;
    }


    /**
     * Sets the primary key
     * @param mixed $value
     * @throws \yii\base\InvalidCallException when record is not new
     */
    public function setPrimaryKey($value)
    {
        $pk = static::primaryKey()[0];
        if ($this->getIsNewRecord() || $pk != '_id') {
            $this->$pk = $value;
        } else {
            throw new InvalidCallException('Changing the primaryKey of an already saved record is not allowed.');
        }
    }

    /**
     * @inheritdoc
     */
    public function getPrimaryKey($asArray = false)
    {
        $pk = static::primaryKey()[0];
        if ($asArray) {
            return [$pk => $this->$pk];
        } else {
            return $this->$pk;
        }
    }

    /**
     * @inheritdoc
     */
    public function getOldPrimaryKey($asArray = false)
    {
        $pk = static::primaryKey()[0];
        if ($this->getIsNewRecord()) {
            $id = null;
        } elseif ($pk == '_id') {
            $id = $this->_id;
        } else {
            $id = $this->getOldAttribute($pk);
        }
        if ($asArray) {
            return [$pk => $id];
        } else {
            return $id;
        }
    }

    /**
     * This method defines the attribute that uniquely identifies a record.
     *
     * The primaryKey for cloudant documents is the `_id` field by default. This field is not part of the
     * ActiveRecord attributes so you should never add `_id` to the list of [[attributes()|attributes]].
     *
     * You may override this method to define the primary key name when you have defined
     * [path mapping]
     * for the `_id` field so that it is part of the `_source` and thus part of the [[attributes()|attributes]].
     *
     * Note that cloudant only supports _one_ attribute to be the primary key. However to match the signature
     * of the [[\yii\db\ActiveRecordInterface|ActiveRecordInterface]] this methods returns an array instead of a
     * single string.
     *
     * @return string[] array of primary key attributes. Only the first element of the array will be used.
     */
    public static function primaryKey()
    {
        return ['_id'];
    }

    /**
     * Returns the list of all attribute names of the model.
     *
     * This method must be overridden by child classes to define available attributes.
     *
     * Attributes are names of fields of the corresponding cloudant document.
     * The primaryKey for cloudant documents is the `_id` field by default which is not part of the attributes.
     * You may define [path mapping](http://www.cloudant.org/guide/en/cloudant/reference/current/mapping-id-field.html)
     * for the `_id` field so that it is part of the `_source` fields and thus becomes part of the attributes.
     *
     * @return string[] list of attribute names.
     * @throws \yii\base\InvalidConfigException if not overridden in a child class.
     */
    public function attributes()
    {
        throw new InvalidConfigException('The attributes() method of cloudant ActiveRecord has to be implemented by child classes.');
    }

    /**
     * A list of attributes that should be treated as array valued when retrieved through [[ActiveQuery::fields]].
     *
     * If not listed by this method, attributes retrieved through [[ActiveQuery::fields]] will converted to a scalar value
     * when the result array contains only one value.
     *
     * @return string[] list of attribute names. Must be a subset of [[attributes()]].
     */
    public function arrayAttributes()
    {
        return [];
    }

    /**
     * @return string the name of the index this record is stored in.
     */
    public static function index()
    {
        return Inflector::pluralize(Inflector::camel2id(StringHelper::basename(get_called_class()), '-'));
    }

    /**
     * @return string the name of the type of this record.
	 * This is the canonical string notation of the ModelClass.
	 * You can override this by explicitly setting 
	 * protected static property $model->type.
     */
    public static function type()
    {
		if (static::$type !== null) {
			return static::$type;
		} else {
        	return Inflector::camel2id(StringHelper::basename(get_called_class()), '-');
		}
    }


    /**
     * @return string the name of the type of this record.
     */
    public static function database()
    {
        return static::$database;
    }




    /**
     * @inheritdoc
     *
     * @param ActiveRecord $record the record to be populated. In most cases this will be an instance
     * created by [[instantiate()]] beforehand.
     * @param array $row attribute values (name => value)
     */
    public static function populateRecord($record, $row)
    {
        $attributes = [];

//        if (isset($row['_source'])) {
//            $attributes = $row['_source'];
//        }
//
//		if (isset($row['fields'])) {
//            // reset fields in case it is scalar value
//            $arrayAttributes = $record->arrayAttributes();
//            foreach($row['fields'] as $key => $value) {
//                if (!isset($arrayAttributes[$key]) && count($value) == 1) {
//                    $row['fields'][$key] = reset($value);
//                }
//            }
//            $attributes = array_merge($attributes, $row['fields']);
//        }

		$attributes = $row; // just a plain document returned as array from query

        parent::populateRecord($record, $attributes);

        $pk = static::primaryKey()[0];//TODO should always set ID in case of fields are not returned
        if ($pk === '_id') {
            $record->_id = $row['_id'];
        }
//        $record->_highlight = isset($row['highlight']) ? $row['highlight'] : null;
//        $record->_score = isset($row['_score']) ? $row['_score'] : null;
        $record->_attachments = isset($row['_attachments']) ? $row['_attachments'] : [];
        $record->_rev = isset($row['_rev']) ? $row['_rev'] : null; // TODO version should always be available...
    }

    /**
     * Creates an active record instance.
     *
     * This method is called together with [[populateRecord()]] by [[ActiveQuery]].
     * It is not meant to be used for creating new records directly.
     *
     * You may override this method if the instance being created
     * depends on the row data to be populated into the record.
     * For example, by creating a record based on the value of a column,
     * you may implement the so-called single-table inheritance mapping.
     * @param array $row row data to be populated into the record.
     * This array consists of the following keys:
     *  - `_source`: refers to the attributes of the record.
     *  - `_type`: the type this record is stored in.
     *  - `_index`: the index this record is stored in.
     * @return static the newly created active record
     */
    public static function instantiate($row)
    {
        return new static;
    }

    /**
     * Inserts a document into the associated index using the attribute values of this record.
     *
     * This method performs the following steps in order:
     *
     * 1. call [[beforeValidate()]] when `$runValidation` is true. If validation
     *    fails, it will skip the rest of the steps;
     * 2. call [[afterValidate()]] when `$runValidation` is true.
     * 3. call [[beforeSave()]]. If the method returns false, it will skip the
     *    rest of the steps;
     * 4. insert the record into database. If this fails, it will skip the rest of the steps;
     * 5. call [[afterSave()]];
     *
     * In the above step 1, 2, 3 and 5, events [[EVENT_BEFORE_VALIDATE]],
     * [[EVENT_BEFORE_INSERT]], [[EVENT_AFTER_INSERT]] and [[EVENT_AFTER_VALIDATE]]
     * will be raised by the corresponding methods.
     *
     * Only the [[dirtyAttributes|changed attribute values]] will be inserted into database.
     *
     * If the [[primaryKey|primary key]] is not set (null) during insertion,
     * it will be populated with a
     * [randomly generated value](http://www.cloudant.org/guide/en/cloudant/reference/current/docs-index_.html#_automatic_id_generation)
     * after insertion.
     *
     * For example, to insert a customer record:
     *
     * ~~~
     * $customer = new Customer;
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->insert();
     * ~~~
     *
     * @param boolean $runValidation whether to perform validation before saving the record.
     * If the validation fails, the record will not be inserted into the database.
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes will be saved.
     * @param array $options options given in this parameter are passed to cloudant
     * as request URI parameters. These are among others:
     *
     * - `routing` define shard placement of this record.
     * - `parent` by giving the primaryKey of another record this defines a parent-child relation
     * - `timestamp` specifies the timestamp to store along with the document. Default is indexing time.
     *
     * Please refer to the [cloudant documentation](http://www.cloudant.org/guide/en/cloudant/reference/current/docs-index_.html)
     * for more details on these options.
     *
     * By default the `op_type` is set to `create`.
     * @return boolean whether the attributes are valid and the record is inserted successfully.
     */
    public function insert($runValidation = true, $attributes = null, $options = [ ])
    {
        if ($runValidation && !$this->validate($attributes)) {
            return false;
        }
        if (!$this->beforeSave(true)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);

        $response = static::getDb()->createCommand()->insert(
            static::database(),
            // static::type(),
            $values,
            $this->getPrimaryKey(),
            $options
        );

        $pk = static::primaryKey()[0];
        $this->$pk = $response['id'];
        if ($pk != '_id') {
            $values[$pk] = $response['id'];
        }
        $this->_rev = $response['rev'];
        // explicitly set updated attributes
        $this->setAttribute("_id", $response['id']);
        $this->setAttribute("_rev", $response['rev']);
        $this->_score = null;

        $changedAttributes = array_fill_keys(array_keys($values), null);
        $this->setOldAttributes($values);
        $this->afterSave(true, $changedAttributes);

        return true;
    }

    /**
     * @inheritdoc
     *
     * @param boolean $runValidation whether to perform validation before saving the record.
     * If the validation fails, the record will not be inserted into the database.
     * @param array $attributeNames list of attribute names that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @param array $options options given in this parameter are passed to cloudant
     * as request URI parameters. These are among others:
     *
     * - `routing` define shard placement of this record.
     * - `parent` by giving the primaryKey of another record this defines a parent-child relation
     * - `timeout` timeout waiting for a shard to become available.
     * - `replication` the replication type for the delete/index operation (sync or async).
     * - `consistency` the write consistency of the index/delete operation.
     * - `refresh` refresh the relevant primary and replica shards (not the whole index) immediately after the operation occurs, so that the updated document appears in search results immediately.
     * - `detect_noop` this parameter will become part of the request body and will prevent the index from getting updated when nothing has changed.
     *
     * Please refer to the [cloudant documentation](http://www.cloudant.org/guide/en/cloudant/reference/current/docs-update.html#_parameters_3)
     * for more details on these options.
     *
     * The following parameters are Yii specific:
     *
     * - `optimistic_locking` set this to `true` to enable optimistic locking, avoid updating when the record has changed since it
     *   has been loaded from the database. Yii will set the `version` parameter to the value stored in [[version]].
     *   See the [cloudant documentation](http://www.cloudant.org/guide/en/cloudant/guide/current/optimistic-concurrency-control.html) for details.
     *
     *   Make sure the record has been fetched with a [[version]] before. This is only the case
     *   for records fetched via [[get()]] and [[mget()]] by default. For normal queries, the `_rev` field has to be fetched explicitly.
     *
     * @return integer|boolean the number of rows affected, or false if validation fails
     * or [[beforeSave()]] stops the updating process.
     * @throws StaleObjectException if optimistic locking is enabled and the data being updated is outdated.
     * @throws InvalidParamException if no [[version]] is available and optimistic locking is enabled.
     * @throws Exception in case update failed.
    */
    public function update($runValidation = true, $attributeNames = null, $options = [])
    {
        if ($runValidation && !$this->validate($attributeNames)) {
            return false;
        }
        return $this->updateInternal($attributeNames, $options);
    }

    /**
     * @see update()
     * @param array $attributes attributes to update
     * @param array $options options given in this parameter are passed to cloudant
     * as request URI parameters. See [[update()]] for details.
     * @return integer|boolean the number of rows affected, or false if validation fails
     * or [[beforeSave()]] stops the updating process.
     * @throws StaleObjectException if optimistic locking is enabled and the data being updated is outdated.
     * @throws InvalidParamException if no [[version]] is available and optimistic locking is enabled.
     * @throws Exception in case update failed.
     */
    protected function updateInternal($attributes = null, $options = [])
    {
        if (!$this->beforeSave(false)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $this->afterSave(false, $values);
            return 0;
        }
        // $values["_id"] = $this->attributes["_id"];
        // $values["_rev"] = $this->attributes["_rev"];

        // Need to submit all values, regardless of change.
        $values = $this->attributes;

        try {
            $result = static::getDb()->createCommand()->update(
                static::database(),
                $this->getOldPrimaryKey(false),
                $values,
                $options
            );
        } catch(Exception $e) {
            // HTTP 409 is the response in case of failed optimistic locking
            // TODO implement for Cloudant
            if (isset($e->errorInfo['responseCode']) && $e->errorInfo['responseCode'] == 409) {
                throw new StaleObjectException('The object being updated is outdated.', $e->errorInfo, $e->getCode(), $e);
            }
            throw $e;
        }

        if (is_array($result) && isset($result['rev'])) {
            $this->_rev = $result['rev'];
        }

        $changedAttributes = [];
        foreach ($values as $name => $value) {
            $changedAttributes[$name] = $this->getOldAttribute($name);
            $this->setOldAttribute($name, $value);
        }
        $this->afterSave(false, $changedAttributes);

        if ($result === false) {
            return 0;
        } else {
            return 1;
        }
    }

    /**
     * Updates all records whos primary keys are given.
     * For example, to change the status to be 1 for all customers whose status is 2:
     *
     * ~~~
     * Customer::updateAll(['status' => 1], [2, 3, 4]);
     * ~~~
     *
     * @param array $attributes attribute values (name-value pairs) to be saved into the table
     * @param array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
     * Please refer to [[ActiveQuery::where()]] on how to specify this parameter.
     * @return integer the number of rows updated
     * @throws Exception on error.
     */
    public static function updateAll($attributes, $condition = [])
    {
        $pkName = static::primaryKey()[0];
        if (count($condition) == 1 && isset($condition[$pkName])) {
            $primaryKeys = (array)$condition[$pkName];
        } else {
            $primaryKeys = static::find()->where($condition)->column($pkName); // TODO check whether this works with default pk _id
        }
        if (empty($primaryKeys)) {
            return 0;
        }
        $bulk = '';
        foreach ($primaryKeys as $pk) {
            $action = Json::encode([
                "update" => [
                    "_id" => $pk,
                    "_type" => static::type(),
                    "_index" => static::index(),
                ],
            ]);
            $data = Json::encode([
                "doc" => $attributes
            ]);
            $bulk .= $action . "\n" . $data . "\n";
        }

        // TODO do this via command
        $url = [static::index(), static::type(), '_bulk'];
        $response = static::getDb()->post($url, [], $bulk);
        $n = 0;
        $errors = [];
        foreach ($response['items'] as $item) {
            if (isset($item['update']['status']) && $item['update']['status'] == 200) {
                $n++;
            } else {
                $errors[] = $item['update'];
            }
        }
        if (!empty($errors) || isset($response['errors']) && $response['errors']) {
            throw new Exception(__METHOD__ . ' failed updating records.', $errors);
        }

        return $n;
    }

    /**
     * Updates all matching records using the provided counter changes and conditions.
     * For example, to increment all customers' age by 1,
     *
     * ~~~
     * Customer::updateAllCounters(['age' => 1]);
     * ~~~
     *
     * @param array $counters the counters to be updated (attribute name => increment value).
     * Use negative values if you want to decrement the counters.
     * @param string|array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @return integer the number of rows updated
     * @throws Exception on error.
     */
    public static function updateAllCounters($counters, $condition = [])
    {
        $pkName = static::primaryKey()[0];
        if (count($condition) == 1 && isset($condition[$pkName])) {
            $primaryKeys = (array)$condition[$pkName];
        } else {
            $primaryKeys = static::find()->where($condition)->column($pkName); // TODO check whether this works with default pk _id
        }
        if (empty($primaryKeys) || empty($counters)) {
            return 0;
        }
        $bulk = '';
        foreach ($primaryKeys as $pk) {
            $action = Json::encode([
                "update" => [
                    "_id" => $pk,
                    "_type" => static::type(),
                    "_index" => static::index(),
                ],
            ]);
            $script = '';
            foreach ($counters as $counter => $value) {
                $script .= "ctx._source.$counter += $counter;\n";
            }
            $data = Json::encode([
                "script" => $script,
                "params" => $counters,
                "lang" => "groovy",
            ]);
            $bulk .= $action . "\n" . $data . "\n";
        }

        // TODO do this via command
        $url = [static::index(), static::type(), '_bulk'];
        $response = static::getDb()->post($url, [], $bulk);
        $n = 0;
        $errors = [];
        foreach ($response['items'] as $item) {
            if (isset($item['update']['status']) && $item['update']['status'] == 200) {
                $n++;
            } else {
                $errors[] = $item['update'];
            }
        }
        if (!empty($errors) || isset($response['errors']) && $response['errors']) {
            throw new Exception(__METHOD__ . ' failed updating records counters.', $errors);
        }

        return $n;
    }

    /**
     * @inheritdoc
     *
     * @param array $options options given in this parameter are passed to cloudant
     * as request URI parameters. These are among others:
     *
     * - `routing` define shard placement of this record.
     * - `parent` by giving the primaryKey of another record this defines a parent-child relation
     * - `timeout` timeout waiting for a shard to become available.
     * - `replication` the replication type for the delete/index operation (sync or async).
     * - `consistency` the write consistency of the index/delete operation.
     * - `refresh` refresh the relevant primary and replica shards (not the whole index) immediately after the operation occurs, so that the updated document appears in search results immediately.
     *
     * Please refer to the [cloudant documentation](http://www.cloudant.org/guide/en/cloudant/reference/current/docs-delete.html)
     * for more details on these options.
     *
     * The following parameters are Yii specific:
     *
     * - `optimistic_locking` set this to `true` to enable optimistic locking, avoid updating when the record has changed since it
     *   has been loaded from the database. Yii will set the `version` parameter to the value stored in [[version]].
     *   See the [cloudant documentation](http://www.cloudant.org/guide/en/cloudant/reference/current/docs-delete.html#delete-versioning) for details.
     *
     *   Make sure the record has been fetched with a [[version]] before. This is only the case
     *   for records fetched via [[get()]] and [[mget()]] by default. For normal queries, the `_rev` field has to be fetched explicitly.
     *
     * @return integer|boolean the number of rows deleted, or false if the deletion is unsuccessful for some reason.
     * Note that it is possible the number of rows deleted is 0, even though the deletion execution is successful.
     * @throws StaleObjectException if optimistic locking is enabled and the data being deleted is outdated.
     * @throws Exception in case delete failed.
     */
    public function delete($options = [])
    {
        if (!$this->beforeDelete()) {
            return false;
        }

        $options["rev"] = $this->_rev;

        try {
            $result = static::getDb()->createCommand()->delete(
                static::database(),
                $this->getOldPrimaryKey(false),
                $options
            );
        } catch(Exception $e) {
            // HTTP 409 is the response in case of failed matching on _rev
            // https://docs.cloudant.com/document.html#delete
            if (isset($e->errorInfo['responseCode']) && $e->errorInfo['responseCode'] == 409) {
                throw new StaleObjectException('The object being deleted is outdated.', $e->errorInfo, $e->getCode(), $e);
            }
            throw $e;
        }

        $this->setOldAttributes(null);

        $this->afterDelete();

        if ($result === false) {
            return 0;
        } else {
            return 1;
        }
    }

    /**
     * Deletes rows in the table using the provided conditions.
     * WARNING: If you do not specify any condition, this method will delete ALL rows in the table.
     *
     * For example, to delete all customers whose status is 3:
     *
     * ~~~
     * Customer::deleteAll('status = 3');
     * ~~~
     *
     * @param array $condition the conditions that will be put in the WHERE part of the DELETE SQL.
     * Please refer to [[ActiveQuery::where()]] on how to specify this parameter.
     * @return integer the number of rows deleted
     * @throws Exception on error.
     */
    public static function deleteAll($condition = [])
    {
        $pkName = static::primaryKey()[0];
        if (count($condition) == 1 && isset($condition[$pkName])) {
            $primaryKeys = (array)$condition[$pkName];
        } else {
            $primaryKeys = static::find()->where($condition)->column($pkName); // TODO check whether this works with default pk _id
        }
        if (empty($primaryKeys)) {
            return 0;
        }
        $bulk = '';
        foreach ($primaryKeys as $pk) {
            $bulk .= Json::encode([
                "delete" => [
                    "_id" => $pk,
                    "_type" => static::type(),
                    "_index" => static::index(),
                ],
            ]) . "\n";
        }

        // TODO do this via command
        $url = [static::index(), static::type(), '_bulk'];
        $response = static::getDb()->post($url, [], $bulk);
        $n = 0;
        $errors = [];
        foreach ($response['items'] as $item) {
            if (isset($item['delete']['status']) && $item['delete']['status'] == 200) {
                if (isset($item['delete']['found']) && $item['delete']['found']) {
                    $n++;
                }
            } else {
                $errors[] = $item['delete'];
            }
        }
        if (!empty($errors) || isset($response['errors']) && $response['errors']) {
            throw new Exception(__METHOD__ . ' failed deleting records.', $errors);
        }

        return $n;
    }

    /**
     * This method has no effect in Cloudant ActiveRecord.
     *
     * Cloudant ActiveRecord uses [native Optimistic locking](http://www.cloudant.org/guide/en/cloudant/guide/current/optimistic-concurrency-control.html).
     * See [[update()]] for more details.
     */
    public function optimisticLock()
    {
        return null;
    }

    /**
     * Destroys the relationship in current model.
     *
     * This method is not supported by cloudant.
     */
    public function unlinkAll($name, $delete = false)
    {
        throw new NotSupportedException('unlinkAll() is not supported by cloudant, use unlink() instead.');
    }
}
