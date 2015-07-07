Cloudant Query and ActiveRecord for Yii 2
==============================================

This extension provides preliminary [cloudant](http://www.cloudant.com/) integration for the Yii2 framework.
It includes basic querying/search support and also implements the `ActiveRecord` pattern that allows you to store active records in cloudant.

This repository is heavily based on yiisoft/yii2-elasticsearch <https://github.com/yiisoft/yii2>.
Please note: this is work in progress and only basic functionality is ported / implemented.

For license information check the [LICENSE](LICENSE.md)-file.

Requirements
------------

cloudant version 1.0 or higher is required.

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist squio/yii2-cloudant
```

or add

```json
"squio/yii2-cloudant": "dev-master"
```

to the require section of your composer.json.

Configuration
-------------

To use this extension, you have to configure the Connection class in your application configuration:

```php
return [
    //....
    'components' => [
        'cloudant' => [
            'class' => 'yii\cloudant\Connection',
            'autodetectCluster' => false,
            'nodes' => [
                [
                    'http_address' => 'my_account.cloudant.com',
                    'auth' => 'username:password',
                    'ssl' => true,
                ],
                // configure more hosts if you have a cluster
            ],
        ],
    ]
];
```

Using the Query
---------------

Cloudant Query is heavily used for search operations.
This needs the right indexes defined for each field which is part of a search or sorting query.
Creating those indexes is not (yet) part of this extension and needs to be done manually through
Cloudant's web administration dashboard or any other tool.

A helpful blog post can be found here: [introducing cloudant query](https://cloudant.com/blog/introducing-cloudant-query/)


Using the ActiveRecord
----------------------

For general information on how to use yii's ActiveRecord please refer to the [guide](https://github.com/yiisoft/yii2/blob/master/docs/guide/db-active-record.md).

For defining an cloudant ActiveRecord class your record class needs to extend from [[yii\cloudant\ActiveRecord]] and
implement at least the [[yii\cloudant\ActiveRecord::attributes()|attributes()]] method to define the attributes of the record as well as the fields 'database' and 'indexes', see example below.

The `_id` field of a document/record can be accessed using [[yii\cloudant\ActiveRecord::getPrimaryKey()|getPrimaryKey()]] and
[[yii\cloudant\ActiveRecord::setPrimaryKey()|setPrimaryKey()]].

The following is an example model called `Customer`:

```php
class Customer extends \yii\cloudant\ActiveRecord
{
    /**
     * @var $database - the name of the database AKA table, collection etc.
     * example: myaccount.cloudant.com/databasename
     */
    public static $database = "customers_1_1";

    // public static $type = "customer"; // inferred from model class
    // NOTE: the Cloudant docs need to have a field called 'type'
    // which is matched for type 'customer' in find() queries

    // Design documents defining views at Cloudant
    // used in yii\cloudant\ActiveRecord::indexes()
    // Make sure the 'count' index exists, this is needed for paging views
    protected static $indexes = [
        'count' => [
            'design' => 'view-count',     // design document name
            'view' => 'count-customers'], // view within design document
    ];

    /**
     * @return array the list of attributes for this record
     */
    public function attributes()
    {
        // valid fields in Cloudant docs for Customers
        // only docs with type = customer are returned!
        return ['_id', 'type', 'name', 'address', 'registration_date'];
    }

    /**
     * @return ActiveQuery defines a relation to the Order record (can be in other database, e.g. redis or sql)
     */
    public function getOrders()
    {
        return $this->hasMany(Order::className(), ['customer_id' => 'id'])->orderBy('id');
    }

    /**
     * Defines a scope that modifies the `$query` to return only active(status = 1) customers
     */
    public static function active($query)
    {
        $query->andWhere(['status' => 1]);
    }
}
```

The general usage of cloudant ActiveRecord is very similar to the database ActiveRecord as described in the
[guide](https://github.com/yiisoft/yii2/blob/master/docs/guide/active-record.md).
It supports the same interface and features except the following limitations and additions(*!*):

- As cloudant does not support SQL, the query API does not support `join()`, `groupBy()`, `having()` and `union()`.
  Sorting, limit, offset and conditional where are all supported.
- `select()` has been replaced with [[yii\cloudant\ActiveQuery::fields()|fields()]] which basically does the same but
  `fields` is more cloudant terminology.
  It defines the fields to retrieve from a document.
- It is also possible to define relations from cloudant ActiveRecords to normal ActiveRecord classes and vice versa.



Usage example:

NOTE: not all is tested or even implemented for Cloudant yet!

```php
$customer = new Customer();
$customer->primaryKey = 1; // in this case equivalent to $customer->_id = 1;
$customer->attributes = ['name' => 'test'];
$customer->save();

$customer = Customer::get(1); // get a record by pk
$customers = Customer::mget([1,2,3]); // get multiple records by pk
$customer = Customer::find()->where(['name' => 'test'])->one(); // find by query, note that you need to configure mapping for this field in order to find records properly
$customers = Customer::find()->active()->all(); // find all by query (using the `active` scope)


$query->all(); // gives you all the documents

etc...
```


Using the cloudant DebugPanel
----------------------------------

The yii2 cloudant extensions provides a `DebugPanel` that can be integrated with the yii debug module
and shows the executed cloudant queries. It also allows to run these queries
and view the results.

Add the following to you application config to enable it (if you already have the debug module
enabled, it is sufficient to just add the panels configuration):

```php
    // ...
    'bootstrap' => ['debug'],
    'modules' => [
        'debug' => [
            'class' => 'yii\\debug\\Module',
            'panels' => [
                'cloudant' => [
                    'class' => 'yii\\cloudant\\DebugPanel',
                ],
            ],
        ],
    ],
    // ...
```
