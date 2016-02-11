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


    public function fake($props = array())
    {
        $content = $this->create();
        $content->fake();
        $content->set($props);
        return $content;
    }
    
    protected static function extractCollectionParams($collection, $skip_linkers = true)
    {
        $params = array();
        if ($collection->finder && $collection->finder instanceof Finder) {
            foreach ($collection->finder->where() as $cond) {
                // original field
                $field = isset($cond[3]) ? $cond[3] : null;
                // collection was found by id, adder is impossible
                if ($field === 'id') {
                    if ($skip_linkers) {
                        return false;
                    }
                    continue;
                }
                if (!preg_match("~\.~", $field) && $cond[2] == '=' && is_scalar($cond[1])) {
                    $params[$field] = $cond[1];
                }
            }
            //$params['_component'] = $collection->finder->getComponent();
            $params['_component'] = $collection->finder->getComponent()->get('keyword');
        }
        if ($collection->linkers && $skip_linkers) {
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
    
    protected function isCollectionInverted($collection)
    {
        $f = $collection->finder;
        if (!$f) {
            return false;
        }
        $order = $f->getOrder();
        if (!$order || !isset($order[0])) {
            return false;
        }
        $order = $order[0];
        if (!preg_match("~desc$~i", $order)) {
            return false;
        }
        $keywords = 'date|created';
        if (preg_match("~`".$keywords."~i", $order) || preg_match("~".$keywords."`~i", $order)) {
            return true;
        }
        return false;
    }

    /**
     * 
     * @param \Floxim\Floxim\System\Collection $collection
     * @return null
     */
    public function createAdderPlaceholder($collection)
    {
        $params = array();
        if ($this->limit && $this->limit['count'] == 1 && count($collection) > 0) {
            return;
        }
        $collection->findRemove(function($e) use ($collection) {
            if (!$e instanceof Entity) {
                return false;
            }
            return $e->isAdderPlaceholder();
        });
        
        // collection has linker map, so it contains final many-many related data, 
        // and current finder can generate only linkers
        // @todo invent something to add-in-place many-many items
        if ($collection->linkers) {
            return $this->createLinkerAdderPlaceholder($collection);
        }
        
        // OH! My! God!
        $add_to_top = $this->isCollectionInverted($collection);
        
        $params = self::extractCollectionParams($collection);
        
        fx::cdebug($params);
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
        
        fx::cdebug($param_variants);
        
        $placeholder_variants = array();
        foreach ($param_variants as $c_params) {
            $com = fx::component($c_params['_component']);
            
            if (isset($c_params['infoblock_id']) && isset($c_params['parent_id'])) {
                $c_ib = fx::data('infoblock', $c_params['infoblock_id']);
                $c_parent = fx::data('floxim.main.content', $c_params['parent_id']);
                $c_ib_avail = 
                        $c_ib && 
                        $c_parent && 
                        ($c_ib['params']['is_pass_through'] || $c_ib->isAvailableOnPage($c_parent));
                
                if (!$c_ib_avail) {
                    fx::cdebug(1);
                    continue;
                }
            }
            $com_types = $com->getAllVariants();
            foreach ($com_types as $com_type) {
                fx::cdebug($c_params, $com_type['keyword']);
                // skip abstract components like "publication", "contact" etc.
                if (
                    $com_type['is_abstract'] && 
                    (!isset($c_params['type']) || ($com_type['keyword'] !== $c_params['type']) )
                ) {
                    fx::cdebug(2);
                    continue;
                }
                $com_key = $com_type['keyword'];
                if (isset($c_params['type']) && $c_params['type'] !== $com_key) {
                    fx::cdebug(3);
                    continue;
                }
                if (!isset($placeholder_variants[$com_key])) {
                    $placeholder = fx::data($com_key)->create($c_params);
                    if (!$placeholder->hasAvailableInfoblock() && false) {
                        fx::cdebug(4);
                        continue;
                    }
                    
                    $placeholder_meta = array(
                        'placeholder' => $c_params + array('type' => $com_key),
                        'placeholder_name' => $com_type->getItemName('add')
                    );
                    if ($add_to_top) {
                        $placeholder_meta['add_to_top'] = true;
                    }
                    
                    $placeholder['_meta'] = $placeholder_meta;
                    
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
        
        $variants = $this->getComponent()->getAllVariants();
        
        $common_params = self::extractCollectionParams($linkers);
        
        
        $content_params = self::extractCollectionParams($collection, false);
        $strict_type = isset($content_params['type']) ? $content_params['type'] : null;
        
        foreach ($variants as $var_com) {
            if ($var_com['is_abstract']) {
                continue;
            }
            if ($strict_type && $var_com['keyword'] !== $strict_type) {
                continue;
            }
            
            $com_finder = fx::data($var_com['keyword']);
            $placeholder = $com_finder->create();
            
            // skip components like floxim.nav.external_link
            if (!$placeholder->isAvailableInSelectedBlock()) {
                continue;
            }
            
            $linker_params = $common_params;
            $linker_params['type'] = $linker_params['_component'];
            unset($linker_params['_component']);
            $linker_params['_link_field'] = $linkers->linkedBy;
            $placeholder['_meta'] = array(
                'placeholder' => array('type' => $var_com['keyword']),
                'placeholder_name' => $var_com->getItemName('add'),
                'placeholder_linker' => $linker_params
            );
            $placeholder->isAdderPlaceholder(true);
            $collection[]= $placeholder;
            $linkers[]= fx::data('floxim.main.linker')->create();
        }
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
            $conds [] = array('id', $parent_ids, 'IN');
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