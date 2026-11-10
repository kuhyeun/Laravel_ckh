<!-- 문서관리 ( 11004 ) / DATA 관리 > 문서관리 — 웹 폴더
     - 좌 : 폴더 트리 ( 깊이 제한 없음 · 펼치기 / 접기 · 맨 위 '전체' = 최상위 )
     - 우 : 고른 폴더의 경로 · 하위 폴더 · 파일 ( 끌어다 놓기 / [파일 올리기] · 받기 · 옮기기 · 삭제 )
     - 폴더 : 새 폴더( 고른 폴더 아래 ) · 이름 바꾸기 · 삭제( 하위 어디에도 파일이 없으면 하위 폴더째 · 파일이 있으면 경고 — 서버도 막는다 )
     - 파일 = 첨부( ref_type doc_folder · 구분 없이 ETC ) — 올리기 · 받기 · 삭제는 공용 첨부 API
     - 폴더 옮기기는 없다 ( 사용자 결정 11-10 — 관리만 어려워진다 ) · 파일은 다른 폴더로 옮길 수 있다
     - 권한 : 보기 · 받기 = 읽기 / 폴더 만들기 · 이름 바꾸기 · 파일 올리기 · 옮기기 = 쓰기 / 폴더 · 파일 삭제 = 삭제 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex flex-1 min-h-0 gap-4">
            <!-- 좌 : 폴더 트리 -->
            <div class="w-80 shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">폴더</span>
                    <div class="flex items-center gap-1">
                        <button type="button" class="text-xs text-gray-400 hover:text-blue-600" @click="expandAll(true)">모두 펼치기</button>
                        <span class="text-gray-200">|</span>
                        <button type="button" class="text-xs text-gray-400 hover:text-blue-600" @click="expandAll(false)">접기</button>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto py-1 select-none">
                    <!-- 최상위 -->
                    <div
                        class="group flex items-center gap-1.5 px-2 py-1 min-h-[32px] text-sm cursor-pointer transition-colors"
                        :class="selectedIdx === null ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        @click="select(null)"
                    >
                        <FolderOpenIcon class="w-4 h-4 shrink-0 text-amber-400" />
                        <span class="truncate">전체</span>
                        <span class="flex-1"></span>
                        <button v-if="canWrite" type="button" :class="ROW_ADD" title="최상위에 새 폴더" @click.stop="newFolder(null)">+</button>
                    </div>

                    <p v-if="loaded && !folders.length" class="px-3 py-3 text-xs text-gray-400">
                        폴더가 없습니다.{{ canWrite ? ' [+] 로 만드세요.' : '' }}
                    </p>

                    <div
                        v-for="f in tree"
                        :key="f.folder_idx"
                        class="group flex items-center gap-1 pr-2 py-1 min-h-[32px] text-sm cursor-pointer transition-colors"
                        :class="selectedIdx === f.folder_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        :style="{ paddingLeft: (8 + (f._depth + 1) * 16) + 'px' }"
                        @click="select(f.folder_idx)"
                    >
                        <button
                            type="button"
                            class="w-4 h-4 shrink-0 flex items-center justify-center text-gray-400 hover:text-gray-700"
                            :class="f._hasChildren ? '' : 'invisible'"
                            @click.stop="toggle(f.folder_idx)"
                        >
                            <ChevronRightIcon class="w-3.5 h-3.5 transition-transform" :class="expanded.has(f.folder_idx) ? 'rotate-90' : ''" />
                        </button>
                        <component :is="selectedIdx === f.folder_idx ? FolderOpenIcon : FolderIcon" class="w-4 h-4 shrink-0 text-amber-400" />
                        <span class="truncate" :title="f.folder_name">{{ f.folder_name }}</span>
                        <span v-if="f.file_count" class="text-[11px] text-gray-400 shrink-0">{{ f.file_count }}</span>
                        <span class="flex-1"></span>
                        <button v-if="canWrite" type="button" :class="ROW_ADD" title="이 폴더 아래에 새 폴더" @click.stop="newFolder(f.folder_idx)">+</button>
                    </div>
                </div>
            </div>

            <!-- 우 : 고른 폴더 -->
            <div
                class="flex-1 min-w-0 flex flex-col border overflow-hidden transition-colors"
                :class="dragging ? 'border-blue-500 bg-blue-50/40' : 'border-gray-200'"
                @dragover.prevent="onDragOver"
                @dragleave.prevent="onDragLeave"
                @drop.prevent="onDrop"
            >
                <!-- 경로 + 폴더 버튼 -->
                <div class="flex items-center gap-2 px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <nav class="flex items-center gap-1 min-w-0 flex-1 text-sm">
                        <button type="button" class="shrink-0 font-semibold text-gray-600 hover:text-blue-600" @click="select(null)">전체</button>
                        <template v-for="p in path" :key="p.folder_idx">
                            <ChevronRightIcon class="w-3 h-3 shrink-0 text-gray-300" />
                            <button
                                type="button"
                                class="truncate hover:text-blue-600"
                                :class="p.folder_idx === selectedIdx ? 'font-semibold text-gray-800' : 'text-gray-500'"
                                @click="select(p.folder_idx)"
                            >{{ p.folder_name }}</button>
                        </template>
                    </nav>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="busy" @click="newFolder(selectedIdx)">새 폴더</BaseButton>
                        <template v-if="current">
                            <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="busy" @click="renameFolder">이름 바꾸기</BaseButton>
                            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="busy" @click="deleteFolder">삭제</BaseButton>
                        </template>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto p-3 space-y-3">
                    <!-- 하위 폴더 -->
                    <div v-if="subFolders.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="c in subFolders"
                            :key="c.folder_idx"
                            type="button"
                            class="flex items-center gap-1.5 max-w-[14rem] px-2.5 py-1.5 rounded border border-gray-200 text-sm text-gray-700 hover:border-blue-300 hover:bg-blue-50/60"
                            :title="c.folder_name"
                            @dblclick="select(c.folder_idx)"
                            @click="select(c.folder_idx)"
                        >
                            <FolderIcon class="w-4 h-4 shrink-0 text-amber-400" />
                            <span class="truncate">{{ c.folder_name }}</span>
                            <span v-if="c.file_count" class="text-[11px] text-gray-400 shrink-0">{{ c.file_count }}</span>
                        </button>
                    </div>

                    <!-- 최상위 : 파일은 폴더 안에만 -->
                    <p v-if="!current" class="px-3 py-8 text-center text-sm text-gray-400 border border-dashed rounded">
                        {{ folders.length ? '폴더를 고르면 그 폴더의 파일이 보입니다.' : '먼저 폴더를 만드세요. 파일은 폴더 안에 올립니다.' }}
                    </p>

                    <template v-else>
                        <!-- 올리기 -->
                        <div v-if="canWrite" class="flex items-center gap-2 px-3 py-2.5 rounded border border-dashed" :class="dragging ? 'border-blue-500' : 'border-gray-300'">
                            <ArrowUpTrayIcon class="w-4 h-4 shrink-0 text-gray-400" />
                            <span class="flex-1 text-xs text-gray-400">
                                파일을 이 영역에 끌어다 놓거나 [파일 올리기] · 파일당 {{ MAX_MB }}MB · 한 번에 {{ MAX_FILES }}개
                            </span>
                            <input ref="fileInput" type="file" multiple class="hidden" :accept="ACCEPT" @change="onPick" />
                            <BaseButton size="sm" variant="secondary" class="shrink-0" :disabled="busy" @click="fileInput?.click()">
                                {{ uploading ? '올리는 중…' : '파일 올리기' }}
                            </BaseButton>
                        </div>
                        <p v-if="uploadError" class="text-xs text-red-500 whitespace-pre-line">{{ uploadError }}</p>

                        <!-- 파일 목록 -->
                        <div class="border border-gray-200 rounded overflow-x-auto">
                            <div class="flex items-center gap-2 px-3 py-1.5 border-b bg-gray-50/70 text-xs text-gray-500">
                                <span>파일 {{ files.length }}개</span>
                                <span v-if="checked.size" class="text-blue-600">· {{ checked.size }}개 선택</span>
                                <span class="flex-1"></span>
                                <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="!checked.size || busy" @click="moveFiles">선택 옮기기</BaseButton>
                                <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="!checked.size || busy" @click="deleteFiles">선택 삭제</BaseButton>
                            </div>
                            <table class="w-full min-w-[560px] table-fixed text-sm">
                                <colgroup>
                                    <col v-if="canSelect" class="w-9"><col><col class="w-24"><col class="w-48">
                                </colgroup>
                                <thead>
                                    <tr class="border-b text-xs text-gray-500">
                                        <th v-if="canSelect" class="py-1.5 px-2 text-center">
                                            <input type="checkbox" class="base-checkbox w-3.5 h-3.5" :checked="allChecked" :disabled="!files.length" @change="checkAll($event.target.checked)" />
                                        </th>
                                        <th class="py-1.5 px-2 text-left font-medium">파일</th>
                                        <th class="py-1.5 px-2 text-right font-medium">크기</th>
                                        <th class="py-1.5 px-2 text-left font-medium">올린 사람 · 시각</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="filesLoading">
                                        <td :colspan="canSelect ? 4 : 3" class="py-6 text-center text-xs text-gray-400">불러오는 중…</td>
                                    </tr>
                                    <tr v-else-if="!files.length">
                                        <td :colspan="canSelect ? 4 : 3" class="py-6 text-center text-xs text-gray-400">이 폴더에 파일이 없습니다.</td>
                                    </tr>
                                    <tr
                                        v-for="f in files"
                                        v-else
                                        :key="f.attachment_idx"
                                        class="border-b last:border-b-0 hover:bg-gray-50"
                                        :class="checked.has(f.attachment_idx) ? 'bg-blue-50/50' : ''"
                                    >
                                        <td v-if="canSelect" class="py-1.5 px-2 text-center">
                                            <input type="checkbox" class="base-checkbox w-3.5 h-3.5" :checked="checked.has(f.attachment_idx)" @change="toggleCheck(f.attachment_idx)" />
                                        </td>
                                        <td class="py-1.5 px-2">
                                            <a :href="route('attachment.download', f.attachment_idx)" class="flex items-center gap-1.5 min-w-0 text-blue-700 hover:underline" :title="f.file_name">
                                                <DocumentIcon class="w-4 h-4 shrink-0 text-gray-400" />
                                                <span class="truncate">{{ f.file_name }}</span>
                                            </a>
                                        </td>
                                        <td class="py-1.5 px-2 text-right text-xs text-gray-500 whitespace-nowrap">{{ sizeText(f.file_size) }}</td>
                                        <td class="py-1.5 px-2 text-xs text-gray-500 truncate" :title="uploaderText(f)">{{ uploaderText(f) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { FolderIcon, FolderOpenIcon } from '@heroicons/vue/24/solid';
import { ChevronRightIcon, DocumentIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { showAlert } from '@core/Utils/message';
import { useToast, failMsg } from '@core/Composables/useToast';

defineOptions({ layout: AppLayout });

// 서버 AttachmentService 와 맞출 것 ( AttachFiles.vue 와 같은 값 )
const MAX_MB     = 50;
const MAX_FILES  = 10;
const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'xls', 'xlsx', 'doc', 'docx', 'ppt', 'pptx', 'hwp', 'hwpx', 'txt', 'csv', 'dwg', 'dxf', 'zip'];
const ACCEPT     = EXTENSIONS.map(e => '.' + e).join(',');
const REF_TYPE   = 'doc_folder';
const CATEGORY   = 'ETC';   // 문서관리는 구분 없이 ( 폴더가 분류 )

// 트리 행의 [+] : 평소 흐리게, 행에 올리면 뚜렷하게 ( 창고관리와 같은 모양 )
const ROW_ADD = 'shrink-0 flex items-center justify-center w-6 h-6 rounded border border-transparent leading-none text-base text-gray-400 opacity-40 '
              + 'transition group-hover:opacity-100 group-hover:border-gray-200 hover:!text-white hover:!bg-blue-600 hover:!border-blue-600';

// 이 페이지( 문서관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);
const canSelect = computed(() => canWrite.value || canDelete.value);

const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )

// ---- 폴더 ----
const folders     = ref([]);          // [{ folder_idx, parent_folder_idx, folder_name, file_count }] — 서버가 이름순
const loaded      = ref(false);
const selectedIdx = ref(null);        // null = 전체( 최상위 )
const expanded    = ref(new Set());
const busy        = ref(false);

const byIdx      = computed(() => new Map(folders.value.map(f => [f.folder_idx, f])));
const childrenOf = computed(() => {
    const map = new Map();
    for (const f of folders.value) {
        const key = f.parent_folder_idx ?? null;
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(f);
    }
    return map;
});

// 펼친 폴더만 따라 내려간 행 목록 ( _depth · _hasChildren )
const tree = computed(() => {
    const out  = [];
    const walk = (parent, depth) => {
        for (const f of childrenOf.value.get(parent) ?? []) {
            const hasChildren = childrenOf.value.has(f.folder_idx);
            out.push({ ...f, _depth: depth, _hasChildren: hasChildren });
            if (hasChildren && expanded.value.has(f.folder_idx)) walk(f.folder_idx, depth + 1);
        }
    };
    walk(null, 0);
    return out;
});

const current    = computed(() => (selectedIdx.value === null ? null : byIdx.value.get(selectedIdx.value) ?? null));
const subFolders = computed(() => childrenOf.value.get(selectedIdx.value ?? null) ?? []);

// 최상위 → 고른 폴더 ( 경로 표시 )
function ancestry(idx) {
    const out  = [];
    const seen = new Set();
    for (let f = byIdx.value.get(idx); f && !seen.has(f.folder_idx); f = byIdx.value.get(f.parent_folder_idx)) {
        seen.add(f.folder_idx);
        out.unshift(f);
    }
    return out;
}
const path = computed(() => (selectedIdx.value === null ? [] : ancestry(selectedIdx.value)));

async function loadFolders() {
    try {
        const { data } = await window.axios.get(route('document.docs.folders'));
        folders.value = data.folders ?? [];
        // 지워졌거나 없어진 폴더를 고르고 있었으면 최상위로
        if (selectedIdx.value !== null && !byIdx.value.has(selectedIdx.value)) selectedIdx.value = null;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loaded.value = true;
    }
}

function select(idx) {
    selectedIdx.value = idx;
    // 고른 폴더까지의 상위는 펼친다 ( 오른쪽에서 하위 폴더 · 경로를 눌러도 트리에 보이게 )
    if (idx !== null) {
        const next = new Set(expanded.value);
        ancestry(idx).slice(0, -1).forEach(f => next.add(f.folder_idx));
        expanded.value = next;
    }
}

function toggle(idx) {
    const next = new Set(expanded.value);
    next.has(idx) ? next.delete(idx) : next.add(idx);
    expanded.value = next;
}

function expandAll(open) {
    expanded.value = open ? new Set(folders.value.map(f => f.folder_idx)) : new Set();
}

// 이름 입력 ( 서버도 같은 규칙으로 다시 검사 )
async function askName(title, value = '') {
    const r = await showAlert(title, '', 'question', 'prompt', {
        inputValue: value,
        inputAttributes: { maxlength: 100 },
        inputValidator: (v) => {
            const s = (v ?? '').trim();
            if (!s) return '폴더 이름을 입력하세요.';
            if (/[\\/:*?"<>|]/.test(s)) return '\\ / : * ? " < > | 는 쓸 수 없습니다.';
            return undefined;
        },
    });
    return r.isConfirmed ? r.value.trim() : null;
}

async function run(fn, okMsg) {
    busy.value = true;
    try {
        await fn();
        if (okMsg) showFlash(okMsg);
        return true;
    } catch (e) {
        showFlash(failMsg(e), true);
        return false;
    } finally {
        busy.value = false;
    }
}

async function newFolder(parentIdx) {
    const name = await askName(parentIdx === null ? '새 폴더 ( 최상위 )' : `새 폴더 ( ${byIdx.value.get(parentIdx)?.folder_name} 아래 )`);
    if (!name) return;
    let newIdx = null;
    const ok = await run(async () => {
        const { data } = await window.axios.post(route('document.docs.folder.save'), { parent_folder_idx: parentIdx, folder_name: name });
        newIdx = data.folder_idx;
        await loadFolders();
    }, `'${name}' 폴더를 만들었습니다.`);
    if (ok && newIdx) select(newIdx);
}

async function renameFolder() {
    const f = current.value;
    if (!f) return;
    const name = await askName('이름 바꾸기', f.folder_name);
    if (!name || name === f.folder_name) return;
    await run(async () => {
        await window.axios.post(route('document.docs.folder.save'), { folder_idx: f.folder_idx, folder_name: name });
        await loadFolders();
    }, '이름을 바꿨습니다.');
}

// 파일을 옮길 폴더 고르기 창 ( 들여쓰기 목록 · exceptIdx = 지금 폴더는 뺀다 — 하위는 고를 수 있다 )
//   SweetAlert select 는 키 순서를 따른다 → 숫자 키가 앞으로 정렬되지 않게 'f' 를 붙인다
async function pickFolder(title, exceptIdx) {
    const options = {};
    const walk = (parent, depth) => {
        for (const f of childrenOf.value.get(parent) ?? []) {
            if (f.folder_idx !== exceptIdx) options['f' + f.folder_idx] = '　'.repeat(depth) + f.folder_name;
            walk(f.folder_idx, depth + 1);
        }
    };
    walk(null, 0);
    if (!Object.keys(options).length) {
        showFlash('옮길 수 있는 폴더가 없습니다.', true);
        return null;
    }

    const r = await showAlert(title, '', 'question', 'confirm', {
        input: 'select',
        inputOptions: options,
        inputPlaceholder: '옮길 폴더를 고르세요',
        confirmButtonText: '옮기기',
        inputValidator: (v) => (v ? undefined : '옮길 폴더를 고르세요.'),
    });
    return r.isConfirmed ? Number(r.value.slice(1)) : null;
}

// 자기 자신 + 하위 전부
function selfAndDescendants(idx) {
    const out   = new Set([idx]);
    const stack = [idx];
    while (stack.length) {
        for (const c of childrenOf.value.get(stack.pop()) ?? []) {
            if (!out.has(c.folder_idx)) {
                out.add(c.folder_idx);
                stack.push(c.folder_idx);
            }
        }
    }
    return out;
}

// 삭제 : 하위 폴더 어디에도 파일이 없으면 하위째 삭제 · 파일이 있으면 경고만 ( 서버도 다시 검사 )
async function deleteFolder() {
    const f = current.value;
    if (!f) return;

    const idxs     = [...selfAndDescendants(f.folder_idx)];
    const withFile = idxs.map(i => byIdx.value.get(i)).filter(x => x?.file_count > 0);
    if (withFile.length) {
        const total = withFile.reduce((s, x) => s + x.file_count, 0);
        // 지우려던 폴더 기준 경로 ( 예: 성적서 > 2026 )
        //   깊으면 가운데를 줄인다 ( 루트 > … > 마지막 두 단계 )
        const rel   = (x) => {
            const names = ancestry(x.folder_idx).slice(ancestry(f.folder_idx).length - 1).map(a => a.folder_name);
            return (names.length > 4 ? [names[0], '…', ...names.slice(-2)] : names).join(' > ');
        };
        const lines = withFile.slice(0, 5).map(x => `· ${rel(x)} : ${x.file_count}개`).join('\n')
                    + (withFile.length > 5 ? `\n· 외 ${withFile.length - 5}개 폴더` : '');
        await showAlert('삭제할 수 없습니다', `'${f.folder_name}' 안에 파일 ${total}개가 있습니다.\n\n${lines}\n\n파일을 옮기거나 지운 뒤 다시 삭제하세요.`, 'warning');
        return;
    }

    const subCount = idxs.length - 1;
    const msg = subCount
        ? `'${f.folder_name}' 폴더와 하위 폴더 ${subCount}개를 삭제합니다.\n( 파일은 없습니다 )`
        : `'${f.folder_name}' 폴더를 삭제합니다.`;
    const r = await showAlert('폴더 삭제', msg, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    const parent = f.parent_folder_idx ?? null;
    const ok = await run(async () => {
        await window.axios.post(route('document.docs.folder.delete'), { folder_idx: f.folder_idx });
        await loadFolders();
    }, '폴더를 삭제했습니다.');
    if (ok) select(parent);
}

// ---- 파일 ----
const files        = ref([]);
const filesLoading = ref(false);
const checked      = ref(new Set());
const uploading    = ref(false);
const uploadError  = ref('');
const dragging     = ref(false);
const fileInput    = ref(null);

const allChecked = computed(() => files.value.length > 0 && files.value.every(f => checked.value.has(f.attachment_idx)));

async function loadFiles() {
    checked.value = new Set();
    uploadError.value = '';
    const idx = selectedIdx.value;
    if (idx === null) {
        files.value = [];
        return;
    }
    filesLoading.value = true;
    try {
        const { data } = await window.axios.get(route('attachment.list'), { params: { ref_type: REF_TYPE, ref_idx: idx } });
        if (selectedIdx.value !== idx) return;   // 그사이 다른 폴더를 골랐으면 버린다
        files.value = data.attachments ?? [];
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        if (selectedIdx.value === idx) filesLoading.value = false;
    }
}
watch(selectedIdx, loadFiles);

function toggleCheck(idx) {
    const next = new Set(checked.value);
    next.has(idx) ? next.delete(idx) : next.add(idx);
    checked.value = next;
}
function checkAll(on) {
    checked.value = on ? new Set(files.value.map(f => f.attachment_idx)) : new Set();
}

// 끌어다 놓기 : 폴더를 골랐고 쓰기 권한이 있을 때만
function canDrop() {
    return canWrite.value && current.value && !busy.value;
}
function onDragOver() {
    if (canDrop()) dragging.value = true;
}
function onDragLeave(e) {
    if (!e.currentTarget.contains(e.relatedTarget)) dragging.value = false;
}
function onDrop(e) {
    dragging.value = false;
    if (!canDrop()) return;
    upload([...(e.dataTransfer?.files ?? [])]);
}
function onPick(e) {
    upload([...(e.target.files ?? [])]);
    e.target.value = '';   // 같은 파일을 다시 고를 수 있게
}

async function upload(list) {
    if (!list.length || !current.value) return;
    uploadError.value = '';

    // 서버도 다시 검사한다. 여기서는 올리기 전에 빨리 알려 주는 것만
    const problems = [];
    if (list.length > MAX_FILES) problems.push(`한 번에 ${MAX_FILES}개까지 올릴 수 있습니다.`);
    list.forEach(f => {
        const ext = (f.name.split('.').pop() ?? '').toLowerCase();
        if (!EXTENSIONS.includes(ext)) problems.push(`${f.name} : 올릴 수 없는 형식입니다.`);
        else if (f.size > MAX_MB * 1024 * 1024) problems.push(`${f.name} : ${MAX_MB}MB 를 넘습니다.`);
    });
    if (problems.length) {
        uploadError.value = problems.join('\n');
        return;
    }

    // 하나씩 보낸다 ( 요청 크기 한도 = 파일 하나 기준 · 실패한 파일만 알려 준다 )
    const folderIdx = current.value.folder_idx;
    busy.value = uploading.value = true;
    const failed = [];
    let done = 0;
    for (const f of list) {
        const body = new FormData();
        body.append('ref_type', REF_TYPE);
        body.append('ref_idx', String(folderIdx));
        body.append('category_code', CATEGORY);
        body.append('files[]', f);
        try {
            await window.axios.post(route('attachment.upload'), body);
            done += 1;
        } catch (e) {
            failed.push(`${f.name} : ${uploadMsg(e)}`);
        }
    }
    busy.value = uploading.value = false;

    if (failed.length) uploadError.value = failed.join('\n');
    if (done) showFlash(`파일 ${done}개를 올렸습니다.`);
    await Promise.all([loadFiles(), loadFolders()]);
    if (failed.length) uploadError.value = failed.join('\n');   // loadFiles 가 지우므로 다시
}

async function moveFiles() {
    const ids = [...checked.value];
    if (!ids.length || !current.value) return;
    const to = await pickFolder(`파일 ${ids.length}개 옮기기`, current.value.folder_idx);
    if (to === null) return;
    await run(async () => {
        await window.axios.post(route('document.docs.files.move'), { attachment_idxs: ids, folder_idx: to });
        await Promise.all([loadFiles(), loadFolders()]);
    }, `파일 ${ids.length}개를 '${byIdx.value.get(to)?.folder_name}' 로 옮겼습니다.`);
}

async function deleteFiles() {
    const targets = files.value.filter(f => checked.value.has(f.attachment_idx));
    if (!targets.length) return;
    const names = targets.slice(0, 5).map(f => f.file_name).join('\n') + (targets.length > 5 ? `\n외 ${targets.length - 5}개` : '');
    const r = await showAlert('파일 삭제', `${names}\n\n파일 ${targets.length}개를 삭제합니다. 되돌릴 수 없습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;

    busy.value = true;
    let done = 0;
    const failed = [];
    for (const f of targets) {
        try {
            await window.axios.post(route('attachment.delete', f.attachment_idx));
            done += 1;
        } catch (e) {
            failed.push(`${f.file_name} : ${failMsg(e)}`);
        }
    }
    busy.value = false;
    if (failed.length) showFlash(failed.join('\n'), true);
    else showFlash(`파일 ${done}개를 삭제했습니다.`);
    await Promise.all([loadFiles(), loadFolders()]);
}

// ---- 표시 ----
function sizeText(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
    return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

function uploaderText(f) {
    const who = f.creator?.user_name || f.creator?.user_id || '-';
    const v   = f.create_datetime;
    if (!v) return who;
    const d = new Date(v);
    const p = (n) => String(n).padStart(2, '0');
    const when = isNaN(d) ? String(v).slice(0, 16).replace('T', ' ')
        : `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    return `${who} · ${when}`;
}

// 올리기 실패 문구 ( 413 = 웹서버 크기 한도 · 422 = 서버 검사 )
function uploadMsg(e) {
    if (e.response?.status === 413) return `파일이 너무 큽니다. ( 파일당 ${MAX_MB}MB )`;
    return failMsg(e);
}

onMounted(loadFolders);
</script>
