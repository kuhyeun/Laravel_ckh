<?php

use Illuminate\Database\Migrations\Migration;
use MesCore\Config\Models\Item;

// 품목 1개 생산 표준시간( item.cycle_time_sec ) 초기 채움
//   지금까지는 채우는 로직이 없어 0 이었다. 이후로는 품목 · 라우팅 저장 때 Item::refreshCycleTime() 이 맞춘다
return new class extends Migration {

    public function up(): void {
        Item::refreshCycleTime();
    }

    public function down(): void {
        // 되돌릴 것 없음 ( 값만 채움 )
    }
};
