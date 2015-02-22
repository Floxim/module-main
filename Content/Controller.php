<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\Component\Field;
use Floxim\Floxim\System;
use Floxim\Floxim\System\Fx as fx;

class Controller extends \Floxim\Floxim\Controller\Frontoffice
{

    public function countParentId()
    {
        if (preg_match("~^listInfoblock~", fx::util()->underscoreToCamel($this->action, false))) {
            $this->setParam('parent_id', $this->getParentId());
        }
    }

    public function process()
    {
        $this->listen('before_action_run', array($this, 'countParentId'));
        $result = parent::process();
        return $result;
    }

    protected function getConfigSources()
    {
        $sources = array();
        $sources [] = fx::path('@module/' . fx::getComponentPath('content') . '/cfg.php');
        $com = $this->getComponent();
        $chain = $com->getChain();
        foreach ($chain as $com) {
            $com_file = fx::path('@module/' . fx::getComponentPath($com['keyword']) . '/cfg.php');
            if (file_exists($com_file)) {
                $sources[] = $com_file;
            }
        }
        return $sources;
    }

    public function getControllerName()
    {
        $name = $this->_content_type;
        return $name;
    }
    
    public function getInfoblock() {
        return fx::data('infoblock', $this->getParam('infoblock_id'));
    }

    public function saveSelectedLinkers($ids)
    {
        if (!is_array($ids)) {
            return;
        }
        $linkers = $this->getSelectedLinkers();
        $last_priority = 0;
        foreach ($linkers as $linker) {
            $linker_pos = array_search($linker['linked_id'], $ids);
            if ($linker_pos === false) {
                $linker->delete();
                $linkers->findRemove('id', $linker['id']);
            } else {
                $linker['priority'] = $linker_pos;
                $last_priority = $linker_pos;
                $linker->save();
                unset($ids[$linker_pos]);
            }
        }
        if (count($ids) > 0) {
            $ib = fx::data('infoblock', $this->getParam('infoblock_id'));
            if ($this->getParam('parent_type') == 'current_page_id') {
                $parent_id = fx::env('page_id');
            } else {
                $parent_id = $ib['page_id'];
            }
            foreach ($ids as $id) {
                $linker = fx::data('linker')->create();
                $linker['parent_id'] = $parent_id;
                $linker['infoblock_id'] = $ib['id'];
                $linker['linked_id'] = $id;
                $linker['priority'] = ++$last_priority;
                $linker->save();
            }
        }
    }

    public function dropSelectedLinkers()
    {
        $linkers = fx::data('linker')->where('infoblock_id', $this->getParam('infoblock_id'))
            ->all();
        $linkers->apply(function ($i) {
            $i->delete();
        });
    }

    /*
     * @return fx_collection
     */
    protected function getSelectedLinkers()
    {
        $q = fx::data('linker')
                ->where('infoblock_id', $this->getParam('infoblock_id'))
                ->where('linked_id', 0, '!=');
        $sorting = $this->getParam('sorting');
        if ($sorting === 'manual') {
            $q->order('priority');
        } else {
            //$q->order('content.'.$sorting, $this->getParam('sorting_dir'));
            //$q->join('')
            $sorter_prop_table = $this->getFinder()->getColTable($sorting);
            $q->join('{{'.$sorter_prop_table.'}} as sorter_table', 'sorter_table.id = linked_id');
            $q->order('sorter_table.'.$sorting, $this->getParam('sorting_dir'));
        }
        if ($this->getParam('parent_type') == 'current_page_id') {
            $q->where('parent_id', fx::env('page_id'));
        } else {
            $ib = $this->getInfoblock();
            $q->where('parent_id', $ib['page_id']);
        }
        return $q->all();
    }

    protected function getSelectedValues()
    {
        $res = $this->getSelectedLinkers()->column('linked_id');
        return $res;
    }

