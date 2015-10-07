<?php

namespace Floxim\Main\MessageTemplate;

class Finder extends \Floxim\Main\Content\Finder
{
    public function getById($id) {
        if (!is_numeric($id) && strlen($id) > 0) {
            $this->where('keyword', $id);
            return $this->one();
        }
        return parent::getById($id);
    }
}