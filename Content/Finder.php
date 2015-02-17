<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\System;
use Floxim\Floxim\Component\Field;
use Floxim\Floxim\Component\Lang;
use Floxim\Floxim\System\Fx as fx;

class Finder extends System\Finder
{

    static $rel_time = 0;
    static $stored_relations = array();
    public function relations()
    {
        $class = get_called_class();
        if (isset(self::$stored_relations[$class])) {
            return static::$stored_relations[$class];
        }
        
        $relations = array();
        $fields = fx::component($this->component_id)->
                getAllFields()->
                find('type', array(Field\Entity::FIELD_LINK, Field\Entity::FIELD_MULTILINK));
        foreach ($fields as $f) {
            if (!($relation = $f->getRelation())) {
                continue;
            }
            switch ($f['type']) {
                case Field\Entity::FIELD_LINK:
                    $relations[$f->getPropName()] = $relation;
                    break;
                case Field\Entity::FIELD_MULTILINK:
                    $relations[$f['keyword']] = $relation;
                    break;
            }
        }
        $relations ['component'] = array(
            self::BELONGS_TO,
            'component',
            'type',
            'keyword'
        );
        
        self::$stored_relations[$class] = $relations;
        return $relations;
    }
    
    public function getTree($children_key = 'children')
    {
        $data = $this->all();
        $tree = $this->makeTree($data, $children_key);
        return $tree;
    }
    
    public function makeTree($data, $children_key = 'children', $extra_root_ids = array())
    {
        $index_by_parent = array();

        foreach ($data as $item) {
            if (in_array($item['id'], $extra_root_ids)) {
                continue;
            }
            $pid = $item['parent_id'];
            if (!isset($index_by_parent[$pid])) {
                $index_by_parent[$pid] = fx::collection();
                $index_by_parent[$pid]->is_sortable = $data->is_sortable;
                $index_by_parent[$pid]->addFilter('parent_id', $pid);
            }
            $index_by_parent[$pid] [] = $item;
        }

        foreach ($data as $item) {
            if (isset($index_by_parent[$item['id']])) {
                if (isset($item[$children_key]) && $item[$children_key] instanceof \Floxim\Floxim\System\Collection) {
                    $item[$children_key]->concat($index_by_parent[$item['id']]);
                } else {
                    $item[$children_key] = $index_by_parent[$item['id']];
                }
                $data->findRemove(
                    'id',
                    $index_by_parent[$item['id']]->getValues('id')
                );
            } elseif (!isset($item[$children_key])) {
                $item[$children_key] = null;
            }
        }
        return $data;
    }

    protected function getDefaultRelationFinder($rel)
    {
        $finder = parent::getDefaultRelationFinder($rel);
        if (!$finder instanceof Lang\Finder) {
            $finder->order('priority');
        }
        return $finder;
    }

    public function getData()
    {
        $data = parent::getData();
        $types_by_id = $data->getValues('type', 'id');
        unset($types_by_id['']);
        if (count($types_by_id) == 0) {
            return $data;
        }
        $base_component = fx::component($this->component_id);
        $base_type = $base_component['keyword'];
        $base_table = $base_component->getContentTable();
        $types = array();
        foreach ($types_by_id as $id => $type) {
            if ($type != $base_type) {
                if (!isset($types[$type])) {
                    $types[$type] = array();
                }
                $types[$type] [] = $id;
            }
        }
        
        foreach ($types as $type => $ids) {
            if (!$type) {
                continue;
            }
            $type_tables = array_reverse(fx::data($type)->getTables());
            $missed_tables = array();
            foreach ($type_tables as $table) {
                if ($table == $base_table) {
                    break;
                }
                $missed_tables [] = $table;
            }
            $base_missed_table = array_shift($missed_tables);
            $q = "SELECT * FROM `{{" . $base_missed_table . "}}` \n";
            foreach ($missed_tables as $mt) {
                $q .= " INNER JOIN `{{" . $mt . '}}` ON `{{' . $mt . '}}`.id = `{{' . $base_missed_table . "}}`.id\n";
            }
            $q .= "WHERE `{{" . $base_missed_table . "}}`.id IN (" . join(", ", $ids) . ")";
            $extensions = fx::db()->getIndexedResults($q);

            foreach ($data as $data_index => $data_item) {
                if (isset($extensions[$data_item['id']])) {
                    $data[$data_index] = array_merge($data_item, $extensions[$data_item['id']]);
                }
            }
        }
        return $data;
    }