    public function getSelectedField($with_values = true)
    {
        $field = array(
            'name'         => 'selected',
            'label'        => fx::alang('Selected', 'controller_component'),
            'type'         => 'livesearch',
            'is_multiple'  => true,
            'ajax_preload' => true,
            'params'       => array(
                'content_type' => $this->_content_type
            ),
            'stored'       => false
        );
        if ($with_values) {
            $field['value'] = $this->getSelectedValues()->getData();
        }
        return $field;
    }

    public function getConditionsField()
    {
        $res_field = array(
            'name'          => 'conditions',
            'label'         => fx::alang('Conditions', 'controller_component'),
            'type'          => 'set',
            'is_cond_set'   => true,
            'tpl'           => array(
                array(
                    'id'   => 'name',
                    'name' => 'name',
                    'type' => 'select'
                ),
            ),
            'operators_map' => array(
                'string'    => array(
                    'contains'     => 'contains',
                    '='            => '=',
                    'not_contains' => 'not contains',
                    '!='           => '!='
                ),
                'int'       => array(
                    '='  => '=',
                    '>'  => '>',
                    '<'  => '<',
                    '>=' => '>=',
                    '<=' => '<=',
                    '!=' => '!=',
                ),
                'datetime'  => array(
                    '='         => '=',
                    '>'         => '>',
                    '<'         => '<',
                    '>='        => '>=',
                    '<='        => '<=',
                    '!='        => '!=',
                    'next'      => 'next',
                    'last'      => 'last',
                    'in_future' => 'in future',
                    'in_past'   => 'in past'
                ),
                'multilink' => array(
                    '='  => '=',
                    '!=' => '!=',
                ),
                'link'      => array(
                    '='  => '=',
                    '!=' => '!=',
                ),
            ),
            'labels'        => array(
                'Field',
                'Operator',
                'Value'
            ),
        );
        $com = $this->getComponent();
        $searchable_fields =
            $com
                ->getAllFields()
                ->find('type', Field\Entity::FIELD_IMAGE, '!=');
        foreach ($searchable_fields as $field) {
            $res = array(
                'description' => $field['name'],
                'type'        => Field\Entity::getTypeById($field['type'])
            );
            if ($field['type'] == Field\Entity::FIELD_LINK) {
                $res['content_type'] = $field->getTargetName();
            }
            if ($field['type'] == Field\Entity::FIELD_MULTILINK) {
                $relation = $field->getRelation();
                $res['content_type'] = $relation[0] == System\Finder::MANY_MANY ? $relation[4] : $relation[1];
            }
            // Add allow values for select parent page
            if ($field['keyword'] == 'parent_id') {
                $pages = $this->getAllowParentPages();
                $values = $pages->getValues(array('id', 'name'));
                $res['values'] = $values;
            }
            $res_field['tpl'][0]['values'][$field['keyword']] = $res;
        }
        $ib_field_params = array(
            'description'  => 'Infoblock',
            'type'         => 'link',
            'content_type' => 'infoblock',
            'conditions'   => array(
                'controller' => array(
                    $com->getAllVariants()->getValues('keyword'),
                    'IN'
                ),
                'site_id'    => fx::env('site_id'),
                'action'     => array(array('list_infoblock', 'list_selected'), 'IN')
            )
        );
        if (($cib_id = $this->getParam('infoblock_id'))) {
            $ib_field_params['conditions']['id'] = array($cib_id, '!=');
        }
        $res_field['tpl'][0]['values']['infoblock_id'] = $ib_field_params;
        return $res_field;
    }

