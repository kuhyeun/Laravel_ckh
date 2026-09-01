import { defineStore } from 'pinia';
import { shallowRef } from 'vue';

export const useModalStore = defineStore('modal', {
  // state: 모달들을 배열(스택)으로 관리합니다.
  state: () => ({
    modals: [],
    _nextId: 0,
  }),

  actions: {
    /**
     * 모달을 엽니다.
     * @param {Object} component - 표시할 Vue 컴포넌트
     * @param {Object} props - 컴포넌트에 전달할 props
     * @param {Object} options - 추가 옵션 (e.g., { size: 'lg', closeOnEsc: false })
     */
    open(component, props = {}, options = {}) {
      // 컴포넌트는 shallowRef로 감싸 성능을 최적화합니다.
      const componentRef = shallowRef(component);
      
      this.modals.push({
        id: ++this._nextId,
        component: componentRef,
        props,
        size: options.size || 'md', // 기본 사이즈는 'md'
        // 옵션이 명시적으로 false가 아니면 모두 true로 처리
        closeOnEsc: options.closeOnEsc !== false,
        closeOnClickOutside: options.closeOnClickOutside !== false,
      });
    },

    /**
     * 모달을 닫습니다.
     * @param {Number} id - 닫을 모달의 고유 ID. 없으면 최상단 모달을 닫습니다.
     */
    close(id) {
      const targetId = id || this.modals[this.modals.length - 1]?.id;
      if (!targetId) return;

      // closing 상태로 변경 (트랜지션 트리거)
      const modal = this.modals.find(m => m.id === targetId);
      if (modal) modal.closing = true;

      // 트랜지션 완료 후 실제 제거
      setTimeout(() => {
        this.modals = this.modals.filter(m => m.id !== targetId);
      }, 250);
    },
  },
});
