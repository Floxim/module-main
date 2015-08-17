<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\System;
use Floxim\Floxim\Component\Field;
use Floxim\Floxim\Component\Lang;
use Floxim\Floxim\System\Fx as fx;

class Finder extends \Floxim\Floxim\Component\Basic\Finder
{

    static $stored_relations = array();
    
    public static $isStaticCacheUsed = true;
    
    public static function isStaticCacheUsed() {
        return true;
    }
    
    protected static function getStaticCacheKey()
    {
        return 'data-meta-content';
    }
    
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

    protected function getDefaultRelationFinder($rel)
    {
        $finder = parent::getDefaultRelationFinder($rel);
        if (!$finder instanceof Lang\Finder) {
            $finder->order('priority');
        }
        return $finder;
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
                $c_parent = fx::data('content', $c_params['parent_id']);
                $c_ib_avail = $c_ib && $c_parent && $c_ib->isAvailableOnPage($c_parent);
                
                if (!$c_ib_avail) {
                    continue;
                }
            }
            
            $com_types = $com->getAllVariants();
            foreach ($com_types as $com_type) {
                // skip abstract components like "publication", "contact" etc.
                if ($com_type['is_abstract']) {
                    continue;
                }
                $com_key = $com_type['keyword'];
                if (!isset($placeholder_variants[$com_key])) {
                    $placeholder = fx::data($com_key)->create($c_params);
                    
                    if (!$placeholder->hasAvailableInfoblock()) {
                        continue;
                    }
                    
                    $placeholder_meta = array(
                        'placeholder' => $c_params + array('type' => $com_key),
                        'placeholder_name' => $com_type->getItemName()
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
        
        foreach ($variants as $var_com) {
            if ($var_com['is_abstract']) {
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
                'placeholder_name' => $var_com->getItemName(),
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