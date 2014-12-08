<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\System;
use Floxim\Floxim\Template;
use Floxim\Floxim\Component\Field;
use Floxim\Floxim\System\Fx as fx;

class Entity extends System\Entity implements Template\Entity
{
    protected $component_id;

    public function __construct($input = array())
    {
        if ($input['component_id']) {
            $this->component_id = $input['component_id'];
        }
        parent::__construct($input);
        return $this;
    }
    
    public function getAvailableOffsets() {
        return $this->getComponent()->getAvailableEntityOffsets();
    }

    protected $type = null;
    /*
     * Returns the keyword of entity component
     */
    public function getType()
    {
        if (is_null($this->type)) {
            $this->type = $this->getComponent()->get('keyword');
        }
        return $this->type;
    }

    public function getComponentId()
    {
        return $this->component_id;
    }
    
    /**
     * Get entity component
     * @return \Floxim\Floxim\Component\Component\Entity
     */
    public function getComponent()
    {
        $component = fx::component($this->component_id);
        return $component;
    }

    public function isInstanceof($type)
    {
        $type = fx::getComponentFullName($type);
        if ($this['type'] == $type) {
            return true;
        }
        $chain = $this->getComponent()->getChain();
        foreach ($chain as $com) {
            if ($com['keyword'] == $type) {
                return true;
            }
        }
        return false;
    }

    public function getUploadFolder()
    {
        return "content/" . $this->component_id;
    }

    public function loadFromForm($form, $fields = null)
    {
        $vals = $this->getFromForm($form, $fields);
        $this->setFieldValues($vals, $fields);
        $this->bindForm($form);
        return $this;
    }
    
    /*
     * Populates $this->data based on administrative forms
     */
    public function setFieldValues($values = array(), $save_fields = null)
    {
        if (count($values) == 0) {
            return;
        }
        $fields = $save_fields ? $this->getFields()->find('keyword', $save_fields) : $this->getFields();
        $result = array('status' => 'ok');
        $val_keys = $values instanceof \Floxim\Floxim\System\Collection ? $values->keys() : array_keys($values);
        foreach ($fields as $field) {
            $field_keyword = $field['keyword'];
            unset($val_keys[array_search($field_keyword, $val_keys)]);
            if (!isset($values[$field_keyword])) {
                if ($field['type'] == Field\Entity::FIELD_MULTILINK) {
                    $value = array();
                } else {
                    continue;
                }
            } else {
                $value = $values[$field_keyword];
            }

            if (!$field->checkRights()) {
                continue;
            }

            if ($field->validateValue($value)) {
                $field->setValue($value);
                $this[$field_keyword] = $field->getSavestring($this);
            } else {
                $field->setError();
                $result['status'] = 'error';
                $result['text'][] = $field->getError();
                $result['fields'][] = $field_keyword;
            }
        }
        foreach ($val_keys as $payload_key) {
            $this->setPayload($payload_key, $values[$payload_key]);
        }
        return $result;
    }

    //protected $_fields_to_show = null;

    protected static $content_fields_by_component = array();

    protected $_fields_to_show = null;

    public function getFieldMeta($field_keyword)
    {
        $fields = $this->getFields();
        $is_template_var = self::isTemplateVar($field_keyword);
        if ($is_template_var) {
            $field_keyword = mb_substr($field_keyword, 1);
            $cf = $fields[$field_keyword];
            $v_id = $this['id'];
            if (!$v_id) {
                $v_id = '#new_id#';
            }
            $field_meta = array(
                'var_type' => 'visual',
                'id'       => $field_keyword . '_' . $v_id,
                'name'     => $field_keyword . '_' . $v_id
            );
        } else {
            $cf = $fields[$field_keyword];
            if (!$cf) {
                $offsets = $this->getAvailableOffsets();
                if (isset($offsets[$field_keyword])) {
                    $offset_meta = $offsets[$field_keyword];
                    if ($offset_meta['type'] === self::OFFSET_SELECT) {
                        $cf = $fields[$offset_meta['real_offset']];
                    }
                }
                if (!$cf) {
                    return false;
                }
            }
            $field_meta = array(
                'var_type'        => 'content',
                'content_id'      => $this['id'],
                'content_type_id' => $this->component_id,
                'id'              => $cf['id'],
                'name'            => $cf['keyword']
            );
        }
        $field_meta['label'] = $cf && $cf['name'] ? $cf['name'] : $field_keyword;
        if ($cf && $cf->type) {
            if ($cf->type === 'text') {
                $field_meta['type'] = isset($cf['format']['html']) ? 'html' : 'text';
            } else {
                $field_meta['type'] = $cf->type;
            }
            if ($field_meta['type'] === 'html' && $cf['format']['nl2br']) {
                $field_meta['linebreaks'] = true;
            }
            if ($cf->type === 'select') {
                $field_meta['values'] = $cf->getSelectValues();
                $field_meta['value'] = $this[$cf['keyword']];
            }
        }
        return $field_meta;
    }