    protected static $_com_tables_cache = array();

    public function getTables()
    {
        if (isset(self::$_com_tables_cache[$this->component_id])) {
            $cached = self::$_com_tables_cache[$this->component_id];
            return $cached;
        }
        $chain = $this->getComponent()->getChain();
        $tables = array();
        foreach ($chain as $comp) {
            $tables [] = $comp->getContentTable();
        }
        self::$_com_tables_cache[$this->component_id] = $tables;
        return $tables;
    }

    protected $component_id = null;

    public function __construct($table = null)
    {
        parent::__construct($table);

        $this->setComponent(fx::getComponentNameByClass(get_class($this)));
    }

    public function setComponent($component_id_or_code)
    {
        $component = fx::component($component_id_or_code);
        if (!$component) {
            die("Component not found: " . $component_id_or_code);
        }
        $this->component_id = $component['id'];
        $this->table = $component->getContentTable();
        return $this;
    }

    public function getComponent()
    {
        return fx::component($this->component_id);
    }

    public function contentExists()
    {
        static $content_by_type = null;
        if (is_null($content_by_type)) {
            $res = fx::db()->getResults(
                'select `type`, count(*) as cnt '
                . 'from {{floxim_main_content}} '
                . 'where site_id = "' . fx::env('site_id') . '" '
                . 'group by `type`'
            );
            $content_by_type = fx::collection($res)->getValues('cnt', 'type');
        }
        return isset($content_by_type[$this->getComponent()->get('keyword')]);
    }

    /**
     * Create new content entity
     * @param array $data Initial params
     * @return fx_content New content entity (not saved yet, without ID)
     */
    public function create($data = array())
    {
        $obj = parent::create($data);

        $component = fx::component($this->component_id);

        $obj['created'] = date("Y-m-d H:i:s");
        if ($component['keyword'] != 'floxim.user.user' && ($user = fx::env()->getUser())) {
            $obj['user_id'] = $user['id'];
        }
        $obj['type'] = $component['keyword'];
        if (!isset($data['site_id'])) {
            $obj['site_id'] = fx::env('site')->get('id');
        }
        $fields = $component->getAllFields()->find('default', '', System\Collection::FILTER_NEQ);
        foreach ($fields as $f) {
            if ($f['default'] === 'null') {
                continue;
            }
            if (!isset($data[$f['keyword']])) {
                if ($f['type'] == Field\Entity::FIELD_DATETIME) {
                    $obj[$f['keyword']] = date('Y-m-d H:i:s');
                } else {
                    $obj[$f['keyword']] = $f['default'];
                }
            }
        }
        return $obj;
    }

    public function nextPriority()
    {
        return fx::db()->getVar(
            "SELECT MAX(`priority`)+1 FROM `{{floxim_main_content}}`"
        );
    }

    protected static $content_classes = array();

    public function getEntityClassName($data = null)
    {
        if (!is_null($data) && isset($data['type'])) {
            $c_type = $data['type'];
        } else {
            $component = fx::component($this->component_id);
            $c_type = $component['keyword'];
        }
        
        if (isset(Finder::$content_classes[$c_type])) {
            return Finder::$content_classes[$c_type];
        }
        
        $class_namespace = fx::getComponentNamespace($c_type);
        $class_name = $class_namespace.'\\Entity';
        Finder::$content_classes[$c_type] = $class_name;
        return $class_name;
    }

    /**
     * Returns the entity
     * @param array $data
     * @return fx_content
     */
    public function entity($data = array())
    {
        $classname = $this->getEntityClassName($data);
        
        if (isset($data['type'])) {
            $component_id = fx::component($data['type'])->get('id');
        } else {
            $component_id = $this->component_id;
        }

        $obj = new $classname(array(
            'data'         => $data,
            'component_id' => $component_id
        ));
        return $obj;
    }

