<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Removed PT-only moderator panel entries.
        DB::table('modpanel')->whereIn('url', [
            'cheaters.php',
            'allagents.php',
            'uploaders.php',
        ])->delete();
    }

    public function down(): void
    {
        // Intentionally no restore: these features have been removed.
    }
};
