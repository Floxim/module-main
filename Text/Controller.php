<?php
namespace Floxim\Main\Text;

class Controller extends \Floxim\Main\Content\Controller
{
    public function doList()
    {
        $com = $this->getComponent();
        $this->onQueryReady(function($e) use ($com) {
            $e['query']->where('type', $com['keyword']);
        });
        return parent::doList();
    }
}