<?php

namespace MesCore\Page\Controllers;

use MesCore\Http\Controller;
use MesCore\Menu\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

// 제네릭 페이지 렌더러: 매칭되는 라우트가 없을 때 url_path 로 메뉴를 찾아 page_component 를 렌더한다.
class PageController extends Controller {

    public function show( Request $request ) {
        // 로그인 필요
        if( !Auth::check() ) {
            return redirect()->route('user.login');
        }

        $path = '/' . ltrim( $request->path(), '/' );

        $menu = Menu::where( 'url_path', $path )
            ->where( 'is_use', 'Y' )
            ->first();

        // 메뉴가 없거나 렌더할 컴포넌트가 지정 안 됨 → 404
        if( !$menu || !$menu->page_component ) {
            abort( 404 );
        }

        // 메뉴 권한( menu.read ) — 사이드바에 안 보여도 URL 직접 접근 차단
        if( Gate::denies( 'menu.read', $menu->menu_code ) ) {
            abort( 403 );
        }

        // 컴포넌트 실제 존재 확인 ( 오타/미구현 → 404 → Error 페이지 )
        if( !$this->componentExists( $menu->page_component ) ) {
            abort( 404 );
        }

        return Inertia::render( $menu->page_component, [
            'menuCode' => $menu->menu_code,
        ] );
    }

    // src / mes-core 의 Pages 디렉토리에 해당 컴포넌트가 존재하는지 확인
    private function componentExists( string $component ): bool {
        $candidates = [
            resource_path( 'js/Pages/' . $component . '.vue' ),
            base_path( '../packages/mes-core/resources/js/Pages/' . $component . '.vue' ),
        ];

        foreach( $candidates as $file ) {
            if( is_file( $file ) ) {
                return true;
            }
        }

        return false;
    }
}
