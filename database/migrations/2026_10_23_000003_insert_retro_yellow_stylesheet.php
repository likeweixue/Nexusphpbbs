<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $uri = 'styles/RetroYellow/';
        $exists = DB::table('stylesheets')->where('uri', $uri)->exists();
        if (!$exists) {
            DB::table('stylesheets')->insert([
                'uri' => $uri,
                'name' => '复古黄色',
                'addicode' => '',
                'designer' => '自定义',
                'comment' => '复古黄色主题',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('stylesheets')->where('uri', 'styles/RetroYellow/')->delete();
    }
};
