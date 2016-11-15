<?php
namespace Floxim\Main\Factoid;

use Floxim\Floxim\System\Fx as fx;

class Entity extends \Floxim\Main\Content\Entity
{
    public function _getUrl()
    {
        $type = $this['link_type'];
        switch ($type) {
            case 'alias':
                return $this['linked_page']['url'];
            case 'external':
                $url = $this['external_url'];
                if (!preg_match("~^(https?://|\#)~", $url) && !preg_match("~^/~", $url)) {
                    $url = 'http://'.$url;
                }
                return $url;
            case 'none':
                return '';
        }
    }
    
    public function getFormFieldLinkType($field) {
        $res = $field->getJsField($this);
        //$res['parent'] = array('link_type' => 'alias');
        if (!$res['value']) {
            $res['value'] = 'none';
        }
        return $res;
    }
    
    public function getFormFieldLinkedPageId($field) {
        $res = $field->getJsField($this);
        $res['parent'] = array('link_type' => 'alias');
        return $res;
    }
    
    public function getFormFieldExternalUrl($field) {
        $res = $field->getJsField($this);
        $res['parent'] = array('link_type' => 'external');
        return $res;
    }
}