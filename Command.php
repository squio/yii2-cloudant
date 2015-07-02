<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\cloudant;

use yii\base\Component;
use yii\base\InvalidCallException;
use yii\helpers\Json;

/**
 * The Command class implements the API for accessing the cloudant REST API.
 *
 * Check the [cloudant guide](http://www.cloudant.org/guide/en/cloudant/reference/current/index.html)
 * for details on these commands.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class Command extends Component
{
    /**
     * @var Connection
     */
    public $db;

    /**
     * @var string|array the indexes to execute the query on. Defaults to null meaning all indexes
     * @see ...
     * @TODO: remove
     */
    public $index;
    /**
     * @var string|array the types to execute the query on. Defaults to null meaning all types
     * NOTE: this assumes each record has a "type" field in the database corresponding with the model type
     */
    public $type;
    /**
	 * @var $database - the name of the database AKA table, collection etc.
	 * example: myaccount.cloudant.com/databasename
	 */
	public $database;
    /**
     * @var array list of arrays or json strings that become parts of a query
     */
    public $queryParts;
    public $options = [];


    /**
     * Sends a request to the _search API and returns the result
     * @param array $options
     * @return mixed
     */
    public function search($options = [])
    {
        $query = $this->queryParts;
        // array_unshift($query["sort"], ["type" => "asc"]);
        // $query["sort"] = [ ["type" => "asc"]];
        // $query["selector"]["title"] = ['$exists' => true];
        // var_dump($query);exit;
        if (empty($query)) {
            $query = '{}';
        }
        if (is_array($query)) {
            $query = Json::encode($query);
        }

		$url = $this->database . "/_find";

        return $this->db->post($url, array_merge($this->options, $options), $query);
    }

    /**
     * Sends a request to the delete by query
     * @param array $options
     * @return mixed
     */
    public function deleteByQuery($options = [])
    {
        if (!isset($this->queryParts['query'])) {
            throw new InvalidCallException('Can not call deleteByQuery when no query is given.');
        }
        $query = [
            'query' => $this->queryParts['query'],
        ];
        if (isset($this->queryParts['filter'])) {
            $query['filter'] = $this->queryParts['filter'];
        }
        $query = Json::encode($query);
        $url = [
            $this->index !== null ? $this->index : '_all',
            $this->type !== null ? $this->type : '_all',
            '_query'
        ];

        return $this->db->delete($url, array_merge($this->options, $options), $query);
    }

    /**
     * Sends a request to the _suggest API and returns the result
     * @param string|array $suggester the suggester body
     * @param array $options
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/search-suggesters.html
     */
    public function suggest($suggester, $options = [])
    {
        if (empty($suggester)) {
            $suggester = '{}';
        }
        if (is_array($suggester)) {
            $suggester = Json::encode($suggester);
        }
        $url = [
            $this->index !== null ? $this->index : '_all',
            '_suggest'
        ];

        return $this->db->post($url, array_merge($this->options, $options), $suggester);
    }

    /**
     * Inserts a document into an index
     * @param string $database
     * @param string|array $data json string or array of data to store
     * @param null $id the documents id. If not specified Id will be automatically chosen
     * @param array $options
     * @return mixed
     * @see https://docs.cloudant.com/document.html#documentCreate
     */
    public function insert($database, $data, $id = null, $options = [])
    {
        if (empty($data)) {
            $body = '{}';
        } else {
            $body = is_array($data) ? Json::encode($data) : $data;
        }

        if ($id !== null) {
            return $this->db->put([$database, $id], $options, $body);
        } else {
            return $this->db->post([$database], $options, $body);
        }
    }

    /**
     * gets a document from the index
     * @param $database = Cloudant database name
     * @param $type (ignored)
     * @param $id
     * @param array $options
     * @return mixed
     * @see https://docs.cloudant.com/api.html#read33
     */
    public function get($database, $type, $id, $options = [])
    {
        return $this->db->get([$database, $id], $options);
    }

    /**
     * gets a document from the view
     * @param $database = Cloudant database name
     * @param $design_doc - the design document
     * @param $view - the name of the view
     * @param array $options
     * @return mixed
     * @see https://docs.cloudant.com/creating_views.html#using-views
     */
    public function getView($design_doc, $view, $options = [])
    {
        $database = $this->database;
        return $this->db->get([$database, "_design", $design_doc, "_view", $view], $options);
    }

    /**
     * gets multiple documents from the index
     *
     * TODO allow specifying type and index + fields
     * @param $index
     * @param $type
     * @param $ids
     * @param array $options
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/docs-multi-get.html
     */
    public function mget($index, $type, $ids, $options = [])
    {
        $body = Json::encode(['ids' => array_values($ids)]);

        return $this->db->get([$index, $type, '_mget'], $options, $body);
    }

    /**
     * gets a documents _source from the index (>=v0.90.1)
     * @param $index
     * @param $type
     * @param $id
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/docs-get.html#_source
     */
    public function getSource($index, $type, $id)
    {
        return $this->db->get([$index, $type, $id]);
    }

    /**
     * gets a document from the index
     * @param $index
     * @param $type
     * @param $id
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/docs-get.html
     */
    public function exists($index, $type, $id)
    {
        return $this->db->head([$index, $type, $id]);
    }

    /**
     * deletes a document from the index
     * @param $database
     * @param $id
     * @param array $options
     * @return mixed
     * @see https://docs.cloudant.com/document.html#delete
     */
    public function delete($database, $id, $options = [])
    {
        return $this->db->delete([$database, $id], $options);
    }

    /**
     * updates a document
     * @param $database
     * @param $id
     * @param array $options
     * @return mixed
     * @see https://docs.cloudant.com/document.html#update
     */
    public function update($database, $id, $data, $options = [])
    {
        $body = empty($data) ? new \stdClass() : $data;
        if (isset($options["detect_noop"])) {
            $body["detect_noop"] = $options["detect_noop"];
            unset($options["detect_noop"]);
        }

        return $this->db->put([$database, $id], $options, Json::encode($body));
    }


    /**
     * creates an index
     * @param $index
     * @param array $configuration
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-create-index.html
     */
    public function createIndex($index, $configuration = null)
    {
        $body = $configuration !== null ? Json::encode($configuration) : null;

        return $this->db->put([$index], [], $body);
    }

    /**
     * deletes an index
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-delete-index.html
     */
    public function deleteIndex($index)
    {
        return $this->db->delete([$index]);
    }

    /**
     * deletes all indexes
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-delete-index.html
     */
    public function deleteAllIndexes()
    {
        return $this->db->delete(['_all']);
    }

    /**
     * checks whether an index exists
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-exists.html
     */
    public function indexExists($index)
    {
        return $this->db->head([$index]);
    }

    /**
     * @param $index
     * @param $type
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-types-exists.html
     */
    public function typeExists($index, $type)
    {
        return $this->db->head([$index, $type]);
    }


    /**
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-open-close.html
     */
    public function openIndex($index)
    {
        return $this->db->post([$index, '_open']);
    }

    /**
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-open-close.html
     */
    public function closeIndex($index)
    {
        return $this->db->post([$index, '_close']);
    }

    /**
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-status.html
     */
    public function getIndexStatus($index = '_all')
    {
        return $this->db->get([$index, '_status']);
    }


    /**
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-clearcache.html
     */
    public function clearIndexCache($index)
    {
        return $this->db->post([$index, '_cache', 'clear']);
    }

    /**
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-flush.html
     */
    public function flushIndex($index = '_all')
    {
        return $this->db->post([$index, '_flush']);
    }

    /**
     * @param $index
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-refresh.html
     */
    public function refreshIndex($index)
    {
        return $this->db->post([$index, '_refresh']);
    }

    /**
     * @param $index
     * @param $type
     * @param $mapping
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-put-mapping.html
     */
    public function setMapping($index, $type, $mapping, $options = [])
    {
        $body = $mapping !== null ? (is_string($mapping) ? $mapping : Json::encode($mapping)) : null;

        return $this->db->put([$index, '_mapping', $type], $options, $body);
    }

    /**
     * @param string $index
     * @param string $type
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-get-mapping.html
     */
    public function getMapping($index = '_all', $type = '_all')
    {
        return $this->db->get([$index, '_mapping', $type]);
    }

    /**
     * @param $index
     * @param $type
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-put-mapping.html
     */
    public function deleteMapping($index, $type)
    {
        return $this->db->delete([$index, '_mapping', $type]);
    }

    /**
     * @param $name
     * @param $pattern
     * @param $settings
     * @param $mappings
     * @param integer $order
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-templates.html
     */
    public function createTemplate($name, $pattern, $settings, $mappings, $order = 0)
    {
        $body = Json::encode([
            'template' => $pattern,
            'order' => $order,
            'settings' => (object) $settings,
            'mappings' => (object) $mappings,
        ]);

        return $this->db->put(['_template', $name], [], $body);

    }

    /**
     * @param $name
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-templates.html
     */
    public function deleteTemplate($name)
    {
        return $this->db->delete(['_template', $name]);

    }

    /**
     * @param $name
     * @return mixed
     * @see http://www.cloudant.org/guide/en/cloudant/reference/current/indices-templates.html
     */
    public function getTemplate($name)
    {
        return $this->db->get(['_template', $name]);
    }
}
