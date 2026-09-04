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

            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
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
            <BaseButton variant="primary" :disabled="saving || !canWrite" @click="saveConfig">저장</BaseButton>
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

                <div v-for="group in groups" :key="group.key" class="config-group border border-gray-200 rounded-lg mb-3 overflow-hidden shadow-sm">
                    <div
                        class="config-group-header flex items-center justify-between px-4 py-2.5 bg-gray-50/70 hover:bg-gray-100/70 cursor-pointer select-none transition-colors"
                        @click="toggleGroup(group.key)"
                    >
                        <span class="text-[15px] font-semibold text-gray-700 tracking-tight">{{ group.title }}</span>
                        <span class="text-gray-400 text-xs transition-transform duration-300" :class="{ '-rotate-90': isCollapsed(group.key) }">&#9660;</span>
                    </div>

                    <div
                        class="grid transition-[grid-template-rows] duration-300 ease-out"
                        :style="{ gridTemplateRows: isCollapsed(group.key) ? '0fr' : '1fr' }"
                    >
                        <div class="min-h-0 overflow-hidden">
                            <div class="config-group-body p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
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
                                        <component :is="resolveField(item)" :item="item" :store="values" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between mb-3">
                    <p class="text-base font-bold text-gray-800 tracking-tight">
                        시스템 설정 <span class="text-gray-400 text-sm font-normal">[ system ]</span>
                    </p>
                    <div class="flex gap-2">
                        <button type="button" class="text-xs px-2.5 py-1 border border-gray-300 rounded-md text-gray-600 hover:bg-gray-100 transition-colors" @click="collapseAll(true)">모두 접기</button>
                        <button type="button" class="text-xs px-2.5 py-1 border border-gray-300 rounded-md text-gray-600 hover:bg-gray-100 transition-colors" @click="collapseAll(false)">모두 펼치기</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import { reactive, ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

import BaseButton from '@core/Components/Base/BaseButton.vue';
import ConfigText from '@core/Components/Config/Fields/ConfigText.vue';
import ConfigSelect from '@core/Components/Config/Fields/ConfigSelect.vue';
import ConfigRadio from '@core/Components/Config/Fields/ConfigRadio.vue';
import ConfigSwitch from '@core/Components/Config/Fields/ConfigSwitch.vue';
import ConfigCheckboxes from '@core/Components/Config/Fields/ConfigCheckboxes.vue';
import ConfigMulti from '@core/Components/Config/Fields/ConfigMulti.vue';

defineOptions({
    layout: AppLayout
});

const page     = usePage();
const menuCode = page.props.menuCode;
const myPerm   = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite = computed(() => myPerm.value.can_write === true);
const saving   = ref(false);

// ---- 필드 타입 → 컴포넌트 레지스트리 ----
// 새 UI 유형이 필요하면 컴포넌트 하나 만들고 등록
const FIELD = {
    text:       ConfigText,
    number:     ConfigText,
    select:     ConfigSelect,
    radio:      ConfigRadio,
    switch:     ConfigSwitch,
    checkboxes: ConfigCheckboxes,
    multi:      ConfigMulti,
};

// 비정형(bespoke) 위젯용 탈출구 : item.type = 'custom', item.component = '<이름>'
// 예) customFields['LicenseMatrix'] = LicenseMatrix
const customFields = {};

// 스키마 item → 렌더할 컴포넌트 결정
function resolveField(item) {
    if (item.type === 'custom') {
        return customFields[item.component] || ConfigText;
    }
    return FIELD[item.type] || ConfigText;
}

// ---- 그룹 제목 ( 하드코딩 ) : group_key → 표시 제목. 없으면 group_key 를 그대로 사용 ----
const GROUP_TITLES = {
    Common: '사이트 설정',
};

// ---- DB 에서 만든 설정 스키마(groups) 와 값 스토어(values) ----
const groups = ref( [] );          // [{ key, title, items:[...] }]
const values = reactive( {} );     // 'group::key' => value

// DB row(items) → groups 구조로 변환 + values 채우기.
//   type 등 UI 메타는 row.etc(JSON) 에서 꺼낸다. 순서는 DB 순서 그대로.
function buildGroups( items ) {
    const map = {};   // group_key => { key, title, items: [] }

    for( const it of items ) {
        const g   = it.group;
        const etc = it.etc || {};

        if( !map[g] ) {
            map[g] = { key: g, title: GROUP_TITLES[g] ?? g, items: [] };
        }

        const fieldKey = `${g}::${it.key}`;   // 값 스토어 키 ( '::' 규약 )

        map[g].items.push({
            id:         `cfg-${g}-${it.key}`,
            key:        fieldKey,
            type:       etc.type ?? 'text',
            title:      it.name || it.key,
            desc:       it.remark ?? etc.desc ?? '',
            options:    etc.options,      // select / radio / checkboxes
            trueValue:  etc.trueValue,    // switch
            falseValue: etc.falseValue,
            component:  etc.component,     // custom
        });

        values[fieldKey] = it.value ?? '';
    }

    return Object.values( map );
}

// 설정 조회 → groups/values 재구성
async function loadConfig() {
    const { data } = await window.axios.get( route( 'basic.config.datas' ) );
    groups.value = buildGroups( data.items ?? [] );
}

const collapsed   = reactive( {} );
const isCollapsed = ( key ) => !!collapsed[key];
const toggleGroup = ( key ) => { collapsed[key] = !collapsed[key]; };
const collapseAll = ( flag ) => { for( const g of groups.value ) collapsed[g.key] = flag; };

const searchInput = ref( null );
const keyword     = ref( '' );
const matches     = ref( [] );   // 매칭된 item.id 목록
const cursor      = ref( -1 );
const highlightId = ref( null );

// 항목의 검색 대상 텍스트 ( 제목 + 필드 key )
function itemText( item ) {
    return ( item.title + ' ' + ( item.key || '' ) ).toLowerCase();
}

function onSearchInput() {
    const word = keyword.value.trim().toLowerCase();
    highlightId.value = null;

    matches.value = [];
    if( word ) {
        for( const group of groups.value ) {
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
    const group = groups.value.find( g => g.items.some( it => it.id === id ) );
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

const flash = reactive( { text: '', error: false } );
let flashTimer = null;
function showFlash( text, error = false ) {
    flash.text  = text;
    flash.error = error;
    clearTimeout( flashTimer );
    flashTimer = setTimeout( () => { flash.text = ''; }, 2500 );
}

// 스키마를 순회해 values 를 [{ group, key, value }] 로 변환 ( 'group::key' 규약을 쪼갬 )
function collectItems() {
    const items = [];
    for( const group of groups.value ) {
        for( const item of group.items ) {
            const [ g, k ] = item.key.split( '::' );
            items.push( { group: g, key: k, value: String( values[item.key] ?? '' ) } );
        }
    }
    return items;
}

async function saveConfig() {
    saving.value = true;
    try {
        await window.axios.post( route( 'basic.config.save' ), { items: collectItems() } );

        await loadConfig();   // 저장 후 최신값 재조회 → groups/values 재구성
        showFlash( '저장되었습니다' );
    } catch ( e ) {
        if( e.response?.status === 403 )      showFlash( '저장 권한이 없습니다', true );
        else if( e.response?.status === 422 ) showFlash( '입력값을 확인하세요', true );
        else                                  showFlash( '저장에 실패했습니다', true );
    } finally {
        saving.value = false;
    }
}

onMounted( async () => {
    document.addEventListener( 'keyup', onSlashKey );
    try {
        await loadConfig();
    } catch ( e ) {
        // 실패 시 빈 목록 유지
    }
} );
onUnmounted( () => document.removeEventListener( 'keyup', onSlashKey ) );
</script>
