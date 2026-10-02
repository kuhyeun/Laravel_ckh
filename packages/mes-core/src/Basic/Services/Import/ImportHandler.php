<?php

namespace MesCore\Basic\Services\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * 엑셀 일괄 등록 — 화면( 엔티티 )별 처리
 *   공통 처리( 양식 · 변환 · 필수 · 중복 · 모드 · 트랜잭션 )는 ExcelImportService, 여기는 그 엔티티만의 것
 *   검증 · 저장 규칙은 화면 단건 저장과 같은 것을 써야 한다 ( 화면 등록과 엑셀 등록의 결과가 달라지지 않게 )
 */
interface ImportHandler {

    // 양식 JSON 의 "entity" 와 같은 이름 ( 예: item )
    public function entity(): string;

    // 행을 구분하는 키 필드 ( 예: item_code ) — 있으면 수정, 없으면 신규
    public function keyField(): string;

    // 양식과 상관없이 언제나 필수인 필드 ( 서버 저장 규칙 )
    public function serverRequired(): array;

    // 키로 기존 데이터 찾기
    public function find(string $key): ?Model;

    // 시작 데이터 : 수정이면 기존 값, 신규면 기본값 → 그 위에 엑셀 값을 덮는다
    public function baseData(?Model $existing): array;

    // 참조형 칸( type: ref ) 변환 — 사람이 쓴 값( 코드 · 이름 ) → 저장할 값. 못 찾으면 null
    public function resolveRef(string $ref, string $text): mixed;

    // 검증 — 단건 저장과 같은 규칙. 실패는 ValidationException. 저장할 값을 돌려준다
    public function validate(array $data, ?Model $existing, array $attributes): array;

    // 저장 ( 트랜잭션 안에서 불린다 )
    public function save(array $validated, ?Model $existing): Model;
}