    public function getFormFields()
    {
        $all_fields = $this->getFields();
        $form_fields = array();
        $coms = array();
        $content_com_id = fx::component('content')->get('id');
        foreach ($all_fields as $field) {
            if ($field['type_of_edit'] == Field\Entity::EDIT_NONE) {
                continue;
            }
            $field_method = 'getFormField' . fx::util()->underscoreToCamel($field['keyword'], true);
            if (method_exists($this, $field_method)) {
                $jsf = call_user_func(array($this, $field_method), $field);
            } else {
                $jsf = $field->getJsField($this);
            }
            if ($jsf) {
                if (!isset($jsf['tab']) || !$jsf['tab']) {
                    if ($field['form_tab']) {
                        $jsf['tab'] = $field['form_tab'];
                    } else {
                        $coms [$field['component_id']] = true;
                        $jsf['tab'] = count($coms);
                        if ($field['component_id'] !== $content_com_id) {
                            $jsf['tab']--;
                        }
                    }
                }
                $form_fields[] = $jsf;
            }
        }
        return $form_fields;
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
        $values = array();
        $c_id = $this['id'];
        $get_values = function ($level, $level_num = 0) use (&$values, &$get_values, $c_id) {
            foreach ($level as $page) {
                if ($page['id'] == $c_id) {
                    continue;
                }
                $values [] = array($page['id'], str_repeat('- ', $level_num * 2) . $page['name']);
                if ($page['nested']) {
                    $get_values($page['nested'], $level_num + 1);
                }
            }
        };
        $get_values($parents);
        if (count($values) === 1) {
            //fx::debug('only');
            //return;
        }
        $jsf = $field ? $field->getJsField($this) : array();
        $jsf['values'] = $values;
        $jsf['hidden_on_one_value'] = true;
        $jsf['tab'] = 1;
        return $jsf;
    }

    /**
     * Returns a finder to get "potential" parents for the object
     */
    public function getAvailParentsFinder()
    {
        $ib = fx::data('infoblock', $this['infoblock_id']);
        if (!$ib) {
            return false;
        }

        $parent_type = $ib['scope']['page_type'];
        if (!$parent_type) {
            $parent_type = 'page';
        }
        $root_id = $ib['page_id'];
        if (!$root_id) {
            $root_id = fx::data('site', $ib['site_id'])->get('index_page_id');
        }
        $finder = fx::content($parent_type);
        if ($ib['scope']['pages'] === 'this') {
            $finder->where('id', $ib['page_id']);
        } else {
            $finder->descendantsOf($root_id, $ib['scope']['pages'] != 'children');
        }
        return $finder;
    }

    public function getTemplateRecordAtts($collection, $index)
    {
        $entity_meta = array(
            $this->get('id'),
            $this->getType(false)
        );
        
        
        if (is_object($collection) && $collection->linker_map && isset($collection->linker_map[$index])) {
            $linker = $collection->linker_map[$index];
            $entity_meta[] = $linker['id'];
            $entity_meta[] = $linker['type'];
        }
        $entity_atts = array(
            'data-fx_entity' => $entity_meta,
            'class'          => 'fx_entity' . (is_object($collection) && $collection->is_sortable ? ' fx_sortable' : '')
        );
        
        $com = $this->getComponent();
        $entity_atts['data-fx_entity_name'] = $com->getItemName();

        if ($this->isAdderPlaceholder()) {
            $entity_atts['class'] .= ' fx_entity_adder_placeholder';
        }
        if (isset($this['_meta'])) {
            $entity_atts['data-fx_entity_meta'] = $this['_meta'];
        }
        return $entity_atts;
    }

