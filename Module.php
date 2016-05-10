<?php

namespace Floxim\Main;

use \Floxim\Floxim\System\Fx as fx;

class Module extends \Floxim\Floxim\Component\Module\Entity {
    public function init()
    {
        $this->handleLinkers();
        $this->handleThumbs();
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
    
    protected function handleThumbs()
    {
        fx::listen('unlink', function($e) {
            $f = $e['file'];
            if (fx::files()->isMetaFile($f)) {
                return;
            }
            if (fx::path()->isInside($f, fx::path('@thumbs')) ) {
                return;
            }
            if (!fx::path()->isInside($f, fx::path('@content_files')) ) {
                return;
            }
            $thumbs = \Floxim\Floxim\System\Thumb::findThumbs($f);
            foreach ($thumbs as $thumb) {
                fx::files()->rm($thumb);
            }
        });
    }
}