    public function getTargetConfigFields()
    {

        /*
         * Below is the code that produces valid InfoBlock for fields-references
         * offers to choose, where to get/where to add value-links
         * you may elect not for incomprehensible Guia
         */
        $link_fields = $this->
        getComponent()->
            getAllFields()->
            find('type', array(Field\Entity::FIELD_LINK, Field\Entity::FIELD_MULTILINK))->
            find('keyword', 'parent_id', System\Collection::FILTER_NEQ)->
            find('type_of_edit', Field\Entity::EDIT_NONE, System\Collection::FILTER_NEQ);
        $fields = array();
        foreach ($link_fields as $lf) {
            if ($lf['type'] == Field\Entity::FIELD_LINK) {
                $target_com_id = $lf['format']['target'];
            } else {
                $target_com_id = isset($lf['format']['mm_datatype'])
                    ? $lf['format']['mm_datatype']
                    : $lf['format']['linking_datatype'];
            }
            $target_com = fx::data('component', $target_com_id);
            if (!$target_com) {
                continue;
            }
            $com_infoblocks = fx::data('infoblock')->
            where('site_id', fx::env('site')->get('id'))->
            getContentInfoblocks($target_com['keyword']);

            $ib_values = array();
            foreach ($com_infoblocks as $ib) {
                $ib_values [] = array($ib['id'], $ib['name']);
            }
            if (count($ib_values) === 0) {
                continue;
            }
            $c_ib_field = array(
                'name' => 'field_' . $lf['id'] . '_infoblock'
            );
            if (count($ib_values) === 1) {
                $c_ib_field += array(
                    'type'  => 'hidden',
                    'value' => $ib_values[0][0]
                );
            } else {
                $c_ib_field += array(
                    'type'   => 'select',
                    'values' => $ib_values,
                    'label'  => fx::alang('Infoblock for the field', 'controller_component')
                        . ' "' . $lf['name'] . '"'
                );
            }
            $fields[$c_ib_field['name']] = $c_ib_field;
        }
        return $fields;
    }

    /**
     * Get option to bind lost content (having no infoblock_id) to the newly created infoblock
     * @return array
     */
    public function getLostContentField()
    {
        // infoblock already exists
        if ($this->getParam('infoblock_id')) {
            return array();
        }
        $lost = $this->getLostContent();
        if (count($lost) == 0) {
            return array();
        }
        return array(
            'bind_lost_content' => array(
                'type'  => 'checkbox',
                'label' => 'Bind lost content (' . count($lost) . ')'
            )
        );
    }
    
    public function getLostContent()
    {
        $lost = fx::content($this->getComponent()->get('keyword'))
            ->where('infoblock_id', 0)
            ->where('site_id', fx::env('site_id'))
            ->all();
        return $lost;
    }

    public function bindLostContent($ib, $params)
    {
        if (!isset($params['params']['bind_lost_content']) || !$params['params']['bind_lost_content']) {
            return;
        }
        $lost = $this->getLostContent();
        
        foreach ($lost as $lc) {
            $lc->set('infoblock_id', $ib['id']);
            if (
                $ib['page_id'] && (
                    !$lc['parent_id'] ||
                    !in_array($ib['page_id'], $lc->getParentIds())
                )
            ) {
                $lc['parent_id'] = $ib['page_id'];
            }
            $lc->save();
        }
    }

    public function doRecord()
    {
        $page = fx::env('page');
        $this->assign('item', $page);
    }

    public function doList()
    {
        // e.g. fake items for list
        $items = $this->getResult('items');
        
        if (!$items) {
            $f = $this->getFinder();
            $this->trigger('query_ready', array('query' => $f));
            $items = $f->all();

            if (count($items) === 0) {
                $this->_meta['hidden'] = true;
            }
        }
        
        $items_event = fx::event('items_ready', array('items' => $items));
        
        $this->trigger($items_event);
        
        $items = $items_event['items'];
        
        $this->assign('items', $items);
        if (($pagination = $this->getPagination())) {
            $this->assign('pagination', $pagination);
        }
        $this->trigger('result_ready');
    }

    protected function getFakeItems($count = 3)
    {
        $finder = $this->getFinder();
        $items = fx::collection();
        foreach (range(1, $count) as $n) {
            $items [] = $finder->fake();
        }
        return $items;
    }


