<!-- 스타일 가이드 ( 개발 메뉴 · 권한 레벨 0 ) — 규칙 원본 : packages/mes-core/docs/style-guide.md
     - 이 화면은 규칙을 **살아 있는 예시**로 보여 주고 코드를 복사하게 한다. 규칙 글은 md 에만 ( 두 군데 쓰지 않는다 )
     - 구역 : 화면 틀 · 좌우 분할 · 모달 · 알림 · 상태 색 · 색 팔레트 · 숫자 · 부품( 예전 UI Kit ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 제목 줄 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">스타일 가이드</h2>
            <code class="text-xs text-gray-400 hidden md:inline">규칙 : packages/mes-core/docs/style-guide.md</code>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line text-right" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <nav class="flex flex-wrap gap-1 text-sm pb-2 mb-1 border-b shrink-0">
            <a v-for="s in SECTIONS" :key="s.id" :href="'#' + s.id" class="px-3 py-1 rounded-md text-gray-600 hover:bg-gray-100 transition-colors">{{ s.label }}</a>
        </nav>

        <!-- 본문 ( 내부 스크롤 ) -->
        <div class="flex-1 min-h-0 overflow-y-auto pt-4 pr-1 space-y-12">

            <!-- 1. 화면 틀 -->
            <section id="layout" class="scroll-mt-4">
                <SectionHead title="화면 틀 — 3단" note="제목 줄 → 검색바 → 본문. 본문만 늘어나고 넘치면 본문 안에서 스크롤 ( md §1 ~ §3 )" />
                <ShowcaseCard title="목록 + 모달 ( 가장 흔한 화면 )" :code="code.layout">
                    <div class="w-full h-[440px] flex flex-col border border-dashed border-gray-300 p-3 bg-white">
                        <div class="flex items-center gap-3 pb-2 shrink-0">
                            <h2 class="font-bold text-lg tracking-tight">품목관리</h2>
                            <div class="flex-1"></div>
                            <span v-if="demoFlash.text" class="text-sm" :class="demoFlash.error ? 'text-red-500' : 'text-green-600'">{{ demoFlash.text }}</span>
                            <BaseButton variant="primary" @click="openDemoModal('lg')">+ 등록</BaseButton>
                            <BaseButton variant="danger" :disabled="!checked.length" @click="showDemoFlash(`${checked.length}건을 미사용 처리했습니다. ( 예시 )`)">삭제</BaseButton>
                        </div>

                        <SearchBar v-model="search" :fields="DEMO_FIELDS" :date-bases="[{ value: 'created', label: '등록일' }]" :blank="() => ({ kind: '' })" @search="runSearch">
                            <BaseSelect v-model="search.kind" :options="KIND_FILTER" class="w-32" />
                        </SearchBar>

                        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                                <span class="text-sm font-semibold text-gray-600">품목 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                                <div class="flex items-center gap-1.5">
                                    <GridSettingButton :grid="demoGrid" />
                                    <ExcelExportButton :rows="found" :columns="columns" :grid="demoGrid" filename="스타일가이드_예시" />
                                </div>
                            </div>
                            <div class="flex-1 min-h-0 flex flex-col">
                                <DataGrid ref="demoGrid" setting-key="demo" class="flex-1" :data="pageRows" :columns="columns" :options="{ rowHeaders: 'checkbox' }" :pagination="paging"
                                    empty-text="조건에 맞는 품목이 없습니다." @row-click="() => openDemoModal('lg')" @select="sel => checked = sel" @page="onPage" />
                            </div>
                        </div>
                    </div>
                </ShowcaseCard>
            </section>

            <!-- 2. 좌우 분할 -->
            <section id="split" class="scroll-mt-4">
                <SectionHead title="좌우 분할" note="왼쪽에서 고르고 오른쪽에서 처리 — 왼쪽 목록 460 · 짧은 목록 / 트리 240 ( md §4 )" />
                <ShowcaseCard title="목록 + 상세" :code="code.split">
                    <div class="w-full h-[320px] flex gap-3 border border-dashed border-gray-300 p-3 bg-white">
                        <div class="w-[240px] shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                            <div class="px-3 py-2 bg-gray-50/70 shrink-0 text-sm font-semibold text-gray-600">작업지시 · <span class="text-xs text-gray-400">{{ SPLIT_ROWS.length }} 건</span></div>
                            <div class="flex-1 min-h-0 overflow-y-auto">
                                <button v-for="r in SPLIT_ROWS" :key="r.no" type="button" class="w-full text-left px-3 py-2 border-b text-sm"
                                    :class="picked?.no === r.no ? 'bg-blue-50' : 'hover:bg-gray-50'" @click="picked = r">
                                    {{ r.no }} <span class="text-xs text-gray-400">{{ r.item }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                            <div v-if="!picked" class="flex-1 flex items-center justify-center text-sm text-gray-400">왼쪽에서 작업지시를 고르세요.</div>
                            <template v-else>
                                <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3">
                                    <span class="text-sm font-semibold">{{ picked.no }}</span>
                                    <span class="text-sm text-gray-600">{{ picked.item }} · 지시 {{ fmtQty(picked.qty, 'EA') }}</span>
                                    <div class="flex-1"></div>
                                    <BaseButton variant="secondary" @click="picked = null">닫기</BaseButton>
                                </div>
                                <div class="flex-1 min-h-0 overflow-y-auto p-3 space-y-2 text-sm text-gray-600">
                                    <p v-for="n in 12" :key="n">상세 내용 {{ n }} — 넘치면 이 칸만 스크롤</p>
                                </div>
                            </template>
                        </div>
                    </div>
                </ShowcaseCard>
            </section>

            <!-- 2-1. 얇은 목록 -->
            <section id="thin" class="scroll-mt-4">
                <SectionHead title="얇은 표 목록" note="패널 · 모달 안의 짧은 목록 ( 이력 · 줄 ) — 주인공 목록 · 페이징 · 정렬이 필요하면 DataGrid ( md §5-1 )" />
                <ShowcaseCard title="반납 이력 모양 — 체크 · 취소 줄 · 빈 목록 · 강조" :code="code.thin">
                    <div class="w-full border border-dashed border-gray-300 bg-white">
                        <div class="border-t border-gray-200 max-h-56 flex flex-col">
                            <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
                                <span class="text-sm font-semibold text-gray-600">반납 이력 <span class="text-xs text-gray-400">{{ THIN_ROWS.length }} 줄</span></span>
                                <div class="flex-1"></div>
                                <BaseButton variant="danger" :disabled="!thinChecked.length" @click="showFlash(`${thinChecked.length}줄의 반납을 취소했습니다. ( 예시 )`)">반납 취소</BaseButton>
                            </div>
                            <div class="overflow-auto">
                                <table class="w-full text-xs">
                                    <thead class="text-gray-400 sticky top-0 bg-white">
                                        <tr>
                                            <th class="w-8"></th><th class="text-left font-normal px-1">전표</th><th class="text-left font-normal px-1">반납일시</th>
                                            <th class="text-left font-normal px-1">자재</th><th class="text-left font-normal px-1">LOT</th>
                                            <th class="text-right font-normal px-1">수량</th><th class="text-center font-normal px-1">상태</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in THIN_ROWS" :key="r.id" class="border-t border-gray-100"
                                            :class="[r.status === 'CANCELED' ? 'text-gray-400 line-through' : '', r.focus ? 'bg-amber-50' : '']">
                                            <td><input v-if="r.status === 'DONE'" type="checkbox" class="row-check" :value="r.id" v-model="thinChecked" /></td>
                                            <td class="px-1 py-0.5">{{ r.txn_no }}</td>
                                            <td class="px-1">{{ r.at }}</td>
                                            <td class="px-1">{{ r.item }}</td>
                                            <td class="px-1">{{ r.lot }}</td>
                                            <td class="px-1 text-right">{{ fmtQty(r.qty, 'EA') }}</td>
                                            <td class="px-1 text-center">{{ r.status === 'CANCELED' ? '취소' : '반납' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <table class="w-full text-xs border-t border-gray-200">
                            <tbody><tr><td class="px-2 py-2 text-center text-gray-400">반납 이력이 없습니다. ( 빈 목록일 때 )</td></tr></tbody>
                        </table>
                    </div>
                </ShowcaseCard>
            </section>

            <!-- 3. 모달 -->
            <section id="modal" class="scroll-mt-4">
                <SectionHead title="등록 · 수정 모달" note="칸 grid 4열 · 라벨 위 · 필수 * · 오류는 칸 밑 · 아래 버튼 줄 고정 ( md §6 )" />
                <div class="grid gap-4 md:grid-cols-2">
                    <ShowcaseCard title="크기 — md 칸 몇 개 / lg 보통 / xl 탭 · 줄 목록" :code="code.modal">
                        <BaseButton variant="secondary" @click="openDemoModal('md')">md 600</BaseButton>
                        <BaseButton variant="secondary" @click="openDemoModal('lg')">lg 800</BaseButton>
                        <BaseButton variant="secondary" @click="openDemoModal('xl')">xl 1140</BaseButton>
                    </ShowcaseCard>
                    <ShowcaseCard title="칸 · 라벨 · 필수 · 오류" :code="code.field">
                        <div class="w-full grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div>
                                <label class="block text-gray-500 font-medium mb-1">품번 <span class="text-red-500">*</span></label>
                                <BaseInput v-model="fieldDemo" class="w-full" />
                                <p class="text-xs text-red-500 mt-0.5">이미 있는 품번입니다.</p>
                            </div>
                            <div>
                                <label class="block text-gray-500 font-medium mb-1">비고</label>
                                <BaseInput class="w-full" disabled placeholder="읽기 전용이면 끈다" />
                            </div>
                        </div>
                    </ShowcaseCard>
                </div>
            </section>

            <!-- 4. 알림 · 확인 -->
            <section id="feedback" class="scroll-mt-4">
                <SectionHead title="알림 · 확인" note="처리 결과는 제목 줄 문구 ( useFlash ), 되돌리기 어려운 동작 전에는 확인창 ( md §7 )" />
                <div class="grid gap-4 md:grid-cols-2">
                    <ShowcaseCard title="제목 줄 알림 — 이 화면 맨 위에 뜬다" :code="code.flash">
                        <BaseButton variant="secondary" @click="showFlash('저장했습니다.')">성공</BaseButton>
                        <BaseButton variant="secondary" @click="showFlash('출고했습니다.', false, ['재고가 계산 소요보다 적습니다.'])">성공 + 경고</BaseButton>
                        <BaseButton variant="secondary" @click="showFlash('권한이 없습니다.', true)">오류</BaseButton>
                    </ShowcaseCard>
                    <ShowcaseCard title="확인창 — 무엇이 어떻게 되는지 쓴다" :code="code.confirm">
                        <BaseButton variant="danger" @click="confirmDemo">출고 취소</BaseButton>
                    </ShowcaseCard>
                </div>
            </section>

            <!-- 5. 상태 색 -->
            <section id="status" class="scroll-mt-4">
                <SectionHead title="상태 색" note="값 · 이름 · 색은 모듈의 *Codes.js 한 곳에 — 화면에 색을 직접 쓰지 않는다 ( md §7 )" />
                <ShowcaseCard title="뜻별 색" :code="code.status">
                    <div v-for="t in STATUS_TONES" :key="t.color" class="flex items-center gap-2 pr-4">
                        <span class="font-semibold text-sm" :style="{ color: t.color }">{{ t.label }}</span>
                        <span class="text-xs text-gray-400">{{ t.meaning }} · {{ t.color }}</span>
                    </div>
                </ShowcaseCard>
            </section>

            <!-- 5-1. 색 팔레트 -->
            <section id="palette" class="scroll-mt-4">
                <SectionHead title="색 팔레트" note="Tailwind 기본 5계열만 · HEX 를 손으로 치지 않는다 — 클래스 / theme() / @core/Utils/colors ( md §7-1 ) · 칸을 누르면 이름 복사" />
                <ShowcaseCard title="쓰는 색 전부" :code="code.palette">
                    <div class="w-full space-y-4">
                        <div v-for="f in PALETTE" :key="f.family">
                            <p class="text-xs text-gray-500 mb-1.5"><b class="text-gray-700">{{ f.label }}</b> · {{ f.note }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="c in f.colors" :key="c.key" type="button" :title="`${c.key} 복사`"
                                    class="w-[112px] text-left rounded-md border border-gray-200 overflow-hidden hover:border-gray-400 transition-colors"
                                    @click="copyColor(c.key)">
                                    <span class="block h-10 border-b border-gray-200" :style="{ background: c.hex }"></span>
                                    <span class="block px-2 py-1 leading-tight">
                                        <span class="block text-xs font-semibold text-gray-700">{{ c.tw }}</span>
                                        <span class="block text-[11px] text-gray-400 font-mono">{{ c.hex }}</span>
                                        <span class="block text-[11px] text-blue-700 font-mono">{{ c.key }}</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </ShowcaseCard>
            </section>

            <!-- 6. 숫자 · 날짜 -->
            <section id="format" class="scroll-mt-4">
                <SectionHead title="숫자 · 날짜" note="천 단위 · 소수 최대 6 자리 · 오른쪽 정렬 / 해당 없음은 — ( md §8 )" />
                <ShowcaseCard title="@core/Utils/format" :code="code.format">
                    <table class="text-sm">
                        <tbody>
                            <tr v-for="f in FORMAT_DEMO" :key="f.call" class="border-b last:border-b-0">
                                <td class="pr-6 py-1 font-mono text-xs text-gray-500">{{ f.call }}</td>
                                <td class="py-1 text-right font-semibold">{{ f.value }}</td>
                            </tr>
                        </tbody>
                    </table>
                </ShowcaseCard>
            </section>

            <!-- 6-1. 날짜 · 일시 선택 -->
            <section id="picker" class="scroll-mt-4">
                <SectionHead title="날짜 · 일시 선택" note="BaseDatePicker — 모든 날짜 · 일시 입력. 값은 문자열 2026-10-29 / 2026-10-29T15:00 ( md §8 )" />
                <div class="grid gap-4 md:grid-cols-2">
                    <ShowcaseCard title="날짜 · 일시" :code="code.picker">
                        <div class="w-full grid grid-cols-2 gap-3 text-sm">
                            <div><label class="block text-gray-500 font-medium mb-1">날짜</label><BaseDatePicker v-model="pick.d" /></div>
                            <div><label class="block text-gray-500 font-medium mb-1">일시</label><BaseDatePicker v-model="pick.dt" mode="datetime" /></div>
                            <p class="col-span-2 text-xs text-gray-400 font-mono">값 : {{ pick.d || '( 빈 값 )' }} · {{ pick.dt || '( 빈 값 )' }}</p>
                        </div>
                    </ShowcaseCard>
                    <ShowcaseCard title="작게 ( 검색바 · 패널 머리 — size sm )" :code="code.pickerSm">
                        <BaseDatePicker v-model="pick.from" size="sm" />
                        <span class="text-gray-400">~</span>
                        <BaseDatePicker v-model="pick.to" size="sm" />
                        <BaseDatePicker v-model="pick.dt2" mode="datetime" size="sm" />
                    </ShowcaseCard>
                    <ShowcaseCard title="지우기 · 빈 값 안내 · 비활성" :code="code.pickerMore">
                        <div class="w-full grid grid-cols-3 gap-3">
                            <BaseDatePicker v-model="pick.empty" clearable placeholder="날짜 선택" />
                            <BaseDatePicker v-model="pick.emptyDt" mode="datetime" clearable placeholder="일시 선택" />
                            <BaseDatePicker v-model="pick.d" disabled />
                        </div>
                    </ShowcaseCard>
                </div>
            </section>

            <!-- 7. 부품 ( 예전 UI Kit ) -->
            <section id="parts" class="scroll-mt-4">
                <SectionHead title="부품" note="Select · Input · Checkbox · Button — props 는 docs/screens/01-공통컴포넌트.md" />
                <div class="grid gap-4 md:grid-cols-2">
                    <ShowcaseCard title="Select" :code="code.select">
                        <BaseSelect v-model="demoVal" :options="DEMO_OPTIONS" class="w-40" />
                        <BaseSelect v-model="emptyVal" :options="DEMO_OPTIONS" placeholder="선택하세요" class="w-40" />
                        <BaseSelect v-model="demoVal" :options="DEMO_OPTIONS" disabled class="w-40" />
                    </ShowcaseCard>
                    <ShowcaseCard title="Input" :code="code.input">
                        <BaseInput v-model="text" placeholder="값을 입력하세요" class="w-48" />
                        <BaseInput type="number" v-model="num" class="w-28 text-right" :select-on-focus="true" />
                        <BaseInput size="sm" v-model="text" placeholder="sm ( 검색바 )" class="w-36" />
                        <BaseInput v-model="text" placeholder="비활성" disabled class="w-36" />
                    </ShowcaseCard>
                    <ShowcaseCard title="Checkbox · Toggle" :code="code.checkbox">
                        <BaseCheckbox variant="checkbox" true-value="Y" false-value="N">사용</BaseCheckbox>
                        <BaseCheckbox variant="switch" true-value="Y" false-value="N">사용</BaseCheckbox>
                    </ShowcaseCard>
                    <ShowcaseCard title="Button — 보조 · + 등록 · 위험한 동작은 맨 오른쪽" :code="code.button">
                        <BaseButton variant="secondary">엑셀 올리기</BaseButton>
                        <BaseButton variant="primary">+ 등록</BaseButton>
                        <BaseButton variant="danger">삭제</BaseButton>
                        <BaseButton variant="primary" disabled>비활성</BaseButton>
                    </ShowcaseCard>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, h } from 'vue';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import ShowcaseCard from '@core/Components/Base/ShowcaseCard.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import StyleGuideDemoForm from '@core/Components/Dev/StyleGuideDemoForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { useFlash } from '@core/Composables/useFlash';
import { fmtNum, fmtQty, dash } from '@core/Utils/format';
import { STATUS_COLOR, COLORS, CHART } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });

const demoGrid = ref(null);   // 예시 목록 그리드 — 열 설정 · 엑셀

const SECTIONS = [
    { id: 'layout',   label: '화면 틀' },
    { id: 'split',    label: '좌우 분할' },
    { id: 'thin',     label: '얇은 목록' },
    { id: 'modal',    label: '모달' },
    { id: 'feedback', label: '알림 · 확인' },
    { id: 'status',   label: '상태 색' },
    { id: 'palette',  label: '색 팔레트' },
    { id: 'format',   label: '숫자 · 날짜' },
    { id: 'picker',   label: '날짜 선택' },
    { id: 'parts',    label: '부품' },
];

// 구역 머리
const SectionHead = (p) => h('div', { class: 'flex items-baseline gap-3 mb-3' }, [
    h('h3', { class: 'text-base font-bold text-gray-800 tracking-tight' }, p.title),
    h('span', { class: 'text-xs text-gray-400' }, p.note),
]);
SectionHead.props = ['title', 'note'];

const modalStore = useModalStore();
const { flash, showFlash } = useFlash();
const { flash: demoFlash, showFlash: showDemoFlash } = useFlash();

// ---- 1. 화면 틀 ( 예시 데이터 · 서버 대신 여기서 거르고 자른다 ) ----
const KINDS = { RAW: '원자재', FINISHED: '완제품' };
const KIND_FILTER = [{ value: '', label: '구분 전체' }, ...Object.entries(KINDS).map(([value, label]) => ({ value, label }))];
const ALL_ROWS = Array.from({ length: 37 }, (_, i) => ({
    code: `IT-${String(i + 1).padStart(3, '0')}`,
    name: `예시 품목 ${i + 1}`,
    kind: i % 3 ? 'RAW' : 'FINISHED',
    qty: (i + 1) * 125.5,
    unit: 'EA',
    status: ['WAIT', 'RUN', 'DONE', 'CANCELED'][i % 4],
}));
const columns = [
    { header: '품번', name: 'code', width: 100, sortable: true, className: 'cursor-pointer' },
    { header: '품명', name: 'name', minWidth: 160, className: 'cursor-pointer', escapeHTML: true },
    { header: '구분', name: 'kind', width: 80, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => KINDS[value] ?? '' },
    { header: '수량', name: 'qty', width: 110, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmtQty(row.qty, row.unit) },
    { header: '상태', name: 'status', width: 70, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => { const t = STATUS_BY_VALUE[value]; return t ? `<span style="color:${t.color};font-weight:600">${t.label}</span>` : ''; } },
];
const DEMO_FIELDS = [{ value: 'code', label: '품번' }, { value: 'name', label: '품명' }];   // 검색 조건 ( 통합검색은 SearchBar 가 붙인다 )
const search  = reactive({ date_basis: 'created', from: '', to: '', keyword: '', kind: '' });
const paging  = reactive({ page: 1, perPage: 20, total: 0 });
const found   = ref([]);
const checked = ref([]);
const pageRows = computed(() => found.value.slice((paging.page - 1) * paging.perPage, paging.page * paging.perPage));
function runSearch() {
    const kw = search.keyword.trim();
    const keys   = search.search_field ? [search.search_field] : ['code', 'name'];
    found.value  = ALL_ROWS.filter(r => (!kw || keys.some(k => r[k].includes(kw))) && (!search.kind || r.kind === search.kind));
    paging.page  = 1;
    paging.total = found.value.length;
}
function onPage({ page, perPage }) { paging.page = page; paging.perPage = perPage; }

function openDemoModal(size) {
    modalStore.open(StyleGuideDemoForm, { modalTitle: `품목 등록 ( 예시 · ${size} )` }, { size });
}

// ---- 2. 좌우 분할 ----
const SPLIT_ROWS = Array.from({ length: 14 }, (_, i) => ({ no: `WO-261028-${String(i + 1).padStart(3, '0')}`, item: `PN-${i + 1}`, qty: (i + 1) * 10 }));
const picked = ref(null);

// ---- 2-1. 얇은 목록 ----
const THIN_ROWS = [
    { id: 3, txn_no: 'RT-261029-003', at: '2026-10-29 15:40', item: 'MIT-01 자재-1', lot: 'L-001', qty: 5,  status: 'DONE', focus: true },
    { id: 2, txn_no: 'RT-261029-002', at: '2026-10-29 14:20', item: 'MIT-01 자재-1', lot: 'L-001', qty: 15, status: 'DONE' },
    { id: 1, txn_no: 'RT-261029-001', at: '2026-10-29 10:10', item: 'MIT-02 자재-2', lot: 'L-007', qty: 10, status: 'CANCELED' },
];
const thinChecked = ref([]);

// ---- 3. 모달 ----
const fieldDemo = ref('IT-001');

// ---- 4. 확인 ----
async function confirmDemo() {
    const r = await showAlert('출고 취소', '3줄( MIT-01, MIT-02, MIT-03 )의 출고를 취소합니다.\n자재는 출고한 창고로 돌아갑니다.', 'warning', 'confirm');
    showFlash(r.isConfirmed ? '3줄의 출고를 취소했습니다. ( 예시 )' : '취소하지 않았습니다.');
}

// ---- 5. 상태 색 ( md §7 표 ) ----
const STATUS_TONES = [
    { value: 'WAIT',     label: '대기',     meaning: '시작 전',       color: STATUS_COLOR.idle },
    { value: 'RUN',      label: '진행',     meaning: '진행 · 확정',   color: STATUS_COLOR.active },
    { value: 'DONE',     label: '완료',     meaning: '끝남',          color: STATUS_COLOR.done },
    { value: 'HOLD',     label: '검사대기', meaning: '손이 필요함',   color: STATUS_COLOR.attention },
    { value: 'CANCELED', label: '취소',     meaning: '취소 · 미사용', color: STATUS_COLOR.off },
];
const STATUS_BY_VALUE = Object.fromEntries(STATUS_TONES.map(t => [t.value, t]));

// ---- 5-1. 색 팔레트 ( 값은 @core/Utils/colors 그대로 — 여기서 HEX 를 다시 쓰지 않는다 ) ----
const FAMILIES = [
    { family: 'gray',  label: '회색 gray',   note: '글자 · 배경 · 테두리 · 시작 전( 500 ) · 취소( 400 )' },
    { family: 'blue',  label: '파랑 blue',   note: '주색 · 진행 · 확정( 600 ) · 선택 · 강조 수치( 700 )' },
    { family: 'red',   label: '빨강 red',    note: '오류 · 필수 *( 500 ) · 불합격 · 불량 수치( 600 )' },
    { family: 'amber', label: '주황 amber',  note: '손이 필요함 · 검사대기 · 경고( 600 )' },
    { family: 'green', label: '초록 green',  note: '끝남 · 완료 · 성공 알림( 600 )' },
    { family: 'sky',   label: '예외 sky',    note: '그리드 고른 행( row-selected ) 하나만' },
];
const PALETTE = [
    ...FAMILIES.map(f => ({
        ...f,
        colors: Object.entries(COLORS)
            .map(([key, hex]) => ({ key, hex, m: key.match(/^([a-z]+)(\d+)$/) }))
            .filter(c => c.m && c.m[1] === f.family)
            .map(c => ({ key: `COLORS.${c.key}`, hex: c.hex, tw: `${c.m[1]}-${c.m[2]}` })),
    })),
    { family: 'chart', label: '차트 CHART', note: '차트 계열색 — 화면 상태색과 섞지 않는다 ( 일부러 팔레트 밖 )',
      colors: Object.entries(CHART).map(([key, hex]) => ({ key: `CHART.${key}`, hex, tw: key })) },
];
async function copyColor(key) {
    try {
        await navigator.clipboard.writeText(key);
        showFlash(`${key} 복사했습니다.`);
    } catch (e) {
        showFlash('복사하지 못했습니다.', true);
    }
}

// ---- 6. 숫자 ----
const FORMAT_DEMO = [
    { call: 'fmtNum(1234567.5)',       value: fmtNum(1234567.5) },
    { call: 'fmtNum(0.1234567)',       value: fmtNum(0.1234567) },
    { call: "fmtQty(1200, 'EA')",      value: fmtQty(1200, 'EA') },
    { call: 'dash(null)',              value: dash(null) },
    { call: 'dash(0)',                 value: dash(0) },
];

// ---- 6-1. 날짜 선택 ----
const today = new Date();
const p2 = (n) => String(n).padStart(2, '0');
const ymd = `${today.getFullYear()}-${p2(today.getMonth() + 1)}-${p2(today.getDate())}`;
const pick = reactive({ d: ymd, dt: `${ymd}T${p2(today.getHours())}:${p2(today.getMinutes())}`, from: ymd, to: ymd, dt2: `${ymd}T09:00`, empty: '', emptyDt: '' });

// ---- 7. 부품 ----
const DEMO_OPTIONS = [{ value: 'a', label: '옵션 A' }, { value: 'b', label: '옵션 B' }, { value: 'c', label: '옵션 C' }];
const demoVal  = ref('a');
const emptyVal = ref('');
const text     = ref('');
const num      = ref(0);

// ---- 복사용 코드 ----
const code = {
    layout: `<template>
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
        <SearchBar v-model="search" :fields="SEARCH_FIELDS" :loading="loading" @search="loadFirstPage">
            <BaseSelect v-model="search.kind" :options="KIND_FILTER" class="w-32" />
        </SearchBar>

        <!-- ③ 본문 : 목록 박스 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">품목 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <!-- 목록 머리 오른쪽 : 열 설정 + 엑셀 ( 메인 목록 그리드는 기본으로 둘 다 ) -->
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="품목목록" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="mainGrid" setting-key="main" class="flex-1" :data="rows" :columns="columns" :options="{ rowHeaders: 'checkbox' }" :pagination="paging"
                    empty-text="조건에 맞는 품목이 없습니다."
                    @row-click="openModal" @select="sel => checkedRows = sel" @page="onPage" @sort="onSort" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { fmtQty } from '@core/Utils/format';
const { flash, showFlash } = useFlash();
const page      = usePage();
const menuCode  = page.props.menuCode;
const canWrite  = computed(() => page.props.permissions?.[menuCode]?.can_write === true);
const canDelete = computed(() => page.props.permissions?.[menuCode]?.can_delete === true);
<\/script>`,

    split: `<div class="flex-1 min-h-0 flex gap-3">
    <!-- 왼쪽 : 고르는 목록 ( 그리드 460 / 짧은 목록 · 트리 240 ) -->
    <div class="w-[460px] shrink-0 flex flex-col border border-gray-200 overflow-hidden">
        <div class="px-3 py-2 bg-gray-50/70 shrink-0 text-sm font-semibold text-gray-600">작업지시 · <span class="text-xs text-gray-400">{{ n }} 건</span></div>
        <div class="flex-1 min-h-0 flex flex-col"><DataGrid class="flex-1" ... @row-click="select" /></div>
    </div>
    <!-- 오른쪽 : 고른 것의 상세 -->
    <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
        <div v-if="!current" class="flex-1 flex items-center justify-center text-sm text-gray-400">왼쪽에서 작업지시를 고르세요.</div>
        <template v-else>
            <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3"> 이름 · 요약 · 버튼 </div>
            <div class="flex-1 min-h-0 overflow-y-auto p-3 space-y-4"> 내용 </div>
        </template>
    </div>
</div>`,

    thin: `<!-- 패널 아래에 붙는 짧은 목록 : 머리( 제목 · 건수 · 동작 ) + 표( text-xs · 머리줄 sticky ) -->
<div class="shrink-0 border-t border-gray-200 max-h-56 flex flex-col">
    <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
        <span class="text-sm font-semibold text-gray-600">반납 이력 <span class="text-xs text-gray-400">{{ rows.length }} 줄</span></span>
        <div class="flex-1"></div>
        <BaseButton v-if="canCancel" variant="danger" :disabled="!checked.length" @click="cancelChecked">반납 취소</BaseButton>
    </div>
    <div class="overflow-auto">
        <table class="w-full text-xs">
            <thead class="text-gray-400 sticky top-0 bg-white">
                <tr><th class="w-8"></th><th class="text-left font-normal px-1">전표</th><th class="text-right font-normal px-1">수량</th></tr>
            </thead>
            <tbody>
                <tr v-if="!rows.length"><td colspan="3" class="px-2 py-2 text-center text-gray-400">반납 이력이 없습니다.</td></tr>
                <tr v-for="r in rows" :key="r.id" class="border-t border-gray-100" :class="r.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                    <!-- 체크박스는 row-check ( 16px · 칸 가운데 ). 체크할 수 없는 줄은 그리지 않는다 -->
                    <td><input v-if="canCancel && r.status === 'DONE'" type="checkbox" class="row-check" :value="r.id" v-model="checked" /></td>
                    <td class="px-1 py-0.5">{{ r.txn_no }}</td>
                    <td class="px-1 text-right">{{ fmtQty(r.qty, r.spec_unit) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>`,

    modal: `// 크기 : md 칸 몇 개 / lg 보통 등록 / xl 탭 · 줄 목록이 있는 큰 등록
modalStore.open(ItemForm, { modalTitle: isEdit ? '품목 수정' : '품목 등록', item, onSaved }, { size: 'lg' });

<!-- 폼 컴포넌트 : 오류 → 탭 머리 → 내용( 스크롤 ) → 아래 버튼 줄 -->
<div class="mt-3 text-sm flex flex-col min-h-0">
    <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line shrink-0">{{ errorMsg }}</div>
    <div class="flex-1 min-h-0 overflow-y-auto pr-1">
        <div class="grid grid-cols-4 gap-x-4 gap-y-3"> 칸들 </div>
    </div>
    <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
        <BaseButton v-if="canDelete && isEdit" variant="danger" @click="remove">삭제</BaseButton>
        <div class="flex-1"></div>
        <BaseButton variant="secondary" @click="$emit('close')">{{ canWrite ? '취소' : '닫기' }}</BaseButton>
        <BaseButton v-if="canWrite" variant="primary" :disabled="saving" @click="save">{{ saving ? '저장 중…' : (isEdit ? '수정' : '등록') }}</BaseButton>
    </div>
</div>`,

    field: `<div>
    <label class="block text-gray-500 font-medium mb-1">품번 <span class="text-red-500">*</span></label>
    <BaseInput v-model="form.item_code" class="w-full" :disabled="!canWrite" />
    <p v-if="errors.item_code" class="text-xs text-red-500 mt-0.5">{{ errors.item_code[0] }}</p>
</div>`,

    flash: `import { useFlash, failMsg } from '@core/Composables/useFlash';
const { flash, showFlash } = useFlash();

showFlash('저장했습니다.');
showFlash('출고했습니다.', false, data.warnings);   // 경고는 ⚠ 줄로
showFlash(failMsg(e), true);                        // 422 서버 문구 · 403 권한 · 그 밖

<!-- 제목 줄 -->
<span v-if="flash.text" class="text-sm whitespace-pre-line" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>`,

    confirm: `import { showAlert } from '@core/Utils/message';

const r = await showAlert('출고 취소', \`\${n}줄( \${names} )의 출고를 취소합니다.\\n자재는 출고한 창고로 돌아갑니다.\`, 'warning', 'confirm');
if (!r.isConfirmed) return;`,

    palette: `<!-- 화면 template : 클래스 -->
<span class="text-gray-400">안내</span>  <div class="bg-blue-50 border border-blue-200">…</div>

/* CSS · <style> : theme() — 빌드 때 값으로 바뀐다 */
.box { color: theme('colors.gray.700'); border-color: theme('colors.gray.300'); }
.box:focus { box-shadow: 0 0 0 2px theme('colors.gray.500 / 12%'); }

// JS ( 그리드 formatter · TUI 테마 · 차트 ) : @core/Utils/colors
import { COLORS, STATUS_COLOR, CHART } from '@core/Utils/colors';
formatter: ({ value }) => \`<span style="color:\${COLORS.red600}">\${value}</span>\`
{ value: 'DONE', label: '완료', color: STATUS_COLOR.done }   // *Codes.js 는 뜻 이름

// 표에 없는 단계가 필요하면 colors.js 와 md §7-1 표에 같이 올리고 쓴다`,

    status: `// 모듈의 *Codes.js — 예 : Components/Production/productionCodes.js
import { STATUS_COLOR } from '@core/Utils/colors';   // 색은 이름으로 — HEX 를 치지 않는다 ( §7-1 )

export const WO_STATUSES = [
    { value: 'WAIT',     label: '대기', color: STATUS_COLOR.idle },
    { value: 'RUN',      label: '진행', color: STATUS_COLOR.active },
    { value: 'DONE',     label: '완료', color: STATUS_COLOR.done },
    { value: 'CANCELED', label: '취소', color: STATUS_COLOR.off },
];
// 그리드 열
{ header: '상태', name: 'status', width: 70, align: 'center',
  formatter: ({ value }) => \`<span style="color:\${woStatusColor(value)};font-weight:600">\${woStatusLabel(value)}</span>\` }`,

    format: `import { fmtNum, fmtQty, dash } from '@core/Utils/format';

fmtNum(1234567.5)     // 1,234,567.5
fmtQty(1200, 'EA')    // 1,200 EA
dash(null)            // —   ( 해당 없음 — 실제 0 과 구분 )

// 그리드 숫자 열
{ header: '수량', name: 'qty', width: 110, align: 'right', formatter: ({ row }) => fmtQty(row.qty, row.unit) }`,

    picker: `import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';

<!-- 값은 지금과 같은 문자열 : "2026-10-29" / "2026-10-29T15:00" -->
<BaseDatePicker v-model="form.date" />
<BaseDatePicker v-model="form.at" mode="datetime" />`,

    pickerSm: `<BaseDatePicker v-model="search.from" size="sm" />
<BaseDatePicker v-model="search.to" size="sm" />
<BaseDatePicker v-model="form.occurred_at" mode="datetime" size="sm" />`,

    pickerMore: `<BaseDatePicker v-model="form.due" clearable placeholder="날짜 선택" />
<BaseDatePicker v-model="form.at" mode="datetime" clearable placeholder="일시 선택" />
<BaseDatePicker v-model="form.date" :disabled="!canWrite" />`,

    select: `<BaseSelect v-model="value" :options="options" class="w-40" />
<BaseSelect v-model="value" :options="options" placeholder="선택하세요" class="w-40" />
<BaseSelect v-model="value" :options="options" disabled class="w-40" />
<!-- 검색바 안에서는 size="sm" -->`,

    input: `<BaseInput v-model="text" placeholder="값을 입력하세요" class="w-48" />
<BaseInput type="number" v-model="num" class="w-28 text-right" :select-on-focus="true" />
<BaseInput size="sm" v-model="text" class="w-36" />   <!-- 검색바 안 -->
<BaseInput v-model="text" disabled class="w-36" />`,

    checkbox: `<BaseCheckbox variant="checkbox" true-value="Y" false-value="N">사용</BaseCheckbox>
<BaseCheckbox variant="switch" true-value="Y" false-value="N">사용</BaseCheckbox>`,

    button: `<!-- 순서 : 보조 → + 등록 → 위험한 동작( 맨 오른쪽 ). 권한이 없으면 v-if, 지금 못 하면 :disabled -->
<BaseButton variant="secondary">엑셀 올리기</BaseButton>
<BaseButton v-if="canWrite" variant="primary">+ 등록</BaseButton>
<BaseButton v-if="canDelete" variant="danger" :disabled="!checkedRows.length">삭제</BaseButton>`,
};
</script>
