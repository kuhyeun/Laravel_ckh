<template>
    <teleport to="body">
        <div v-for="(modal, index) in modalStore.modals" :key="modal.id">
            <div
                class="modal-overlay fixed inset-0 w-full h-full bg-white/30 backdrop-blur-[1px] flex justify-center items-center"
                :class="{ 'modal-closing': modal.closing }"
                :style="{ zIndex: 1000 + index }"
                @click.self="handleOverlayClick(modal)"
            >
                <div class="modal-inner w-full flex flex-col bg-white p-6 rounded-[8px] shadow-[0_5px_15px_rgba(0,0,0,0.5)] overflow-y-auto" :class="`size-${modal.size}`">
                    <div class="flex">
                        <div class="flex-1 font-bold text-[19px]">{{ modal.props?.modalTitle }}</div>
                        <button class="list_config mr-1">
                            <Cog6ToothIcon class="w-[24px] h-[24px]" />
                        </button>
                        <button class="close_btn" @click="modalStore.close(modal.id)">
                            <XMarkIcon class="w-[24px] h-[24px]" />
                        </button>
                    </div>

                    <component
                        class="flex-1"
                        :is="modal.component"
                        v-bind="modal.props"
                        @close="modalStore.close(modal.id)"
                    />
                </div>
            </div>
        </div>
    </teleport>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useModalStore } from '@core/Stores/modalStore';
import { XMarkIcon, Cog6ToothIcon } from '@heroicons/vue/24/outline';

const modalStore = useModalStore();

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

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<style scoped>
    .size-sm { max-width: 400px; }
    .size-md { max-width: 600px; }
    .size-lg { max-width: 800px; }
    .size-xl { max-width: 1140px; }

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
