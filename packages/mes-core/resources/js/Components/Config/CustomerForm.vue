<!-- 거래처 등록/수정 폼 ( BAS-01 ) — 모달 본체
     - customer prop( 목록 행 ) 있으면 수정 : 열릴 때 출고지·담당자까지 다시 조회
     - 탭 : 기본 / 담당자(N) / 출고지(N)  → 저장은 한 번에 ( 거래처 + 출고지 + 담당자 )
     - 담당자 소속은 출고지 key 로 연결 ( 새로 추가한 출고지도 저장 전에 고를 수 있음 )
     - 입고검사 설정( 정책 오버라이드 )은 정책 기능 개발 시 추가
     - 삭제 없음 : 사용 여부로 내림 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>

        <!-- 탭 -->
        <div class="flex gap-1 border-b shrink-0">
            <button
                v-for="t in TABS"
                :key="t.key"
                type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key"
            >
                {{ t.label }}
                <span v-if="t.key !== 'basic'" class="text-xs text-gray-400">( {{ t.key === 'contacts' ? form.contacts.length : form.sites.length }} )</span>
                <span v-if="tabHasError(t.key)" class="ml-0.5 text-red-500">•</span>
            </button>
        </div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <!-- 탭 1 · 기본 -->
            <div v-show="tab === 'basic'" class="grid grid-cols-2 gap-x-4 gap-y-3 pt-4">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">거래처코드 <span class="text-red-400">*</span></label>
                    <BaseInput v-model="form.customer_code" class="w-full" placeholder="예: PT-0012" :disabled="!canWrite" />
                    <p v-if="errors.customer_code" class="text-xs text-red-500 mt-0.5">{{ errors.customer_code[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">거래처명 <span class="text-red-400">*</span></label>
                    <BaseInput v-model="form.customer_name" class="w-full" :disabled="!canWrite" />
                    <p v-if="errors.customer_name" class="text-xs text-red-500 mt-0.5">{{ errors.customer_name[0] }}</p>
                </div>
                <div class="col-span-2">
                    <label class="block text-gray-500 font-medium mb-1">구분 <span class="text-red-400">*</span> <span class="text-xs text-gray-400">( 여러 개 선택 가능 )</span></label>
                    <div class="flex items-center gap-5 h-9">
                        <BaseCheckbox v-for="r in ROLES" :key="r.key" v-model="form[r.key]" true-value="Y" false-value="N" :disabled="!canWrite">
                            {{ r.label }}
                        </BaseCheckbox>
                    </div>
                    <p v-if="errors.roles" class="text-xs text-red-500 mt-0.5">{{ errors.roles[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">사업자번호</label>
                    <BaseInput v-model="form.biz_no" class="w-full" placeholder="000-00-00000" :disabled="!canWrite" />
                    <p v-if="errors.biz_no" class="text-xs text-red-500 mt-0.5">{{ errors.biz_no[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">대표자</label>
                    <BaseInput v-model="form.ceo_name" class="w-full" :disabled="!canWrite" />
                    <p v-if="errors.ceo_name" class="text-xs text-red-500 mt-0.5">{{ errors.ceo_name[0] }}</p>
                </div>
                <div class="col-span-2 pt-1">
                    <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                        {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                    </BaseCheckbox>
                </div>
                <div class="col-span-2">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="!canWrite"></textarea>
                </div>
            </div>

            <!-- 탭 2 · 담당자 N -->
            <div v-show="tab === 'contacts'" class="pt-4">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs text-gray-400">구분은 역할, 소속은 출고지입니다. 소속을 비우면 본사로 봅니다.</p>
                    <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="addContact">+ 담당자</BaseButton>
                </div>
                <p v-if="!contactTypeLoading && !contactTypeOptions.length" class="mb-2 text-xs text-amber-600">
                    등록된 담당자 구분이 없습니다. 코드관리의 CUSTOMER_CONTACT_TYPE 그룹에 먼저 추가하세요.
                </p>

                <div class="overflow-x-auto border rounded">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 font-medium w-32">구분 <span class="text-red-400">*</span></th>
                                <th class="px-2 py-1.5 font-medium w-32">소속</th>
                                <th class="px-2 py-1.5 font-medium w-28">이름 <span class="text-red-400">*</span></th>
                                <th class="px-2 py-1.5 font-medium w-32">연락처</th>
                                <th class="px-2 py-1.5 font-medium">이메일</th>
                                <th v-if="canWrite" class="px-2 py-1.5 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.contacts.length">
                                <td :colspan="canWrite ? 6 : 5" class="px-2 py-4 text-center text-gray-400">담당자가 없습니다.</td>
                            </tr>
                            <tr v-for="(c, i) in form.contacts" :key="c._rowKey" class="border-t align-top">
                                <td class="px-1.5 py-1">
                                    <BaseSelect v-model="c.contact_type" :options="contactTypeRowOptions(c)" size="sm" class="w-full" placeholder="선택" :disabled="!canWrite" />
                                    <p v-if="rowError('contacts', i, 'contact_type')" class="text-[11px] text-red-500">{{ rowError('contacts', i, 'contact_type') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseSelect v-model="c.site_key" :options="siteOptions" size="sm" class="w-full" :disabled="!canWrite" />
                                    <p v-if="rowError('contacts', i, 'site_key')" class="text-[11px] text-red-500">{{ rowError('contacts', i, 'site_key') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="c.contact_name" size="sm" :disabled="!canWrite" />
                                    <p v-if="rowError('contacts', i, 'contact_name')" class="text-[11px] text-red-500">{{ rowError('contacts', i, 'contact_name') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="c.phone" size="sm" :disabled="!canWrite" />
                                    <p v-if="rowError('contacts', i, 'phone')" class="text-[11px] text-red-500">{{ rowError('contacts', i, 'phone') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="c.email" size="sm" :disabled="!canWrite" />
                                    <p v-if="rowError('contacts', i, 'email')" class="text-[11px] text-red-500">{{ rowError('contacts', i, 'email') }}</p>
                                </td>
                                <td v-if="canWrite" class="px-1.5 py-1 text-center">
                                    <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="행 삭제" @click="form.contacts.splice(i, 1)">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 탭 3 · 출고지 N -->
            <div v-show="tab === 'sites'" class="pt-4">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs text-gray-400">출고지를 삭제하면 그 소속 담당자는 본사로 바뀝니다.</p>
                    <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="addSite">+ 출고지</BaseButton>
                </div>

                <div class="overflow-x-auto border rounded">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 font-medium w-40">출고지명 <span class="text-red-400">*</span></th>
                                <th class="px-2 py-1.5 font-medium">주소</th>
                                <th class="px-2 py-1.5 font-medium w-36">연락처</th>
                                <th class="px-2 py-1.5 font-medium w-20">담당자</th>
                                <th v-if="canWrite" class="px-2 py-1.5 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.sites.length">
                                <td :colspan="canWrite ? 5 : 4" class="px-2 py-4 text-center text-gray-400">출고지가 없습니다.</td>
                            </tr>
                            <tr v-for="(s, i) in form.sites" :key="s.key" class="border-t align-top">
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="s.site_name" size="sm" :disabled="!canWrite" />
                                    <p v-if="rowError('sites', i, 'site_name')" class="text-[11px] text-red-500">{{ rowError('sites', i, 'site_name') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="s.address" size="sm" :disabled="!canWrite" />
                                    <p v-if="rowError('sites', i, 'address')" class="text-[11px] text-red-500">{{ rowError('sites', i, 'address') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="s.tel" size="sm" :disabled="!canWrite" />
                                    <p v-if="rowError('sites', i, 'tel')" class="text-[11px] text-red-500">{{ rowError('sites', i, 'tel') }}</p>
                                </td>
                                <td class="px-1.5 py-1 text-center text-xs text-gray-500 leading-[30px]">
                                    {{ form.contacts.filter(c => c.site_key === s.key).length }} 명
                                </td>
                                <td v-if="canWrite" class="px-1.5 py-1 text-center">
                                    <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="행 삭제" @click="removeSite(i)">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ canWrite ? '취소' : '닫기' }}</BaseButton>
            <BaseButton v-if="canWrite" variant="primary" size="sm" :disabled="saving || loading" @click="save">
                {{ isEdit ? '수정' : '등록' }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { CUSTOMER_ROLES } from '@core/Components/Config/customerCodes';

const props = defineProps({
    customer:  { type: Object,   default: null },   // 목록 행 ( customer_idx 로 상세 재조회 )
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },   // 저장 후 호출 ( 저장된 거래처 전달 )
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.customer?.customer_idx);

const TABS = [
    { key: 'basic',    label: '기본' },
    { key: 'contacts', label: '담당자' },
    { key: 'sites',    label: '출고지' },
];
const ROLES = CUSTOMER_ROLES;

const tab = ref('basic');

// ---- 폼 ----
let rowSeq = 0;
const newKey = (prefix) => `${prefix}${Date.now().toString(36)}${++rowSeq}`;

const form = reactive({
    customer_idx:  props.customer?.customer_idx ?? null,
    customer_code: '',
    customer_name: '',
    is_supplier:  'N',
    is_customer:  'Y',
    is_subcon:    'N',
    biz_no:       '',
    ceo_name:     '',
    is_use:       'Y',
    remark:       '',
    sites:        [],   // [{ site_idx, key, site_name, address, tel, remark }]
    contacts:     [],   // [{ contact_idx, _rowKey, contact_type, site_key, contact_name, phone, email, remark }]
});

function applyCustomer(p) {
    Object.assign(form, {
        customer_idx:  p.customer_idx,
        customer_code: p.customer_code ?? '',
        customer_name: p.customer_name ?? '',
        is_supplier:  p.is_supplier ?? 'N',
        is_customer:  p.is_customer ?? 'N',
        is_subcon:    p.is_subcon ?? 'N',
        biz_no:       p.biz_no ?? '',
        ceo_name:     p.ceo_name ?? '',
        is_use:       p.is_use ?? 'Y',
        remark:       p.remark ?? '',
        sites:        (p.sites ?? []).map(s => ({ ...s })),
        contacts:     (p.contacts ?? []).map(c => ({ ...c, site_key: c.site_key ?? '', _rowKey: `c${c.contact_idx}` })),
    });
}

// 수정이면 출고지·담당자까지 조회
const loading = ref(false);
onMounted(async () => {
    if (!isEdit.value) return;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('config.customer.show', form.customer_idx));
        applyCustomer(data.customer);
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '거래처를 찾을 수 없습니다. 목록을 새로고침하세요.' : '거래처를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

function addContact() {
    form.contacts.push({
        contact_idx: null, _rowKey: newKey('c'),
        contact_type: contactTypeOptions.value[0]?.value ?? '',
        site_key: '', contact_name: '', phone: '', email: '', remark: '',
    });
}

function addSite() {
    form.sites.push({ site_idx: null, key: newKey('n'), site_name: '', address: '', tel: '', remark: '' });
}

// 출고지 행 삭제 → 그 소속 담당자는 본사로
function removeSite(i) {
    const key = form.sites[i].key;
    form.contacts.forEach(c => { if (c.site_key === key) c.site_key = ''; });
    form.sites.splice(i, 1);
}

// 소속 선택지 : 본사 + 출고지( 저장 전 새 출고지 포함 )
const siteOptions = computed(() => [
    { value: '', label: '본사' },
    ...form.sites.map(s => ({ value: s.key, label: s.site_name || '( 이름 없음 )' })),
]);

// ---- 담당자 구분 ( 코드 마스터 CUSTOMER_CONTACT_TYPE ) ----
const { options: codeOptions, loading: contactTypeLoading } = useCommonCode(['CUSTOMER_CONTACT_TYPE']);
const contactTypeOptions = computed(() => codeOptions('CUSTOMER_CONTACT_TYPE'));
// 기존 담당자의 구분이 미사용으로 내려가 목록에 없으면 현재 값은 보이도록 추가
function contactTypeRowOptions(c) {
    const opts = contactTypeOptions.value;
    if (c.contact_type && !contactTypeLoading.value && !opts.some(o => o.value === c.contact_type)) {
        return [...opts, { value: c.contact_type, label: `${c.contact_type} ( 미사용 )` }];
    }
    return opts;
}

// ---- 저장 ----
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

// 오류 키 → 탭 ( sites.0.site_name → sites / contacts.1.email → contacts / 그 외 기본 )
const tabOf = (field) => field.startsWith('sites.') ? 'sites' : field.startsWith('contacts.') ? 'contacts' : 'basic';
const tabHasError = (key) => Object.keys(errors.value).some(f => tabOf(f) === key);
const rowError = (list, i, field) => errors.value[`${list}.${i}.${field}`]?.[0] ?? '';

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const payload = {
            ...form,
            sites:    form.sites.map(({ site_idx, key, site_name, address, tel, remark }) => ({ site_idx, key, site_name, address, tel, remark })),
            contacts: form.contacts.map(({ _rowKey, ...c }) => ({ ...c, site_key: c.site_key || null })),
        };
        const { data } = await window.axios.post(route('config.customer.save'), payload);
        props.onSaved?.(data.customer);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            const first = Object.keys(errors.value)[0];
            if (first) tab.value = tabOf(first);
        } else if (e.response?.status === 403) {
            errorMsg.value = '저장 권한이 없습니다.';
        } else if (e.response?.status === 404) {
            errorMsg.value = '거래처를 찾을 수 없습니다. 목록을 새로고침하세요.';
        } else {
            errorMsg.value = '저장에 실패했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>
