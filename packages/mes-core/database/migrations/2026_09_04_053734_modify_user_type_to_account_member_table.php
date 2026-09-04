<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        DB::table('account_member')->whereNotIn('user_type', ['admin', 'user'])->update(['user_type' => 'user']);

        Schema::table('account_member', function (Blueprint $table) {
            $table->enum('user_type', ['admin', 'user'])
                ->default('user')
                ->comment('사용자 종류')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('account_member', function (Blueprint $table) {
            // 원래대로 VARCHAR(20) DEFAULT '' 로 복구
            $table->string('user_type', 20)->default('')->comment('사용자 종류')->change();
        });
    }
};
