# 화면 스타일 가이드

> 새 화면을 만들거나 고칠 때 **이 문서부터** 읽는다. 사람도 AI 도 같은 문서를 본다.
> 살아 있는 예시 : 앱의 **개발 메뉴 → 스타일 가이드** ( `/dev/style-guide` · `Pages/Dev/StyleGuide.vue` ) — 예시 코드를 복사해서 쓴다.
> 부품 하나하나의 props · 이벤트는 [`docs/screens/01-공통컴포넌트.md`](../../../docs/screens/01-공통컴포넌트.md) ( 이 문서는 **화면을 어떻게 짜는가** )
>
> 상태 : **확정** ( 2026-10-28 — §9 통일안 전부 제안대로 ). **새 화면부터** 따른다. 기존 화면은 손댈 때 맞춘다 ( §9 "기존 화면" )

---

## 1. 화면 틀 — 3단

모든 업무 화면은 위에서 아래로 **제목 줄 → 검색바 → 본문** 세 단이다. 본문만 늘어나고, 넘치면 **본문 안에서** 스크롤한다 ( 페이지 전체가 스크롤되지 않는다 ).

```
┌────────────────────────────────────────────────────────────────────┐
│ 품목관리                          ✓ 저장했습니다   [엑셀] [+ 등록] [삭제] │ ← ① 제목 줄
├────────────────────────────────────────────────────────────────────┤
│ [기간 ▾] [from] ~ [to] [오늘][1주][1달] │ [검색어      ] [상태 ▾] [조회][초기화] │ ← ② 검색바
├────────────────────────────────────────────────────────────────────┤
│ 품목 목록 · 120 건                                          [엑셀 ↓] │ ← ③ 본문 : 목록 박스 머리
│ ┌──────────────────────────────────────────────────────────────┐ │
│ │ DataGrid                                                     │ │
│ └──────────────────────────────────────────────────────────────┘ │
│ 총 120 건                 « ‹ 1 2 3 › »                 [50개씩 ▾] │ ← 페이징 ( DataGrid 가 그림 )
└────────────────────────────────────────────────────────────────────┘
```

```vue
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- ① 제목 줄 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">품목관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" variant="primary" @click="openModal(null)">+ 등록</BaseButton>
            <BaseButton v-if="canDelete" variant="danger" :disabled="!checkedRows.length" @click="deleteChecked">삭제</BaseButton>
        </div>

        <!-- ② 검색바 -->
        <SearchBar v-model="search" placeholder="품번 · 품명" :loading="loading" @search="loadFirstPage">
            <BaseSelect v-model="search.status" :options="STATUS_FILTER" size="sm" class="w-28" />
        </SearchBar>

        <!-- ③ 본문 : 목록 박스 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">품목 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <ExcelExportButton ... />
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid class="flex-1" :data="rows" :columns="columns" :pagination="paging" empty-text="조건에 맞는 품목이 없습니다."
                    @row-click="openModal" @page="onPage" @sort="onSort" />
            </div>
        </div>
    </div>
</template>
```

| 규칙 | 이유 |
|---|---|
| 루트는 `flex flex-col flex-1 min-h-0` | AppLayout 이 높이를 정한다. `min-h-0` 이 없으면 본문 스크롤 대신 화면이 뚫고 내려간다 |
| 높이가 정해지는 줄( 제목 · 검색바 · 박스 머리 )은 `shrink-0` | 본문이 줄어들 때 같이 찌그러지지 않게 |
| 늘어나는 칸은 `flex-1 min-h-0` + 그 안에서 `overflow-y-auto` | flex 자식은 기본 `min-height:auto` 라 내용만큼 커진다 |

---

## 2. ① 제목 줄

