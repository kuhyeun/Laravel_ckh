<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    
    public function up(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->change();
        });
    }

    public function down(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->timestamp('update_datetime')->nullable(false)->change();
        });
    }
};
