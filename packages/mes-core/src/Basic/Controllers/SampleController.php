<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use Illuminate\Http\Request;

// 차트/ApiSample 페이지 렌더는 제네릭 라우터가 처리. 여기는 샘플 API 엔드포인트만.
class SampleController extends Controller {

    public function apiGet( Request $request ) {
        $requestParams = $request->all(); // 모든 데이터 조회
        $requestHas    = $request->has( 'type' ); // 해당 키가 있는지 확인
        $requestByKey  = $request->input( 'type' ); // GET,POST 상관없음
        $requestOnly   = $request->only( ['item_name', 'item_code'] ); // 특정키만 조회
        $requestGetParams = $request->query( 'type' ); // GET 파라미터만 조회

        $data = ['requestParams' => $requestParams,
                 'requestHas' => $requestHas,
                 'requestByKey' => $requestByKey,
                 'requestOnly' => $requestOnly,
                 'requestGetParams' => $requestGetParams ];
        
        return response()->json( $data );
    }

    public function apiPost( Request $request ) {
        $requestParams = $request->all(); // 모든 데이터 조회
        $requestHas    = $request->has( 'type' ); // 해당 키가 있는지 확인
        $requestByKey  = $request->input( 'type' ); // GET,POST 상관없음
        $requestOnly   = $request->only( ['item_name', 'item_code'] ); // 특정키만 조회
        $requestGetParams = $request->query( 'type' ); // GET 파라미터만 조회

        $data = ['requestParams' => $requestParams,
                 'requestHas' => $requestHas,
                 'requestByKey' => $requestByKey,
                 'requestOnly' => $requestOnly,
                 'requestGetParams' => $requestGetParams ];
        
        return response()->json( $data );
    }
}