- 순서 : **제목 → ( 빈칸 ) → 알림 문구 → 버튼들**. 버튼은 오른쪽 끝에 모은다
- 제목은 **메뉴 이름과 같게** ( `<h2 class="font-bold text-lg tracking-tight">` )
- 버튼 순서 : 보조( 엑셀 올리기 등 `secondary` ) → **`+ 등록`( `primary` )** → 위험한 동작( 삭제 · 취소 `danger` ) — 위험한 동작은 맨 오른쪽
- 권한이 없으면 **숨긴다**( `v-if="canWrite"` ), 지금 할 수 없으면 **끈다**( `:disabled` — 예 : 체크한 행이 없음 )
- `BaseButton` 은 크기가 하나뿐이다 ( `size` prop 없음 — 기존 화면의 `size="sm"` 은 아무 효과가 없다. 새 화면에는 쓰지 않는다 ). `size="sm"` 은 검색바 안의 `BaseInput` · `BaseSelect` 에만
- 권한 : `page.props.permissions[menuCode].can_read / can_write / can_delete` — 등록 · 수정 = write, 삭제 · 취소 = delete

---

## 3. ② 검색바

- **`SearchBar` 컴포넌트**를 쓴다 ( 기간 · 빠른 기간 · 검색어 · [조회] · [초기화] 가 들어 있다 ). 화면만의 거르기는 slot 에 `BaseSelect size="sm"`
- 기간이 없는 화면은 `date-bases` 를 주지 않으면 기간 칸이 빠진다
- 조회 중 표시 : `:loading` 을 넘긴다 — 0.3 초가 넘을 때만 [조회] 가 꺼진다 ( `useDelayedBusy` — 짧은 조회에 깜빡이지 않게 )
- 첫 조회는 SearchBar 가 보낸다 ( 화면의 `onMounted` 에서 또 부르지 않는다 )

---

## 4. ③ 본문 — 세 가지 모양

| 모양 | 쓰는 곳 | 틀 |
|---|---|---|
| **목록 + 모달** | 대부분 ( 품목 · 수주 · 발주 · 작업지시 … ) | §1 의 목록 박스. 행 클릭 → 등록 · 수정 모달 |
| **좌우 분할** | 목록에서 하나 골라 옆에서 처리 ( 자재출고 · 공정실적 · BOM · 라우팅 … ) | 왼쪽 목록 박스( 고정 폭 ) + 오른쪽 상세 박스( `flex-1` ) |
| **설정 화면** | 한 장짜리 설정 ( 시스템 설정 · 메뉴관리 · 권한 ) | 제목 줄 + 본문 하나 ( 검색바 없음 ) |

### 좌우 분할

```vue
<div class="flex-1 min-h-0 flex gap-3">
    <!-- 왼쪽 : 고르는 목록 -->
    <div class="w-[460px] shrink-0 flex flex-col border border-gray-200 overflow-hidden">
        <div class="px-3 py-2 bg-gray-50/70 shrink-0 text-sm font-semibold text-gray-600">품목 줄 · <span class="text-xs text-gray-400">{{ n }} 건</span></div>
        <div class="flex-1 min-h-0 flex flex-col"><DataGrid class="flex-1" ... @row-click="select" /></div>
    </div>
    <!-- 오른쪽 : 고른 것의 상세 -->
    <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
        <div v-if="!current" class="flex-1 flex items-center justify-center text-sm text-gray-400">왼쪽에서 품목 줄을 고르세요.</div>
        <template v-else>
            <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3"> 머리 : 이름 · 요약 · 버튼 </div>
            <div class="flex-1 min-h-0 overflow-y-auto p-3 space-y-4"> 내용 </div>
        </template>
    </div>
</div>
```

- 아무것도 안 골랐을 때 오른쪽 가운데에 **할 일**을 쓴다 ( "왼쪽에서 ○○을 고르세요." ), 불러오는 중이면 "불러오는 중…"
- 왼쪽 폭 : 목록 그리드 **`w-[460px]`** ( 열이 많으면 `w-[520px]` ) · 짧은 목록 · 트리 **`w-[240px]`**

### 목록 박스 머리

- 왼쪽 : **"○○ 목록 · n 건"** ( 건수는 `text-xs text-gray-400` ), 오른쪽 : 목록에 대한 버튼( 엑셀 내려받기 등 )

---

## 5. 그리드 ( `DataGrid` )

