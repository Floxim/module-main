<?php

namespace Floxim\Main;

use \Floxim\Floxim\System\Fx as fx;

class Module extends \Floxim\Floxim\Component\Module\Entity {
    public function init()
    {
        $this->handleLinkers();
    }
    
    protected function handleLinkers()
    {
        fx::listen('after_delete', function($e) {
            $entity = $e['entity'];
            if (!$entity->isInstanceOf('floxim.main.content') || $entity->isInstanceOf('floxim.main.linker')) {
                return;
            }
            $linkers = fx::data('floxim.main.linker')->where('linked_id', $entity['id'])->all();
            $linkers->apply(function($l) {
                $l->delete();
            });
        });
    }
}