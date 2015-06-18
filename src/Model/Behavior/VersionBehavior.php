<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Josegonzalez\Version\Model\Behavior;

use ArrayObject;
use Cake\Collection\Collection;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\Time;
use Cake\ORM\Behavior;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;

/**
 * This behavior provides a way to version dynamic data by keeping versions
 * in a separate table linked to the original record from another one. Versioned
 * fields can be configured to override those in the main table when fetched or
 * put aside into another property for the same entity.
 *
 * If you want to retrieve all versions for each of the fetched records,
 * you can use the custom `versions` finders that is exposed to the table.
 */
class VersionBehavior extends Behavior
{

    const ASSOC_SUFFIX = '_version';
    const PROPERTY_NAME = '__version';

    /**
     * Table instance
     *
     * @var \Cake\ORM\Table
     */
    protected $_table;

    /**
     * Default config
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'implementedFinders' => ['versions' => 'findVersions'],
        'versionTable' => 'version',
        'versionField' => 'version_id',
        'fields' => null,
        'filterFields' => []
    ];

    /**
     * Constructor hook method.
     *
     * Implement this method to avoid having to overwrite
     * the constructor and call parent.
     *
     * @param array $config The configuration settings provided to this behavior.
     * @return void
     */
    public function initialize(array $config)
    {
        $this->setupFieldAssociations($this->_config['versionTable']);
    }

    /**
     * Creates the associations between the bound table and every field passed to
     * this method.
     *
     * Additionally it creates a `version` HasMany association that will be
     * used for fetching all versions for each record in the bound table
     *
     * @param string $table the table name to use for storing each field version
     * @return void
     */
    public function setupFieldAssociations($table)
    {
        $alias = $this->_table->alias();
        $target = TableRegistry::get($table);

        foreach ($this->_fields() as $field) {
            $name = $this->_table->alias() . '_' . $field . '_' . $table;

            $this->_table->hasOne($name, [
                'className' => $table,
                'foreignKey' => 'foreign_key',
                'joinType' => 'LEFT',
                'conditions' => [
                    $name . '.model' => $alias,
                    $name . '.field' => $field,
                ],
                'propertyName' => $field . static::ASSOC_SUFFIX
            ]);
        }

        $versionsName = $target->alias();
        $this->_table->hasMany($versionsName, [
            'targetTable' => $target,
            'foreignKey' => 'foreign_key',
            'strategy' => 'subquery',
            'conditions' => [$versionsName . '.model' => $alias],
            'propertyName' => static::PROPERTY_NAME,
            'dependent' => true
        ]);
    }

    /**
     * Modifies the entity before it is saved so that versioned fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject $options the options passed to the save method
     * @return void
     */
    public function beforeSave(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        $table = TableRegistry::get($this->_config['versionTable']);
        $newOptions = [$table->alias() => ['validate' => false]];
        $options['associated'] = $newOptions + $options['associated'];

        $fields = $this->_fields();
        $values = $entity->extract($fields);

        $model = $this->_table->alias();
        $primaryKey = $this->_primaryKey();
        $foreignKey = $entity->get($primaryKey);
        $versionField = $this->_config['versionField'];

        $preexistent = $table->find()
            ->select(['version_id'])
            ->where([
                'foreign_key' => $foreignKey,
                'model' => $model
            ])
            ->order(['id desc'])
            ->limit(1)
            ->hydrate(false)
            ->toArray();

        $versionId = Hash::get($preexistent, '0.version_id', 0) + 1;

        $created = new Time();
        foreach ($values as $field => $content) {
            if ($field == $primaryKey || $field == $versionField) {
                continue;
            }

            $filter = $this->_extractFilter($entity);
            $data = [
                'version_id' => $versionId,
                'model' => $model,
                'foreign_key' => $foreignKey,
                'field' => $field,
                'content' => $content,
                'created' => $created,
            ] + $filter;

            $event = new Event('Model.Version.beforeSave', $this, $options);
            $userData = EventManager::instance()->dispatch($event);
            if (isset($userData->result) && is_array($userData->result)) {
                $data = array_merge($data, $userData->result);
            }

            $new[$field] = $table->newEntity($data, [
                'useSetters' => false,
                'markNew' => true
            ]);
        }

        $entity->set(static::PROPERTY_NAME, $new);
        if (!empty($versionField) && in_array($versionField, $fields)) {
            $entity->set($this->_config['versionField'], $versionId);
        }
    }

