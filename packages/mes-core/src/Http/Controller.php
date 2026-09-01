<?php

namespace MesCore\Http;

use Illuminate\Support\Facades\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController {
    use AuthorizesRequests, ValidatesRequests;

    protected function viewOrAbort( $viewPath, $data = [], $mergeData = [], $error_code = 404 ) {
        if( View::exists( $viewPath ) ) {
            return view( $viewPath, $data, $mergeData );
        }

        abort( $error_code );
    }
}
