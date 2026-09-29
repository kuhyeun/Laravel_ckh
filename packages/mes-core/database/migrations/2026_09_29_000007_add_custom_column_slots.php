<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 커스텀 컬럼 예비 슬롯 — docs/커스텀-컬럼-설계.md §4 · 개발범위-및-MVP "Phase 1 에 미리 넣을 것"
//   운영 중 DDL 금지 → 슬롯 컬럼과 인덱스를 지금 만들어 두고, 관리 기능( 정의 · 할당 · 화면 )은 Phase 2
//   item     : 텍스트 5 · 숫자 3 · 날짜 2 + 슬롯마다 인덱스 ( 검색 · 정렬 대상 )
//   bom_line : 기능형 숫자 3 ( 계산식 변수 — CUT 등, 자재소요-계산-설계 §7.3 ). 검색 대상이 아니라 인덱스 없음
//              ext_n01 은 이미 절단 길이( CUT ) 임시 칸으로 쓰는 중 → 자리 그대로, n02 · n03 만 추가
//   발주 · 수주 · 출하( 각 텍스트 3 · 숫자 2 · 날짜 2 )는 그 테이블을 만들 때 함께 만든다
return new class extends Migration {

    private const ITEM_SLOTS = [
        'ext_c01', 'ext_c02', 'ext_c03', 'ext_c04', 'ext_c05',
        'ext_n01', 'ext_n02', 'ext_n03',
        'ext_d01', 'ext_d02',
    ];

    public function up(): void {
        Schema::table('item', function (Blueprint $table) {
            $after = 'remark';
            foreach (self::ITEM_SLOTS as $col) {
                $def = match ($col[4]) {
                    'c' => $table->string($col, 200),
                    'n' => $table->decimal($col, 18, 6),
                    'd' => $table->date($col),
                };
                $def->nullable()->after($after)->comment('커스텀 컬럼 예비 슬롯');
                $after = $col;
            }
        });

        Schema::table('item', function (Blueprint $table) {
            foreach (self::ITEM_SLOTS as $col) {
                $table->index($col, "ix_item_{$col}");
            }
        });

        Schema::table('bom_line', function (Blueprint $table) {
            $table->decimal('ext_n02', 18, 6)->nullable()->after('ext_n01')->comment('기능형 슬롯');
            $table->decimal('ext_n03', 18, 6)->nullable()->after('ext_n02')->comment('기능형 슬롯');
        });
    }

    public function down(): void {
        Schema::table('bom_line', function (Blueprint $table) {
            $table->dropColumn(['ext_n02', 'ext_n03']);
        });
        Schema::table('item', function (Blueprint $table) {
            foreach (self::ITEM_SLOTS as $col) {
                $table->dropIndex("ix_item_{$col}");
            }
            $table->dropColumn(self::ITEM_SLOTS);
        });
    }
};
