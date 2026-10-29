<!-- 사이트설정 ( 1012 ) / 기준정보관리 > 사이트설정 — 스타일 가이드 적용
     - 회사 설정( 상호 · 사업자 정보 · 연락처 + 직인 · 로고 이미지 ) · 거래명세서( 부가세 · 원 미만 )
     - 값 = 정책 묶음 company · invoice ( 시스템 환경설정에서는 빠진다 ) — 칸은 서버가 준 정의로 그린다 ( 항목 추가 = system_config.php + 시더 )
     - 세금계산서 : 기능이 생기면 묶음( 섹션 )을 더한다
     - 저장은 바뀐 칸만 · 빈 칸 = 기본값 / 이미지는 고르면 바로 올라간다 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">사이트설정</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <span v-if="dirtyCount" class="text-xs text-amber-600">바뀐 칸 {{ dirtyCount }}개</span>
            <BaseButton v-if="canWrite" variant="primary" :disabled="saving || !dirtyCount" @click="save">저장</BaseButton>
        </div>

        <p v-if="loading" class="py-10 text-center text-gray-400">불러오는 중…</p>

        <div v-else class="flex-1 min-h-0 overflow-y-auto space-y-4 pb-4">
            <section v-for="sec in sections" :key="sec.key" class="border border-gray-200 rounded">
                <div class="px-4 py-2 bg-gray-50/70 border-b border-gray-200">
                    <span class="text-sm font-semibold text-gray-700">{{ sec.title }}</span>
                    <span v-if="sec.desc" class="ml-2 text-xs text-gray-400">{{ sec.desc }}</span>
                </div>

                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 max-w-5xl">
                    <div v-for="it in sec.items" :key="it.id" class="flex items-center gap-3" :class="WIDE.includes(it.id) ? 'md:col-span-2' : ''">
                        <label class="w-28 shrink-0 text-sm text-gray-600 font-semibold" :for="`site-${it.id}`">{{ it.name }}</label>
                        <BaseSelect v-if="it.type === 'ENUM'" :id="`site-${it.id}`" v-model="values[it.id]" :options="it.options" class="flex-1 max-w-sm" :disabled="!canWrite" />
                        <BaseInput v-else :id="`site-${it.id}`" v-model="values[it.id]" class="flex-1" :placeholder="it.remark || ''" :disabled="!canWrite" />
                    </div>
                </div>

                <!-- 회사 설정 : 직인 · 로고 -->
                <div v-if="sec.key === 'company'" class="px-4 pb-4 flex flex-wrap gap-6">
                    <div v-for="img in IMAGES" :key="img.kind" class="w-56">
                        <div class="text-sm text-gray-600 mb-1 font-semibold">{{ img.label }} <span class="text-xs text-gray-400">{{ img.hint }}</span></div>
                        <div class="h-32 border border-dashed border-gray-300 rounded flex items-center justify-center bg-[repeating-conic-gradient(#f3f4f6_0_25%,#fff_0_50%)] bg-[length:16px_16px]">
                            <img v-if="images[img.kind]" :src="imageUrl(img.kind)" :alt="img.label" class="max-h-28 max-w-[13rem] object-contain" />
                            <span v-else class="text-xs text-gray-400">없음</span>
                        </div>
                        <div v-if="canWrite" class="mt-2 flex gap-2">
                            <BaseButton size="sm" variant="secondary" :disabled="uploading === img.kind" @click="pick(img.kind)">{{ images[img.kind] ? '수정' : '등록' }}</BaseButton>
                            <BaseButton v-if="images[img.kind]" size="sm" variant="secondary" :disabled="uploading === img.kind" @click="removeImage(img)">삭제</BaseButton>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- 이미지 파일 고르기 ( v-for 밖 — 안에 두면 ref 가 배열이 된다 ) -->
        <input ref="fileInput" type="file" class="hidden" :accept="ACCEPT" @change="onFile" />
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
import { useFlash, failMsg } from '@core/Composables/useFlash';

