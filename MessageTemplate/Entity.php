<?php

namespace Floxim\Main\MessageTemplate;

use Floxim\Floxim\System\Fx;

class Entity extends \Floxim\Main\Content\Entity
{
    public function process($data) {
        $com_content_id = fx::component('floxim.main.content')->get('id');
        $res = array();
        foreach ($this->getFields() as $f) {
            $type = $f['type'];
            
            $field_keyword = $f['keyword'];
            
            if (!in_array($type, array('string', 'text'))) {
                continue;
            }
            if ($f['component_id'] === $com_content_id) {
                continue;
            }
            if ($field_keyword === 'keyword') {
                continue;
            }
            $prop_tpl = $this[$field_keyword];
            $tpl_obj = fx::template()->virtual($prop_tpl);
            $tpl_obj->isAdmin(false);
            $res[$field_keyword] = $tpl_obj->render($data);
        }
        return $res;
    }
}