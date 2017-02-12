<?php
namespace Floxim\Main\Page;

use Floxim\Floxim\System\Fx as fx;

class Controller extends \Floxim\Main\Content\Controller
{
    public function doNeighbours()
    {
        $item = fx::env('page');

        $q = $this->getFinder()->order(null)->limit(1)->where('site_id', fx::env('site_id'));

        $q_next = clone $q;
        $q_prev = clone $q;

        if ($this->getParam('sorting') === 'auto' && $item['infoblock_id']) {
            $item_ib_params = fx::data('infoblock', $item['infoblock_id'])->get('params');
            $ib_sorting = $item_ib_params['sorting'];
            $this->setParam('sorting', ($ib_sorting == 'manual' || $ib_sorting == 'auto') ? 'priority' : $ib_sorting);
            $this->setParam('sorting_dir', $item_ib_params['sorting_dir']);
        }

        $sort_field = $this->getParam('sorting', 'priority');
        
        if ($sort_field === 'auto') {
            $sort_field = 'priority';
        }
        
        $dir = strtolower($this->getParam('sorting_dir', 'asc'));

        $where_prev = array(array($sort_field, $item[$sort_field], $dir == 'asc' ? '<' : '>'));
        $where_next = array(array($sort_field, $item[$sort_field], $dir == 'asc' ? '>' : '<'));

        $group_by_parent = $this->getParam('group_by_parent');

        if ($group_by_parent) {
            $c_parent = fx::content($item['parent_id']); // todo: psr0 need verify
            $q_prev->order('parent.priority', 'desc')->where('parent.priority', $c_parent['priority'], '<=');
            $q_next->order('parent.priority', 'asc')->where('parent.priority', $c_parent['priority'], '>=');
            $where_prev [] = array('parent_id', $item['parent_id'], '!=');
            $where_next [] = array('parent_id', $item['parent_id'], '!=');
        }


        $q_prev->order($sort_field, $dir == 'asc' ? 'desc' : 'asc')
            ->where($where_prev, null, 'or');

        $prev = $q_prev->all();

        $q_next->order($sort_field, $dir)
            ->where($where_next, null, 'or');

        $next = $q_next->all();
        
        //fx::log($q_prev->showQuery(), $q_next->showQuery());
        return array(
            'prev'    => $prev,
            'current' => $item,
            'next'    => $next
        );
    }
    
    public function createRecordInfoblock($list_ib)
    {
        
        $tvs = fx::data('template_variant')
                ->where('theme_id', fx::env('theme_id'))
                ->where('template', 'floxim.ui.record:record')
                ->all();
        
        $record_tv = null;

        foreach ($tvs as $tv) {
            if ($this->checkTemplateAvailForType($tv)) {
                $record_tv = $tv;
                break;
            }
        }
        if (!$record_tv) {
            return;
        }
        
        $rec_ib = fx::data('infoblock')->create();
        //$content_type = $this->getContentType();
        $rec_ib->set(
            array(
                'site_id' => $list_ib['site_id'],
                'controller' => $this->getControllerName(),
                'action' => 'record',
                'name' => 'Поля',
                'scope_type' => 'infoblock_pages',
                'scope_infoblock_id' => $list_ib['id']
            )
        );
        $rec_ib->save();
        $list_vis = $list_ib->getVisual();
        $rec_vis = fx::data('infoblock_visual')->create(
            [
                'infoblock_id' => $rec_ib['id'],
                'area' => $list_vis['area'],
                'priority' => $list_vis['priority'] + 0.5,
                'template_variant_id' => $record_tv['id'],
                'theme_id' => fx::env('theme_id')
            ]
        );
        $rec_vis->save();
        fx::log('creatd', $rec_ib, $rec_vis);
    }
    
    public function deleteRecordInfoblock($list_ib)
    {
        $rec_ib = fx::data('infoblock')
                    ->where('scope_type', 'infoblock_pages')
                    ->where('scope_infoblock_id', $list_ib['id'])
                    ->where('controller', $this->getControllerName())
                    ->where('action', 'record')
                    ->one();
        if ($rec_ib) {
            $rec_ib->delete();
        }
    }
}