    public function doListInfoblock()
    {
        // "fake mode" - preview of newly created infoblock
        if ($this->getParam('is_fake')) {
            $count = $this->getParam('limit');
            if (!$count) {
                $count = 3;
            }
            if ($this->getParam('bind_lost_content')) {
                $items = $this->getLostContent();
            } else {
                $items = $this->getFakeItems($count);
            }
            $this->assign('items', $items);
        }
        $this->listen('query_ready', function ($e) {
            $q = $e['query'];
            $ctr = $e['controller'];
            $parent_id = $ctr->getParam('parent_id');
            if ($parent_id && !$ctr->getParam('skip_parent_filter')) {
                $q->where('parent_id', $parent_id);
            }
            $infoblock_id = $ctr->getParam('infoblock_id');
            if ($infoblock_id && !$ctr->getParam('skip_infoblock_filter')) {
                $q->where('infoblock_id', $infoblock_id);
            }
        });
        $this->doList();
        if (fx::isAdmin()) {
            
            $component = $this->getComponent();
            $component_variants = $component->getAllVariants();
            $infoblock = fx::data('infoblock', $this->getParam('infoblock_id'));
            
            foreach ($component_variants as $component_variant) {
                $adder_title = fx::alang('Add') . ' ' . $component_variant->getItemName();//.' &rarr; '.$ib_name;

                $this->acceptContent(array(
                    'title'        => $adder_title,
                    'parent_id'    => $this->getParentId(),
                    'type'         => $component_variant['keyword'],
                    'infoblock_id' => $this->getParam('infoblock_id')
                ));
            }
            if (!$this->getResult('items')) {
                $this->_meta['hidden_placeholder'] = 'Infoblock "' . $infoblock['name'] . '" is empty. ' .
                    'You can add ' . $component->getItemName() . ' here';
            }
        }
    }

    public function acceptContent($params, $entity = null)
    {
        $params = array_merge(
            array(
                'infoblock_id' => $this->getParam('infoblock_id'),
                'type'         => $this->getContentType()
            ), $params
        );
        $component = fx::component($params['type']);
        $has_title = isset($params['title']);
        if (!$has_title) {
            $params['title'] = fx::alang('Add') . ' ' . $component->getItemName();
        }

        if (!is_null($entity)) {
            $meta = isset($entity['_meta']) ? $entity['_meta'] : array();
            
            // by default add items as children of the entity
            if (!isset($params['parent_id']) && !isset($params['rel_field'])) {
                $params['parent_id'] = $entity['id'];
            }
            
            if (!isset($meta['accept_content'])) {
                $meta['accept_content'] = array();
            }
            $meta['accept_content'][] = $params;
            $entity['_meta'] = $meta;
        } else {
            if (!isset($this->_meta['accept_content'])) {
                $this->_meta['accept_content'] = array();
            }
            $this->_meta['accept_content'] [] = $params;
        }
        
        if (isset($params['with_extensions']) && $params['with_extensions']) {
            $extensions =  $component->getAllChildren();
            foreach ($extensions as $extension) {
                $e_name = $extension->getItemName();
                $this->acceptContent(array(
                    'title' => $has_title ? $params['title']. ' / '.$e_name : fx::alang('Add'). ' '.$e_name,
                    'type' => $extension['keyword']
                ), $entity);
            }
        }
    }

    protected function getPaginationUrlTemplate()
    {
        $url = $_SERVER['REQUEST_URI'];
        $url = preg_replace("~[\?\&]page=\d+~", '', $url);
        return $url . '##' . (preg_match("~\?~", $url) ? '&' : '?') . 'page=%d##';
    }

    protected function getCurrentPageNumber()
    {
        return isset($_GET['page']) ? $_GET['page'] : 1;
    }

