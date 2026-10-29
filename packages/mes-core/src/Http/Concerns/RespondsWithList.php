<?php

namespace MesCore\Http\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 목록 API 공통 응답 — 화면정의 CMP-01 MesGrid 의 GridResult { rows, total, page, perPage }
 *
 *   page 가 오면     → 서버 페이징   { rows, total, page, perPage }
 *   export=1 이 오면 → 엑셀용 전체   { rows, total } ( exportLimit() 초과면 422 — 조건을 좁히게 )
 *   둘 다 없으면     → 기존 방식     { $legacyKey: [...] }  ( 페이징 없는 화면은 그대로 동작 )
 *
 *   정렬 : sort( 허용한 컬럼만 ) + dir( asc | desc ). 허용 안 된 컬럼은 무시하고 기본 정렬
 *   $map : 행을 화면용으로 바꾸는 함수 ( 없으면 모델 그대로 )
 */
trait RespondsWithList {

    // 엑셀로 내보낼 수 있는 최대 행 수 ( 화면별로 바꾸려면 컨트롤러에서 이 메서드를 재정의 )
    protected function exportLimit(): int {
        return 10000;
    }

    // 열 정렬 때 같은 값끼리의 순서 ( 예 : 'r.run_idx' ) — 없으면 페이지마다 순서가 흔들릴 수 있다. 화면별로 재정의
    protected function listTieBreaker(): ?string {
        return null;
    }

    protected function listResponse(Request $request, Builder $query, array $sortable, string $legacyKey, ?callable $map = null): JsonResponse {
        $validated = $request->validate([
            'page'    => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|in:20,50,100,200',
            'sort'    => 'nullable|string|max:60',
            'dir'     => 'nullable|in:asc,desc',
            'export'  => 'nullable|in:0,1',
        ]);

        // 정렬 : 허용 목록에 있을 때만 ( 컬럼명을 그대로 SQL 에 넣지 않는다 )
        if (!empty($validated['sort']) && in_array($validated['sort'], $sortable, true)) {
            $query->reorder($validated['sort'], $validated['dir'] ?? 'asc');
            if ($tie = $this->listTieBreaker()) {
                $query->orderBy($tie, $validated['dir'] ?? 'asc');
            }
        }

        $mapRows = fn ($rows) => $map ? $rows->map($map)->values() : $rows->values();

        if ((string) ($validated['export'] ?? '0') === '1') {
            $total = (clone $query)->toBase()->getCountForPagination();
            $limit = $this->exportLimit();
            if ($total > $limit) {
                throw ValidationException::withMessages(['export' => [
                    "엑셀로 내보낼 행이 {$total}건으로 상한( {$limit}건 )을 넘습니다. 검색 조건을 좁혀 주세요.",
                ]]);
            }
            return response()->json(['rows' => $mapRows($query->get()), 'total' => $total]);
        }

        if (!empty($validated['page'])) {
            $perPage   = (int) ($validated['perPage'] ?? 50);
            $paginator = $query->paginate($perPage, ['*'], 'page', (int) $validated['page']);
            return response()->json([
                'rows'    => $mapRows($paginator->getCollection()),
                'total'   => $paginator->total(),
                'page'    => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ]);
        }

        return response()->json([$legacyKey => $mapRows($query->get())]);
    }
}
