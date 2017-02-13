<?php
namespace Floxim\Main\Text;

class Entity extends \Floxim\Main\Content\Entity
{
    public function getDefaultBoxFields()
    {
        return array(
            array(
                array('keyword' => 'text')
            )
        );
    }
}