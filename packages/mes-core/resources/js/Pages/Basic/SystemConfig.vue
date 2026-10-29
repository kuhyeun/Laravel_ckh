<!-- 시스템 환경설정 · 정책 설정 / 권한 레벨 0 · 1
     - 화면은 정책 카탈로그( system_config 정의 )로 그린다 — 항목 추가 = src/config/system_config.php 한 줄 + 시더
     - 관리자 설정 [ admin ] : 업체가 바꾸는 업무 규칙 · 앱 설정
       시스템 설정 [ system ] : 기능 스위치 · 시스템관리자 전용 규칙 → 시스템 계정만 수정, 관리자에게는 읽기 전용
     - 기본값을 따르는 항목은 "기본값", 바꾼 항목은 "기본값과 다름" + [ 기본값으로 ]
     - 선행 설정이 꺼져 있으면 항목을 숨긴다 ( 예: LOT관리 사용이 꺼지면 출하 LOT 숨김 )
     - 저장은 바뀐 항목만. 검증 · 이력은 서버( PolicyService )
     - 검색 : '/' 포커스, Enter 로 다음 결과, 강조 -->

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
            <BaseButton variant="primary" :disabled="saving || !canWrite || !dirtyCount" @click="saveConfig">
                저장<span v-if="dirtyCount"> ( {{ dirtyCount }} )</span>
            </BaseButton>
        </div>

        <!-- 설정 조합 경고 ( 서버 계산, 막지 않음 ) -->
        <div v-for="w in warnings" :key="w" class="mt-3 px-3 py-2 text-sm rounded border border-amber-200 bg-amber-50 text-amber-700 shrink-0">⚠ {{ w }}</div>

        <!-- 설정 영역 ( 이 안에서만 스크롤 ) -->
        <div class="flex-1 min-h-0 overflow-y-auto pt-4 pr-1">
            <div class="setting-area">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.groups.length" class="mb-6">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-base font-bold text-gray-800 tracking-tight">
                                {{ section.title }} <span class="text-gray-400 text-sm font-normal">[ {{ section.key }} ]</span>
                                <span v-if="section.key === 'system' && !isSystem" class="ml-2 text-xs font-normal text-gray-400">시스템 계정만 바꿀 수 있습니다</span>
                            </p>
                            <div class="flex gap-2">
                                <button type="button" class="text-xs px-2.5 py-1 border border-gray-300 rounded-md text-gray-600 hover:bg-gray-100 transition-colors" @click="collapseAll(section, true)">모두 접기</button>
                                <button type="button" class="text-xs px-2.5 py-1 border border-gray-300 rounded-md text-gray-600 hover:bg-gray-100 transition-colors" @click="collapseAll(section, false)">모두 펼치기</button>
                            </div>
                        </div>

                        <div v-for="group in section.groups" :key="group.id" class="config-group border border-gray-200 rounded-lg mb-3 overflow-hidden shadow-sm">
                            <div
                                class="config-group-header flex items-center justify-between px-4 py-2.5 bg-gray-50/70 hover:bg-gray-100/70 cursor-pointer select-none transition-colors"
                                @click="toggleGroup(group.id)"
                            >
                                <span class="text-[15px] font-semibold text-gray-700 tracking-tight">{{ group.title }}</span>
                                <span class="text-gray-400 text-xs transition-transform duration-300" :class="{ '-rotate-90': isCollapsed(group.id) }">&#9660;</span>
                            </div>

                            <div
                                class="grid transition-[grid-template-rows] duration-300 ease-out"
                                :style="{ gridTemplateRows: isCollapsed(group.id) ? '0fr' : '1fr' }"
                            >
                                <div class="min-h-0 overflow-hidden">
                                    <div class="config-group-body p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <template v-for="item in group.items" :key="item.id">
                                            <div
                                                v-if="isShown(item)"
                                                :id="item.id"
                                                class="config-item p-4 rounded-lg border transition-colors"
                                                :class="highlightId === item.id ? 'bg-yellow-50 border-yellow-300' : 'border-gray-200 hover:border-gray-300'"
                                            >
                                                <div class="flex items-start gap-2">
                                                    <div class="config-title flex-1 text-[15px] font-semibold text-gray-800 tracking-tight">{{ item.title }}</div>
                                                    <span v-if="isDirty(item)" class="shrink-0 text-[11px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-600">변경됨</span>
                                                    <span v-else-if="differsFromDefault(item)" class="shrink-0 text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">기본값과 다름</span>
                                                    <span v-else class="shrink-0 text-[11px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500">기본값</span>
                                                </div>
                                                <p v-if="item.desc" class="config-desc text-xs leading-relaxed text-gray-500 mt-1">{{ item.desc }}</p>

                                                <!-- 수정 권한이 없으면 입력칸 전체를 읽기 전용으로 ( fieldset disabled ) -->
                                                <fieldset class="config-control mt-3" :disabled="!item.editable || !canWrite">
                                                    <component :is="resolveField(item)" :item="item" :store="values" />
                                                </fieldset>

                                                <div class="mt-2 flex items-center gap-2 text-xs text-gray-400">
                                                    <span>기본값 : {{ defaultLabel(item) }}</span>
                                                    <button
                                                        v-if="item.editable && canWrite && differsFromDefault(item)"
                                                        type="button"
                                                        class="text-blue-600 hover:underline"
                                                        @click="resetToDefault(item)"
                                                    >기본값으로</button>
                                                    <span v-if="!item.editable" class="ml-auto">읽기 전용</span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
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
const isSystem = ref(false);   // 시스템 계정 여부 ( 서버가 알려 줌 )
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