    public function update($data, $where = array())
    {
        $wh = array();
        foreach ($where as $k => $v) {
            $wh[] = "`" . fx::db()->escape($k) . "` = '" . fx::db()->escape($v) . "' ";
        }

        $update = $this->setStatement($data);
        foreach ($update as $table => $props) {
            $q = 'UPDATE `{{' . $table . '}}` SET ' . $this->compileSetStatement($props); //join(', ', $props);
            if ($wh) {
                $q .= " WHERE " . join(' AND ', $wh);
            }
            fx::db()->query($q);
        }
    }

    public function delete($cond_field = null, $cond_val = null)
    {
        if (func_num_args() === 0) {
            parent::delete();
        }
        if ($cond_field != 'id' || !is_numeric($cond_val)) {
            throw new Exception("Content can be killed only by id!");
        }
        $tables = $this->getTables();

        $q = 'DELETE {{' . join("}}, {{", $tables) . '}} ';
        $q .= 'FROM {{' . join("}} INNER JOIN {{", $tables) . '}} ';
        $q .= ' WHERE ';
        $base_table = array_shift($tables);
        foreach ($tables as $t) {
            $q .= ' {{' . $t . '}}.id = {{' . $base_table . '}}.id AND ';
        }
        $q .= ' {{' . $base_table . '}}.id = "' . fx::db()->escape($cond_val) . '"';
        fx::db()->query($q);
    }

    /**
     * Generate SET statement from field-value array
     * @param array $props Array with field names as keys and data as values (both quoted)
     * e.g. array('`id`' => "1", '`name`' => "'My super name'")
     * @return string
     * joined pairs (with no SET keyword)
     * e.g. "`id` = 1, `name` = 'My super name'"
     */
    protected function  compileSetStatement($props)
    {
        $res = array();
        foreach ($props as $p => $v) {
            $res [] = $p . ' = ' . $v;
        }
        return join(", ", $res);
    }

    public function insert($data)
    {
        if (!isset($data['type'])) {
            throw  new \Exception('Can not save entity with no type specified');
        }
        $set = $this->setStatement($data);

        $tables = $this->getTables();

        $base_table = array_shift($tables);
        $root_set = $set[$base_table];
        $q = "INSERT INTO `{{" . $base_table . "}}` ";
        if (!isset($data['priority'])) {
            $q .= ' ( `priority`, ' . join(", ", array_keys($root_set)) . ') ';
            $q .= ' SELECT MAX(`priority`)+1, ';
            $q .= join(", ", $root_set);
            $q .= ' FROM {{' . $base_table . '}}';
        } else {
            $q .= "SET " . $this->compileSetStatement($root_set);
        }

        $tables_inserted = array();

        $q_done = fx::db()->query($q);
        $id = fx::db()->insertId();
        if ($q_done) {
            // remember, whatever table has inserted
            $tables_inserted [] = $base_table;
        } else {
            return false;
        }

        foreach ($tables as $table) {

            $table_set = isset($set[$table]) ? $set[$table] : array();

            $table_set['`id`'] = "'" . $id . "'";
            $q = "INSERT INTO `{{" . $table . "}}` SET " . $this->compileSetStatement($table_set);

            $q_done = fx::db()->query($q);
            if ($q_done) {
                // remember, whatever table has inserted
                $tables_inserted [] = $table;
            } else {
                // could not be deleted from all previous tables
                foreach ($tables_inserted as $tbl) {
                    fx::db()->query("DELETE FROM {{" . $tbl . "}} WHERE id  = '" . $id . "'");
                }
                // and return false
                return false;
            }
        }
        return $id;
    }

