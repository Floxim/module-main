<?php

use Floxim\Floxim\System\Fx as fx;
/*
$record_templates = fx::controller($component['keyword'].':record')
                        ->getAvailableTemplates();
*/

$page_config = array(
    'actions' => array(
        '*neighbours' => array(
            'check_context' => function($page) use ($component) {
                $res = $page['type'] === $component['keyword'];
                return $res;
            },
            'name' => fx::alang('Neighbour %s', 'controller_component', $component->getItemName('list')),
            'settings' => array(
                'sorting' => array(
                    'name' => 'sorting',
                    'label' => fx::alang('Sorting','controller_component'),
                    'type' => 'select',
                    'values' => array('auto' => fx::alang('Auto', 'controller_component')) + $sort_fields
                ),
                'sorting_dir' => array(
                    'name' => 'sorting_dir',
                    'label' => fx::alang('Order','controller_component'),
                    'type' => 'select',
                    'values' => array(
                        'asc' => fx::alang('Ascending','controller_component'), 
                        'desc' => fx::alang('Descending','controller_component')
                    ),
                    'parent' => array('sorting' => '!=auto')
                ),
                'group_by_parent' => array(
                    'name' => 'group_by_parent',
                    'label' => fx::alang('Group by parent', 'controller_component'),
                    'type' => 'checkbox'
                )
            )
        ),
        'list_infoblock' => array(
            'disabled' => true
        ),
        '*list_infoblock' => array(
            'install' => function($list_ib, $ctr) {
                $ctr->createRecordInfoblock($list_ib);
                return;
                
                if (!$list_ib['params']['create_record_ib']) {
                    return;
                }
                $rec_ib = fx::data('infoblock')->create();
                $content_type = $ctr->getContentType();
                $rec_ib->set(
                    array(
                        'site_id' => $list_ib['site_id'],
                        'controller' => $ctr->getControllerName(),
                        'action' => 'record',
                        'name' => $content_type.' record',
                        'scope_type' => 'infoblock_pages',
                        'scope_infoblock_id' => $list_ib['id']
                    )
                );
                $rec_ib->save();
            },
            'delete' => function($list_ib, $ctr) {
                $ctr->deleteRecordInfoblock($list_ib);
            }
        )
    )
);
return $page_config;