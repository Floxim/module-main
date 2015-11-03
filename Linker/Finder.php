<?php
namespace Floxim\Main\Linker;

use Floxim\Floxim\System\Fx as fx;

class Finder extends \Floxim\Main\Content\Finder
{
    public function invertCollection($collection)
    {
        $finder = $this;
        $content_finder = $finder->with['content'][1];
        $items = $collection->column('content');
        
        $res = $items->fork();
        $res->finder = $content_finder;
        $res->is_sortable = true;
        $res->linkers = $collection->fork();
        $res->linkers->linkedBy = 'linked_id';
        foreach ($items as $n => $item) {
            $res[]= $item;
            $res->linkers[]= $collection[$n];
        }
        return $res;
    }
}