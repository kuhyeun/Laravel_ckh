<?php

namespace MesCore\Menu\Controllers;

use MesCore\Http\Controller;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Models\MenuOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller {

    // 시스템 계정만 쓰는 화면 ( 레벨 옵션은 레벨 0 행만 둔다 )
    private const SYSTEM_ONLY_PAGE = 'Basic/MenuManage';

    // 메뉴 관리 화면용 - 전체 메뉴 + 레벨별 옵션 목록 ( JSON )
    public function list() {
        $menus = Menu::with( [ 'menuOptions' => function( $q ) {
                $q->orderBy( 'menu_level' );
            } ] )
            ->orderBy( 'menu_code' )
            ->get();

        return response()->json( [ 'menus' => $menus ] );
    }

    public function insertUserMenu( Request $request ) {
        try {
            $menu = Menu::createUserMenu( $request );

            $this->bump();
            return response()->json([
                'message' => 'Success',
                'menu' => $menu
            ], 200 );
        } catch( \Illuminate\Validation\ValidationException $e ) {
            // 검증 실패는 Laravel 이 422 로 응답하도록 그대로 전달
            throw $e;
        } catch( \Throwable $e ) {
            Log::error( '사용자 메뉴 생성 실패 : '. $e->getMessage() );

            return response()->json([
                'message' => 'Failed'
            ], 500 );
        }
    }

    public function insertAdminMenu( Request $request ) {
        try {
            $menu = Menu::createAdminMenu( $request );

            $this->bump();
            return response()->json([
                'message' => 'Success',
                'menu' => $menu
            ], 200 );
        } catch( \Illuminate\Validation\ValidationException $e ) {
            // 검증 실패는 Laravel 이 422 로 응답하도록 그대로 전달
            throw $e;
        } catch( \Throwable $e ) {
            Log::error( '관리자 메뉴 생성 실패 : '. $e->getMessage() );

            return response()->json([
                'message' => 'Failed'
            ], 500 );
        }
    }

    // 사용자/관리자 메뉴 수정·삭제는 로직이 동일하므로 공용 메서드로 위임 ( 라우트 구조는 유지 )
    public function updateUserMenu( Request $request )  { return $this->updateMenu( $request ); }
    public function updateAdminMenu( Request $request ) { return $this->updateMenu( $request ); }
    public function deleteUserMenu( Request $request )  { return $this->deleteMenu( $request ); }
    public function deleteAdminMenu( Request $request ) { return $this->deleteMenu( $request ); }

    private function updateMenu( Request $request ) {
        $menu = Menu::find( $request->input( 'menu_idx' ) );
        if( !$menu ) {
            return response()->json([ 'message' => 'NotFound' ], 404 );
        }

        // validate() 는 try 밖에서 → ValidationException 이 422 로 정상 응답되도록
        $validated = $request->validate( Menu::validateMenuData( $menu->menu_idx ) );

        // 메뉴 코드를 비우고 저장하면 기존 코드 유지
        if( empty( $validated['menu_code'] ) ) {
            unset( $validated['menu_code'] );
        }

        try {
            DB::transaction( function () use ( $menu, $validated ) {
                $oldCode   = $menu->menu_code;
                $oldParent = $menu->parent_menu_code;

                $menu->update( array_merge( $validated, [
                    'update_account_idx' => Auth::id(),
                ] ) );

                // 상위 메뉴가 바뀌면 새 형제들 맨 뒤로 ( 모든 레벨 )
                if( $oldParent !== $menu->parent_menu_code ) {
                    $menu->moveToEnd();
                }

                // 하위 메뉴는 코드 문자열로 부모 · 최상위를 가리킴 → 코드가 바뀌면 함께 변경
                if( $oldCode !== $menu->menu_code ) {
                    Menu::where( 'parent_menu_code', $oldCode )->update( [ 'parent_menu_code' => $menu->menu_code ] );
                    Menu::where( 'top_menu_code', $oldCode )->update( [ 'top_menu_code' => $menu->menu_code ] );
                }
            } );

            $this->bump();
            return response()->json([
                'message' => 'Success',
                'menu' => $menu
            ], 200 );
        } catch( \Throwable $e ) {
            Log::error( '메뉴 수정 실패 : '. $e->getMessage() );

            return response()->json([ 'message' => 'Failed' ], 500 );
        }
    }

    private function deleteMenu( Request $request ) {
        try {
            $menu = Menu::find( $request->input( 'menu_idx' ) );
            if( !$menu ) {
                return response()->json([ 'message' => 'NotFound' ], 404 );
            }

            // 하위 메뉴가 있으면 삭제 차단 ( parent_menu_code 는 FK 가 아닌 문자열 참조라 cascade 안 됨 )
            $hasChildren = Menu::where( 'parent_menu_code', $menu->menu_code )->exists();
            if( $hasChildren ) {
                return response()->json([ 'message' => 'HasChildren' ], 409 );
            }

            // menu_option / account_menu_permission 은 FK cascade 로 함께 삭제됨
            $menu->delete();

            $this->bump();
            return response()->json([ 'message' => 'Success' ], 200 );
        } catch( \Throwable $e ) {
            Log::error( '메뉴 삭제 실패 : '. $e->getMessage() );

            return response()->json([ 'message' => 'Failed' ], 500 );
        }
    }

    // 메뉴의 레벨별 권한( menu_option ) 저장: 레벨 행을 upsert
    //   정렬은 여기서 바꾸지 않음 ( 순서 변경 = saveMenuSort ) · 새로 생기는 레벨 행만 형제 맨 뒤
    public function saveMenuOptions( Request $request ) {
        $validated = $request->validate([
            'menu_idx'             => 'required|integer|exists:system_menu,menu_idx',
            'options'              => 'required|array|min:1',
            'options.*.menu_level' => 'required|integer',
            'options.*.can_read'   => 'required|in:Y,N',
            'options.*.can_write'  => 'required|in:Y,N',
            'options.*.can_delete' => 'required|in:Y,N',
        ]);

        $menu = Menu::find( $validated['menu_idx'] );

        // 메뉴관리 메뉴 자신은 시스템( 레벨 0 ) 전용 → 다른 레벨 행은 저장하지 않음
        $systemOnly = $menu->page_component === self::SYSTEM_ONLY_PAGE;

        try {
            DB::transaction(function () use ( $validated, $systemOnly, $menu ) {
                foreach( $validated['options'] as $opt ) {
                    if( $systemOnly && (int) $opt['menu_level'] !== 0 ) {
                        continue;
                    }

                    $row = MenuOption::firstOrNew([
                        'menu_idx'   => $validated['menu_idx'],
                        'menu_level' => $opt['menu_level'],
                    ]);

                    if( !$row->exists ) {
                        $row->create_account_idx = Auth::id();
                        $row->menu_sort = Menu::nextSort( $menu->parent_menu_code, (int) $opt['menu_level'], $menu->menu_idx );
                    }

                    // 레벨 0( 시스템 )은 항상 전부 허용
                    $isSystem = (int) $opt['menu_level'] === 0;

                    $row->fill([
                        'can_read'           => $isSystem ? 'Y' : $opt['can_read'],
                        'can_write'          => $isSystem ? 'Y' : $opt['can_write'],
                        'can_delete'         => $isSystem ? 'Y' : $opt['can_delete'],
                        'update_account_idx' => Auth::id(),
                    ]);
                    $row->save();
                }
            });

            // 참고: 메뉴 권한 캐시는 테이블 변경 감지( 버전 키 )로 자동 무효화 → 다음 요청부터 즉시 반영됨.
            $this->bump();
            return response()->json([ 'message' => 'Success' ], 200 );
        } catch( \Throwable $e ) {
            Log::error( '메뉴 권한 저장 실패 : '. $e->getMessage() );

            return response()->json([ 'message' => 'Failed' ], 500 );
        }
    }

    // 순서 저장 : 형제 묶음( 같은 부모 · 같은 메뉴 구분 )마다 보낸 순서대로 1, 2, 3 …
    //   level 없음 = 모든 레벨 같은 순서 / level 지정 = 그 레벨만 ( 레벨별로 순서를 따로 둘 때 )
    public function saveMenuSort( Request $request ) {
        $validated = $request->validate([
            'level'                => 'nullable|integer|in:1,10,99',
            'groups'               => 'required|array|min:1',
            'groups.*.menu_idxs'   => 'required|array|min:1',
            'groups.*.menu_idxs.*' => 'required|integer|distinct',
        ]);

        $level = $validated['level'] ?? null;

        // 한 묶음 = 같은 부모 · 같은 메뉴 구분( 사용자 / 관리자 ) 의 메뉴만
        foreach( $validated['groups'] as $group ) {
            $rows = Menu::whereIn( 'menu_idx', $group['menu_idxs'] )->get( [ 'menu_idx', 'parent_menu_code', 'is_admin' ] );
            if( $rows->count() !== count( $group['menu_idxs'] )
                || $rows->pluck( 'parent_menu_code' )->unique()->count() > 1
                || $rows->pluck( 'is_admin' )->unique()->count() > 1 ) {
                return response()->json([ 'message' => 'InvalidGroup' ], 422 );
            }
        }

        try {
            DB::transaction( function () use ( $validated, $level ) {
                foreach( $validated['groups'] as $group ) {
                    foreach( array_values( $group['menu_idxs'] ) as $i => $menuIdx ) {
                        MenuOption::where( 'menu_idx', $menuIdx )
                            ->when( $level !== null, fn ( $q ) => $q->where( 'menu_level', $level ) )
                            ->update( [ 'menu_sort' => $i + 1, 'update_account_idx' => Auth::id() ] );
                    }
                }
            } );

            $this->bump();
            return response()->json([ 'message' => 'Success' ], 200 );
        } catch( \Throwable $e ) {
            Log::error( '메뉴 순서 저장 실패 : '. $e->getMessage() );

            return response()->json([ 'message' => 'Failed' ], 500 );
        }
    }

    // 메뉴 · 레벨 옵션을 바꾸면 권한 캐시 서명을 지운다 ( MenuPermissionService — 다음 요청부터 새 사이드바 · 권한 )
    private function bump(): void {
        app(\MesCore\Menu\Services\MenuPermissionService::class)->bumpVersion();
    }
}
