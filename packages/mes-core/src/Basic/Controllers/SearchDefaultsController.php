<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Services\PolicyService;

// 공통 검색바( CMP-02 ) 기본값 — 기간 기본값은 화면이 정하지 않고 정책에서 온다
//   오늘은 서버 기준 ( 업체 로컬 시각, 프로그램-스펙 §11 )
class SearchDefaultsController extends Controller {

    public function defaults(PolicyService $policy) {
        $fromDays = $policy->get('ui.search_range_from_days') ?? -10;
        $toDays   = $policy->get('ui.search_range_to_days') ?? 10;

        return response()->json([
            'today' => now()->toDateString(),
            'from'  => now()->addDays((int) $fromDays)->toDateString(),
            'to'    => now()->addDays((int) $toDays)->toDateString(),
        ]);
    }
}