- 서버 페이징 : `:pagination="{ page, perPage, total }"` → `@page` · `@sort`( 열에 `sortable: true` ) — 페이징 바는 DataGrid 가 그린다
- 정렬 열에 같은 값이 많으면( 일시 · 날짜 ) 컨트롤러에서 `listTieBreaker()` 로 보조 기준( 보통 IDX )을 준다 — 없으면 같은 값끼리 순서가 조회마다 바뀐다. 기본 정렬도 `일시 → IDX` 처럼 끝에 IDX
- 빈 결과 : `empty-text` 에 **조건을 탓하는 문구** ( "조건에 맞는 ○○이 없습니다." )
- 클릭할 수 있는 열은 `className: 'cursor-pointer'`
- 숫자 열은 `align: 'right'` + `fmtNum` / `fmtQty` ( `@core/Utils/format` — §8 ), 단위는 값 뒤에 한 칸 띄워
- 날짜 열 `width: 100, align: 'center'`, 상태 열 `align: 'center'` + 상태 색( §7 )
- 사용자가 쓴 글자가 들어가는 열은 `escapeHTML: true`
- 체크 : `options.rowHeaders: 'checkbox'` → `@select` ( 선택된 행 배열 )
- 트리 ( 머리 행 + 하위 행 ) : `options.treeColumnOptions: { name: '첫 열', useIcon: false, useCascadingCheckbox: false }`, 행에 `_children`
  - 하위 행이 있는 행은 **트리 칸을 누르면 펼침 / 접기**, 다른 칸은 row-click. 빈 `_children` 은 DataGrid 가 지운다
  - **트리 칸( 번호 )** : 머리 행 = 번호 그대로, 하위 행 = **`{머리 번호} - {줄 번호}`** ( 예: `RC-261029-001 - 1` — `1.` 만 쓰지 않는다 ). 칸 너비는 그만큼 넓게 ( 190 )
    - 하위 행이 **제 번호를 따로 가진 것**( 작업지시 줄 = 작업지시 번호 `WO-…-01` )이면 그 번호를 그대로 쓴다
  - **머리 값을 그대로 물려받는 열**( 일시 · 날짜 · 거래처 · 담당처럼 줄마다 같은 값 ) : 하위 행은 빈칸 대신 연한 회색 **`〃`**( 위와 같음, 가운데 ) — `@core/Utils/gridTree`
    ```js
    import { ditto, escapeHtml, fillHead } from '@core/Utils/gridTree';
    const HEAD_FIELDS = ['occurred_at', 'recorded_at', 'partner_name'];   // 〃 로 보이는 열
    { header: '입고일시', name: 'occurred_at', formatter: ditto(r => r.occurred_at) },
    { header: '구매처', name: 'partner_name', formatter: ditto(r => escapeHtml(r.partner_name)) },   // 사용자 글자는 escapeHtml
    // 엑셀 : 하위 행에 머리 값을 채워 실제 값으로 ( 거르기 · 정렬이 되게 )
    async function exportRows() { …; return fillHead(data.rows, HEAD_FIELDS); }
    ```
    - 줄마다 값이 다른 열( 발주번호 · 품목 · LOT · 수량 · 상태 )은 그 값을 그대로 쓴다
    - `〃` 를 HTML 로 그리므로 그 열은 `escapeHTML` 을 못 쓴다 → 사용자 글자( 거래처명 · 수주명 · 담당 )는 `escapeHtml()` 로
    - 엑셀 열을 따로 정한 화면( `exportColumns` — 수주 · 발주 )은 줄 행에 이미 머리 값이 있어 `fillHead` 가 필요 없다
  - 적용 화면 ( 2026-10-29 ) : 입고관리 · 수주 · 발주 · 작업지시 · 출하 — 트리 그리드를 새로 만들면 이 규칙대로
  - 하위 행은 체크하지 않게 `_attributes: { checkDisabled: true }`

### 5-0. 목록 머리 : 열 설정 + 엑셀 — **일반 그리드는 기본 포함**

