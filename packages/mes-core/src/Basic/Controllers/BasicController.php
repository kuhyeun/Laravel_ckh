<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use Inertia\Inertia;

// 페이지 렌더는 제네릭 라우터가 처리. 여기는 데이터/도구 엔드포인트만.
class BasicController extends Controller {

    // 시스템 환경설정 값 ( JSON ) - SystemConfig 페이지가 fetch. (데모용 샘플)
    public function systemConfigData() {
        $configData = [
            'start_url'         => [ 'start_url' => '/dashboard' ],
            'search_date_range' => [ 'search_date_range' => '30', 'search_date_base' => '7' ],
            'project_setting'   => [ 'plan_auto_project_yn' => 'y' ],
            'process_type'      => [ 'production_order_start_date_type' => 'request_due_date' ],
        ];

        return response()->json( [ 'configData' => $configData ] );
    }

    // UI Kit ( 메뉴에 없는 개발 도구 )
    public function uiKit() {
        return Inertia::render( 'Basic/UiKit' );
    }
}
