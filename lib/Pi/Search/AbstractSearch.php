<?php
/**
 * Pi Engine (http://pialog.org)
 *
 * @link            http://code.pialog.org for the Pi Engine source repository
 * @copyright       Copyright (c) Pi Engine http://pialog.org
 * @license         http://pialog.org/license.txt BSD 3-Clause License
 */

namespace Pi\Search;

use Pi;
use Pi\Application\Api\AbstractApi;
use Pi\Db\Sql\Where;
use Pi\Application\Model\Model;

/**
 * Abstract class for module search
 *
 * @author Taiwen Jiang <taiwenjiang@tsinghua.org.cn>
 */
abstract class AbstractSearch extends AbstractApi
{
    /** @var string Table name */
    protected $table;

    /** @var array columns to search */
    protected $searchIn = array(
        'title',
        'content'
    );

    /** @var array Columns to fetch: table column => meta key */
    protected $meta = array(
        'id'        => 'id',
        'title'     => 'title',
        'content'   => 'content',
        'time'      => 'time',
        'uid'       => 'uid',
    );

    /** @var array Extra conditions */
    protected $condition = array(
        'active'    => 1,
    );

    /** @var array Search order */
    protected $order = array(
        'id DESC'
    );

    /**
     * Search query
     *
     * @param array|string  $terms
     * @param int           $limit
     * @param int           $offset
     * @param array         $condition
     * @param array         $columns
     *
     * @return ResultSet
     */
    public function query(
        $terms,
        $limit  = 0,
        $offset = 0,
        array $condition = array(),
        array $columns = array()
    ) {
        $dataAll = array();
        $countAll = 0;
        $terms = (array) $terms;
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $model = $this->getModel($table);
            $where = $this->buildCondition($terms, $condition, $columns, $table);
            $count = $model->count($where);
            if ($count) {
                $data = $this->fetchResult($model, $where, $limit, $offset, $table);
                $dataAll = array_merge($dataAll, $data);
                $countAll = $countAll + $count;
            }
        }
        $result = $this->buildResult($countAll, $dataAll);
        return $result;
    }

    /**
     * Get table list
     *
     * @return array
     */
    protected function getTables()
    {
        if (is_array($this->table)) {
            $tables = $this->table;
        } else {
            $tables = array();
            $tables[] = $this->table;
        }

        return $tables;
    }

    /**
     * Get table model
     *
     * @param string $table
     *
     * @return Model
     */
    protected function getModel($table = '')
    {
        $table = empty($table) ? $this->table : $table;
        $model = Pi::model($table, $this->module);

        return $model;
    }

    /**
     * Build query condition
     *
     * @param array  $terms
     * @param array  $condition
     * @param array  $columns
     * @param string $table
     *
     * @return Where
     */
    protected function buildCondition(array $terms, array $condition = array(), array $columns = array(), $table = '')
    {
        // Set columns
        if (empty($columns)) {
            $columns = $this->searchIn;
        } else {
            $columns = array_intersect($columns, $this->searchIn);
            $columns = empty($columns) ? $this->searchIn : $columns;
        }

        $where = Pi::db()->where()->or;
        // Create search term clause
        /* foreach ($terms as $term) {
            foreach ($columns as $column) {
                $where->like($column, '%' . $term . '%')->or;
            }
        } */

        foreach ($columns as $column) {
            foreach ($terms as $term) {
                $where->like($column, '%' . $term . '%')->and;
            }
            $where->or;
        }

        // Canonize conditions
        if ($condition) {
            $meta = array_flip($this->meta);
            foreach (array_keys($condition) as $key) {
                if (isset($meta[$key])) {
                    $condition[$meta[$key]] = $condition[$key];
                    unset($condition[$key]);
                }
            }
            $condition = array_merge($this->condition, $condition);
        } else {
            $condition = $this->condition;
        }
        // Create condition clauses
        if ($condition) {
            $where = Pi::db()->where($where);
            $where->add($condition);
        }

        return $where;
    }

    /**
     * Fetch search result count
     *
     * @param Model $model
     * @param Where $where
     *
     * @return int
     */
    protected function fetchCount(Model $model, Where $where)
    {
        $count = $model->count($where);

        return $count;
    }

    /**
     * Fetch search result
     *
     * @param Model  $model
     * @param Where  $where
     * @param int    $limit
     * @param int    $offset
     * @param string $table
     *
     * @return array
     */
    protected function fetchResult(
        Model $model,
        Where $where,
        $limit = 0,
        $offset = 0,
        $table = ''
    ) {
        $data = array();
        $select = $model->select();
        $select->where($where);
        $select->columns(array_keys($this->meta));
        $select->limit($limit)->offset($offset);
        if ($this->order) {
            $select->order($this->order);
        }
        $rowset = $model->selectWith($select);
        foreach ($rowset as $row) {
            $item = array();
            foreach ($this->meta as $column => $field) {
                $item[$field] = $row[$column];
                if ('content' == $field) {
                    $item[$field] = $this->buildContent($item[$field]);
                }
            }
            $item['url'] = $this->buildUrl($item, $table);
            $item['image'] = $this->buildImage($item, $table);
            $data[] = $item;
        }

        return $data;
    }

    /**
     * Formulate content for render
     *
     * @param string $content
     *
     * @return string
     */
    protected function buildContent($content = '')
    {
        $content = mb_substr(strip_tags($content), 0, 255);

        return $content;
    }

    /**
     * Build item link URL
     *
     * @param array  $item
     * @param string $table
     *
     * @return string
     */
    protected function buildUrl(array $item, $table = '')
    {
        return Pi::url('www');
    }

    /**
     * Build item image URL
     *
     * @param array  $item
     * @param string $table
     *
     * @return string
     */
    protected function buildImage(array $item, $table = '')
    {
        return '';
    }

    /**
     * Build search result set
     *
     * @param int   $total
     * @param array $data
     *
     * @return ResultSet
     */
    public function buildResult($total, array $data)
    {
        $result = new ResultSet($total, $data);

        return $result;
    }
}