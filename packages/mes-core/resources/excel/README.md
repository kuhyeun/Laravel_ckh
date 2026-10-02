# 엑셀 양식

목록 화면의 **[엑셀]**( 내보내기 )과 **[엑셀 업로드]**( 일괄 등록 )가 쓰는 양식입니다. **개발자가 만들어 배포**하고, 사용자 · 관리자는 고르기만 합니다.

내보내기와 업로드는 **양식을 따로** 둡니다. 모양도 다르게 만들어, 내보낸 파일을 그대로 고쳐 올리는 실수를 막습니다.

## 어디에 두나

| 위치 | 용도 |
|---|---|
| `packages/mes-core/resources/excel/` | **공통 기본 양식** — 모든 업체 공유 |
| `src/resources/excel/` | **업체 전용** — 이 업체 repo 에만. **같은 파일 이름이면 공통 양식 대신 이것**을 씁니다 |
| `src/resources/excel/templates/` | 양식 파일형이 채울 `.xlsx` ( 공통에도 같은 구조로 둘 수 있습니다 ) |

## 파일 이름

화면이 엑셀 버튼에 넘기는 **양식 이름**( 예: `item.list` )으로 찾습니다.

| 파일 | 뜻 |
|---|---|
| `item.list.json` | 기본 양식 ( 선택 목록의 맨 위 ) |
| `item.list.{아무거나}.json` | 추가 양식 — 하나라도 있으면 버튼을 누를 때 고르는 목록이 뜹니다 |
| ( 파일 없음 ) | 화면 그리드의 열 그대로 내보냅니다 |

화면별 양식 이름
- 내보내기 : `item.list` · `partner.list` · `process.list` · `equipment.list` · `inspection_item.list` · `lead_time.list` · `member.list`
- 업로드 : `item.import` · `partner.import` ( 화면당 하나, 변형 없이 이 이름 그대로 )

## ① 열 구성형 — `"type": "list"`

```json
{
    "type": "list",
    "name": "품목 목록",
    "sheet": "품목",
    "filename": "품목목록",
    "freeze": "A2",
    "columns": [
        { "field": "item_code",    "header": "품번",     "width": 16 },
        { "field": "item_type",    "header": "대분류",   "display": true },
        { "field": "safety_stock", "header": "안전재고", "numFmt": "#,##0" },
        { "field": "is_use",       "header": "사용",     "map": { "Y": "사용", "N": "미사용" } }
    ]
}
```

| 열 옵션 | 뜻 |
|---|---|
| `field` | 행의 필드 이름 ( 목록 API 응답 그대로 ) 또는 기본 제공 값( 아래 ) |
| `header` · `width` | 머리글 · 열 너비( 글자 수 ) |
| `numFmt` | 엑셀 숫자 서식 ( `#,##0` · `0.00` · `yyyy-mm-dd` ) |
| `map` | 코드 → 표시 ( `{ "Y": "사용" }` ) |
| `display` | `true` 면 **화면 그리드에 보이는 값** 그대로 ( 코드 → 이름 변환을 화면과 같게 ) |

## ② 양식 파일형 — `"type": "template"`

```json
{
    "type": "template",
    "name": "품목 목록 ( 출력용 )",
    "template": "templates/item-list.xlsx",
    "filename": "품목목록_양식",
    "cells": { "F2": "@today", "F3": "@count" },
    "rows": { "start": 5, "columns": { "A": "@no", "B": "item_code", "C": "item_name" } }
}
```

- `.xlsx` 는 엑셀로 그대로 그립니다 — 제목 · 병합 · 테두리 · 로고 · 숫자 서식을 **양식에** 넣어 두면 유지됩니다
- `cells` : 한 칸씩 채울 곳. 값은 화면이 넘긴 `context` → 첫 행 순서로 찾습니다
- `rows.start` : 데이터 첫 줄. **이 줄의 서식을 복제해** 행 수만큼 아래로 늘립니다 → 데이터 줄 하나만 서식을 잡아 두면 됩니다
- `rows.start` 아래에 합계 줄 등이 있으면 함께 밀려 내려갑니다

## 기본 제공 값

| 값 | 뜻 |
|---|---|
| `@no` | 순번 ( 1부터 ) |
| `@count` | 건수 |
| `@today` · `@now` | 오늘 · 지금 ( 칸의 날짜 서식을 따릅니다 ) |
| `@userName` | 출력한 사람 |

## ③ 업로드형 — `"type": "import"`

[엑셀 업로드] → [양식 받기]가 이 JSON 으로 엑셀을 만들고, 올린 파일도 이 JSON 으로 읽고 검증합니다.

```json
{
    "type": "import",
    "entity": "item",
    "version": 1,
    "name": "품목 일괄 등록",
    "title": "품목 일괄 등록 양식",
    "sheet": "품목",
    "columns": [
        { "field": "item_code", "header": "품번",   "type": "text", "required": true, "width": 16, "example": "PN-A" },
        { "field": "item_type", "header": "대분류", "type": "enum", "required": true,
          "options": [ { "value": "MATERIAL", "label": "자재" }, { "value": "PRODUCT", "label": "제품" } ] },
        { "field": "spec_unit", "header": "단위",   "type": "code", "codeGroup": "UNIT" },
        { "field": "routing_idx", "header": "기본 라우팅", "type": "ref", "ref": "routing" },
        { "field": "safety_stock", "header": "안전재고", "type": "number" }
    ]
}
```

| 항목 | 뜻 |
|---|---|
| `entity` | 서버 처리기 이름 ( `item` · `partner` ) — 검증 · 저장은 화면 단건 저장과 같은 규칙 |
| `version` | **열을 더하거나 바꾸면 올린다.** 옛 양식으로 쓴 파일은 "새 양식을 받으세요"로 막힌다 |
| `required` | 필수. **더하는 쪽으로만** 쓴다 — 서버 저장 규칙상 필수인 칸( 품번 · 품명 … )은 이것이 없어도 필수 |
| `type` | `text` · `number` · `integer` · `yn`( Y/N · 사용/미사용 ) · `enum`( `options` 의 값 또는 이름 ) · `code`( 코드관리 `codeGroup` 의 코드 또는 이름 ) · `ref`( 처리기가 아는 참조 — 예: 라우팅코드 → 라우팅 ) |
| `maxLength` | 글자 수 상한 ( `text` ) |
| `note` · `example` | 머리글 메모 · [작성 안내] 시트에 들어갈 설명과 예시 |

만들어지는 양식
- 1행 안내 띠 · 2행 초록 머리글( 필수는 `*` ) · 3행부터 입력 · `enum` · `code` · `ref` · `yn` 칸은 드롭다운
- [작성 안내] 시트에 규칙 · 칸별 입력 방법 · 예시 ( **예시는 입력 시트에 넣지 않는다** — 지우지 않고 올리는 사고 방지 )
- 숨김 시트 `_meta` 에 양식 이름 · 버전 — 이게 없으면 올릴 수 없다

올릴 때
- 열은 **머리글 이름**으로 찾는다 ( 순서를 바꾸거나 필요 없는 열을 지워도 된다 )
- 신규만 / 신규 + 수정 — 수정할 때 **빈 칸은 기존 값 유지**
- 한 번에 1000행 · **하나라도 오류면 아무것도 저장하지 않는다** · 오류는 '오류 사유' 열을 붙인 파일로 돌려준다 ( 고쳐서 그대로 다시 올리면 된다 )