    public function addTemplateRecordMeta($html, $collection, $index, $is_subroot)
    {
        // do nothing if html is empty
        if (!trim($html)) {
            return $html;
        }

        $entity_atts = $this->getTemplateRecordAtts($collection, $index);

        if ($is_subroot) {
            $html = preg_replace_callback(
                "~^(\s*?)(<[^>]+>)~",
                function ($matches) use ($entity_atts) {
                    $tag = Template\HtmlToken::createStandalone($matches[2]);
                    $tag->addMeta($entity_atts);
                    return $matches[1] . $tag->serialize();
                },
                $html
            );
            return $html;
        }
        $proc = new Template\Html($html);
        $html = $proc->addMeta($entity_atts);
        return $html;
    }

    protected function beforeSave()
    {

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
        /*
        fx::debug(
            'n:'.$this['priority'], 'o:'.$old_priority,
            $this['name'],
            $rel_dir, fx::content($rel_item_id)->get('name')
        );
         *
         */
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
                    $old_linkers = isset($this->modified_data[$link_field['keyword']]->linker_map) ?
                        $this->modified_data[$link_field['keyword']]->linker_map :
                        new System\Collection();

                    // new linkers
                    // must be set
                    // @todo then we will cunning calculation
                    if (!isset($val->linker_map) || count($val->linker_map) != count($val)) {
                        throw new \Exception('Wrong linker map');
                    }
                    foreach ($val->linker_map as $linker_obj) {
                        $linker_obj[$related_field_keyword] = $this['id'];
                        $linker_obj->save();
                    }

                    $old_linkers->findRemove('id', $val->linker_map->getValues('id'));
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

    public function getFields()
    {
        $com_id = $this->component_id;

        if (!isset(self::$content_fields_by_component[$com_id])) {
            $fields = array();
            foreach ($this->getComponent()->getAllFields() as $f) {
                $fields[$f['keyword']] = $f;
            }
            self::$content_fields_by_component[$com_id] = fx::collection($fields);
        }
        return self::$content_fields_by_component[$com_id];
    }

    public function hasField($field_keyword)
    {
        $fields = $this->getFields();
        return isset($fields[$field_keyword]);
    }

    protected function afterDelete()
    {
        parent::afterDelete();
        // delete images when deleting content
        $image_fields = $this->getFields()->
        find('type', Field\Entity::FIELD_IMAGE);
        foreach ($image_fields as $f) {
            $c_prop = $this[$f['keyword']];
            if (fx::path()->isFile($c_prop)) {
                fx::files()->rm($c_prop);
            }
        }

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
        // modified image fields
        $image_fields = $this->getFields()->
        find('keyword', $this->modified)->
        find('type', Field\Entity::FIELD_IMAGE);

        foreach ($image_fields as $img_field) {
            $old_value = $this->modified_data[$img_field['keyword']];
            if (fx::path()->isFile($old_value)) {
                fx::files()->rm($old_value);
            }
        }
        
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

    public function fake()
    {
        $fields = $this->getFields();
        foreach ($fields as $f) {
            $this[$f['keyword']] = $f->fakeValue();
        }
    }

    /**
     * Check if the entity is adder placeholder or set this property to $switch_to value
     * @param bool $switch_to set true or false
     * @return bool
     */
    public function isAdderPlaceholder($switch_to = null)
    {
        if (func_num_args() == 1) {
            $this->_is_adder_placeholder = $switch_to;
        }
        return isset($this->_is_adder_placeholder) && $this->_is_adder_placeholder;

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
        $this->path = fx::data('content')->where('id', $path_ids)->all();
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
}