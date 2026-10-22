<?php

use Nexus\Cache\FileStore;

/**
 * File based replacement for the old class_cache_redis.
 *
 * It keeps the exact same public API as class_cache_redis so every legacy
 * page keeps working, but the data is stored on disk instead of Redis.
 */
class class_cache_file
{
    public $isEnabled;
    public $clearCache = 0;
    public $language = 'en';
    public $Page = array();
    public $Row = 1;
    public $Part = 0;
    public $MemKey = "";
    public $Duration = 0;
    public $cacheReadTimes = 0;
    public $cacheWriteTimes = 0;
    public $keyHits = array();
    public $languageFolderArray = array();

    /** @var FileStore */
    public $store;

    public function __construct()
    {
        $this->store = FileStore::instance();
        $this->isEnabled = $this->store->isEnabled();
    }

    public function getIsEnabled()
    {
        return $this->isEnabled;
    }

    public function setClearCache($isEnabled)
    {
        $this->clearCache = $isEnabled;
    }

    public function getLanguageFolderArray()
    {
        return $this->languageFolderArray;
    }

    public function setLanguageFolderArray($languageFolderArray)
    {
        $this->languageFolderArray = $languageFolderArray;
    }

    public function getClearCache()
    {
        return $this->clearCache;
    }

    public function setLanguage($language)
    {
        $this->language = $language;
    }

    public function getLanguage()
    {
        return $this->language;
    }

    public function new_page($MemKey = '', $Duration = 3600, $Lang = true)
    {
        if ($Lang) {
            $language = $this->getLanguage();
            $this->MemKey = $language . "_" . $MemKey;
        } else {
            $this->MemKey = $MemKey;
        }
        $this->Duration = $Duration;
        $this->Row = 1;
        $this->Part = 0;
        $this->Page = array();
    }

    public function set_key()
    {
    }

    //---------- Adding functions ----------//

    public function add_row()
    {
        $this->Part = 0;
        $this->Page[$this->Row] = array();
    }

    public function end_row()
    {
        $this->Row++;
    }

    public function add_part()
    {
        ob_start();
    }

    public function end_part()
    {
        $this->Page[$this->Row][$this->Part] = ob_get_clean();
        $this->Part++;
    }

    // Shorthand for:
    // add_row();
    // add_part();
    public function add_whole_row()
    {
        $this->Part = 0;
        $this->Page[$this->Row] = array();
        ob_start();
    }

    // Shorthand for:
    // end_part();
    // end_row();
    public function end_whole_row()
    {
        $this->Page[$this->Row][$this->Part] = ob_get_clean();
        $this->Row++;
    }

    public function set_row_value($Key, $Value)
    {
        $this->Page[$this->Row][$Key] = $Value;
    }

    public function set_constant_value($Key, $Value)
    {
        $this->Page[$Key] = $Value;
    }

    public function break_loop()
    {
        if (count($this->Page) > 0) {
            $this->Page[$this->Row] = false;
            $this->Row++;
        }
    }

    //---------- Locking functions ----------//

    public function lock($Key)
    {
        $this->cache_value('lock_' . $Key, 'true', 3600);
    }

    public function unlock($Key)
    {
        $this->store->del('lock_' . $Key);
    }

    //---------- Caching functions ----------//

    public function cache_page()
    {
        $this->cache_value($this->MemKey, $this->Page, $this->Duration);
        $this->Row = 0;
        $this->Part = 0;
    }

    public function setup_page()
    {
        $this->Row = 0;
        $this->Part = 0;
    }

    public function cache_value($Key, $Value, $Duration = 3600)
    {
        if (!$this->getIsEnabled()) {
            return;
        }
        $this->store->setex($Key, (int)$Duration, $Value);
        $this->cacheWriteTimes++;
        $this->keyHits['write'][$Key] = !isset($this->keyHits['write'][$Key]) ? 1 : $this->keyHits['write'][$Key] + 1;
    }

    //---------- Getting functions ----------//

    public function next_row()
    {
        $this->Row++;
        $this->Part = 0;
        if (!isset($this->Page[$this->Row]) || $this->Page[$this->Row] == false) {
            return false;
        } elseif (count($this->Page[$this->Row]) == 1) {
            return $this->Page[$this->Row][0];
        } else {
            return $this->Page[$this->Row];
        }
    }

    public function next_part()
    {
        $Return = $this->Page[$this->Row][$this->Part];
        $this->Part++;

        return $Return;
    }

    public function get_row_value($Key)
    {
        return $this->Page[$this->Row][$Key];
    }

    public function get_constant_value($Key)
    {
        return $this->Page[$Key];
    }

    public function get_page()
    {
        $Result = $this->get_value($this->MemKey);
        if ($Result) {
            $this->Row = 0;
            $this->Part = 0;
            $this->Page = $Result;

            return true;
        }

        return false;
    }

    public function get_value($Key)
    {
        if (!$this->getIsEnabled()) {
            return false;
        }
        if ($this->getClearCache()) {
            $this->delete_value($Key);

            return false;
        }
        $Return = $this->store->get($Key);
        $this->cacheReadTimes++;
        $this->keyHits['read'][$Key] = !isset($this->keyHits['read'][$Key]) ? 1 : $this->keyHits['read'][$Key] + 1;

        return $Return;
    }

    public function delete_value($Key, $AllLang = false)
    {
        if (!$this->getIsEnabled()) {
            return 0;
        }
        $this->store->del($Key);
        if ($AllLang) {
            $langfolder_array = $this->getLanguageFolderArray();
            foreach ($langfolder_array as $lf) {
                $this->store->del($lf . "_" . $Key);
            }
        }

        return 0;
    }

    public function getCacheReadTimes()
    {
        return $this->cacheReadTimes;
    }

    public function getCacheWriteTimes()
    {
        return $this->cacheWriteTimes;
    }

    public function getKeyHits($type = 'read')
    {
        return $this->keyHits[$type] ?? [];
    }

    /**
     * The file store plays the role of the old Redis client.
     *
     * @return FileStore
     */
    public function getRedis()
    {
        return $this->store;
    }

    public function getStore(): FileStore
    {
        return $this->store;
    }
}
