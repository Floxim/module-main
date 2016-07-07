<?php
namespace Floxim\Main\Content;

use Floxim\Floxim\Component\Field;
use Floxim\Floxim\System;
use Floxim\Floxim\System\Fx as fx;

class Controller extends \Floxim\Floxim\Controller\Frontoffice
{

    public function countParentId()
    {
        if (
            preg_match("~^listInfoblock~", fx::util()->underscoreToCamel($this->action, false)) &&
            !$this->getParam('is_pass_through')
        ) {
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
        $sources [] = fx::path('@module/' . fx::getComponentPath('floxim.main.content') . '/cfg.php');
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
            if (!$this->getParam('is_pass_through')) {
                $parent_id = fx::env('page_id');
            } else {
                $parent_id = $ib['page_id'];
            }
            foreach ($ids as $id) {
                $linker = fx::data('floxim.main.linker')->create();
                $linker['parent_id'] = $parent_id;
                $linker['infoblock_id'] = $ib['id'];
                $linker['linked_id'] = $id;
                $linker['priority'] = ++$last_priority;
                $linker->save();
            }
        }
    }
    
    public function getParentFinderConditions()
    {
        $ib = $this->getInfoblock();
        switch ($ib['scope_type']) {
            case 'one_page':
                return array('id', $ib['page_id']);
            case 'custom':
            case 'all_pages':
            case 'infoblock_pages':
                $parent_type = $ib['params']['parent_type'];
                if ($parent_type === 'current_page') {
                    if ($ib['scope_type'] === 'custom') {
                        $scope = $ib['scope_entity'];
                        $finder = fx::data('floxim.main.page');
                        $conds = $finder->processCondition($scope->getConditions());
                        return $conds;
                    }
                    return array(true);
                } 
                if ($parent_type === 'certain_page') {
                    $parent_id = $ib['params']['parent_id'];
                    return array('id', $parent_id);
                }
                break;
        }
        return array(true);
    }

    public function dropSelectedLinkers()
    {
        fx::data('floxim.main.linker')
            ->where(
                'infoblock_id', 
                $this->getParam('infoblock_id')
            )
            ->all()
            ->apply(
                function ($i) {
                    $i->delete();
                }
            );
    }

    /*
     * @return fx_collection
     */
    protected function getSelectedLinkers()
    {
        $q = fx::data('floxim.main.linker')
                ->where('infoblock_id', $this->getParam('infoblock_id'))
                ->where('linked_id', 0, '!=');
        $sorting = $this->getParam('sorting');
        if ($sorting === 'manual') {
            $q->order('priority');
        } else {
            
            $sorter_prop_table = $this->getFinder()->getColTable($sorting);
            if ($sorter_prop_table) {
                $q->join('{{'.$sorter_prop_table.'}} as sorter_table', 'sorter_table.id = linked_id');
                $q->order('sorter_table.'.$sorting, $this->getParam('sorting_dir'));
            }
        }
        if (!$this->getParam('is_pass_through')) {
            $q->where('parent_id', fx::env('page_id'));
        } else {
            $ib = $this->getInfoblock();
            $q->where('parent_id', $ib['page_id']);
        }
        return $q->all();
    }