    protected function setStatement($data)
    {
        $res = array();
        $chain = fx::component($this->component_id)->getChain();
        foreach ($chain as $level_component) {
            $table_res = array();
            $fields = $level_component->fields();
            $field_keywords = $fields->getValues('keyword');
            // while the underlying field content manually prescription
            if ($level_component['keyword'] == 'floxim.main.content') {
                $field_keywords = array_merge($field_keywords, array(
                    'priority',
                    'last_updated',
                    'type',
                    'infoblock_id',
                    'materialized_path',
                    'level'
                ));
            }
            $table_name = $level_component->getContentTable();
            $table_cols = $this->getColumns($table_name);
            foreach ($field_keywords as $field_keyword) {
                if (!in_array($field_keyword, $table_cols)) {
                    continue;
                }

                $field = $fields->findOne('keyword', $field_keyword);
                // put only if the sql type of the field is not false (e.g. multilink)
                if ($field && !$field->getSqlType()) {
                    continue;
                }

                //if (isset($data[$field_keyword]) ) {
                if (array_key_exists($field_keyword, $data)) {
                    $field_val = $data[$field_keyword];
                    $sql_val = is_null($field_val) ? 'NULL' : "'" . fx::db()->escape($field_val) . "'";
                    $table_res['`' . fx::db()->escape($field_keyword) . '`'] = $sql_val;
                }
            }
            if (count($table_res) > 0) {
                $res[$table_name] = $table_res;
            }
        }
        return $res;
    }

    public function fake($props = array())
    {
        $content = $this->create();
        $content->fake();
        $content->set($props);
        return $content;
    }
    
    protected static function extractCollectionParams($collection)
    {
        $params = array();
        if ($collection->finder && $collection->finder instanceof Finder) {
            foreach ($collection->finder->where() as $cond) {
                // original field
                $field = isset($cond[3]) ? $cond[3] : null;
                // collection was found by id, adder is impossible
                if ($field === 'id') {
                    return false;
                }
                if (!preg_match("~\.~", $field) && $cond[2] == '=' && is_scalar($cond[1])) {
                    $params[$field] = $cond[1];
                }
            }
            //$params['_component'] = $collection->finder->getComponent();
            $params['_component'] = $collection->finder->getComponent()->get('keyword');
        }
        if ($collection->linkers) {
            return false;
        }
        foreach ($collection->getFilters() as $coll_filter) {
            list($filter_field, $filter_value) = $coll_filter;
            if (is_scalar($filter_value)) {
                $params[$filter_field] = $filter_value;
            }
        }
        return  $params;
    }

    /**
     * 
     * @param \Floxim\Floxim\System\Collection $collection
     * @return null
     */
    public function createAdderPlaceholder($collection)
    {
        //fx::log('creating adder', $collection, count($collection));
        $params = array();
        if ($this->limit && $this->limit['count'] == 1) {
            return;
        }
        
        // collection has linker map, so it contains final many-many related data, 
        // and current finder can generate only linkers
        // @todo invent something to add-in-place many-many items
        if ($collection->linkers) {
            return $this->createLinkerAdderPlaceholder($collection);
        }
        
        $params = self::extractCollectionParams($collection);
        
        if (!$params) {
            return;
        }
        
        $param_variants = array();
        if (isset($params['parent_id']) && !isset($params['infoblock_id'])) {
            $avail_infoblocks = fx::data('infoblock')->whereContent($params['_component']);
            if (isset($params['parent_id'])) {
                $avail_infoblocks = $avail_infoblocks->getForPage($params['parent_id']);
            } else {
                $avail_infoblocks = $avail_infoblocks->all();
            }
            if (count($avail_infoblocks)) {
                foreach ($avail_infoblocks as $c_ib) {
                    $param_variants []= array_merge(
                        $params,
                        array(
                            'infoblock_id' => $c_ib['id'],
                            '_component' => $c_ib['controller']
                        )
                    );
                }
            } else {
                $param_variants []= $params;
            }
        } else {
            $param_variants[]= $params;
        }
        
        
        foreach ($collection->getConcated() as $concated_coll) {
            if (!$concated_coll->finder) {
                continue;
            }
            $concated_params = self::extractCollectionParams($concated_coll);
            if (!$concated_params || count($concated_params) === 0) {
                continue;
            }
            if (!isset($concated_params['parent_id']) && isset($params['parent_id'])) {
                $concated_params['parent_id'] = $params['parent_id'];
            }
            $param_variants []= $concated_params;
        }
        
        $placeholder_variants = array();
        
        foreach ($param_variants as $c_params) {
            $com = fx::component($c_params['_component']);
            
            if (isset($c_params['infoblock_id']) && isset($c_params['parent_id'])) {
                $c_ib = fx::data('infoblock', $c_params['infoblock_id']);
                $c_parent = fx::data('page', $c_params['parent_id']);
                $c_ib_avail = $c_ib && $c_parent && $c_ib->isAvailableOnPage($c_parent);
                
                if (!$c_ib_avail) {
                    continue;
                }
            }
            
            $com_types = $com->getAllVariants();
            foreach ($com_types as $com_type) {
                $com_key = $com_type['keyword'];
                if (!isset($placeholder_variants[$com_key])) {
                    $placeholder = fx::data($com_key)->create($c_params);
                    $placeholder['_meta'] = array(
                        'placeholder' => $c_params + array('type' => $com_key),
                        'placeholder_name' => $com_type->getItemName()
                    );
                    $placeholder->isAdderPlaceholder(true);
                    $collection[] = $placeholder;
                    $placeholder_variants[$com_key] = $placeholder;
                }
            }
        }
    }
    