    protected function getPagination()
    {

        if (!$this->getParam('pagination')) {
            return null;
        }
        $total_rows = $this->getFinder()->getFoundRows();

        if ($total_rows == 0) {
            return null;
        }
        $limit = $this->getParam('limit');
        if ($limit == 0) {
            return null;
        }
        $total_pages = ceil($total_rows / $limit);
        if ($total_pages == 1) {
            return null;
        }
        $links = array();
        $url_tpl = $this->getPaginationUrlTemplate();
        $base_url = preg_replace('~##.*?##~', '', $url_tpl);
        $url_tpl = str_replace("##", '', $url_tpl);
        $c_page = $this->getCurrentPageNumber();
        foreach (range(1, $total_pages) as $page_num) {
            $links[$page_num] = array(
                'active' => $page_num == $c_page,
                'page'   => $page_num,
                'url'    =>
                    $page_num == 1 ?
                        $base_url :
                        sprintf($url_tpl, $page_num)
            );
        }
        $res = array(
            'links'        => fx::collection($links),
            'total_pages'  => $total_pages,
            'total_items'  => $total_rows,
            'current_page' => $c_page
        );
        if ($c_page != 1) {
            $res['prev'] = $links[$c_page - 1]['url'];
        }
        if ($c_page != $total_pages) {
            $res['next'] = $links[$c_page + 1]['url'];
        }
        return $res;
    }

    protected function getParentId()
    {
        $ib = fx::data('infoblock', $this->getParam('infoblock_id'));
        $parent_id = null;
        switch ($this->getParam('parent_type')) {
            case 'mount_page_id':
                $parent_id = $ib['page_id'];
                if ($parent_id === 0) {
                    $parent_id = fx::env('site')->get('index_page_id');
                }
                break;
            case 'current_page_id':
            default:
                $parent_id = fx::env('page')->get('id');
                break;
        }
        return $parent_id;
    }

    public function doListSelected()
    {
        $is_overriden = $this->getParam('is_overriden');
        $linkers = null;
        // preview
        if ($is_overriden) {
            $content_ids = array();
            $selected_val = $this->getParam('selected');
            if (is_array($selected_val)) {
                $content_ids = $selected_val;
            }
        } else {
            // normal
            $linkers = $this->getSelectedLinkers();
            $content_ids = $linkers->getValues('linked_id');
        }
        
        $this->listen('query_ready', function ($e) use ($content_ids) {
            $e['query']->where('id', $content_ids);
        });
        
        if ($linkers) {
            $this->listen('items_ready', function ($e) use ($linkers) {
                $c = $e['items'];
                $ctr = $e['controller'];
                if ($ctr->getParam('sorting') === 'manual') {
                    $c->sort(function ($a, $b) use ($linkers) {
                        $a_l = $linkers->findOne('linked_id', $a['id']);
                        $b_l = $linkers->findOne('linked_id', $b['id']);
                        if (!$a_l || !$b_l) {
                            return 0;
                        }
                        $a_priority = $linkers->findOne('linked_id', $a['id'])->get('priority');
                        $b_priority = $linkers->findOne('linked_id', $b['id'])->get('priority');
                        return $a_priority - $b_priority;
                    });
                    $c->is_sortable = true;
                }
                $c->linkers = $linkers;
            });
        } else {
            $this->listen('items_ready', function ($e) use ($content_ids) {
                $c = $e['items'];
                $ctr = $e['controller'];
                if ($ctr->getParam('sorting') === 'manual') {
                    $c->sort(function ($a, $b) use ($content_ids) {
                        $a_priority = array_search($a['id'], $content_ids);
                        $b_priority = array_search($b['id'], $content_ids);
                        return $a_priority - $b_priority;
                    });
                }
            });
        }
        if (!isset($this->_meta['fields'])) {
            $this->_meta['fields'] = array();
        }
        $this->doList();
        
        $items = $this->getResult('items');

        // if we are admin and not viewing the block in preview mode,
        // let's add livesearch field loaded with the selected values
        if (!$is_overriden && fx::isAdmin()) {
            $selected_field = $this->getSelectedField(false);
            $selected_field['value'] = array();
            // filter result by selected content ids,
            // because some items can be added from inherited controllers (e.g. menu with subsections)
            $selected_items = $items->find('id', $content_ids);
            foreach ($selected_items as $selected_item) {
                $selected_field['value'][] = array(
                    'name' => $selected_item['name'],
                    'id'   => $selected_item['id']
                );
            }
            $selected_field['var_type'] = 'ib_param';
            unset($selected_field['ajax_preload']);
            $this->_meta['fields'][] = $selected_field;
            if ($items->linkers) {
                $items->linkers->selectField = $selected_field;
                $items->linkers->linkedBy = 'linked_id';
            }
        }
        if (count($items) === 0 && fx::isAdmin()) {
            $component = $this->getComponent();
            $ib = fx::data('infoblock', $this->getParam('infoblock_id'));
            $this->_meta['hidden_placeholder'] = 'Infoblock "' . $ib['name'] . '" is empty. ' .
                'Select ' . $component->getItemName() . ' to show here';
        }
    }

