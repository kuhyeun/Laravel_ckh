<!-- BOM관리 ( BAS-03 ) / 기준정보관리 > BOM관리
     - 좌 : 품목 목록 ( 활성 버전 표시 )  /  우 : 선택 품목의 버전 + 자재 줄
     - 변경 한 번이 버전 하나 → 버전 목록이 곧 변경 이력 ( 별도 이력 화면 없음 )
     - 승인은 관문이 아니라 기록 : 승인 없이도 활성 전환되고 경고만
     - 투입 지점은 라우팅의 "줄" ( 그 줄이 작업지시에 없으면 그 자재도 소요 없음 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">BOM관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <div class="flex flex-1 min-h-0 gap-4">
            <!-- 좌 : 품목 -->
            <div class="w-72 shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <BaseInput v-model="itemKeyword" size="sm" class="w-full" placeholder="품번 · 품명" @keyup.enter="loadItems" />
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!items.length" class="px-3 py-4 text-sm text-gray-400">품목이 없습니다.</p>
                    <button
                        v-for="it in items"
                        :key="it.item_idx"
                        type="button"
                        class="w-full text-left px-3 py-1.5 text-sm flex items-center gap-1.5 transition-colors"
                        :class="selectedItem?.item_idx === it.item_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        @click="selectItem(it)"
                    >
                        <span class="truncate">{{ it.item_name }}</span>
                        <span class="text-[11px] text-gray-400 shrink-0">[{{ it.item_code }}]</span>
                        <span class="flex-1"></span>
                        <span v-if="it.active_version" class="text-[10px] px-1 rounded bg-blue-50 text-blue-600 shrink-0">{{ it.active_version }}</span>
                        <span v-else-if="it.bom_count" class="text-[10px] text-gray-400 shrink-0">{{ it.bom_count }}버전</span>
                    </button>
                </div>
            </div>

            <!-- 우 : 버전 + 줄 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">
                        {{ selectedItem ? `${selectedItem.item_name} [${selectedItem.item_code}]` : 'BOM' }}
                    </span>
                    <template v-if="selectedItem">
                        <BaseSelect
                            :model-value="form.bom_idx ?? ''"
                            :options="versionOptions" size="sm" class="w-40"
                            @update:model-value="onVersionChange"
                        />
                        <span v-if="form.is_active === 'Y'" class="text-[11px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">활성</span>
                        <span v-if="form.approved_at" class="text-[11px] text-gray-500">승인 {{ form.approved_at }}</span>
                        <span v-else-if="form.bom_idx" class="text-[11px] text-amber-600">미승인</span>
                    </template>
                    <div class="flex-1"></div>
                    <template v-if="selectedItem && canWrite">
                        <BaseButton size="sm" variant="secondary" @click="newVersion">{{ form.bom_idx ? '버전 추가' : '새 BOM' }}</BaseButton>
                        <BaseButton v-if="form.bom_idx && form.is_active !== 'Y'" size="sm" variant="secondary" @click="activate">활성으로</BaseButton>
                        <BaseButton v-if="form.bom_idx && !form.approved_at" size="sm" variant="secondary" @click="approve">승인</BaseButton>
                        <BaseButton size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
                    </template>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto p-4">
                    <p v-if="!selectedItem" class="py-10 text-center text-sm text-gray-400">왼쪽에서 품목을 선택하세요.</p>
                    <p v-else-if="loading" class="py-10 text-center text-gray-400">불러오는 중...</p>

                    <template v-else>
                        <!-- 버전 머리 -->
                        <div class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm mb-4">
                            <div>
                                <label class="block text-gray-500 font-medium mb-1">버전 <span class="text-red-400">*</span></label>
                                <BaseInput v-model="form.version" class="w-full" placeholder="예: V1" :disabled="!canWrite" />
                                <p v-if="errors.version" class="text-xs text-red-500 mt-0.5">{{ errors.version[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-gray-500 font-medium mb-1">
                                    변경일 <span class="text-xs text-gray-400">( 설계변경 발효일 )</span>
                                </label>
                                <BaseInput v-model="form.changed_at" type="date" class="w-full" :disabled="!canWrite" />
                                <p v-if="errors.changed_at" class="text-xs text-red-500 mt-0.5">{{ errors.changed_at[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-gray-500 font-medium mb-1">변경 사유</label>
                                <BaseInput v-model="form.remark" class="w-full" placeholder="예: 볼트 규격 M6 → M8" :disabled="!canWrite" />
                            </div>
                        </div>

                        <p v-if="!hasRouting" class="mb-3 text-xs text-amber-600">
                            이 품목에 기본 라우팅이 없어 투입 지점을 고를 수 없습니다. 품목관리에서 라우팅을 지정하면 선택할 수 있습니다.
                        </p>

                        <!-- 자재 줄 -->
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <span class="text-sm font-semibold text-gray-600">자재</span>
                                <span class="ml-1 text-xs text-gray-400">투입 지점을 비우면 언제나 소요됩니다. 같은 자재를 투입 지점만 다르게 두 줄 넣을 수 있습니다.</span>
                            </div>
                            <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="addLine">+ 자재</BaseButton>
                        </div>

                        <div class="border rounded overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-gray-500">
                                    <tr>
                                        <th class="px-2 py-1.5 font-medium">자재 <span class="text-red-400">*</span></th>
                                        <th class="px-2 py-1.5 w-24 font-medium">소요 <span class="text-red-400">*</span></th>
                                        <th class="px-2 py-1.5 w-44 font-medium">투입 지점</th>
                                        <th class="px-2 py-1.5 w-32 font-medium">계산식</th>
                                        <th class="px-2 py-1.5 w-24 font-medium">절단(CUT)</th>
                                        <th v-if="canWrite" class="px-2 py-1.5 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!form.lines.length">
                                        <td :colspan="canWrite ? 6 : 5" class="px-2 py-6 text-center text-gray-400">자재를 추가하세요.</td>
                                    </tr>
                                    <tr v-for="(l, i) in form.lines" :key="l._rowKey" class="border-t align-top">
                                        <td class="px-1.5 py-1">
                                            <BaseSelect v-model="l.child_item_idx" :options="childOptions(l)" size="sm" class="w-full" placeholder="자재 선택" :disabled="!canWrite" />
                                            <p v-if="rowError(i, 'child_item_idx')" class="text-[11px] text-red-500">{{ rowError(i, 'child_item_idx') }}</p>
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.qty" type="number" min="0" size="sm" class="w-full text-right" :disabled="!canWrite" />
                                            <p v-if="rowError(i, 'qty')" class="text-[11px] text-red-500">{{ rowError(i, 'qty') }}</p>
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <BaseSelect v-model="l.routing_line_idx" :options="routingOptions" size="sm" class="w-full" :disabled="!canWrite || !hasRouting" />
                                            <p v-if="rowError(i, 'routing_line_idx')" class="text-[11px] text-red-500">{{ rowError(i, 'routing_line_idx') }}</p>
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.calc_code" size="sm" class="w-full" placeholder="예: BAR_CUT" :disabled="!canWrite" />
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.ext_n01" type="number" min="0" size="sm" class="w-full text-right" :disabled="!canWrite" />
                                        </td>
                                        <td v-if="canWrite" class="px-1.5 py-1 text-center">
                                            <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="줄 삭제" @click="form.lines.splice(i, 1)">✕</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 버전 이력 -->
                        <div v-if="versions.length" class="mt-5">
                            <span class="text-sm font-semibold text-gray-600">버전 이력</span>
                            <span class="ml-1 text-xs text-gray-400">변경 한 번이 버전 하나입니다.</span>
                            <div class="mt-2 border rounded overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-500">
                                        <tr>
                                            <th class="px-2 py-1.5 w-24 font-medium text-left">버전</th>
                                            <th class="px-2 py-1.5 w-20 font-medium">상태</th>
                                            <th class="px-2 py-1.5 w-28 font-medium">변경일</th>
                                            <th class="px-2 py-1.5 w-36 font-medium">승인</th>
                                            <th class="px-2 py-1.5 w-20 font-medium">자재</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="v in versions"
                                            :key="v.bom_idx"
                                            class="border-t cursor-pointer hover:bg-gray-50"
                                            :class="v.bom_idx === form.bom_idx ? 'bg-blue-50/50' : ''"
                                            @click="loadBom(v.bom_idx)"
                                        >
                                            <td class="px-2 py-1.5">{{ v.version }}</td>
                                            <td class="px-2 py-1.5 text-center">
                                                <span v-if="v.is_active === 'Y'" class="text-[11px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700">활성</span>
                                                <span v-else class="text-gray-300">—</span>
                                            </td>
                                            <td class="px-2 py-1.5 text-center text-gray-600">{{ v.changed_at ?? '—' }}</td>
                                            <td class="px-2 py-1.5 text-center text-gray-600">{{ v.approved_at ?? '미승인' }}</td>
                                            <td class="px-2 py-1.5 text-center text-gray-600">{{ v.lines_count }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';

defineOptions({ layout: AppLayout });

// 이 페이지( BOM관리 ) 권한
const page     = usePage();
const menuCode = page.props.menuCode;
const myPerm   = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite = computed(() => myPerm.value.can_write === true);

const items        = ref([]);
const itemKeyword  = ref('');
const selectedItem = ref(null);
const versions     = ref([]);
const routingLines = ref([]);
const loading      = ref(false);
const saving       = ref(false);
const errors       = ref({});

let rowSeq = 0;
const blankForm = () => ({
    bom_idx: null, item_idx: null, version: '', is_active: 'N',
    changed_at: '', approved_at: null, remark: '', lines: [],
});
const form = reactive(blankForm());

const hasRouting = computed(() => !!routingLines.value.length);

// ---- 플래시 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 4000);
}

// ---- 선택지 ----
const versionOptions = computed(() => [
    ...versions.value.map(v => ({ value: v.bom_idx, label: `${v.version}${v.is_active === 'Y' ? ' · 활성' : ''}` })),
    ...(form.bom_idx ? [] : [{ value: '', label: '( 새 BOM )' }]),
]);

// 투입 지점 : 그 품목 라우팅의 줄 ( "4. 조립" )
const routingOptions = computed(() => [
    { value: '', label: '( 없음 · 첫 공정 )' },
    ...routingLines.value.map(l => ({ value: l.routing_line_idx, label: `${l.seq}. ${l.process_name}` })),
]);

// 자재 선택지 : 자기 자신 제외
function childOptions(line) {
    const opts = items.value
        .filter(it => it.item_idx !== selectedItem.value?.item_idx)
        .map(it => ({ value: it.item_idx, label: `${it.item_name} [${it.item_code}]` }));
    if (line.child_item_idx && !opts.some(o => Number(o.value) === Number(line.child_item_idx))) {
        opts.push({ value: line.child_item_idx, label: `${line.child_item_name ?? line.child_item_idx}` });
    }
    return opts;
}

const rowError = (i, field) => errors.value[`lines.${i}.${field}`]?.[0] ?? '';

// ---- 데이터 ----
async function loadItems() {
    try {
        const { data } = await window.axios.get(route('config.bom.items'), { params: itemKeyword.value ? { keyword: itemKeyword.value } : {} });
        items.value = data.items ?? [];
    } catch (e) {
        showFlash('품목 목록을 불러오지 못했습니다', true);
    }
}

async function selectItem(it) {
    selectedItem.value = it;
    errors.value = {};
    Object.assign(form, blankForm());
    form.item_idx = it.item_idx;

    loading.value = true;
    try {
        const [vRes, rRes] = await Promise.all([
            window.axios.get(route('config.bom.versions', it.item_idx)),
            window.axios.get(route('config.bom.routingLines', it.item_idx)),
        ]);
        versions.value     = vRes.data.versions ?? [];
        routingLines.value = rRes.data.routingLines ?? [];

        // 활성 버전이 있으면 그것을, 없으면 첫 버전을 연다
        const target = versions.value.find(v => v.is_active === 'Y') ?? versions.value[0];
        if (target) await loadBom(target.bom_idx);
    } catch (e) {
        showFlash('BOM 정보를 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

async function loadBom(bomIdx) {
    errors.value = {};
    try {
        const { data } = await window.axios.get(route('config.bom.show', bomIdx));
        const b = data.bom;
        routingLines.value = data.routingLines ?? routingLines.value;
        Object.assign(form, {
            bom_idx: b.bom_idx, item_idx: b.item_idx, version: b.version, is_active: b.is_active,
            changed_at: b.changed_at ?? '', approved_at: b.approved_at, remark: b.remark ?? '',
            lines: (b.lines ?? []).map(l => ({ ...l, routing_line_idx: l.routing_line_idx ?? '', _rowKey: `b${++rowSeq}` })),
        });
    } catch (e) {
        showFlash('BOM 을 불러오지 못했습니다', true);
    }
}

function onVersionChange(value) {
    if (value === '') {
        newBom();
    } else {
        loadBom(Number(value));
    }
}

function newBom() {
    errors.value = {};
    Object.assign(form, blankForm());
    form.item_idx = selectedItem.value?.item_idx ?? null;
}

function addLine() {
    form.lines.push({
        _rowKey: `b${++rowSeq}`, bom_line_idx: null,
        child_item_idx: '', routing_line_idx: '', qty: 1, calc_code: '', ext_n01: '', remark: '',
    });
}

// 버전 추가 : 현재 버전을 복사 ( 승인 정보는 복사하지 않음 )
async function newVersion() {
    if (!form.bom_idx) {
        newBom();
        return;
    }

    const result = await showAlert('버전 추가', '현재 버전을 복사해 새 버전을 만듭니다. 새 버전명을 입력하세요.', 'question', 'prompt');
    const version = (result.value ?? '').trim();
    if (!result.isConfirmed || !version) return;

    try {
        const { data } = await window.axios.post(route('config.bom.copyVersion'), { bom_idx: form.bom_idx, version });
        await refreshVersions();
        await loadBom(data.bom_idx);
        showFlash(`${version} 버전을 만들었습니다. ( 승인 정보는 복사되지 않습니다 )`);
    } catch (e) {
        if (e.response?.status === 422) showFlash(Object.values(e.response.data.errors ?? {})[0]?.[0] ?? '입력값을 확인하세요', true);
        else                            showFlash('버전 추가에 실패했습니다', true);
    }
}

async function refreshVersions() {
    const { data } = await window.axios.get(route('config.bom.versions', form.item_idx ?? selectedItem.value.item_idx));
    versions.value = data.versions ?? [];
    await loadItems();
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            bom_idx: form.bom_idx,
            item_idx: form.item_idx,
            version: form.version,
            changed_at: form.changed_at || null,
            remark: form.remark || null,
            lines: form.lines.map(l => ({
                bom_line_idx: l.bom_line_idx,
                child_item_idx: l.child_item_idx === '' ? null : Number(l.child_item_idx),
                routing_line_idx: l.routing_line_idx === '' ? null : Number(l.routing_line_idx),
                qty: l.qty,
                calc_code: l.calc_code || null,
                ext_n01: l.ext_n01 === '' ? null : l.ext_n01,
                remark: l.remark ?? null,
            })),
        };
        const { data } = await window.axios.post(route('config.bom.save'), payload);
        const wasApproved = !!form.approved_at;
        await refreshVersions();
        await loadBom(data.bom.bom_idx);

        const notes = [...(data.warnings ?? [])];
        if (wasApproved && !form.approved_at) notes.push('자재가 바뀌어 승인이 해제되었습니다.');
        showFlash(notes.length ? `저장되었습니다. ⚠ ${notes.join(' / ')}` : '저장되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422)      { errors.value = e.response.data.errors || {}; showFlash('입력값을 확인하세요', true); }
        else if (status === 403) showFlash('저장 권한이 없습니다.', true);
        else                     showFlash('저장에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

// 활성 전환 ( 승인 없어도 되고, 없으면 경고 )
async function activate() {
    const msg = form.approved_at
        ? `[ ${form.version} ]을(를) 활성으로 전환합니다.`
        : `[ ${form.version} ]은(는) 아직 승인되지 않았습니다.\n그래도 활성으로 전환할까요?`;
    const result = await showAlert('활성 전환', msg, 'question', 'confirm');
    if (!result.isConfirmed) return;

    try {
        const { data } = await window.axios.post(route('config.bom.activate'), { bom_idx: form.bom_idx });
        await refreshVersions();
        await loadBom(form.bom_idx);
        showFlash((data.warnings ?? []).length ? `활성으로 전환했습니다. ⚠ ${data.warnings.join(' / ')}` : '활성으로 전환했습니다.');
    } catch (e) {
        showFlash('활성 전환에 실패했습니다', true);
    }
}

// 승인 ( 활성 전환과 별개 · 기록 )
async function approve() {
    const result = await showAlert('BOM 승인', `[ ${form.version} ]을(를) 승인합니다.\n승인 후 자재를 고치면 승인이 해제됩니다.`, 'question', 'confirm');
    if (!result.isConfirmed) return;

    try {
        const { data } = await window.axios.post(route('config.bom.approve'), { bom_idx: form.bom_idx });
        form.approved_at = data.approved_at;
        await refreshVersions();
        showFlash('승인되었습니다.');
    } catch (e) {
        showFlash('승인에 실패했습니다', true);
    }
}

onMounted(loadItems);
</script>
