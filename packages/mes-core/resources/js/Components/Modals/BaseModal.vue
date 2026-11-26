<template>
    <teleport to="body">
        <div v-for="(modal, index) in modalStore.modals" :key="modal.id">
            <div
                class="modal-overlay fixed inset-0 w-full h-full bg-white/30 backdrop-blur-[1px] flex justify-center items-start"
                :class="{ 'modal-closing': modal.closing }"
                :style="{ zIndex: 1000 + index }"
                @click.self="handleOverlayClick(modal)"
            >
                <!-- 11-25 : 머리 · 본문 두 칸 — 머리는 끝까지 색칠( 연한 파랑 ) · 본문만 여백 p-3 · 본문만 스크롤( 제목 고정 ) -->
                <div class="modal-inner w-full flex flex-col bg-white rounded-[8px] shadow-[0_5px_15px_rgba(0,0,0,0.5)] overflow-hidden" :class="`size-${modal.size}`">
                    <div class="modal-head shrink-0 flex items-center gap-2 px-4 py-2.5 bg-blue-50 border-b border-blue-100">
                        <div class="flex-1 min-w-0 truncate font-bold text-[17px] text-gray-800" :title="modal.props?.modalTitle">{{ modal.props?.modalTitle }}</div>
                        <!-- <button class="list_config mr-1">
                            <Cog6ToothIcon class="w-[24px] h-[24px]" />
                        </button> -->
                        <button class="close_btn shrink-0 rounded p-0.5 text-gray-500 hover:text-gray-800 hover:bg-blue-100" @click="modalStore.close(modal.id)">
                            <XMarkIcon class="w-[22px] h-[22px]" />
                        </button>
                    </div>

                    <div class="modal-body flex-1 min-h-0 flex flex-col p-3 overflow-y-auto">
                        <!-- !mt-0 : 화면들 첫 줄의 mt-3 은 제목과 띄우던 간격 — 머리가 따로라 필요 없다
                             줄어들지 않게( flex 1 0 auto · 11-25 ) — 화면 맨 위에 min-h-0 이 있으면 본문 높이에 맞춰 줄어들고 넘친 내용( 단추 줄 )이
                             상자 밖으로 삐져나와 아래 이름표와 겹쳤다 ( 거래처 수정 + 입고검사 칸 ) → 본문이 스크롤
                             전체 화면( size-full · 높이 고정 )만 예전처럼 줄여서 화면 안쪽이 나눠 쓴다 -->
                        <component
                            class="!mt-0"
                            :class="modal.size === 'full' ? 'flex-1 min-h-0' : 'flex-[1_0_auto]'"
                            :is="modal.component"
                            v-bind="modal.props"
                            @close="modalStore.close(modal.id)"
                        />
                        <!-- 이름표 줄 : 보일 때만 ( 빈 줄도 여백이 남아 창에 스크롤이 생겼다 · 11-25 ) · mt-3 = 단추 줄( ModalFooter )이 아래 여백만큼 내려온 자리 다음 -->
                        <div v-if="showSourceTag" class="flex justify-end mt-3 -mb-1.5 -mr-1.5"><DevSourceTag :name="sourceName(modal.component)" /></div>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { useModalStore } from '@core/Stores/modalStore';
import { XMarkIcon, Cog6ToothIcon } from '@heroicons/vue/24/outline';
import DevSourceTag from '@core/Components/Common/DevSourceTag.vue';
import { usePage } from '@inertiajs/vue3';

const modalStore = useModalStore();
// 화면 파일 이름표 : 시스템 계정 + 시스템 설정 켬 ( DevSourceTag 와 같은 조건 )
const inertiaPage = usePage();
const showSourceTag = computed(() => inertiaPage.props.auth?.user?.user_level === 0 && inertiaPage.props.devSourceTag !== false);

// 모달 본체의 파일 이름 ( Vue SFC 가 붙이는 __name · 개발 서버는 __file 로 경로까지 )
const sourceName = (c) => {
    const file = c?.__file;
    if (file) {
        const i = file.indexOf('resources/js/');
        return i >= 0 ? file.slice(i + 'resources/js/'.length) : file.split('/').pop();
    }
    return c?.__name ? `${c.__name}.vue` : '';
};

const handleOverlayClick = (modal) => {
    if( modal.closeOnClickOutside ) {
        modalStore.close( modal.id );
    }
};

const handleKeydown = (e) => {
    if( e.key === 'Escape' && modalStore.modals.length > 0 ) {
        const topModal = modalStore.modals[modalStore.modals.length - 1];
        
        if( topModal.closeOnEsc ) {
            modalStore.close(); // 최상단 모달을 닫습니다.
        }
    }
};

// 뒤로 · 앞으로 가기 ( 11-26 ) — 브라우저 기록이 바뀌면 떠 있는 모달을 모두 닫는다
//   ( 전에는 모달이 남은 채 뒤 화면만 바뀌었다 · 저장 안 한 입력도 닫힌다 — 이동이 먼저 )
const closeAllOnHistory = () => {
    if (modalStore.modals.length) modalStore.modals = [];
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    window.addEventListener('popstate', closeAllOnHistory);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('popstate', closeAllOnHistory);
});
</script>

<style scoped>
    /* 위쪽 고정 : 가운데 정렬이면 탭을 바꿔 높이가 달라질 때 위쪽이 움직인다 → 위에서 일정 거리에 두고 아래로만 늘고 줄게
       화면보다 길면 모달 안에서 스크롤 */
    .modal-overlay {
        padding-top: 6vh;
    }
    .modal-overlay .modal-inner {
        max-height: 88vh;
    }

    .size-sm { max-width: 400px; }
    .size-md { max-width: 600px; }
    .size-lg { max-width: 800px; }
    .size-xl { max-width: 1240px; }
    /* 거의 전체 화면 ( LOT 추적 흐름도 등 — 높이 고정 · 안쪽이 나눠 쓴다 ) */
    .size-full { max-width: 96vw; height: 88vh; }

    .modal-overlay {
        animation: fadeIn 0.25s ease;
    }
    .modal-overlay .modal-inner {
        animation: slideUp 0.25s ease;
    }
    .modal-closing {
        opacity: 0;
        transition: opacity 0.25s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>
