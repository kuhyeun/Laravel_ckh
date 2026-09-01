<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    // 제네릭 페이지 라우팅용: url_path( 접속 경로 ) + page_component( 렌더할 Vue 컴포넌트 )
    public function up(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->string('url_path', 150)->nullable()->unique()->after('menu_route_name')->comment('접속 경로 ( 제네릭 라우팅 )');
            $table->string('page_component', 150)->nullable()->after('url_path')->comment('렌더할 Vue 컴포넌트 경로');
        });
    }

    public function down(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->dropUnique(['url_path']);
            $table->dropColumn(['url_path', 'page_component']);
        });
    }
};
