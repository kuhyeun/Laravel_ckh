<?php

namespace MesCore\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MesCore\Http\Controller;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;

// 바코드 · QR 스캔 → 이동 ( 11-26 ) — 화면 어디서든 찍은 코드( TopFrame 스캔 · useScanner )를 풀어 이동할 주소를 준다
//   작업지시 : 지시 번호( WO-… ) 또는 주소 …/go/wo/{지시 번호}            → 공정실적관리 ?wo={idx}
//   공정     : OP-{work_order_op_idx}  또는 주소 …/go/op/{work_order_op_idx} → 공정실적관리 ?wo={idx}&op={idx}&run=1 ( 실적 등록 창까지 )
//   작업지시서 : 지시서 번호( WO-… · 줄 번호 없이 · 공정흐름표 아래 코드 ) → 작업지시관리를 그 번호로 검색( ?kw= · 모달은 열지 않음 · 11-26 )
//                권한 = 작업지시관리 읽기 ( 전에는 공정실적관리 — 줄이 하나면 그 지시 창까지 열었다 )
//   LOT ( 11-26 ) : 물품표 · 현품표 바코드 · LOT 번호 → 이동 없이 LOT 추적 창 ( type LOT · lot = { lot_idx, lot_no, item…, dir } — 자재 = 정추적 · 그 밖 = 역추적 )
//          작업지시 · 공정 번호가 아닐 때만 · 폐기된 라벨은 이유만 · 권한 = LOT 관리 읽기
//   권한 : 공정실적관리 읽기 ( 없으면 이유만 )
//   go() : 휴대폰 카메라로 QR 주소를 열었을 때 — 같은 풀이로 바로 넘긴다 ( 로그인 안 했으면 로그인 뒤 )
class ScanController extends Controller {

    public function __construct(private MenuPermissionService $perm) {}

    public function resolve(Request $request) {
        $v = $request->validate(['code' => 'required|string|max:300']);
        return response()->json($this->find(trim($v['code'])));
    }

    public function go(string $type, string $key) {
        $r = $this->find($type === 'op' ? "OP-{$key}" : $key);
        abort_unless($r['url'], 404, $r['message'] ?? '찾을 수 없습니다.');
        return redirect($r['url']);
    }

    // 코드 → { type, label, url, message }
    private function find(string $code): array {
        $miss = fn (string $m) => ['type' => null, 'label' => null, 'url' => null, 'message' => $m];
        // 주소면 /go/{wo|op}/{값} 만 본다
        if (preg_match('#/go/(wo|op)/([^/?\#\s]+)#i', $code, $m)) {
            $code = strtolower($m[1]) === 'op' ? 'OP-' . urldecode($m[2]) : urldecode($m[2]);
        }
        $base = $this->perm->canPageForMe('Production/OpResult', 'can_read')
            ? Menu::where('page_component', 'Production/OpResult')->orderByRaw("read_only = 'N' DESC")->value('url_path') : null;

        // 공정
        if (preg_match('/^OP-(\d+)$/i', $code, $m)) {
            $op = DB::table('work_order_op as o')->join('work_order as w', 'w.work_order_idx', '=', 'o.work_order_idx')
                ->leftJoin('process as p', 'p.process_idx', '=', 'o.process_idx')
                ->where('o.work_order_op_idx', (int) $m[1])->first(['o.work_order_op_idx', 'o.work_order_idx', 'w.wo_no', 'p.process_name']);
            if (!$op) return $miss("{$code} : 공정을 찾을 수 없습니다.");
            if (!$base) return $miss('공정실적관리 권한이 없습니다.');
            return ['type' => 'OP', 'label' => "{$op->wo_no} · {$op->process_name}", 'url' => "{$base}?wo={$op->work_order_idx}&op={$op->work_order_op_idx}&run=1", 'message' => null];
        }
        // 작업지시 ( 번호 그대로 )
        $wo = DB::table('work_order')->where('wo_no', $code)->first(['work_order_idx', 'wo_no']);
        if ($wo) {
            if (!$base) return $miss('공정실적관리 권한이 없습니다.');
            return ['type' => 'WO', 'label' => $wo->wo_no, 'url' => "{$base}?wo={$wo->work_order_idx}", 'message' => null];
        }
        // 작업지시서 → 작업지시관리에서 그 번호로 검색 ( 11-26 )
        $sheet = DB::table('work_order_sheet')->where('sheet_no', $code)->first(['sheet_idx', 'sheet_no']);
        if ($sheet) {
            $woBase = $this->perm->canPageForMe('Production/WorkOrderManage', 'can_read')
                ? Menu::where('page_component', 'Production/WorkOrderManage')->orderByRaw("read_only = 'N' DESC")->value('url_path') : null;
            if (!$woBase) return $miss('작업지시관리 권한이 없습니다.');
            return ['type' => 'SHEET', 'label' => $sheet->sheet_no, 'url' => "{$woBase}?kw=" . urlencode($sheet->sheet_no), 'message' => null];
        }
        return $this->lot($code, $miss);
    }

    // 물품표 · 현품표 · LOT 번호 → LOT 추적 창 ( 11-26 )
    private function lot(string $code, \Closure $miss): array {
        $r = app(\MesCore\Label\Services\LabelService::class)->lookup($code);
        if ($r['status'] === 'SCRAPPED') {
            $why = implode(' · ', array_filter([$r['scrap_reason'] ?? null, $r['scrapped_at'] ?? null]));
            return $miss("{$code} : 폐기된 라벨입니다." . ($why ? " ( {$why} )" : ''));
        }
        $lot = $r['status'] === 'OK' && !empty($r['lot_idx'])
            ? \MesCore\Stock\Models\Lot::with('item:item_idx,item_code,item_name,spec,item_type')->find($r['lot_idx']) : null;
        if (!$lot) {
            return $miss("{$code} : 작업지시 · 공정 · LOT 코드가 아닙니다.");
        }
        if (!$this->perm->canPageForMe('Shipment/LotTrace', 'can_read')) {
            return $miss('LOT 관리 권한이 없어 LOT 추적을 열 수 없습니다.');
        }
        return ['type' => 'LOT', 'label' => $lot->lot_no, 'url' => null, 'message' => null, 'lot' => [
            'lot_idx' => (int) $lot->lot_idx, 'lot_no' => $lot->lot_no,
            'item_code' => $lot->item?->item_code, 'item_name' => $lot->item?->item_name, 'item_spec' => $lot->item?->spec,
            'dir' => $lot->item?->item_type === 'MATERIAL' ? 'forward' : 'backward',   // 자재 = 정추적( 자재 → 출하 ) · 제품 = 역추적
        ]];
    }
}
