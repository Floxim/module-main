<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\System;
use Floxim\Floxim\Component\Field;
use Floxim\Floxim\Component\Lang;
use Floxim\Floxim\System\Fx as fx;

class Finder extends System\Finder
{

    public function relations()
    {
        $relations = array();
        $fields = fx::data('component', $this->component_id)->
        allFields()->
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
        return $relations;
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
        $base_component = fx::data('component', $this->component_id);
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
                $extension = $extensions[$data_item['id']];
                if ($extension) {
                    $data[$data_index] = array_merge($data_item, $extension);
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
        $chain = fx::data('component', $this->component_id)->getChain();
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
        // todo: psr0 need remove after rename tables
        // $component_id_or_code = str_replace('floxim.main.', '', $component_id_or_code);

        $component = fx::data('component', $component_id_or_code);
        if (!$component) {
            die("Component not found: " . $component_id_or_code);
        }
        $this->component_id = $component['id'];
        $this->table = $component->getContentTable();
        return $this;
    }

    public function getComponent()
    {
        return fx::data('component', $this->component_id);
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

        $component = fx::data('component', $this->component_id);

        $obj['created'] = date("Y-m-d H:i:s");
        if ($component['keyword'] != 'floxim.user.user' && ($user = fx::env()->getUser())) {
            $obj['user_id'] = $user['id'];
        }
        $obj['checked'] = 1;
        $obj['type'] = $component['keyword'];
        if (!isset($data['site_id'])) {
            $obj['site_id'] = fx::env('site')->get('id');
        }
        $fields = $component->allFields()->find('default', '', System\Collection::FILTER_NEQ);
        foreach ($fields as $f) {
            if (!isset($obj[$f['keyword']])) {
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

    public function getClassName($data = null)
    {
        if ($data && isset($data['type'])) {
            if (isset(Finder::$content_classes[$data['type']])) {
                return Finder::$content_classes[$data['type']];
            }
            $c_type = $data['type'];
            $component = fx::data('component', $c_type);
        } else {
            $component = fx::data('component', $this->component_id);
            $c_type = $component['keyword'];
        }
        if (!$component) {
            throw new \Exception("No component: " . $c_type);
        }
        $chain = array_reverse($component->getChain());

        $exists = false;

        while (!$exists && count($chain) > 0) {
            $c_level = array_shift($chain);
            $class_namespace = fx::getComponentNamespace($c_level['keyword']);
            $class_name = $class_namespace . '\\Entity';
            try {
                $exists = class_exists($class_name);
            } catch (\Exception $e) {
            }
        }
        Finder::$content_classes[$data['type']] = $class_name;
        return $class_name;
    }

    /**
     * Returns the entity installed component_id
     * @param array $data
     * @return fx_content
     */
    public function entity($data = array())
    {
        $classname = $this->getClassName($data);
        if (isset($data['type'])) {
            $component_id = fx::data('component', $data['type'])->get('id');
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
            throw  new Exception('Can not save entity with no type specified');
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
        $chain = fx::data('component', $this->component_id)->getChain();
        foreach ($chain as $level_component) {
            $table_res = array();
            $fields = $level_component->fields();
            $field_keywords = $fields->getValues('keyword');
            // while the underlying field content manually prescription
            if ($level_component['keyword'] == 'floxim.main.content') {
                $field_keywords = array_merge($field_keywords, array(
                    'priority',
                    'checked',
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

    public function createAdderPlaceholder($collection = null)
    {
        $params = array();
        foreach ($this->where as $cond) {
            // original field
            $field = $cond[3];
            // collection was found by id, adder is impossible
            if ($field === 'id') {
                return;
            }
            if (!preg_match("~\.~", $field) && $cond[2] == '=' && is_scalar($cond[1])) {
                $params[$field] = $cond[1];
            }
        }
        if ($collection) {
            // collection has linker map, so it contains final many-many related data, 
            // and current finder can generate only linkers
            // @todo invent something to add-in-place many-many items
            if ($collection->linker_map) {
                return null;
            }
            foreach ($collection->getFilters() as $coll_filter) {
                list($filter_field, $filter_value) = $coll_filter;
                if (is_scalar($filter_value)) {
                    $params[$filter_field] = $filter_value;
                }
            }
        }
        $placeholder = $this->create($params);
        $placeholder->digSet('_meta.placeholder', $params + array('type' => $placeholder['type']));
        $placeholder->digSet('_meta.placeholder_name', fx::data('component', $placeholder['type'])->get('item_name'));
        $placeholder->isAdderPlaceholder(true);
        // guess item's position here
        if ($collection) {
            $collection[] = $placeholder;
        }
        return $placeholder;
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