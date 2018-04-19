<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\System;
use Floxim\Floxim\Template;
use Floxim\Floxim\Component\Field;
use Floxim\Floxim\System\Fx as fx;

class Entity extends \Floxim\Floxim\Component\Basic\Entity
{
    protected $component_id;
    
    protected $isActive;

    public function _getIsActive()
    {
        return $this->isActive();
    }

    public function isActive()
    {
        if (isset($this->data['is_active'])) {
            return $this->data['is_active'];
        }
        if ($this->isActive) {
            return $this->isActive;
        }
        $path = fx::env('path')->getValues('id');
        return $this->isActive = in_array($this['id'], $path);
    }

    public function isCurrent()
    {
        return $this['id'] == fx::env('page_id');
    }

    public function _getIsCurrent()
    {
        return $this->isCurrent();
    }
    
    public function getStructureFields()
    {
        return array();
    }
    
    protected function treeToParentFieldValues($tree)
    {
        $values = array();
        $c_id = $this['id'];
        $get_values = function ($level, $level_num = 0) use (&$values, &$get_values, $c_id) {
            foreach ($level as $page) {
                if ($page['id'] == $c_id) {
                    continue;
                }
                $name = $page['name'] ? $page['name'] : '#'.$page['id'];
                $values [] = array($page['id'], str_repeat('- ', $level_num * 2) . $name);
                if ($page['nested']) {
                    $get_values($page['nested'], $level_num + 1);
                }
            }
        };
        $get_values($tree);
        return $values;
    }
    
    public function getFormFieldInfoblockId($field)
    {
        $res = $field->getJsField($this);
        if ($this['infoblock_id'] || $this['id']) {
            $res['type'] = 'hidden';
            $res['value'] = $this['infoblock_id'];
        } else {
            $avail_ibs = $this->getRelationFinderInfoblockId()->all();
            if (count($avail_ibs) === 0) {
                $res['type'] = 'hidden';
                $res['value'] = null;
            }
        }
        return $res;
    }

    public function getFormFieldParentId($field = null)
    {
        if (!$this['id'] && $this['parent_id'] && $this['type'] !== 'floxim.nav.section') {
            return;
        }
        
        if ($this['parent_id'] && !$this['infoblock_id']) {
            return;
        }

        $finder = $this->getAvailParentsFinder();
        if (!$finder) {
            return;
        }
        $parents = $finder->getTree('nested');
        $values = $this->treeToParentFieldValues($parents);
        $value_found = false;
        if ($this['parent_id']) {
            foreach ($values as $v) {
                if ($v[0] === $this['parent_id']) {
                    $value_found = true;
                    break;
                }
            }
        }
        $jsf = $field ? $field->getJsField($this, false) : array();
        if ($this['parent_id'] && !$value_found) {
            //return;
        }
        $jsf['values'] = $values;
        $jsf['hidden_on_one_value'] = true;
        //$jsf['type'] = 'livesearch';
        return $jsf;
    }
    
    public function getRelationFinderParentId()
    {
        return $this->getAvailParentsFinder();
    }
    
    public function getRelationFinderInfoblockId()
    {
        return fx::data('infoblock')
                ->where('site_id', $this['site_id'])
                ->whereContent($this['type'], true);
    }
    
    public function hasAvailableInfoblock() {
        $ib = $this->getRelationFinderInfoblockId()->one();
        return $ib;
    }
    
    public function filterAvailableInfoblocksByParent($ibs, $parent_id = null)
    {
        if (func_num_args() === 1) {
            $parent_id = $this['parent_id'];
        }
        if (!$parent_id) {
            return $ibs;
        }
        $that = $this;
        $ibs = $ibs->find(
            function($ib) use ($that, $parent_id) {
                $ib_parents_finder = $that->getAvailParentsFinder($ib);
                $parent = $ib_parents_finder->where('id', $parent_id)->one();
                return $parent ? true : false;
            }
        );
        return $ibs;
    }
    
    /**
     * Returns a finder to get "potential" parents for the object
     */
    public function getAvailParentsFinder($ibs = null)
    {
        if (!$ibs && $this['infoblock_id']) {
            $ibs = fx::data('infoblock', $this['infoblock_id']);
        }
        
        if (!$ibs) {
            $ibs = $this->getRelationFinderInfoblockId()->all();
        } elseif ( ! ($ibs instanceof \Floxim\Floxim\System\Collection) ) {
            $ibs = fx::collection($ibs);
        }
        $conds = array();
        $finder = fx::data('floxim.main.page');
        foreach ($ibs as $ib) {
            $c_cond = $ib->getParentFinderConditions();
            $conds []= $c_cond;
        }
        
        $finder->where( $conds, null, 'or' );
        return $finder;
    }
    
    public function isVisible()
    {
        return (bool) $this['is_published'];
    }

    
    public function getForcedEditableFields() {
        return array('is_published');
    }

    protected function beforeSave()
    {
        if ($this->isModified('parent_id') || ($this['parent_id'] && !$this['materialized_path'])) {
            $new_parent = $this['parent'];
            if ($new_parent) {
                $this['level'] = $new_parent['level'] + 1;
                $this['materialized_path'] = $new_parent['materialized_path'] . $new_parent['id'] . '.';
                $this['is_branch_published'] = $new_parent['is_published'] && $new_parent['is_branch_published'];
            } else {
                $this['materialized_path'] = '.';
                $this['is_branch_published'] = $this['is_published'];
                $this['level'] = 1;
            }
        }
        parent::beforeSave();
    }
    
