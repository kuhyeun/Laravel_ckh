<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Services\ExcelLayouts;
use MesCore\Basic\Services\Import\ExcelImportService;
use Illuminate\Http\Request;

// 엑셀 양식 ( 개발자가 만들어 배포 — 사용자 · 관리자는 바꾸지 않는다 )
//   파일 위치 · 형식 : resources/excel/README.md  ( 업체 → 공통 순서, 같은 이름이면 업체가 이긴다 )
//   내보내기( list · template )와 업로드( import ) 양식을 같은 규칙으로 찾는다
//   데이터는 각 목록 · 업로드 API 의 권한을 따른다. 여기는 로그인만 확인 ( 양식 자체는 민감하지 않음 )
class ExcelLayoutController extends Controller {

    public function __construct(private ExcelLayouts $layouts) {}

    // 한 화면( base )의 양식 목록 : 기본 양식이 먼저, 추가 양식은 이름순
    //   업로드 양식은 서버 규칙상 필수인 칸을 required 로 표시해 준다 ( JSON 에 빠져 있어도 양식에 * 가 붙도록 )
    public function layouts(Request $request, ExcelImportService $imports) {
        $validated = $request->validate(['base' => ['required', 'string', 'max:100', 'regex:' . ExcelLayouts::NAME_PATTERN]]);

        $layouts = array_map(
            fn ($l) => ($l['type'] ?? null) === 'import' ? $imports->withServerRequired($l) : $l,
            $this->layouts->list($validated['base'])
        );

        return response()->json(['layouts' => $layouts]);
    }

    // 양식 파일형의 .xlsx ( 업체 → 공통 )
    public function template(Request $request) {
        $validated = $request->validate(['path' => ['required', 'string', 'max:200']]);

        $path = $this->layouts->templatePath($validated['path']);
        if ($path === null) {
            // 폴더 밖 · 상대 경로 · .xlsx 아님 → 422, 없는 파일 → 404
            $rel = str_replace('\\', '/', $validated['path']);
            $bad = str_contains($rel, '..') || str_starts_with($rel, '/') || !preg_match('/\.xlsx$/i', $rel);
            return response()->json(['message' => $bad ? '잘못된 양식 경로입니다.' : '양식 파일이 없습니다.'], $bad ? 422 : 404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
