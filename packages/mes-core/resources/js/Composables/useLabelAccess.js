// 바코드 라벨 버튼을 그릴지 — 기능 스위치( feature.barcode ) · 대상별 발행 권한 ( 서버 label.access )
//   const label = useLabelAccess();   label.can_lot / label.can_shipment
//   페이지 이동마다 한 번만 묻는다 ( 같은 화면 안의 여러 창이 같이 쓴다 ) · Label 모듈이 꺼져 있으면 라우트가 없다 → 전부 false
//   조회 전용 메뉴( 입고이력 등 — page.props.readOnly )에서는 발행하지 않는다 → 전부 false
import { reactive } from 'vue';
import { route } from 'ziggy-js';
import { usePage } from '@inertiajs/vue3';

const state = reactive({ enabled: false, can_lot: false, can_shipment: false });
const OFF = { enabled: false, can_lot: false, can_shipment: false };
let pending = null;
let seq = 0;   // 페이지마다 새 번호 — 앞 페이지의 늦은 응답이 지금 페이지 값을 덮지 않게 ( 덮이면 조회 전용 화면에 발행 버튼이 나왔다 )

export function useLabelAccess() {
    if (!pending) {
        const my = ++seq;
        const apply = (v) => { if (my === seq) Object.assign(state, v); };
        pending = (async () => {
            try {
                if (!route().has('label.access')) return;
                if (usePage().props?.readOnly) {
                    apply(OFF);
                    return;
                }
                const { data } = await window.axios.get(route('label.access'));
                apply({ enabled: !!data.enabled, can_lot: !!data.can_lot, can_shipment: !!data.can_shipment });
            } catch (e) {
                apply(OFF);
            }
        })();
        // 다음 페이지에서 다시 묻는다 ( 권한 · 스위치가 바뀌었을 수 있다 )
        document.addEventListener('inertia:navigate', () => { pending = null; seq++; }, { once: true });
    }
    return state;
}