    protected function getSelectedValues()
    {
        $linkers = $this->getSelectedLinkers();
        $res = $linkers->column('linked_id');
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
                'content_type' => $this->_content_type,
                'allow_select_doubles' => $this->getParam('allow_select_doubles')
            ),
            'stored'       => false,
            'tab' => array(
                'key' => 'selected',
                'label' => fx::alang('Selected entries')
            )
        );
        if ($with_values) {
            $field['value'] = $this->getSelectedValues()->getData();
        }
        return $field;
    }
    
    public function getConditionsField() {
        $com = $this->getComponent();
        $cond_fields = array(
            $com->getFieldForFilter('entity')
        );
        $context = fx::env()->getFieldsForFilter();
        
        foreach ($context as $context_prop) {
            $cond_fields []= $context_prop;
        }
        
        $field = array(
            'name' => 'conditions',
            'type' => 'condition',
            'context' => $context,
            'fields' => $cond_fields,
            'label' => false,
            'types' => fx::data('component')->getTypesHierarchy(),
            'tab' => array(
                'key' => 'conditions',
                'label' => fx::alang('Conditions', 'controller_component')
            )
        );
        return $field;
    }

    public function getTargetConfigFields()
    {

        /*
         * Below is the code that produces valid InfoBlock for fields-references
         * offers to choose, where to get/where to add value-links
         * you may elect not for incomprehensible Guia
         */
        $link_fields = $this
            ->getComponent()
            ->getAllFields()
            ->find('type', array('link', 'multilink'))
            ->find('keyword', 'parent_id', System\Collection::FILTER_NEQ)
            ->find('is_editable', 1);
        $fields = array();
        foreach ($link_fields as $lf) {
            if ($lf['type'] == 'link') {
                $target_com_id = $lf['format']['target'];
            } else {
                $target_com_id = isset($lf['format']['mm_datatype'])
                    ? $lf['format']['mm_datatype']
                    : $lf['format']['linking_datatype'];
            }
            $target_com = fx::component($target_com_id);
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
        return array();
        // infoblock already exists
        if ($this->getParam('infoblock_id')) {
            return array();
        }
        $count_lost = $this->countLostContent();
        if ($count_lost < 1) {
            return array();
        }
        return array(
            'bind_lost_content' => array(
                'type'  => 'checkbox',
                'label' => 'Bind lost content (' . $count_lost . ')'
            )
        );
    }
    
    protected function getLostFinder()
    {
        $finder = fx::content($this->getComponent()->get('keyword'))
                ->where('infoblock_id', 0)
                ->where('site_id', fx::env('site_id'));
        return $finder;
    }
    
    static $lost_content_stats = null;
    
    public function countLostContent()
    {
        if (is_null(self::$lost_content_stats)) {
            self::$lost_content_stats = array();
            $site_id = fx::env('site_id');
            if (!$site_id) {
                return 0;
            }
            $lost = fx::db()->getResults(
                'select count(*) as cnt, `type` 
                from {{floxim_main_content}} as c
                left join {{infoblock}} as ib on c.infoblock_id = ib.id
                where ib.id IS NULL and c.site_id = '.$site_id.' 
                group by c.type'
            );
            foreach ($lost as $entry) {
                self::$lost_content_stats[$entry['type']] = $entry['cnt'];
            }
        }
        $c_type = $this->getComponent()->get('keyword');
        return isset(self::$lost_content_stats[$c_type]) ? self::$lost_content_stats[$c_type] : 0;
    }
    
    public function getLostContent()
    {
        $lost = $this->getLostFinder()->all();
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
        $this->trigger('result_ready');
    }

    public function doList()
    {
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

    protected function getFakeItems($count = 4)
    {
        $coms = $this->getComponent()->getAllVariants()->find('is_abstract', 0)->getValues();
        $items = fx::collection();
        $index = 0;
        foreach (range(1, $count) as $n) {
            if (!isset($coms[$index])) {
                $index = 0;
            }
            $items [] = $coms[$index]->getEntityFinder()->fake();
            $index++;
        }
        return $items;
    }


    public function doListInfoblock()
    {
        // "fake mode" - preview of newly created infoblock
        if ($this->getParam('is_fake')) {
            $count = $this->getParam('limit');
            if (!$count) {
                $count = 4;
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
            $params['title'] = fx::alang('Add') . ' ' . $component->getItemName('add');
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
                $e_name = $extension->getItemName('add');
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
                        //sprintf($url_tpl, $page_num)
                        str_replace("%d", $page_num, $url_tpl)
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
        $parent_type = $this->getParam('parent_type');
        switch ($parent_type) {
            case 'none':
                return null;
            case 'current_page':
                return fx::env('page_id');
            case 'certain_page':
                return (int) $this->getParam('parent_id');
            case 'expression':
                // some magic here
                break;
        }
    }
    
    protected function getCommonInfoblockPage($items)
    {
        $ib_ids = array_unique($items->getValues('infoblock_id'));
        
        if (count($ib_ids) !== 1) {
            return;
        }
        $common_ib = fx::data('infoblock', current($ib_ids));
        if (!$common_ib || !$common_ib['page_id']) {
            return;
        }
        $common_page = fx::data('floxim.main.page', $common_ib['page_id']);
        return $common_page;
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
                $real_c = $c->fork();
                $real_linkers = $linkers->fork();
                foreach ($linkers as $l) {
                    $linked = $c->findOne('id', $l['linked_id']);
                    if ($linked) {
                        $real_c[]= $linked;
                        $real_linkers[]= $l;
                    }
                }
                $real_c->linkers = $real_linkers;
                $e['items'] = $real_c;
            });
        } else {
            $this->listen('items_ready', function ($e) use ($content_ids) {
                $c = $e['items'];
                $real_c = $c->fork();
                
                $ctr = $e['controller'];
                if ($ctr->getParam('sorting') === 'manual') {
                    foreach ($content_ids as $c_id) {
                        $linked = $c->findOne('id', $c_id);
                        $real_c[]= $linked;
                    }
                } else {
                    $counts = array_count_values($content_ids);
                    foreach ($c as $linked) {
                        $c_count = $counts[$linked['id']];
                        foreach (range(1, $c_count) as $n) {
                            $real_c[]= $linked;
                        }
                    }
                }
                $e['items'] = $real_c;
            });
        }
        if (!isset($this->_meta['fields'])) {
            $this->_meta['fields'] = array();
        }
        $this->doList();
        
        $items = $this->getResult('items');
        
        $common_page = $this->getCommonInfoblockPage($items);
        if ($common_page) {
            $this->assign('more_url', $common_page['url']);
        }

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
            //$this->_meta['fields'][] = $selected_field;
            
            // allow select the same item several times
            $selected_field['allow_select_doubles'] = $this->getParam('allow_select_doubles');
            if ($items->linkers) {
                $items->linkers->selectField = $selected_field;
                $items->linkers->linkedBy = 'linked_id';
            }
        }
        /*
        if (count($items) === 0 && fx::isAdmin()) {
            $component = $this->getComponent();
            $ib = fx::data('infoblock', $this->getParam('infoblock_id'));
            //$this->_meta['hidden_placeholder'] = '';
        }
         * 
         */
    }

    public function doListFiltered()
    {
        $conds = $this->getParam('conditions');
        if (!is_string($conds)) {
            return;
        }
        $conds = json_decode($conds, true);
        $this->listen('query_ready', function ($e) use ($conds) {
            $q = $e['query'];
            $q->where('site_id', fx::env('site_id'));
            if ($conds) {
                $q->applyConditions($conds);
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
        return //array_reverse(
                    $this->getComponent()
                         ->getChain()
                         ->getValues('keyword');
                //);
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

    protected function initDataForm($action)
    {
        $id = 'form_'.$action.'_'
                .str_replace(".", '_', $this->getComponent()->get('keyword'))
                .'_'
                .$this->getParam('infoblock_id');
        $params = array(
            'id' => $id
        );
        if ($this->getParam('ajax') || fx::env('ajax')) {
            $form = $this->ajaxForm($params);
        } else {
            $form = new \Floxim\Form\Form($params);
        }
        return $form;
    }
    
    public function doFormCreate() 
    {
        $user = fx::env('user');
        
        $item = $this->getFinder()->create();
        
        $form = $this->initDataForm('create');
        
        $target_infoblock = $this->getParam('target_infoblock');
        $item['infoblock_id'] = $target_infoblock;
        
        
        if (!$user->can('see_create_form', $item)) {
            return false;
        }
        
        $this->assign('form', $form);
        $this->assign('item', $item);
        
        $fields = $item->getFormFields();
        $form->addFields($fields);
        $this->trigger('form_ready', array('form' => $form, 'action' => 'create'));
        
        if ($form->isSent() && !$form->hasErrors()) {
            if ($user->can('create', $item)) {
                $this->trigger('form_sent', array('form' => $form, 'action' => 'create'));
                $item->loadFromForm($form);
                if ($item->validateWithForm()) {
                    $item->save();
                    $this->trigger('form_completed', array('form' => $form, 'action' => 'create', 'entity' => $item));
                    if (fx::env('ajax')) {
                        $form->finish();
                        return;
                    }
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
                        fx::alang('Unable to save ', 'controller_component') 
                        . $item['component']->getItemName('add')
                    );
                }
            } else {
                $form->addError('Permission denied');
            }
        }
    }
    
    public function doFormEdit() 
    {
        $user = fx::env('user');
        
        $form = $this->initDataForm('edit');
        
        $item_id = $form->storeValue('item_id', $this->getParam('item_id'));
        
        if ($item_id) {
            $item = $this->getFinder()->getById($item_id);    
        } else {
            $item = fx::env('page');
        }
        if (!$user->can('see_edit_form', $item)) {
            return false;
        }
        $fields = $item->getFormFields();
        
        
        $form->addFields($fields);
        
        if ($form->isSent() && !$form->hasErrors()) {
            $vals = $form->getValues();
            $item->setFieldValues($vals);
            if ($user->can('edit', $item)) {
                $res = $item->save();
                if ($res) {
                    $form->finish();
                }
            }
        }
        $this->assign('form', $form);
        $this->assign('item', $item);
    }
    
    public function doLivesearch()
    {
        $input = $_POST;
        $params = isset($input['params']) ? (array) $input['params'] : array();
        $id_field = isset($params['id_field']) ? $params['id_field'] : 'id';
        if (isset($params['relation_field_id'])) {
            $entity_data = array();
            
            $field = fx::data('field')->getById( (int) $params['relation_field_id']);
            
            if (isset($input['form_data'])) {
                $form_data = array();
                parse_str($input['form_data'], $form_data);
                $entity_data = isset($form_data['content']) ? $form_data['content'] : array();
                $entity_type = isset($form_data['content_type']) ? $form_data['content_type'] : null;
            }
            if (!$entity_type) {
                $entity_type = isset($params['linking_entity_type']) ? $params['linking_entity_type'] : $field['component']['keyword'];
            }
            

            $entity_finder = fx::data($entity_type);
            $entity_id = isset($params['entity_id']) && $params['entity_id'] ? (int) $params['entity_id'] : null;
            
            if ($entity_id) {
                $entity = $entity_finder->getById($entity_id);
            } else {
                $entity = $entity_finder->create();
            }
            $entity->setFieldValues($entity_data);
            $finder = $field->getTargetFinder($entity);
        } else {
            if (!isset($input['content_type'])) {
                return;
            }
            $content_type = $input['content_type'];
            $finder = fx::data($content_type);
            if (($finder instanceof \Floxim\Main\Content\Finder) and $content_type != 'user') {
                $finder->where('site_id', fx::env('site')->get('id'));
            }
        }
        if (isset($input['skip_ids']) && is_array($input['skip_ids'])) {
            $finder->where($id_field, $input['skip_ids'], 'NOT IN');
        }
        if (isset($input['ids'])) {
            $finder->where($id_field, $input['ids']);
        }
        if (isset($input['conditions'])) {
            $finder->livesearchApplyConditions($input['conditions']);
        }
        $term = isset($input['term']) ? $input['term'] : '';
        $limit = isset($input['limit']) ? $input['limit'] : 20;
        $res = $finder->livesearch($term, $limit, $id_field);
        
        // sort items in the original way
        if (isset($input['ids'])) {
            $results = fx::collection($res['results']);
            $res['results'] = array();
            foreach ($input['ids'] as $id) {
                $item = $results->findOne($id_field, $id);
                if ($item) {
                    $res['results'][]= $item;
                }
            }
        }
        fx::complete($res);
    }
    
    public function getParentConfigFields()
    {
        
        
        $vals = array(
            array('current_page', 'Разные на разных страницах'),
            array('certain_page', 'Везде одинаковые')
        );
        return array(
            'parent_type' => array(
                'type' => 'livesearch',
                'label' => 'Данные',
                'value' => 'current_page',
                'parent' => array('/scope[type]' => '!~one_page'),
                'values' => $vals,
                'hidden_on_one_value' => true
            ),
            'parent_id' => array(
                'type' => 'hidden',
                'value' => fx::env('page_id')
            )
        );
    }
}