// ---- 그룹 제목 : group_key → 표시 제목. 없으면 group_key 를 그대로 사용 ----
const GROUP_TITLES = {
    Common:     '공통',
    feature:    '기능 스위치',
    stock:      '재고 · 수불',
    plan:       '부족 계산',
    material:   '자재',
    shipment:   '출하',
    lot:        'LOT',
    closing:    '재고 마감',
    quality:    '품질',
    production: '생산',
    barcode:    '바코드 · 라벨',
    auth:       '인증',
    ui:         '화면 기본값',
};
const SECTION_TITLES = { admin: '관리자 설정', system: '시스템 설정' };

// ---- DB 에서 만든 설정 스키마(sections) 와 값 스토어(values) ----
const sections = ref([]);          // [{ key: admin|system, title, groups: [{ id, key, title, items }] }]
const values   = reactive({});     // 'group::key' => 현재 화면 값
const original = reactive({});     // 'group::key' => 불러온 값 ( 변경 판단 )
const resets   = reactive({});     // 'group::key' => true ( 기본값으로 되돌리기 → null 로 저장 )
const warnings = ref([]);

// 모든 그룹 ( 검색 · 접기용 )
const allGroups = computed(() => sections.value.flatMap(s => s.groups));

// DB row(items) → sections 구조로 변환 + values 채우기. 순서는 서버( 카탈로그 정렬 ) 그대로
function buildSections(items) {
    const secMap = {};

    for (const it of items) {
        const secKey = it.section ?? 'admin';
        const etc    = it.etc || {};
        const sec    = secMap[secKey] ??= { key: secKey, title: SECTION_TITLES[secKey] ?? secKey, groups: [], _map: {} };
        const group  = sec._map[it.group] ??= (() => {
            const g = { id: `${secKey}-${it.group}`, key: it.group, title: GROUP_TITLES[it.group] ?? it.group, items: [] };
            sec.groups.push(g);
            return g;
        })();

        const fieldKey = `${it.group}::${it.key}`;   // 값 스토어 키 ( '::' 규약 )

        group.items.push({
            id:           `cfg-${it.group}-${it.key}`.replace(/\./g, '_'),
            key:          fieldKey,
            policyKey:    `${it.group}.${it.key}`,
            type:         etc.type ?? 'text',
            title:        it.name || it.key,
            desc:         it.remark ?? etc.desc ?? '',
            options:      etc.options,      // select / radio / checkboxes
            trueValue:    etc.trueValue,    // switch
            falseValue:   etc.falseValue,
            component:    etc.component,    // custom
            editable:     it.editable === true,
            isDefault:    it.is_default === true,
            defaultValue: it.default_value ?? '',
            dependsOn:    it.depends_on_key ? { key: it.depends_on_key.replace('.', '::'), value: it.depends_on_value ?? 'Y' } : null,
        });

        values[fieldKey]   = it.value ?? '';
        original[fieldKey] = it.value ?? '';
        delete resets[fieldKey];
    }

    // admin 먼저, system 다음
    return ['admin', 'system'].map(k => secMap[k]).filter(Boolean).map(({ _map, ...s }) => s);
}

// 설정 조회 → sections/values 재구성
async function loadConfig() {
    const { data } = await window.axios.get(route('basic.config.datas'));
    sections.value = buildSections(data.items ?? []);
    // 화면에 없는 선행 설정 값 ( 관리자에게 숨긴 시스템 설정 ) — 노출 판단에만 쓰고 저장하지 않음
    for (const [policyKey, value] of Object.entries(data.depend_values ?? {})) {
        values[policyKey.replace('.', '::')] = value ?? '';
    }
    warnings.value = data.warnings ?? [];
    isSystem.value = data.is_system === true;
}