    /**
     * Unsets the temporary `__version` property after the entity has been saved
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @return void
     */
    public function afterSave(Event $event, EntityInterface $entity)
    {
        $entity->unsetProperty(static::PROPERTY_NAME);
    }

    /**
     * Custom finder method used to retrieve all versions for the found records.
     *
     * Versioned values will be found for each entity under the property `_versions`.
     *
     * ### Example:
     *
     * {{{
     * $article = $articles->find('versions')->first();
     * $firstVersion = $article->get('_versions')[1];
     * }}}
     *
     * @param \Cake\ORM\Query $query The original query to modify
     * @param array $options Options
     * @return \Cake\ORM\Query
     */
    public function findVersions(Query $query, array $options)
    {
        $versionTable = TableRegistry::get($this->_config['versionTable']);
        $table = $versionTable->alias();
        return $query
            ->contain([$table => function (Query $q) use ($table, $options) {
                if (!empty($options['entity'])) {
                    $entity = $options['entity'];

                    $primaryKey = $this->_primaryKey();
                    $foreignKey = $entity->get($primaryKey);

                    $conditions = ["$table.foreign_key IN" => $foreignKey];
                    $conditions += $this->_selectFilter($entity);
                    $q->where($conditions);
                } elseif (!empty($options['primaryKey'])) {
                    $q->where(["$table.foreign_key IN" => $options['primaryKey']]);
                }
                if (!empty($options['versionId'])) {
                    $q->where(["$table.version_id IN" => $options['versionId']]);
                }
                $q->where(["$table.field IN" => $this->_fields()]);

                return $q;
            }])
            ->formatResults([$this, 'groupVersions'], $query::PREPEND);
    }

    /**
     * Modifies the results from a table find in order to merge full version records
     * into each entity under the `_versions` key
     *
     * @param \Cake\Datasource\ResultSetInterface $results Results to modify.
     * @return \Cake\Collection\Collection
     */
    public function groupVersions($results)
    {
        return $results->map(function ($row) {
            $versions = (array)$row->get(static::PROPERTY_NAME);
            $grouped = new Collection($versions);

            $result = [];
            foreach ($grouped->combine('field', 'content', 'version_id') as $versionId => $keys) {
                $version = $this->_table->newEntity($keys + ['version_id' => $versionId], [
                    'markNew' => false,
                    'useSetters' => false,
                    'markClean' => true
                ]);
                $result[$versionId] = $version;
            }

            $options = ['setter' => false, 'guard' => false];
            $row->set('_versions', $result, $options);
            unset($row[static::PROPERTY_NAME]);
            $row->clean();
            return $row;
        });
    }

    /**
     * Returns an array of fields to be versioned.
     *
     * @return array
     */
    protected function _fields()
    {
        $schema = $this->_table->schema();
        $fields = $schema->columns();
        if ($this->_config['fields'] !== null) {
            $fields = array_intersect($fields, (array)$this->_config['fields']);
        }

        return $fields;
    }

    /**
     * Returns simple primary key. No support for composite keys yet.
     *
     * @return string
     */
    protected function _primaryKey()
    {
        $primaryKey = (array)$this->_table->primaryKey();
        return current($primaryKey);
    }

    /**
     * Extracts filter fields from an entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity An entity to filter against.
     * @return array
     */
    protected function _extractFilter(EntityInterface $entity)
    {
        $filterFields = (array)$this->_config['filterFields'];
        $filter = $entity->extract($filterFields);

        return $filter;
    }

    /**
     * Extracts filter fields from an entity for select.
     *
     * @param EntityInterface $entity An entity to filter against.
     * @return array
     */
    protected function _selectFilter(EntityInterface $entity)
    {
        $filter = [];
        foreach ($this->_extractFilter($entity) as $field => $value) {
            $filter["$field is"] = $value;
        }
        return $filter;
    }
}