    public function doListFiltered()
    {
        $this->listen('query_ready', function ($e) {
            $q = $e['query'];
            $ctr = $e['controller'];
            $component = $ctr->getComponent();
            $fields = $component->getAllFields();
            $conditions = fx::collection($ctr->getParam('conditions'));
            if (!$conditions->findOne('name', 'site_id')) {
                $conditions[] = array(
                    'name'     => 'site_id',
                    'operator' => '=',
                    'value'    => array(fx::env('site')->get('id'))
                );
            }
            $target_parent_id = null;
            $target_infoblock_id = null;
            foreach ($conditions as $condition) {
                if (
                    $condition['name'] === 'parent_id'
                    && isset($condition['value'])    
                    && is_array($condition['value'])
                    && count($condition['value']) === 1
                ) {
                    $target_parent_id = current($condition['value']);
                } elseif (
                    $condition['name'] === 'infoblock_id'
                    && isset($condition['value'])
                    && is_array($condition['value'])
                    && count($condition['value']) === 1
                ) {
                    $target_infoblock_id = current($condition['value']);
                }

                $field = $fields->findOne('keyword', $condition['name']);
                $error = false;
                switch ($condition['operator']) {
                    case 'contains':
                    case 'not_contains':
                        $condition['value'] = '%' . $condition['value'] . '%';
                        $condition['operator'] = ($condition['operator'] == 'not_contains' ? 'NOT ' : '') . 'LIKE';
                        break;
                    case 'next':
                        if (isset($condition['value']) && !empty($condition['value'])) {
                            $q->where(
                                $condition['name'],
                                '> NOW()',
                                'RAW'
                            );
                            $condition['value'] = '< NOW() + INTERVAL ' . $condition['value'] . ' ' . $condition['interval'];
                            $condition['operator'] = 'RAW';
                        } else {
                            $error = true;
                        }
                        break;
                    case 'last':
                        if (isset($condition['value']) && !empty($condition['value'])) {
                            $q->where(
                                $condition['name'],
                                '< NOW()',
                                'RAW'
                            );
                            $condition['value'] = '> NOW() - INTERVAL ' . $condition['value'] . ' ' . $condition['interval'];
                            $condition['operator'] = 'RAW';
                        } else {
                            $error = true;
                        }
                        break;
                    case 'in_future':
                        $condition['value'] = '> NOW()';
                        $condition['operator'] = 'RAW';
                        break;
                    case 'in_past':
                        $condition['value'] = '< NOW()';
                        $condition['operator'] = 'RAW';
                        break;
                }
                if ($field['type'] == Field\Entity::FIELD_LINK) {
                    if (!isset($condition['value'])) {
                        $error = true;
                    } else {
                        $ids = array();
                        foreach ($condition['value'] as $v) {
                            $ids[] = $v;
                        }
                        $condition['value'] = $ids;
                        if ($condition['operator'] === '!=') {
                            $condition['operator'] = 'NOT IN';
                        } elseif ($condition['operator'] === '=') {
                            $condition['operator'] = 'IN';
                        }
                    }
                }

                if ($field['type'] == Field\Entity::FIELD_MULTILINK) {

                    if (!isset($condition['value']) || !is_array($condition['value'])) {
                        $error = true;
                    } else {
                        foreach ($condition['value'] as $v) {
                            $ids[] = $v;
                        }
                        $relation = $field->getRelation();
                        if ($relation[0] === System\Finder::MANY_MANY) {
                            $content_ids = fx::data($relation[1])->
                            where($relation[5], $ids)->
                            select($relation[2])->
                            getData()->getValues($relation[2]);
                        } else {
                            $content_ids = fx::data($relation[1])->
                            where('id', $ids)->
                            select($relation[2])->getData()->getValues($relation[2]);
                        }
                        $condition['name'] = 'id';
                        $condition['value'] = $content_ids;
                        if ($condition['operator'] === '!=') {
                            $condition['operator'] = 'NOT IN';
                        } elseif ($condition['operator'] === '=') {
                            $condition['operator'] = 'IN';
                        }
                    }
                }

                if ($condition['name'] == 'infoblock_id') {
                    if (empty($condition['value'])) {
                        continue;
                    }
                    $target_ib = fx::data('infoblock', $condition['value']);//->first();
                    if (!$target_ib) {
                        continue;
                    }
                    $target_ib = $target_ib->first();
                    if ($target_ib['action'] == 'list_selected') {
                        $linkers = fx::data('linker')->where('infoblock_id', $target_ib['id'])
                            ->all();
                        $content_ids = $linkers->getValues('linked_id');
                        $condition['name'] = 'id';
                        $condition['value'] = $content_ids;
                        $condition['operator'] = 'IN';
                    }
                }
                if (!$error) {
                    $q->where(
                        $condition['name'],
                        $condition['value'],
                        $condition['operator']
                    );
                }
            }
            if ($target_parent_id && $target_infoblock_id) {
                $ctr->acceptContent(array(
                    'parent_id'    => $target_parent_id,
                    'type'         => $component['keyword'],
                    'infoblock_id' => $target_infoblock_id
                ));
            }
        });

        $this->doList();
    }