// ---- 표시 상태 ----
// 선행 설정이 맞지 않으면 숨김 ( 화면의 현재 값 기준이라 스위치를 끄면 바로 사라진다 )
function isShown(item) {
    if (!item.dependsOn) return true;
    return String(values[item.dependsOn.key] ?? '') === String(item.dependsOn.value);
}
const isDirty            = (item) => resets[item.key] || String(values[item.key] ?? '') !== String(original[item.key] ?? '');
const differsFromDefault = (item) => !resets[item.key] && String(values[item.key] ?? '') !== String(item.defaultValue ?? '');
// 숨겨진 항목( 선행 설정이 꺼짐 )의 변경은 보내지 않는다 — 서버가 선행 조건으로 거부하므로
const dirtyItems         = computed(() => allGroups.value.flatMap(g => g.items).filter(i => isShown(i) && isDirty(i)));
const dirtyCount         = computed(() => dirtyItems.value.length);

function defaultLabel(item) {
    const v = item.defaultValue;
    if (v === '' || v === null) return '( 없음 )';
    if (item.type === 'switch') return String(v) === String(item.trueValue ?? 'Y') ? '사용' : '미사용';
    return item.options?.find(o => String(o.value) === String(v))?.label ?? v;
}

// 기본값으로 되돌림 → 화면 값을 기본값으로 바꾸고, 저장 시 null( 기본값을 따르는 상태 )로 보낸다
function resetToDefault(item) {
    values[item.key] = item.defaultValue;
    if (!item.isDefault) resets[item.key] = true;
}

// ---- 접기 ----
const collapsed   = reactive({});
const isCollapsed = (id) => !!collapsed[id];
const toggleGroup = (id) => { collapsed[id] = !collapsed[id]; };
const collapseAll = (section, flag) => { for (const g of section.groups) collapsed[g.id] = flag; };

// ---- 검색 ----
const searchInput = ref(null);
const keyword     = ref('');
const matches     = ref([]);   // 매칭된 item.id 목록
const cursor      = ref(-1);
const highlightId = ref(null);

// 항목의 검색 대상 텍스트 ( 제목 + 정책 키 )
function itemText(item) {
    return (item.title + ' ' + (item.policyKey || '')).toLowerCase();
}

function onSearchInput() {
    const word = keyword.value.trim().toLowerCase();
    highlightId.value = null;

    matches.value = [];
    if (word) {
        for (const group of allGroups.value) {
            for (const item of group.items) {
                if (isShown(item) && itemText(item).indexOf(word) !== -1) matches.value.push(item.id);
            }
        }
    }
    cursor.value = -1;
}

// Enter: 다음 매칭으로 이동 ( 마지막 다음은 처음으로 순환 )
function jumpNext() {
    if (!matches.value.length) return;

    cursor.value = (cursor.value + 1) % matches.value.length;
    const id = matches.value[cursor.value];

    // 매칭 항목이 속한 그룹이 접혀 있으면 펼친 뒤 스크롤
    const group = allGroups.value.find(g => g.items.some(it => it.id === id));
    if (group) collapsed[group.id] = false;

    nextTick(() => {
        const el = document.getElementById(id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        highlightId.value = id;
    });
}

const counterText = computed(() => {
    if (!matches.value.length) return keyword.value.trim() ? '결과 없음' : '';
    const pos = cursor.value < 0 ? '' : (cursor.value + 1) + ' / ';
    return '검색 ' + pos + matches.value.length + '건';
});

// '/' 키로 검색창 포커스
function onSlashKey(ev) {
    if (ev.key === '/' && document.activeElement !== searchInput.value) {
        ev.preventDefault();
        searchInput.value?.focus();
    }
}

const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text  = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 4000);
}

// 바뀐 항목만 [{ group, key, value }] 로 ( 되돌리기는 value = null )
function collectItems() {
    return dirtyItems.value.map(item => {
        const [g, k] = item.key.split('::');
        return { group: g, key: k, value: resets[item.key] ? null : String(values[item.key] ?? '') };
    });
}

async function saveConfig() {
    const items = collectItems();
    if (!items.length) return;

    saving.value = true;
    try {
        const { data } = await window.axios.post(route('basic.config.save'), { items });

        await loadConfig();   // 저장 후 최신값 재조회 → sections/values 재구성
        showFlash(data.changed ? `${data.changed}건 저장되었습니다` : '바뀐 값이 없습니다');
    } catch (e) {
        if (e.response?.status === 403)      showFlash('저장 권한이 없습니다', true);
        else if (e.response?.status === 422) showFlash(e.response.data?.message || '입력값을 확인하세요', true);
        else                                 showFlash('저장에 실패했습니다', true);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    document.addEventListener('keyup', onSlashKey);
    try {
        await loadConfig();
    } catch (e) {
        // 실패 시 빈 목록 유지
    }
});
onUnmounted(() => document.removeEventListener('keyup', onSlashKey));
</script>
