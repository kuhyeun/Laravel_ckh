# 업체 마이그레이션 규칙

이 폴더는 **이 업체에만 있는 테이블 · 칸**을 둡니다. Core 테이블은 `packages/mes-core/database/migrations` 에 있습니다.
( ADR 0028 · ADR 0029 )

## 1. 실행 순서

- `php artisan migrate` 는 Core 와 이 폴더의 마이그레이션을 **파일 이름( 날짜 ) 순으로 섞어서** 실행합니다
- 그래서 업체 마이그레이션은 반드시 `php artisan make:migration` 으로 만들어 **만든 날짜**가 파일 이름에 들어가게 합니다
  ( 참조하는 Core 테이블보다 늦은 날짜 → 항상 Core 테이블이 먼저 생김 )
- Core 는 업체 테이블을 참조하지 않으므로, 나중에 Core 마이그레이션이 추가돼도 순서 문제가 없습니다

## 2. 하지 않는 것

- **Core 테이블을 고치지 않는다** — 칸 추가 · 변경 · 삭제 · 인덱스 모두. Core 를 올릴 때마다 충돌합니다
- Core 테이블 이름과 겹칠 수 있는 이름을 쓰지 않는다 ( 아래 접두어 규칙 )

## 3. 테이블 이름

- 업체 테이블은 **`cx_` 로 시작**합니다 ( Core 는 `cx_` 를 쓰지 않습니다 )
  - 예 ) `cx_mold` ( 금형 대장 ) · `cx_customer_grade`

## 4. Core 테이블에 칸이 필요할 때 — 1:1 확장 테이블

먼저 Core 의 **예비 칸**( `ext_c01` · `ext_n01` · `ext_d01` … — 품목 · BOM 줄 · 수주 · 발주 머리 )으로 되는지 봅니다.
예비 칸이 없거나 모자라면 **같은 키를 쓰는 1:1 확장 테이블**을 만듭니다.

- 이름 : `cx_{Core 테이블}_ext` — 예 ) `cx_sales_order_line_ext`
- 키 : Core 테이블의 키를 그대로 기본키로 + 외래키 `ON DELETE CASCADE` ( Core 가 줄을 지우면 함께 지워짐 )
- 행이 없을 수 있다 → 읽을 때 `LEFT JOIN`, 저장은 업체 쪽 서비스( 바꿔 끼운 서비스 · 업체 모듈 )에서

```php
Schema::create('cx_sales_order_line_ext', function (Blueprint $table) {
    $table->foreignId('sales_order_line_idx')->primary()
          ->constrained('sales_order_line', 'sales_order_line_idx')->cascadeOnDelete();
    $table->string('color', 30)->nullable()->comment('색상 ( 업체 A )');
    $table->timestamps();
});
```

## 5. 다른 업체도 쓰게 되면

두 번째 업체가 같은 칸 · 테이블을 원하면 **Core 로 올립니다** ( Core 마이그레이션으로 새로 만들고, 업체 테이블 값을 옮긴 뒤 업체 테이블은 정리 ).
