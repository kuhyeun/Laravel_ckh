<!-- 메뉴 권한 표 ( 사용자권한관리 — 사용자 탭 · 템플릿 탭이 같이 쓴다 · 11-13 MemberAuth 에서 분리 )
     - rows : [{ menu_idx, menu_code, menu_name, parent_menu_code, url_path, _depth, cap_* , can_* , base_*? , is_override? }] — 부모 → 자식 순서
       ( 행 값을 그 자리에서 바꾼다 — 부모가 같은 객체를 들고 저장 )
     - 레벨 상한( cap_* )이 N 인 칸은 비활성 · 상위 토글 → 아래 메뉴 전부 · 켜면 상위도 켠다
     - base_* 가 있으면 그것과 다른 칸을 노랗게 ( 템플릿과 다른 개인 지정 )
     - 값이 바뀌면 change -->
<template>
    <table class="w-full min-w-[620px] table-fixed text-sm">
        <colgroup>
            <col><col class="w-16"><col class="w-28"><col class="w-28"><col class="w-24">
        </colgroup>
        <thead>
            <tr class="text-gray-500">
                <th :class="TH"></th>
                <th :class="[TH, headClass]" @click="toggleAll">전체</th>
                <th :class="[TH, headClass]" @click="toggleColumn('can_read')">노출(읽기)</th>
                <th :class="[TH, headClass]" @click="toggleColumn('can_write')">등록·수정</th>
                <th :class="[TH, headClass]" @click="toggleColumn('can_delete')">삭제</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.menu_idx" class="border-t first:border-t-0 last:border-0 transition-colors hover:bg-blue-50/70" :class="{ 'bg-gray-100': row._depth === 0 }">
                <td class="px-3 py-1.5 text-gray-700 truncate" :title="row.menu_name">
                    <span :style="{ paddingLeft: (row._depth * 16) + 'px' }" class="inline-flex items-center gap-1.5">
                        <span v-if="row._depth > 0" class="text-gray-300">└</span>
                        <span :class="row._depth === 0 ? 'font-semibold' : ''">{{ row.menu_name }}</span>
                        <span class="text-[11px] text-gray-400">[{{ row.menu_code }}]</span>
                        <span v-if="row.url_path" class="text-[11px] text-gray-300">{{ row.url_path }}</span>
                        <span v-if="differs(row)" class="text-[11px] px-1 rounded bg-amber-50 text-amber-600" title="템플릿 값과 다르게 개인 지정됨">개인</span>
                    </span>
                </td>
                <!-- 이 메뉴 세 권한 한 번에 ( 일부만 켜짐 = 중간 표시 · 레벨이 막은 칸은 제외 ) -->
                <td class="px-3 py-1.5 text-center">
                    <input type="checkbox" class="base-checkbox align-middle"
                        :checked="rowState(row) === 'on'" :indeterminate="rowState(row) === 'some'"
                        :disabled="disabled || rowState(row) === 'none'" @change="toggleRow(row)" />
                </td>
                <td v-for="f in FIELDS" :key="f" class="px-3 py-1.5 text-center" :class="cellDiffers(row, f) ? 'bg-amber-50' : ''"
                    :title="cellDiffers(row, f) ? `템플릿 값 : ${row[BASE_OF[f]] === 'Y' ? '켜짐' : '꺼짐'}` : ''">
                    <BaseCheckbox variant="switch" :model-value="row[f]" true-value="Y" false-value="N"
                        :disabled="disabled || row[CAP_OF[f]] === 'N'" @update:model-value="v => { setPerm(row, f, v); emit('change'); }" />
                </td>
            </tr>
        </tbody>
    </table>
</template>

<script setup>
import { computed } from 'vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';

