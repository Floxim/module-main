<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\System;
use Floxim\Floxim\Component\Field;
use Floxim\Floxim\Component\Lang;
use Floxim\Floxim\System\Fx as fx;

class Finder extends \Floxim\Floxim\Component\Basic\Finder
{

    public static $isStaticCacheUsed = true;
    
    public static function isStaticCacheUsed() {
        return true;
    }
    
    public function orderDefault() {
        $this->order('priority');
    }
    
    protected static function getStaticCacheKey()
    {
        return 'data-meta-content';
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
            unset($item[$children_key]);
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
        
        $com = $this->getComponent();
        if (isset($content_by_type[$com['keyword']])) {
            return true;
        }
        
        foreach ($com->getAllChildren() as $child) {
            if (isset($content_by_type[$child['keyword']])) {
                return true;
            }
        }
        
        return false;
    }


    protected function livesearchApplyTerms($terms)
    {
        $table = $this->getColTable('name');
        if ($table) {
            parent::livesearchApplyTerms($terms);
            return;
        }
        
        $c_component = $this->getComponent();
        $components = $c_component->getAllVariants();
        $name_conds = array();
        foreach ($components as $com) {
            $name_field = $com->fields()->findOne('keyword', 'name');
            if (!$name_field || $name_field['parent_field_id']) {
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
    
    public function conditionIs($type) 
    {
        if (!is_array($type)) {
            $type = array($type);
        }
        $variants = array();
        foreach ($type as $c_type) {
            $com = fx::component($c_type);
            $variants = array_merge($variants, $com->getAllVariants()->getValues('keyword'));
        }
        $variants = array_unique($variants);
        return array('type', $variants, 'in');
    }
    
    public function processConditionIsUnder($field, $value) {
        $res = $this->conditionDescendantsOf($value, false, $field);
        return $res;
    }
    
    public function processConditionIsUnderOrEquals($field, $value) {
        $res = $this->conditionDescendantsOf($value, true, $field);
        return $res;
    }
    
    public function conditionDescendantsOf($parent_ids, $include_parents = false, $field = null)
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
            $parents = fx::data('floxim.main.content', $parent_ids);
        }
        $conds = array();
        $prefix = $field ? $field.'.' : '';
        foreach ($parents as $p) {
            $conds [] = array($prefix.'materialized_path', $p['materialized_path'] . $p['id'] . '.%', 'like');
        }
        if ($include_parents) {
            $conds [] = array($prefix.'id', $parent_ids, 'IN');
        }
        $res = count($conds) === 1 ? $conds[0] : array($conds, null, 'or');
        return $res;
    }

    /**
     * Add filter to get subtree for one ore more parents
     * @param mixed $parent_ids
     * @param boolean $add_parents - include parents to subtree
     * @return fx_data_content
     */
    public function descendantsOf($parent_ids, $include_parents = false)
    {
        $cond = $this->conditionDescendantsOf($parent_ids, $include_parents);
        $this->where($cond);
        return $this;
    }
}