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
        $c_page_id = fx::env('page_id');
        if (!$c_page_id) {
            return false;
        }
        $path = fx::env('page')->getPath()->getValues('id');
        $path [] = $c_page_id;

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
        $ibs = fx::data('infoblock')->where('site_id', $this['site_id'])->whereContent($this['type'], true)->all();
        $com_id = $this->getComponentId();
        $ib_field = array(
            'label' => fx::alang('Infoblock'),
            'value' => $this['infoblock_id'],
            'hidden_on_one_value' => true
        );
        
        if ($this['id'] || $this['infoblock_id']) {
            $ib_field['type'] = 'hidden';
        } else {
            $ib_field['type'] = 'select';
            $ib_field['values'] = array();
        }
        
        $res = array('infoblock_id'  => $ib_field);
        foreach ($ibs as $ib) {
            $finder = $this->getAvailParentsFinder($ib);
            if (!$finder) {
                continue;
            }
            $parents = $finder->getTree('nested');
            $values = $this->treeToParentFieldValues($parents);
            if (count($values) > 0) {
                $res['infoblock_id']['values'][]= array(
                    $ib['id'], $ib['name'] ? $ib['name'] : '#'.$ib['id']
                );
                $parent_field_name = 'infoblock_'.$ib['id'].'_parent_id';
                $res[$parent_field_name] = array(
                    'label' => fx::alang('Section'),
                    'type' => 'select',
                    //'parent' => array('infoblock_id' => $ib['id']),
                    'values' => $values,
                    'value' => $this['parent_id'] ? $this['parent_id'] : $this->getPayload($parent_field_name),
                    'join_with' => 'infoblock_id',
                    'join_type' => 'line',
                    'hidden_on_one_value' => true
                );
            }
        }
        foreach ($res as &$var) {
            $var = array_merge(
                $var, 
                array(
                    'content_id' => $this['id'],
                    'content_type_id' => $com_id,
                    'var_type' => 'content'
                )
            );
        }
        if (count($res['infoblock_id']['values']) === 0) {
            unset($res['infoblock_id']);
        }
        return $res;
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

    public function getFormFieldParentId($field = null)
    {
        if (!$this['id'] && $this['parent_id']) {
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
            return;
        }
        $jsf['values'] = $values;
        $jsf['hidden_on_one_value'] = true;
        $jsf['tab'] = 1;
        $jsf['type'] = 'livesearch';
        return $jsf;
    }

    /**
     * Returns a finder to get "potential" parents for the object
     */
    public function getAvailParentsFinder($ib = null)
    {
        if (!$ib) {
            if (!$this['infoblock_id']) {
                return;
            }

            $ib = fx::data('infoblock', $this['infoblock_id']);
            if (!$ib) {
                return;
            }
        }
        
        $parent_data_type = $ib['scope']['page_type'];
        if (!$parent_data_type) {
            $parent_data_type = 'page';
        }
        $root_id = $ib['page_id'];
        if (!$root_id) {
            $root_id = fx::data('site', $ib['site_id'])->get('index_page_id');
        }
        $finder = fx::content($parent_data_type);
        
        if (isset($ib['params']['is_pass_through'])) {
            $is_pass_through = $ib['params']['is_pass_through'];
        } else {
            // load forced param from controller config
            $ctr = $ib->initController();
            $is_pass_through = $ctr->getParam('is_pass_through');
        }
        
        if ($ib['scope']['pages'] === 'this' || $is_pass_through) {
            $finder->where('id', $root_id);
        } else {
            $finder->descendantsOf($root_id, $ib['scope']['pages'] != 'children');
        }
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
        $new_parent_id = $this->getPayload('infoblock_'.$this['infoblock_id'].'_parent_id');
        if ($new_parent_id) {
            $this['parent_id'] = $new_parent_id;
        }
        $component = fx::data('component', $this->component_id);
        $link_fields = $component->fields()->find('type', Field\Entity::FIELD_LINK);
        foreach ($link_fields as $lf) {
            // save the cases of type $tagpost['tag'] -> $tagpost['most part']
            $lf_prop = $lf['format']['prop_name'];
            if (
                isset($this->data[$lf_prop]) &&
                $this[$lf_prop] instanceof Entity &&
                empty($this[$lf['keyword']])
            ) {
                if (!$this[$lf_prop]['id']) {
                    $this[$lf_prop]->save();
                }
                $this[$lf['keyword']] = $this[$lf_prop]['id'];
            }
            // synchronize the field bound to the parent
            if ($lf['format']['is_parent']) {
                $lfv = $this[$lf['keyword']];
                if ($lfv != $this['parent_id']) {
                    if (!$this['parent_id'] && $lfv) {
                        $this['parent_id'] = $lfv;
                    } elseif ($lfv != $this['parent_id']) {
                        $this[$lf['keyword']] = $this['parent_id'];
                    }
                }
            }
        }

        if ($this->isModified('parent_id') || ($this['parent_id'] && !$this['materialized_path'])) {
            $new_parent = $this['parent'];
            $this['level'] = $new_parent['level'] + 1;
            $this['materialized_path'] = $new_parent['materialized_path'] . $new_parent['id'] . '.';
        }
        $this->handleMove();
        parent::beforeSave();
    }
    
    public function handleMove()
    {
        $rel_item_id = null;
        if (isset($this['__move_before'])) {
            $rel_item_id = $this['__move_before'];
            $rel_dir = 'before';
        } elseif (isset($this['__move_after'])) {
            $rel_item_id = $this['__move_after'];
            $rel_dir = 'after';
        }
        if (!$rel_item_id) {
            return;
        }
        $rel_item = fx::content($rel_item_id);
        if (!$rel_item) {
            return;
        }
        $rel_priority = fx::db()->getVar(array(
            'select priority from {{floxim_main_content}} where id = %d',
            $rel_item_id
        ));
        //fx::debug($rel_priority, $rel_item_id);
        if ($rel_priority === false) {
            return;
        }
        // 1 2 3 |4| 5 6 7 (8) 9 10
        $old_priority = $this['priority'];
        $this['priority'] = $rel_dir == 'before' ? $rel_priority : $rel_priority + 1;
        $q = 'update {{floxim_main_content}} ' .
            'set priority = priority + 1 ' .
            'where parent_id = %d ' .
            'and infoblock_id = %d ' .
            'and priority >= %d ' .
            'and id != %d';
        $q_params = array(
            $this['parent_id'],
            $this['infoblock_id'],
            $this['priority'],
            $this['id']
        );
        
        if ($old_priority !== null) {
            $q .= ' and priority < %d';
            $q_params [] = $old_priority;
        }
        array_unshift($q_params, $q);
        fx::db()->query($q_params);
    }

    /*
     * Store multiple links, linked to the entity
     */
    protected function saveMultiLinks()
    {
        $link_fields =
            $this->getFields()->
            find('keyword', $this->modified)->
            find('type', Field\Entity::FIELD_MULTILINK);
        foreach ($link_fields as $link_field) {
            $val = $this[$link_field['keyword']];
            $relation = $link_field->getRelation();
            $related_field_keyword = $relation[2];

            switch ($relation[0]) {
                case System\Finder::HAS_MANY:
                    $old_data = isset($this->modified_data[$link_field['keyword']]) ?
                        $this->modified_data[$link_field['keyword']] :
                        new System\Collection();
                    $c_priority = 0;
                    foreach ($val as $linked_item) {
                        $c_priority++;
                        $linked_item[$related_field_keyword] = $this['id'];
                        $linked_item['priority'] = $c_priority;
                        $linked_item->save();
                    }
                    $old_data->findRemove('id', $val->getValues('id'));
                    $old_data->apply(function ($i) {
                        $i->delete();
                    });
                    break;
                case System\Finder::MANY_MANY:
                    $old_linkers = isset($this->modified_data[$link_field['keyword']]->linkers) ?
                        $this->modified_data[$link_field['keyword']]->linkers :
                        new System\Collection();

                    // new linkers
                    // must be set
                    // @todo then we will cunning calculation
                    if (!isset($val->linkers) || count($val->linkers) != count($val)) {
                        throw new \Exception('Wrong linker map');
                    }
                    foreach ($val->linkers as $linker_obj) {
                        $linker_obj[$related_field_keyword] = $this['id'];
                        $linker_obj->save();
                    }

                    $old_linkers->findRemove('id', $val->linkers->getValues('id'));
                    $old_linkers->apply(function ($i) {
                        $i->delete();
                    });
                    break;
            }
        }
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
        $descendants = fx::data('content')->descendantsOf($this);
        foreach ($descendants->all() as $d) {
            $d->_skip_cascade_delete_children = true;
            $d->delete();
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
            $children = fx::data('content')->where('parent_id', $this['id'])->all();
            foreach ($children as $child) {
                $child['is_branch_published'] = !$new_publish_status ? 0 : $this['is_published'];
                $child->save();
            }
        }

        /*
         * Update level and mat.path for children if item moved somewhere
         */
        if ($this->isModified('parent_id')) {
            $old_path = $this->modified_data['materialized_path'] . $this['id'] . '.';
            // new path for descendants
            $new_path = $this['materialized_path'] . $this['id'] . '.';
            $nested_items = fx::data('content')->where('materialized_path', $old_path . '%', 'LIKE')->all();
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
            $this->parent_ids = $path;
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
            $c_pid = fx::data('page', $ids[0])->get('parent_id');
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
        $this->path = fx::data('content')->where('id', $path_ids)->order('level','asc')->all();
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
}