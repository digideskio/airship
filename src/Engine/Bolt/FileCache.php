<?php
declare(strict_types=1);
namespace Airship\Engine\Bolt;

use \Airship\Engine\Cache\File;

trait FileCache
{
    public $airship_filecache_object;
    public $airship_cspcache_object;

    function tightenCacheBolt()
    {
        static $tightened = false;
        if ($tightened) {
            return;
        }
        $this->airship_filecache_object = new File(ROOT.'/tmp/cache/static');
        $this->airship_cspcache_object = new File(ROOT.'/tmp/cache/csp_static');
    }
}