const props = defineProps({
    rows:     { type: Array,   default: () => [] },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['change']);

const TH = 'sticky top-0 z-10 bg-white font-medium px-3 py-2 shadow-[inset_0_-1px_0_0_theme(colors.gray.200)]';
const headClass = computed(() => props.disabled ? '' : 'cursor-pointer select-none hover:text-blue-500');

const CAP_OF  = { can_read: 'cap_read', can_write: 'cap_write', can_delete: 'cap_delete' };
const BASE_OF = { can_read: 'base_read', can_write: 'base_write', can_delete: 'base_delete' };
const FIELDS  = Object.keys(CAP_OF);

// 템플릿 값( base_* )과 다른 칸 — base 가 없는 표( 템플릿 편집 )는 표시 없음
const cellDiffers = (row, f) => row[BASE_OF[f]] !== undefined && row[CAP_OF[f]] === 'Y' && row[f] !== row[BASE_OF[f]];
const differs     = (row) => FIELDS.some(f => cellDiffers(row, f));

// 아래 메뉴 ( 바로 뒤에 이어지는 더 깊은 행 — rows 는 부모 → 자식 순서 )
function descendants(row) {
    const list = props.rows;
    const out  = [];
    for (let i = list.indexOf(row) + 1; i < list.length && list[i]._depth > row._depth; i++) out.push(list[i]);
    return out;
}

// 권한 토글 → 아래 메뉴 전부 같은 값 ( 레벨 상한이 Y 인 것만 ) · 상위 메뉴도 맞춘다
function setPerm(row, field, v) {
    row[field] = v;
    descendants(row).forEach(d => { if (d[CAP_OF[field]] === 'Y') d[field] = v; });
    syncAncestors(row, field, v);
}

// 상위 메뉴 : 켜면 같이 켠다 ( 상위가 꺼져 있으면 사이드바에 안 보인다 )
//   끄면 그 아래에 켜진 메뉴가 하나도 남지 않을 때만 같이 끈다 — 화면 없는 메뉴 묶음( url_path 없음 )만
//   레벨이 막은( cap N ) 상위는 건드리지 않고 그 위로
function syncAncestors(row, field, v) {
    const list = props.rows;
    let code = row.parent_menu_code;
    while (code) {
        const p = list.find(r => r.menu_code === code);
        if (!p) break;
        if (p[CAP_OF[field]] === 'Y') {
            if (v === 'Y') {
                p[field] = 'Y';
            } else if (!p.url_path && !descendants(p).some(d => d[field] === 'Y')) {
                p[field] = 'N';
            } else {
                break;   // 이 상위는 켜진 채 → 그 위도 켜진 아래 메뉴가 있다
            }
        }
        code = p.parent_menu_code;
    }
}

// 행의 전체 상태 : 레벨이 허용한 권한만 본다 ( on 모두 · some 일부 · off 없음 · none 허용된 권한 없음 )
const rowFields = (row) => FIELDS.filter(f => row[CAP_OF[f]] === 'Y');
function rowState(row) {
    const fs = rowFields(row);
    if (!fs.length) return 'none';
    const on = fs.filter(f => row[f] === 'Y').length;
    return on === 0 ? 'off' : (on === fs.length ? 'on' : 'some');
}

// 행 [전체] : 모두 켜져 있으면 모두 끄고, 아니면 모두 켠다
function toggleRow(row) {
    const fs = rowFields(row);
    if (!fs.length || props.disabled) return;
    const next = fs.every(f => row[f] === 'Y') ? 'N' : 'Y';
    fs.forEach(f => setPerm(row, f, next));
    emit('change');
}

// 헤더 [전체] : 표 전체 세 권한을 한 번에
function toggleAll() {
    if (props.disabled) return;
    const cells = props.rows.flatMap(r => rowFields(r).map(f => [r, f]));
    if (!cells.length) return;
    const next = cells.every(([r, f]) => r[f] === 'Y') ? 'N' : 'Y';
    cells.forEach(([r, f]) => { r[f] = next; });
    emit('change');
}

// 헤더 클릭 → 컬럼 전체 Y/N 토글 ( 레벨 상한이 Y 인 행만 )
function toggleColumn(field) {
    if (props.disabled) return;
    const targets = props.rows.filter(r => r[CAP_OF[field]] === 'Y');
    if (!targets.length) return;
    const next = targets.every(r => r[field] === 'Y') ? 'N' : 'Y';
    targets.forEach(r => { r[field] = next; });
    emit('change');
}
</script>

<script>
// 부모( parent_menu_code ) 기준으로 부모 → 자식 순서로 펴고 _depth 를 붙인다 ( 서버가 준 형제 순서는 그대로 )
export function orderMenuTree(rows) {
    const codes = new Set(rows.map(r => r.menu_code));
    const byParent = {};
    for (const r of rows) {
        const key = (r.parent_menu_code && codes.has(r.parent_menu_code)) ? r.parent_menu_code : '__root__';
        (byParent[key] ||= []).push(r);
    }
    const out = [];
    const walk = (parent, depth) => {
        for (const r of (byParent[parent] || [])) {
            r._depth = depth;
            out.push(r);
            walk(r.menu_code, depth + 1);
        }
    };
    walk('__root__', 0);
    return out;
}
</script>