    public function createLinkerAdderPlaceholder($collection)
    {
        if (!isset($collection->linkers)) {
            return;
        }
        $linkers = $collection->linkers;
        $placeholder = $this->create();
        $linker_params = self::extractCollectionParams($linkers);
        $linker_params['type'] = $linker_params['_component'];
        unset($linker_params['_component']);
        $placeholder['_meta'] = array(
            'placeholder' => array('type' => $this->getComponent()->get('keyword')),
            'placeholder_name' => 'Linker',
            'placeholder_linker' => $linker_params
        );
        $placeholder->isAdderPlaceholder(true);
        $collection[]= $placeholder;
        $linkers[]= fx::data('floxim.main.linker')->create();
    }
    
    protected function livesearchApplyTerms($terms)
    {
        $table = $this->getColTable('name');
        if ($table) {
            parent::livesearchApplyTerms($terms);
            return;
        }

        $c_component = fx::data('component', $this->component_id);
        $components = $c_component->getAllVariants();
        $name_conds = array();
        foreach ($components as $com) {
            $name_field = $com->fields()->findOne('keyword', 'name');
            if (!$name_field) {
                continue;
            }
            $table = '{{' . $com->getContentTable() . '}}';
            $this->join($table, $table . '.id = {{floxim_main_content}}.id', 'left');
            $cond = array(
                array(),
                false,
                'OR'
            );
            foreach ($terms as $term) {
                $cond[0][] = array(
                    $table . '.name',
                    '%' . $term . '%',
                    'like'
                );
            }
            $name_conds [] = $cond;
        }
        call_user_func_array(array($this, 'whereOr'), $name_conds);
    }

    /**
     * Add filter to get subtree for one ore more parents
     * @param mixed $parent_ids
     * @param boolean $add_parents - include parents to subtree
     * @return fx_data_content
     */
    public function descendantsOf($parent_ids, $include_parents = false)
    {
        if ($parent_ids instanceof System\Collection) {
            $non_content = $parent_ids->find(function ($i) {
                return !($i instanceof Entity);
            });
            if (count($non_content) == 0) {
                $parents = $parent_ids;
                $parent_ids = $parents->getValues('id');
            }
        }
        if ($parent_ids instanceof Entity) {
            $parents = array($parent_ids);
            $parent_ids = array($parent_ids['id']);
        } elseif (!isset($parents)) {
            if (is_numeric($parent_ids)) {
                $parent_ids = array($parent_ids);
            }
            $parents = fx::data('content', $parent_ids);
        }
        $conds = array();
        foreach ($parents as $p) {
            $conds [] = array('materialized_path', $p['materialized_path'] . $p['id'] . '.%', 'like');
        }
        if ($include_parents) {
            $conds [] = array('id', $parent_ids, 'IN');
        }
        $this->where($conds, null, 'OR');
        return $this;
    }
}