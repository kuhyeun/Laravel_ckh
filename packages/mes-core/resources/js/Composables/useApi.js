import { ref } from 'vue';
import apiClient from '../api'; 

export function useApi() {
  
    const data = ref(null);       // API 응답 데이터를 담을 변수
    const isLoading = ref(false); // 로딩 상태 (UI에 로딩 스피너 등을 표시할 때 사용)
    const error = ref(null);      // 에러 객체를 담을 변수

    const get = async ( url, params = {} ) => {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await apiClient.get(url, { params });
            data.value = response.data;

            return response.data;
        } catch (err) {
            console.error(`[API GET Error] ${url}:`, err);
            error.value = err;
            data.value = null;
        } finally {
            isLoading.value = false;
        }
    };

    const post = async ( url, payload ) => {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await apiClient.post(url, payload);
            data.value = response.data; // 성공 시 응답 데이터를 data 상태에 저장

            return response.data;
        } catch (err) {
            console.error(`[API POST Error] ${url}:`, err);
            error.value = err;
            throw err; // 에러를 다시 던져서, 호출한 컴포넌트에서도 try/catch로 추가 처리를 할 수 있게 합니다.
        } finally {
            isLoading.value = false;
        }
    };

    return {
        data,
        isLoading,
        error,
        get,
        post,
    };
}
