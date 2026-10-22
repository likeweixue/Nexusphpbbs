<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Nexus\Cache\FileStore;

class CacheGc extends Command
{
    protected $signature = 'cache:gc {--limit=100000 : Maximum number of cache files to inspect}';

    protected $description = 'Remove expired files from the file cache (no Redis required)';

    public function handle(): int
    {
        $store = FileStore::instance();
        if (!$store->isEnabled()) {
            $this->error('File cache is not enabled or the storage directory is not writable.');

            return self::FAILURE;
        }
        $limit = (int)$this->option('limit');
        $removed = $store->gc($limit > 0 ? $limit : 100000);
        $this->info(sprintf('Cache dir: %s', $store->getDir()));
        $this->info(sprintf('Removed %d expired cache files.', $removed));

        return self::SUCCESS;
    }
}
