<?php

namespace MesCore\Http\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * 검색바 검색어 — 화면정의 CMP-02 · 스타일 가이드 §3
 *
 *   $fields : [ 조건 키 => fn ($q, string $like) ] — 그 조건 하나로 찾는 방법 ( $like = '%검색어%' · 이스케이프됨 )
 *   search_field 없음( 통합검색 ) → 모든 조건을 OR / 있으면 그 조건만 ( 목록에 없는 키는 422 )
 *   키는 화면 SearchBar :fields 의 value 와 같다
 *   Eloquent · Query Builder 둘 다 ( where · orWhere 에 함수를 넘긴다 )
 */
trait SearchesKeyword {

    protected function applyKeyword($query, ?string $keyword, array $fields): void {
        $field = (string) request()->input('search_field', '');
        if ($field !== '' && !isset($fields[$field])) {
            throw ValidationException::withMessages(['search_field' => ['검색 조건이 올바르지 않습니다.']]);
        }
        if ($keyword === null || trim($keyword) === '') {
            return;
        }
        $like = '%' . addcslashes(trim($keyword), '%_\\') . '%';

        if ($field !== '') {
            $query->where(fn ($q) => $fields[$field]($q, $like));
            return;
        }
        $query->where(function ($w) use ($fields, $like) {
            foreach ($fields as $fn) {
                $w->orWhere(fn ($q) => $fn($q, $like));
            }
        });
    }
}