`DataGrid` 로 목록을 보여 주면( 메뉴의 메인 목록 · 좌우 분할의 고르는 목록 포함 ) **목록 머리 오른쪽에 [⚙ 열 설정] · [엑셀] 을 꼭 둔다.**
빼는 것은 모달 안의 작은 표 · 입력용 그리드 · 엑셀 업로드 결과처럼 "목록"이 아닌 그리드뿐이다.

```html
<div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
    <span class="text-sm font-semibold text-gray-600">품목 목록 · <span class="text-xs text-gray-400">{{ n }} 건</span></span>
    <div class="flex items-center gap-1.5">
        <GridSettingButton :grid="mainGrid" />
        <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="품목목록" />
        <!-- 화면 버튼( 신규 · 저장 · 삭제 )은 이 뒤에 -->
    </div>
</div>
<DataGrid ref="mainGrid" setting-key="main" … />
```
```js
const mainGrid = ref(null);
// 서버 페이징이면 엑셀은 지금 조건 전체 ( export=1 — RespondsWithList ) / 화면 데이터면 :rows="rows"
async function exportRows() {
    const { data } = await window.axios.get(route('….list'), { params: { ...search, export: 1 } });
    return data.rows ?? [];   // 트리면 (data.rows ?? []).flatMap(r => [r, ...(r._children ?? [])])
}
```

- **열 설정** = 사용자별 표시 · 이름 · 순서 · 너비 ( 사용자 × 화면 × `setting-key` — 테이블 `user_grid_setting` ). 오른쪽 클릭 "열 설정…" 으로도 연다 / 끌어서 바꾼 너비는 자동 저장
  - 한 화면에 그리드가 여럿이면 키를 다르게 ( 예: 재고관리 `lot` · `item`, 생산실적현황 `list` · `summary-묶음` )
  - 꼭 있어야 하는 열( 번호 · 품번 등 )은 열 정의에 `lockHide: true` — 숨길 수 없다. 트리 펼침 열은 DataGrid 가 알아서 잠근다
  - 화면 코드에 새 열을 넣으면 저장된 설정 끝에 보이게 붙고, 지운 열은 설정에서 무시된다
- **엑셀** 기본 내보내기 ( `:grid` 를 주면 ) : 순서 · 이름은 화면 설정 그대로, **숨긴 열도 내보내고 엑셀에서 숨김 처리** ( 필요하면 펼쳐 본다 )
  - 엑셀 열을 따로 정한 화면( `exportColumns` — 수주 · 발주 · 출하이력 · 생산실적 이력처럼 줄 단위로 펼쳐 내보내는 곳 )은 `:grid` 를 주지 않는다 ( 그 열 목록대로 나간다 )
  - 양식 JSON( `resources/excel/*.list.json` )이 있으면 양식이 우선이다 ( `:grid` 를 줘도 양식대로 )

### 5-1. 얇은 표 목록 ( 그리드 대신 `<table>` )

패널 · 모달 **안에** 붙는 짧은 목록 — 출고 이력 · 반납 이력 · 입고 상세 줄 · 실적 줄 · 자재 표.

| 쓰는 곳 | 쓰지 않는 곳 |
|---|---|
| 화면의 **일부**( 패널 아래 · 모달 탭 ) · 줄이 수십 개 이하 · 페이징 · 정렬 · 엑셀이 필요 없음 | 화면의 **주인공 목록** · 줄이 많음 · 서버 페이징 / 정렬 / 엑셀 → `DataGrid` |

```
┌ 반납 이력  3 줄 ─────────────────────────────────────── [반납 취소] ┐  ← 머리 : 제목 + 건수 · 오른쪽 동작
│ □  전표           반납일시          자재        LOT      수량   상태 │  ← thead : 옅은 회색 · sticky
│ ☑  RT-261029-002  2026-10-29 14:20  MIT-01 자재  L-001   15 EA  반납 │
│    RT-261029-001  2026-10-29 10:10  MIT-01 자재  L-001   ̶1̶0̶ ̶E̶A̶  취소 │  ← 취소는 회색 줄긋기 · 체크 없음
└──────────────────────────────────────────────────────────────┘
```

