// 바코드 조회 — 스캔 화면( CMP-04 · 자재 투입 · 출하 스캔 등 )이 찍은 번호를 먼저 여기로 보낸다
//   const { lookupBarcode } = useBarcodeLookup();
//   const r = await lookupBarcode('L2611-0001');   // { status: 'OK' | 'SCRAPPED' | 'UNKNOWN', target, lot_idx, shipment_line_idx, ... }
//   폐기된 라벨( SCRAPPED )이면 경고창을 띄운다 ( 사유 · 일시 ) — 화면은 status 가 OK 일 때만 다음 처리
//   서버 label.lookup ( 같은 번호가 여러 번 발행돼도 살아 있는 라벨이 하나라도 있으면 OK )
import { route } from 'ziggy-js';
import { showAlert } from '@core/Utils/message';

export function useBarcodeLookup() {
    async function lookupBarcode(barcodeNo, { alertScrapped = true, alertUnknown = false } = {}) {
        const no = String(barcodeNo ?? '').trim();
        if (!no || !route().has('label.lookup')) return { status: 'UNKNOWN', barcode_no: no };

        const { data } = await window.axios.get(route('label.lookup'), { params: { barcode_no: no } });

        if (data.status === 'SCRAPPED' && alertScrapped) {
            const why = [data.scrap_reason, data.scrapped_at].filter(Boolean).join(' · ');
            await showAlert('폐기된 라벨', `${no}\n폐기된 라벨입니다.${why ? `\n( ${why} )` : ''}\n이 라벨은 쓸 수 없습니다. 라벨을 떼고 새 라벨을 확인하세요.`, 'error');
        } else if (data.status === 'UNKNOWN' && alertUnknown) {
            await showAlert('알 수 없는 바코드', `${no}\n발행 기록이 없는 번호입니다.`, 'warning');
        }
        return data;
    }

    return { lookupBarcode };
}
