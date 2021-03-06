<?php
namespace Floxim\Main\Page;

use Floxim\Floxim\Component;
use Floxim\Floxim\System\Fx as fx;

class Finder extends \Floxim\Main\Content\Finder
{
    
    public static $isStaticCacheUsed = true;

    public function getById($id)
    {
        if (!is_numeric($id)) {
            return $this->getByUrl($id);
        }
        return parent::getById($id);
    }

    protected static $url_cache = null;
    
    /**
     * get page (urlAlias) by url string
     *
     * @param string url string
     *
     * @return object page
     */
    public function getByUrl($url, $site_id = null)
    {
        
        if ($site_id === null) {
            $site_id = fx::env('site_id');
        }
        
        $cache_key = $url.'@'.$site_id;
        if (!isset(self::$url_cache[$cache_key])) {
            $url_variants = array($url);


            $url_with_no_params = preg_replace("~\?.+$~", '', $url);

            $url_variants [] =
                preg_match("~/$~", $url_with_no_params) ?
                    preg_replace("~/$~", '', $url_with_no_params) :
                    $url_with_no_params . '/';

            if ($url_with_no_params != $url) {
                $url_variants [] = $url_with_no_params;
            }
            // get alias by url
            $alias = fx::data('url_alias')->
                where('url', $url_variants)->
                where('site_id', $site_id)->
                one();

            if (!$alias) {
                $page = null;
            } else {
                // get page by id
                $page = $this->getById($alias['page_id']);
            }
            self::$url_cache[$cache_key] = $page;
        }
        return self::$url_cache[$cache_key];
    }
}