```vue
<div class="shrink-0 border-t border-gray-200 max-h-56 flex flex-col">
    <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
        <span class="text-sm font-semibold text-gray-600">반납 이력 <span class="text-xs text-gray-400">{{ rows.length }} 줄</span></span>
        <div class="flex-1"></div>
        <BaseButton v-if="canCancel" variant="danger" :disabled="!checked.length" @click="cancelChecked">반납 취소</BaseButton>
    </div>
    <div class="overflow-auto">
        <table class="w-full text-xs">
            <thead class="text-gray-400 sticky top-0 bg-white">
                <tr><th class="w-8"></th><th class="text-left font-normal px-1">전표</th> … <th class="text-right font-normal px-1">수량</th></tr>
            </thead>
            <tbody>
                <tr v-if="!rows.length"><td colspan="9" class="px-2 py-2 text-center text-gray-400">반납 이력이 없습니다.</td></tr>
                <tr v-for="r in rows" :key="r.id" class="border-t border-gray-100" :class="r.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                    <td><input v-if="canCancel && r.status === 'DONE'" type="checkbox" class="row-check" :value="r.id" v-model="checked" /></td>
                    <td class="px-1 py-0.5">{{ r.txn_no }}</td> …
                    <td class="px-1 text-right">{{ fmtQty(r.qty, r.spec_unit) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
```

| 규칙 | 값 |
|---|---|
| 글자 · 줄 | `table text-xs` · 칸 `px-1 py-0.5` ( 줄 높이 약 20px ) · 줄 사이 `border-t border-gray-100` |
| 머리줄 | `thead text-gray-400 sticky top-0 bg-white` · `th font-normal` — 본문보다 옅게 |
| 높이 | 패널 아래에 붙으면 `max-h-56` + 안쪽 `overflow-auto` ( 머리줄은 sticky 로 남음 ) / 모달 탭 안이면 탭 내용 칸의 스크롤을 따름 |
| 체크박스 | **`class="row-check"`** ( 16px · 칸 가운데 — `core.css` ). 기본 체크박스는 작고 글자 기준선에 붙어 위로 치우친다. 체크할 수 없는 줄은 체크박스를 **그리지 않는다** |
| 숫자 | 오른쪽 정렬 + `fmtNum` / `fmtQty` |
| 취소된 줄 | 지우지 않고 `text-gray-400 line-through` ( 그리드는 `row-canceled` — §5 ) |
| 빈 목록 | 한 줄 가운데 `text-gray-400` "○○이 없습니다." |
| 강조 | 지금 보고 있는 줄 `bg-amber-50` ( 예 : 생산실적 상세에서 누른 실적 ) |

---

## 6. 등록 · 수정 모달

```js
modalStore.open(ItemForm, { modalTitle: isEdit ? '품목 수정' : '품목 등록', item, onSaved }, { size: 'xl' });
```

```
┌ 품목 등록 ──────────────────────────────────────────── ✕ ┐
│ 오류가 있으면 맨 위에 빨간 글 ( 칸에 붙일 수 없는 오류 )         │
│ [기본] [공정] [자재] [첨부]                                    │ ← 탭 ( 있으면 ) — 머리는 고정
│ ┌ 칸 grid ( grid-cols-4 gap-x-4 gap-y-3 ) ──────────────┐ │
│ │ 품번 *        품명 *                    규격           │ │ ← 라벨 위 · 입력 아래 · 오류는 입력 밑
│ │ [        ]   [                    ]   [      ]         │ │
│ │ 이미 있는 품번입니다                                    │ │
│ └──────────────────────────────────────────────────┘ │
│ ─────────────────────────────────────────────────── │
│ [줄 취소] [지시서 취소]                      [취소] [등록]   │ ← 아래 버튼 줄 ( 고정 )
└──────────────────────────────────────────────────────┘
```

