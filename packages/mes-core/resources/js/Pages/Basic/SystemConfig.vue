<!-- 시스템 환경설정 / 권한 레벨 0 -->
<!-- new-mes 의 config_setting 화면을 Vue 로 재구성한 데모.
     - CONFIG_DATA(props.configData) 를 받아 화면에 뿌림
     - 설정 항목 UI 템플릿(text/number/select/switch/radio) 예시
     - 검색 시 해당 설정값으로 이동 ( '/' 포커스, Enter 순환, 강조 )
     저장/토스트/모달 등은 이번 범위에서 제외. -->

<template>
    <!-- main 높이를 채우는 flex 컬럼. 헤더는 고정, 목록만 내부 스크롤 → 부모 패딩을 건드리지 않음 -->
    <div class="config-setting-wrap flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 검색 바 ( 상단 고정 헤더 ) -->
        <div class="flex items-center gap-3 pb-3 border-b shrink-0">
            <h2 class="font-bold text-lg">시스템 환경설정</h2>

            <div class="flex-1"></div>

            <span class="text-sm text-gray-500 whitespace-nowrap">{{ counterText }}</span>
            <div class="relative">
                <input
                    ref="searchInput"
                    v-model="keyword"
                    type="text"
                    class="w-72 pl-3 pr-3 py-1.5 text-sm border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400"
                    placeholder="검색 후 Enter [ / 키로 입력 ]"
                    @input="onSearchInput"
                    @keydown.enter.prevent="jumpNext"
                />
            </div>
        </div>

        <!-- 설정 영역 ( 이 안에서만 스크롤 ) -->
        <div class="flex-1 min-h-0 overflow-y-auto pt-4 pr-1">
        <div class="setting-area">
            <div class="flex items-center justify-between mb-3">
                <p class="text-base font-bold text-gray-800 tracking-tight">
                    관리자 설정 <span class="text-gray-400 text-sm font-normal">[ admin ]</span>
                </p>
                <div class="flex gap-2">
                    <button type="button" class="text-xs px-2.5 py-1 border border-gray-300 rounded-md text-gray-600 hover:bg-gray-100 transition-colors" @click="collapseAll(true)">모두 접기</button>
                    <button type="button" class="text-xs px-2.5 py-1 border border-gray-300 rounded-md text-gray-600 hover:bg-gray-100 transition-colors" @click="collapseAll(false)">모두 펼치기</button>
                </div>
            </div>

            <!-- 그룹 반복 -->
            <div v-for="group in groups" :key="group.key" class="config-group border border-gray-200 rounded-lg mb-3 overflow-hidden shadow-sm">
                <div
                    class="config-group-header flex items-center justify-between px-4 py-2.5 bg-gray-50/70 hover:bg-gray-100/70 cursor-pointer select-none transition-colors"
                    @click="toggleGroup(group.key)"
                >
                    <span class="text-[15px] font-semibold text-gray-700 tracking-tight">{{ group.title }}</span>
                    <span class="text-gray-400 text-xs transition-transform duration-300" :class="{ '-rotate-90': isCollapsed(group.key) }">&#9660;</span>
                </div>

                <!-- 접기/펼치기 애니메이션: grid-rows 0fr↔1fr 로 높이를 부드럽게 전환 -->
                <div
                    class="grid transition-[grid-template-rows] duration-300 ease-out"
                    :style="{ gridTemplateRows: isCollapsed(group.key) ? '0fr' : '1fr' }"
                >
                <div class="min-h-0 overflow-hidden">
                <div class="config-group-body p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- 설정 항목 반복 -->
                    <div
                        v-for="item in group.items"
                        :key="item.id"
                        :id="item.id"
                        class="config-item p-4 rounded-lg border transition-colors"
                        :class="highlightId === item.id ? 'bg-yellow-50 border-yellow-300' : 'border-gray-200 hover:border-gray-300'"
                    >
                        <div class="config-title text-[15px] font-semibold text-gray-800 tracking-tight">{{ item.title }}</div>
                        <p v-if="item.desc" class="config-desc text-xs leading-relaxed text-gray-500 mt-1">{{ item.desc }}</p>

                        <div class="config-control mt-3">
                            <!-- 텍스트 / 숫자 -->
                            <input
                                v-if="item.type === 'text' || item.type === 'number'"
                                :type="item.type"
                                :min="item.min"
                                v-model="values[item.key]"
                                class="w-full max-w-xs px-2.5 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400/40 focus:border-blue-400 transition"
                                placeholder="값을 입력하세요"
                            />

                            <!-- 여러 줄 입력 -->
                            <div v-else-if="item.type === 'multi'" class="grid gap-2">
                                <div v-for="row in item.rows" :key="row.key" class="flex items-center gap-2">
                                    <label class="w-48 text-sm text-gray-600">{{ row.label }}</label>
                                    <input
                                        :type="row.inputType || 'text'"
                                        :min="row.min"
                                        v-model="values[row.key]"
                                        class="flex-1 max-w-xs px-2.5 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400/40 focus:border-blue-400 transition"
                                        placeholder="값을 입력하세요"
                                    />
                                </div>
                            </div>

                            <!-- 셀렉트 -->
                            <select
                                v-else-if="item.type === 'select'"
                                v-model="values[item.key]"
                                class="w-full max-w-xs px-2.5 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400/40 focus:border-blue-400 transition"
                            >
                                <option v-for="opt in item.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>

                            <!-- 스위치 ( 사용/미사용 ) -->
                            <label v-else-if="item.type === 'switch'" class="inline-flex items-center gap-2 cursor-pointer">
                                <span class="relative inline-block w-10 h-5">
                                    <input type="checkbox" class="peer sr-only" v-model="switchState[item.key]" />
                                    <span class="absolute inset-0 rounded-full bg-gray-300 peer-checked:bg-blue-500 transition-colors"></span>
                                    <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></span>
                                </span>
                                <span class="text-sm">{{ switchState[item.key] ? '사용' : '미사용' }}</span>
                            </label>

                            <!-- 라디오 -->
                            <div v-else-if="item.type === 'radio'" class="flex flex-wrap gap-4">
                                <label v-for="opt in item.options" :key="opt.value" class="inline-flex items-center gap-1.5 text-sm cursor-pointer">
                                    <input type="radio" :value="opt.value" v-model="values[item.key]" />
                                    {{ opt.label }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import { reactive, ref, computed, nextTick, onMounted, onUnmounted } from 'vue';

defineOptions({
    layout: AppLayout
});

// systemConfigData() 로 fetch 한 CONFIG_DATA ( { group: { name: value } } )
const configData = ref( {} );

// configData[group][name] 조회 ( 없으면 기본값 )
function confValue( group, name, fallback = '' ) {
    return configData.value?.[group]?.[name] ?? fallback;
}

// ---- 설정 스키마 ( 원본의 config_item 을 데이터로 정의. UI 유형별 대표 항목 ) ----
const groups = [
    {
        key: 'site',
        title: '사이트 설정',
        items: [
            {
                id: 'cfg-start_url', key: 'start_url::start_url',
                type: 'text', title: '시작 메뉴',
                desc: 'MES 로그인 진행 후 열리게 되는 첫 메뉴 링크를 설정할 수 있습니다.',
                default: ''
            },
            {
                id: 'cfg-search_date', key: null,
                type: 'multi', title: '검색일자 설정',
                desc: '메뉴에서 날짜 검색창의 기본 범위를 설정합니다.',
                rows: [
                    { key: 'search_date_range::search_date_range', label: '검색 일자범위 (단위: 일)', inputType: 'number', min: 0, default: '30' },
                    { key: 'search_date_range::search_date_base',  label: '기준일자 (현재일로부터 +)', inputType: 'number', min: 0, default: '7' }
                ]
            }
        ]
    },
    {
        key: 'production',
        title: '생산관리 설정',
        items: [
            {
                id: 'cfg-plan_auto', key: 'project_setting::plan_auto_project_yn',
                type: 'switch', title: '계획생산시 수주 자동등록',
                desc: '사용으로 설정한 경우 생산계획에서 계획생산을 등록하면 수주가 자동 등록됩니다.',
                trueValue: 'y',
                default: 'n'
            },
            {
                id: 'cfg-start_date_type', key: 'process_type::production_order_start_date_type',
                type: 'radio', title: '생산시작일 기본값',
                desc: '생산계획 등록 시 생산 시작일의 기본값을 설정할 수 있습니다.',
                options: [
                    { value: 'order_date', label: '수주일자' },
                    { value: 'request_due_date', label: '납기일' }
                ],
                default: 'order_date'
            }
        ]
    }
];

// ---- 값 상태 ( key => value ). switch 는 boolean 으로 별도 관리 ----
const values = reactive( {} );
const switchState = reactive( {} );

// 스키마 + configData 로 값 채우기 ( 초기엔 기본값, fetch 후 재적용 )
function syncValues() {
    for( const group of groups ) {
        for( const item of group.items ) {
            if( item.type === 'switch' ) {
                const [ g, n ] = item.key.split( '::' );
                switchState[item.key] = String( confValue( g, n, item.default ) ) === String( item.trueValue );
            } else if( item.type === 'multi' ) {
                for( const row of item.rows ) {
                    const [ g, n ] = row.key.split( '::' );
                    values[row.key] = confValue( g, n, row.default );
                }
            } else if( item.key ) {
                const [ g, n ] = item.key.split( '::' );
                values[item.key] = confValue( g, n, item.default );
            }
        }
    }
}
syncValues();

// ---- 그룹 접기 ----
const collapsed = reactive( {} );
const isCollapsed = ( key ) => !!collapsed[key];
const toggleGroup = ( key ) => { collapsed[key] = !collapsed[key]; };
const collapseAll = ( flag ) => { for( const g of groups ) collapsed[g.key] = flag; };

// ---- 검색 → 이동 ----
const searchInput = ref( null );
const keyword = ref( '' );
const matches = ref( [] );   // 매칭된 item.id 목록
const cursor = ref( -1 );
const highlightId = ref( null );

// 항목의 검색 대상 텍스트 ( 제목 + 필드 key )
function itemText( item ) {
    const keys = item.type === 'multi'
        ? item.rows.map( r => r.key ).join( ' ' )
        : ( item.key || '' );
    return ( item.title + ' ' + keys ).toLowerCase();
}

function onSearchInput() {
    const word = keyword.value.trim().toLowerCase();
    highlightId.value = null;

    matches.value = [];
    if( word ) {
        for( const group of groups ) {
            for( const item of group.items ) {
                if( itemText( item ).indexOf( word ) !== -1 ) matches.value.push( item.id );
            }
        }
    }
    cursor.value = -1;
}

// Enter: 다음 매칭으로 이동 ( 마지막 다음은 처음으로 순환 )
function jumpNext() {
    if( !matches.value.length ) return;

    cursor.value = ( cursor.value + 1 ) % matches.value.length;
    const id = matches.value[cursor.value];

    // 매칭 항목이 속한 그룹이 접혀 있으면 펼친 뒤 스크롤
    const group = groups.find( g => g.items.some( it => it.id === id ) );
    if( group ) collapsed[group.key] = false;

    nextTick( () => {
        const el = document.getElementById( id );
        if( el ) el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
        highlightId.value = id;
    } );
}

const counterText = computed( () => {
    if( !matches.value.length ) return keyword.value.trim() ? '결과 없음' : '';
    const pos = cursor.value < 0 ? '' : ( cursor.value + 1 ) + ' / ';
    return '검색 ' + pos + matches.value.length + '건';
} );

// '/' 키로 검색창 포커스
function onSlashKey( ev ) {
    if( ev.key === '/' && document.activeElement !== searchInput.value ) {
        ev.preventDefault();
        searchInput.value?.focus();
    }
}

onMounted( async () => {
    document.addEventListener( 'keyup', onSlashKey );
    try {
        const { data } = await window.axios.get( '/basic/systemConfig/data' );
        configData.value = data.configData ?? {};
        syncValues();
    } catch ( e ) {
        // 실패 시 기본값 유지
    }
} );
onUnmounted( () => document.removeEventListener( 'keyup', onSlashKey ) );
</script>