    /**
     * $_content_type may be one of the values
     * the table fx_component in the keyword field
     * @var string
     */
    protected $_content_type = null;

    /**
     * @return string
     */
    public function getContentType()
    {
        if (!$this->_content_type) {
            $com_name = fx::getComponentNameByClass(get_class($this));
            $this->_content_type = $com_name;
        }
        return $this->_content_type;
    }

    /**
     * Returns the component at the value of the property _content_type
     * @return fx_data_component
     */
    public function getComponent()
    {
        return fx::component($this->getContentType());
    }


    protected $_finder = null;

    /**
     * @return \Floxim\Floxim\System\Finder data finder
     */
    public function getFinder()
    {
        if (!is_null($this->_finder)) {
            return $this->_finder;
        }
        $finder = fx::data($this->getContentType());
        if (!fx::isAdmin()) {
            $finder
                ->where('is_published', 1)
                ->where('is_branch_published', 1);
        }
        $show_pagination = $this->getParam('pagination');
        $c_page = $this->getCurrentPageNumber();
        $limit = $this->getParam('limit');
        if ($show_pagination && $limit) {
            $finder->calcFoundRows();
        }
        if ($limit) {
            if ($show_pagination && $c_page != 1) {
                $finder->limit(
                    $limit * ($c_page - 1),
                    $limit
                );
            } else {
                $finder->limit($limit);
            }
        }
        if (($sorting = $this->getParam('sorting'))) {
            $dir = $this->getParam('sorting_dir');
            if ($sorting === 'manual') {
                $sorting = 'priority';
                $dir = 'ASC';
            }
            if (!$dir) {
                $dir = 'ASC';
            }
            $finder->order($sorting, $dir);
        }
        $this->_finder = $finder;
        return $finder;
    }

    protected function getControllerVariants()
    {
        return array_reverse(
                    $this->getComponent()
                         ->getChain()
                         ->getValues('keyword')
                );
    }