| | 규칙 |
|---|---|
| 크기 | `md` 600 : 칸 몇 개 / `lg` 800 : 보통 등록 / `xl` 1140 : 탭 · 줄 목록이 있는 큰 등록 |
| 높이 | 모달은 위에서 6vh 에 붙고 아래로만 늘어난다 ( 최대 88vh ). 넘치면 **모달 안 내용 칸**만 스크롤 — 제목 · 탭 머리 · 아래 버튼 줄은 고정 |
| 칸 | `grid grid-cols-4 gap-x-4 gap-y-3` ( 넓은 칸은 `col-span-2` / `col-span-4` ). 라벨 위 · 입력 아래 |
| 라벨 | `<label class="block text-gray-500 font-medium mb-1">` |
| 필수 | 라벨 다음 `<span class="text-red-500">*</span>` |
| 오류 | 서버 422 의 `errors.칸이름[0]` 을 **그 칸 밑**에 `<p class="text-xs text-red-500 mt-0.5">` · 칸에 붙일 수 없는 오류만 모달 맨 위( `text-xs text-red-500 whitespace-pre-line` ) |
| 읽기 전용 | 쓰기 권한이 없거나 완료 · 취소된 건은 칸을 끄고 아래 버튼은 [닫기] 하나 |
| 아래 버튼 줄 | `flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0` — **왼쪽 : 위험한 동작( `danger` )** · `flex-1` · **오른쪽 : [취소]( `secondary` ) [등록 / 수정]( `primary` )** |
| 저장 버튼 이름 | 새로 만들면 **등록**, 고치면 **수정**. 저장 중에는 끄고 "저장 중…" |
| 저장 후 | 모달을 닫고 목록을 다시 불러오고 제목 줄에 성공 문구 |

---

## 7. 알림 · 확인 · 상태 색

### 알림 ( 처리 결과 )

- **제목 줄의 알림 문구** ( `flash` ) — 성공 `text-green-600` / 오류 `text-red-500`, 5 ~ 6 초 뒤 사라짐
- 경고가 함께 오면 성공 문구 뒤에 `⚠ 경고` ( 여러 줄이면 `whitespace-pre-line` )
- 422 는 서버 문구를 그대로, 403 은 "권한이 없습니다.", 그 밖은 "처리하지 못했습니다."

```js
import { useFlash, failMsg } from '@core/Composables/useFlash';
const { flash, showFlash } = useFlash();          // 5 초 뒤 사라짐

showFlash('저장했습니다.');
showFlash('출고했습니다.', false, data.warnings);   // 경고는 ⚠ 줄로
showFlash(failMsg(e), true);                        // 422 서버 문구 · 403 권한 · 그 밖 "처리하지 못했습니다."
```

### 확인 ( 되돌리기 어려운 동작 전 )

```js
const r = await showAlert('출고 취소', `3줄의 출고를 취소합니다.\n자재는 출고한 창고로 돌아갑니다.`, 'warning', 'confirm');
if (!r.isConfirmed) return;
```

- 제목 = 동작 이름, 내용 = **무엇이 어떻게 되는지** ( 대상 몇 건 · 되돌아가는 것 · 되돌릴 수 없는 것 )

### 상태 색 ( 그리드 · 머리 공통 )

| 뜻 | 색 | 예 |
|---|---|---|
| 시작 전 | `#6b7280` 회색 | 대기 · 미확정 |
| 진행 · 확정 | `#2563eb` 파랑 | 진행 · 확정 |
| 끝남 | `#16a34a` 초록 | 완료 · 입고완료 |
| 손이 필요함 | `#d97706` 주황 | 검사대기 · 미달 |
| 취소 · 미사용 | `#9ca3af` 옅은 회색 | 취소 |

- 상태 값 · 이름 · 색은 모듈의 `*Codes.js` 한 곳에 ( 예 : `productionCodes.js` 의 `WO_STATUSES` ) — 화면에 색을 직접 쓰지 않는다
- 경고 문구 `text-amber-600` ~ `700`, 안내 문구 `text-gray-400` ~ `500`, 강조 수치 `text-blue-700`

---

## 8. 글 · 숫자 · 날짜