    /*
     * Get the id of the information block where to add the linked objects on the field $link_field
     */
    public function getLinkFieldInfoblock($link_field_id)
    {
        // information block, where ourselves live
        $our_infoblock = fx::data('infoblock', $this['infoblock_id']);
        return $our_infoblock['params']['field_' . $link_field_id . '_infoblock'];
    }

    protected function afterDelete()
    {
        parent::afterDelete();
        if (!isset($this->_skip_cascade_delete_children) || !$this->_skip_cascade_delete_children) {
            $this->deleteChildren();
        }
    }

    public function deleteChildren()
    {
        $descendants = fx::data('floxim.main.content')->descendantsOf($this);
        foreach ($descendants->all() as $d) {
            $d->_skip_cascade_delete_children = true;
            $d->delete();
        }
    }
    
    protected function beforeUpdate() {
        parent::beforeUpdate();
        if ($this->isModified()) {
            $this['last_updated'] = fx::date(time());
        }
    }

    protected function afterUpdate()
    {
        parent::afterUpdate();
        
        
        /*
         * Update is_branch_published for nested nodes
         */
        $new_publish_status = null;
        if ($this->isModified('is_published')) {
            $new_publish_status = $this['is_published'];
        } elseif ($this->isModified('is_branch_published')) {
            $new_publish_status = $this['is_branch_published'];
        }
        if (!is_null($new_publish_status)) {
            $children = fx::data('floxim.main.content')->where('parent_id', $this['id'])->all();
            foreach ($children as $child) {
                $child['is_branch_published'] = !$new_publish_status ? 0 : $this['is_published'];
                $child->save();
            }
        }

        /*
         * Update level and mat.path for children if item moved somewhere
         */
        if ($this->isModified('parent_id') && isset($this->modified_data['materialized_path'])) {
            $old_path = $this->modified_data['materialized_path'] . $this['id'] . '.';
            // new path for descendants
            $new_path = $this['materialized_path'] . $this['id'] . '.';
            $nested_items = fx::data('floxim.main.content')->where('materialized_path', $old_path . '%', 'LIKE')->all();
            $level_diff = 0;
            if ($this->isModified('level')) {
                $level_diff = $this['level'] - $this->modified_data['level'];
            }
            foreach ($nested_items as $child) {
                $child['materialized_path'] = str_replace($child['materialized_path'], $old_path, $new_path);
                if ($level_diff !== 0) {
                    $child['level'] = $child['level'] + $level_diff;
                }
                $child->save();
            }
        }
    }

    protected $parent_ids = null;
    protected $path = null;

    /**
     * Get the id of the page-parents
     * @return array
     */
    public function getParentIds()
    {
        if (!is_null($this->parent_ids)) {
            return $this->parent_ids;
        }

        $path = $this['materialized_path'];
        if (!empty($path)) {
            $path = explode(".", trim($path, '.'));
            $this->parent_ids = array_map(function($id) {
               return (int) $id;
            }, $path);
            return $this->parent_ids;
        }

        $c_pid = $this->get('parent_id');
        // if page has null parent, hold it as if it was nested to index
        if ($c_pid === null && ($site = fx::env('site')) && ($index_id = $site['index_page_id'])) {
            return $index_id != $this['id'] ? array($index_id) : array();
        }
        $ids = array();
        while ($c_pid != 0) {
            array_unshift($ids, $c_pid);
            $c_pid = fx::data('floxim.main.page', $ids[0])->get('parent_id');
        }
        $this->parent_ids = $ids;
        return $ids;
    }

    public function getPath()
    {
        if ($this->path) {
            return $this->path;
        }
        $path_ids = $this->getParentIds();
        $path_ids [] = $this['id'];
        //fx::cdebug($path_ids);
        $this->path = fx::data('floxim.main.content')
            ->where('id', $path_ids)
            // ->order('level','asc')
            ->all()
            ->sort(function($el) use ($path_ids) {
                return array_search($el['id'], $path_ids);
            });
        return $this->path;
    }
    
    /**
     * Force "virtual" path for the entity
     * @param array $path
     */
    public function setVirtualPath($path) {
        $this->path = fx::collection($path);
        $this->has_virtual_path = true;
    }
    
    protected $has_virtual_path = false;
    
    public function hasVirtualPath($set = null) {
        if (is_null($set)) {
            return (bool) $this->has_virtual_path;
        }
        $this->has_virtual_path = (bool) $set;
    }
    
    public function canHaveNoParent() 
    {
        
    }
    
    public function canHaveNoInfoblock()
    {
        $no_ib_types = fx::config('content.can_have_no_infoblock');
        return is_array($no_ib_types) && in_array($this['type'], $no_ib_types);
    }

    public function prepareForLivesearch($res, $term = '')
    {
        if ( ($parent_entity = $this['parent'])) {
            $site = fx::data('site', $this['site_id']);
            if (!$site || $parent_entity['id'] !== $site['index_page_id']) {
                $res['path'] = array( $parent_entity->getName() );
            }
        }
        return parent::prepareForLivesearch($res, $term);
    }
}