    public function getActions()
    {
        $actions = parent::getActions();
        $com = $this->getComponent();
        foreach ($actions as $action => &$info) {
            if (!isset($info['name'])) {
                $info['name'] = $com['name'] . ' / ' . $action;
            }
        }
        return $actions;
    }

    /**
     * Return allow parent pages for current component
     * This method need override for controller specific component
     *
     * @return fx_collection
     */
    protected function getAllowParentPages()
    {
        return fx::collection();
    }
    
    public function doFormCreate() 
    {
        $user = fx::env('user');
        
        $item = $this->getFinder()->create();
        
        $form  = new \Floxim\Form\Form(array(
            'id' => 'form_create_'.str_replace(".", '_', $item['type']).'_'.$this->getParam('infoblock_id')
        ));
        
        $target_infoblock = $this->getParam('target_infoblock');
        $item['infoblock_id'] = $target_infoblock;
        
        if (!$user->can('see_create_form', $item)) {
            return false;
        }
        $fields = $item->getFormFields();
        $form->addFields($fields);
        $this->trigger('form_ready', array('form' => $form, 'action' => 'create'));
        
        if ($form->isSent()) {
            if ($user->can('create', $item)) {
                $this->trigger('form_sent', array('form' => $form, 'action' => 'create'));
                $item->loadFromForm($form);
                if ($item->validateWithForm()) {
                    $item->save();
                    $this->trigger('form_completed', array('form' => $form, 'action' => 'create', 'entity' => $item));
                    $target_type = $this->getParam('redirect_to');
                    switch ($target_type) {
                        case 'refresh':
                            fx::http()->refresh();
                            break;
                        case 'new_page':
                            fx::http()->redirect($item['url']);
                            break;
                        case 'parent_page':
                            fx::http()->redirect($item['parent']['url']);
                            break;
                    }
                } else {
                    $form->addError(
                        fx::lang('Unable to save ', 'controller_component') 
                        . $item['component']->getItemName()
                    );
                }
            } else {
                $form->addError('Permission denied');
            }
        }
        $this->assign('form', $form);
        $this->assign('item', $item);
    }
    
    public function doFormEdit() 
    {
        $user = fx::env('user');
        $item_id = $this->getParam('item_id');
        if ($item_id) {
            $item = $this->getFinder()->getById($item_id);
        } else {
            $item = fx::env('page');
        }
        if (!$user->can('see_edit_form', $item)) {
            return false;
        }
        $fields = $item->getFormFields();
        $form  = new \Floxim\Form\Form();
        $form->addFields($fields);
        if ($form->isSent()) {
            $vals = $form->getValues();
            $item->setFieldValues($vals);
            if ($user->can('edit', $item)) {
                $item->save();
            }
        }
        $this->assign('form', $form);
        $this->assign('item', $item);
    }
    
    public function doLivesearch()
    {
        $input = $_POST;
        if (!isset($input['content_type'])) {
            return;
        }
        $content_type = $input['content_type'];
        $finder = fx::data($content_type);
        if (($finder instanceof \Floxim\Main\Content\Finder) and $content_type != 'user') {
            $finder->where('site_id', fx::env('site')->get('id'));
        }
        if (isset($input['skip_ids']) && is_array($input['skip_ids'])) {
            $finder->where('id', $input['skip_ids'], 'NOT IN');
        }
        if (isset($input['ids'])) {
            $finder->where('id', $input['ids']);
        }
        if (isset($input['conditions'])) {
            foreach ($input['conditions'] as $cond_field => $cond_val) {
                if (is_array($cond_val)) {
                    $finder->where($cond_field, $cond_val[0], $cond_val[1]);
                } else {
                    $finder->where($cond_field, $cond_val);
                }
            }
        }
        $res = $finder->livesearch($_POST['term'], (isset($_POST['limit']) && $_POST['limit']) ? $_POST['limit'] : 20);
        fx::complete($res);
    }
}