<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->string('menu_icon', 50)->nullable()->after('menu_name')->comment('메뉴 ICON');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->dropColumn('menu_icon');
        });
    }
};
