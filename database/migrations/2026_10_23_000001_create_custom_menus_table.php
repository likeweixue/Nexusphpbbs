<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_menus')) {
            return;
        }
        Schema::create('custom_menus', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->default(0);
            $table->string('name', 100)->default('');
            $table->string('url', 500)->default('');
            $table->string('target', 10)->default('_self');
            $table->integer('sort')->default(0);
            $table->tinyInteger('enabled')->default(1);
            $table->timestamps();
            $table->index('parent_id');
            $table->index('sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_menus');
    }
};
