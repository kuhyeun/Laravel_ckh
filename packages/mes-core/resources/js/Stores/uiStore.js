import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useUiStore = defineStore('ui', () => {
    // State: 현재 페이지 제목을 저장하는 반응형 변수
    const title = ref( 'Top Frame Area' ); // 기본 제목

    // Action: 페이지 제목을 변경하는 함수
    function setTitle(newTitle) {
        title.value = newTitle;
    }

    return { title, setTitle };
});
