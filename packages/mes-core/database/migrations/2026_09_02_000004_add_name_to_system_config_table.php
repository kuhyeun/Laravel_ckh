<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::table('system_config', function (Blueprint $table) {
            $table->string('system_config_name', 100)->nullable()->default('')->after('system_config_value')->comment('시스템설정 이름');
        });
    }

    public function down(): void {
        Schema::table('system_config', function (Blueprint $table) {
            $table->dropColumn('system_config_name');
        });
    }
};