defineOptions({ layout: AppLayout });

// 이 페이지( 사이트설정 ) 권한
const page     = usePage();
const menuCode = page.props.menuCode;
const myPerm   = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite = computed(() => myPerm.value.can_write === true);

const { flash, showFlash } = useFlash();

// 묶음 → 섹션 제목 ( 순서는 서버 )
const SECTION_META = {
    company: { title: '회사 설정', desc: '거래명세서 공급자 · 문서 머리에 쓰인다' },
    invoice: { title: '거래명세서', desc: '금액 계산' },
};
const WIDE   = ['company.address', 'company.homepage'];   // 두 칸 너비
const IMAGES = [
    { kind: 'seal', label: '직인', hint: '문서 양식 ( 인 ) 자리' },
    { kind: 'logo', label: '로고', hint: '문서 양식 왼쪽 상단' },
];
const ACCEPT = '.png,.jpg,.jpeg,.gif,.webp';

const loading  = ref(true);
const saving   = ref(false);
const items    = ref([]);
const values   = reactive({});   // 'group.key' → 화면 값
const original = reactive({});
const images   = reactive({ seal: null, logo: null });   // 바뀐 시각( 없으면 null )

const sections = computed(() => {
    const map = {};
    for (const it of items.value) {
        (map[it.group] ??= { key: it.group, title: SECTION_META[it.group]?.title ?? it.group, desc: SECTION_META[it.group]?.desc ?? '', items: [] }).items.push(it);
    }
    return Object.values(map);
});
const dirtyKeys  = computed(() => items.value.map(i => i.id).filter(id => (values[id] ?? '') !== (original[id] ?? '')));
const dirtyCount = computed(() => dirtyKeys.value.length);

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('config.site.data'));
        items.value = (data.items ?? []).map(i => ({ ...i, id: `${i.group}.${i.key}`, options: (i.options ?? []).map(o => ({ value: String(o.value), label: o.label })) }));
        for (const it of items.value) {
            values[it.id]   = it.value ?? '';
            original[it.id] = it.value ?? '';
        }
        Object.assign(images, data.images ?? {});
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (!dirtyCount.value) return;
    saving.value = true;
    try {
        const payload = dirtyKeys.value.map(id => {
            const it = items.value.find(i => i.id === id);
            return { group: it.group, key: it.key, value: values[id] ?? '' };
        });
        await window.axios.post(route('config.site.save'), { items: payload });
        showFlash('저장했습니다.');
        await load();
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        saving.value = false;
    }
}

// ---- 이미지 ----
const fileInput = ref(null);
const uploading = ref('');
let pickKind = '';

const imageUrl = (kind) => `${route('config.site.image', { kind })}?v=${images[kind]}`;

function pick(kind) {
    pickKind = kind;
    fileInput.value.value = '';
    fileInput.value.click();
}
async function onFile(e) {
    const file = e.target.files?.[0];
    if (!file || !pickKind) return;
    uploading.value = pickKind;
    try {
        const fd = new FormData();
        fd.append('file', file);
        const { data } = await window.axios.post(route('config.site.image.upload', { kind: pickKind }), fd);
        images[pickKind] = data.version ?? Date.now();
        showFlash('이미지를 올렸습니다.');
    } catch (err) {
        showFlash(failMsg(err), true);
    } finally {
        uploading.value = '';
    }
}
async function removeImage(img) {
    const r = await showAlert('이미지 삭제', `${img.label} 이미지를 지웁니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    uploading.value = img.kind;
    try {
        await window.axios.post(route('config.site.image.delete', { kind: img.kind }));
        images[img.kind] = null;
        showFlash('이미지를 지웠습니다.');
    } catch (err) {
        showFlash(failMsg(err), true);
    } finally {
        uploading.value = '';
    }
}

onMounted(load);
</script>