- 문장은 **해요체가 아니라 합니다체** ( "저장했습니다." · "왼쪽에서 고르세요." )
- 오류는 **무엇이 틀렸고 어떻게 하면 되는지** ( "앞 공정( 010 원자재출고 )을 먼저 완료하세요." )
- 숫자 : 천 단위 · 소수 최대 6 자리 · 오른쪽 정렬 — **`@core/Utils/format`** 의 `fmtNum(v)` · `fmtQty(v, unit)` 를 쓴다 ( 화면마다 `fmt` 를 새로 만들지 않는다 )
- 해당 없음은 `dash(v)` → `—`, 실제 0 은 `0` — 둘을 구분한다
- 날짜 `YYYY-MM-DD`, 일시 `YYYY-MM-DD HH:mm` ( 입력은 `type="date"` / `type="datetime-local"` )
- 날짜 · 일시 입력은 **`BaseDatePicker`** ( 10-29 채택 — `@vuepic/vue-datepicker` 11.0.3 을 감쌈 ) — `<BaseDatePicker v-model="form.date" />` · 일시는 `mode="datetime"` · 작게 `size="sm"` · `disabled` · `clearable` · `placeholder`. 값은 위 문자열 그대로( 서버 형식과 같음 ). `BaseInput type="date"` · `datetime-local` 은 쓰지 않는다 ( 팝업 모양이 브라우저마다 다르고 바꿀 수 없다 )
- 최소 너비는 컴포넌트가 잡는다( 날짜 8.75rem · 일시 13rem ) — 너비를 따로 줄 필요 없고 이보다 좁은 칸에 넣지 않는다

---

## 9. 통일안 ( 2026-10-28 결정 — 전부 제안대로 )

화면들이 서로 달랐던 곳과 정한 쪽. 위 절들은 이미 정한 쪽으로 적혀 있다.

| # | 항목 | 달랐던 것 | 정한 것 |
|---|---|---|---|
| 1 | 제목 줄 아래 | 대부분 `pb-2` · 시스템 화면 4개는 `pb-3 border-b` | **`pb-2`, 선 없음** |
| 2 | 검색바 | 업무 화면 `SearchBar` / 기준정보 11개는 같은 모양을 손으로 | **`SearchBar`** |
| 3 | 라벨 | `block text-gray-500 font-medium mb-1`( 33곳 ) / `.lbl` 12px | **`block text-gray-500 font-medium mb-1`** |
| 4 | 칸 오류 | `text-xs text-red-500 mt-0.5`( 61 ) / `text-[11px] text-red-500`( 24 ) / `.err`( 11 ) | **`text-xs text-red-500 mt-0.5`** |
| 5 | 필수 `*` | `text-red-500` / `text-red-400` | **`text-red-500`** |
| 6 | 모달 크기 | 기준 없음 | **md 칸 몇 개 · lg 보통 · xl 탭 · 줄 목록** |
| 7 | 좌우 분할 왼쪽 폭 | 460 · 520 · 160 | **목록 그리드 460 ( 열이 많으면 520 ) · 짧은 목록 · 트리 240** |
| 8 | 숫자 표시 | 화면마다 `fmt` / `num` ( 17 파일 ) | **`@core/Utils/format`** ( `fmtNum` · `fmtQty` · `dash` ) |
| 9 | 알림 | 대부분 제목 줄 `flash` · 재고관리는 오류 문자열만 | **제목 줄 + `@core/Composables/useFlash`** ( `showFlash` · `failMsg` ) |

### 기존 화면

- 새 화면은 처음부터 위 규칙대로. **기존 화면은 그 화면을 고칠 때 같이 맞춘다** ( 한꺼번에 고치지 않는다 — 고친 화면은 다시 확인해야 하므로 )
- 아직 맞추지 않은 곳 ( 2026-10-28 ) : 1 메뉴관리 · 권한 · 시스템 설정 / 2 기준정보 11개 / 3 · 4 작업지시 · 공정실적의 `.lbl` · `.err` / 5 `text-red-400` 쓰는 폼 / 8 `fmt` 를 직접 만든 17 파일 / 9 재고관리 · 그 밖 `showFlash` 를 직접 만든 화면 · 모든 화면의 `BaseButton size="sm"`
