<?php

namespace MesCore\Production\Controllers;

use Illuminate\Http\Request;
use MesCore\Http\Controller;
use MesCore\Production\Services\SubconMaterialService;

// 외주 건 자재 출고 ( 자재관리 > 출고관리 [외주] · 11-23 · docs/외주발주-설계.md ) — 권한은 자재출고 메뉴
//   kind ORDER = 외주 발주 줄 · ISSUE = 발주 없이 나간 외주 출고 / ref_idx = 그 IDX
class SubconMaterialController extends Controller {
    public function __construct(private SubconMaterialService $svc) {}

    private const REF = ['kind' => 'required|in:ORDER,ISSUE', 'ref_idx' => 'required|integer'];

    public function cases(Request $request) {
        $v = $request->validate(['keyword' => 'nullable|string|max:100']);
        return response()->json(['rows' => $this->svc->cases($v['keyword'] ?? null)]);
    }

    public function plan(Request $request) {
        $v = $request->validate(self::REF + ['items' => 'nullable|array|max:100', 'items.*' => 'integer']);   // 화면에서 고른 품목 ( 11-24 )
        return response()->json($this->svc->plan($v['kind'], (int) $v['ref_idx'], $v['items'] ?? []) + ['now' => now()->format('Y-m-d\TH:i')]);
    }

    public function add(Request $request) {
        $v = $request->validate(self::REF + ['item_idx' => 'required|integer'], [], ['item_idx' => '자재']);
        return response()->json(['message' => 'Success', 'work_order_material_idx' => $this->svc->addMaterial($v['kind'], (int) $v['ref_idx'], (int) $v['item_idx'])]);
    }

    public function save(Request $request) {
        $d = $request->validate(self::REF + [
            'occurred_at'                     => 'required|date_format:Y-m-d\TH:i',
            'remark'                          => 'nullable|string|max:200',
            'lines'                           => 'required|array|min:1|max:500',
            'lines.*.work_order_material_idx' => 'nullable|integer',
            'lines.*.item_idx'                => 'nullable|integer',   // 「출고 전」 자재 ( 줄이 없으면 출고할 때 만든다 · 11-24 )
            'lines.*.stock_balance_idx'       => 'required|integer',
            'lines.*.qty'                     => 'required|numeric|min:0|max:999999999999',
        ], [], ['occurred_at' => '출고일시', 'remark' => '비고', 'lines' => '출고 수량', 'lines.*.qty' => '출고 수량']);
        $data = ['occurred_at' => $d['occurred_at'], 'remark' => $d['remark'] ?? null, 'lines' => $d['lines']];
        [$txn, $warnings] = $this->svc->issue($d['kind'], (int) $d['ref_idx'], $data);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'txn_no' => $txn->txn_no]);
    }

    // 재고관리 LOT 창 [출고 → 외주] ( 11-23 ) — 그 재고 줄에서 수량만큼
    public function quick(Request $request) {
        $d = $request->validate(self::REF + [
            'stock_balance_idx' => 'required|integer',
            'qty'               => 'required|numeric|gt:0|max:999999999999',
            'occurred_at'       => 'required|date_format:Y-m-d\TH:i',
            'remark'            => 'nullable|string|max:200',
        ], ['qty.gt' => '수량은 0보다 커야 합니다.', 'ref_idx.required' => '외주 건을 고르세요.'], ['qty' => '수량', 'occurred_at' => '출고일시', 'remark' => '비고', 'ref_idx' => '외주 건']);
        [$txn, $warnings] = $this->svc->issueBalance($d['kind'], (int) $d['ref_idx'], (int) $d['stock_balance_idx'],
            ['occurred_at' => $d['occurred_at'], 'remark' => $d['remark'] ?? null, 'qty' => (float) $d['qty']]);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'txn_no' => $txn->txn_no]);
    }

    // 출고한 적 없는 추가 자재 줄 지우기 ( ✕ · 11-24 )
    public function remove(Request $request) {
        $v = $request->validate(self::REF + ['work_order_material_idx' => 'required|integer']);
        $this->svc->removeMaterial($v['kind'], (int) $v['ref_idx'], (int) $v['work_order_material_idx']);
        return response()->json(['message' => 'Success']);
    }

    public function cancel(Request $request) {
        $v = $request->validate(self::REF + ['stock_txn_line_idxs' => 'required|array|min:1|max:200', 'stock_txn_line_idxs.*' => 'integer']);
        return response()->json(['message' => 'Success', 'canceled' => $this->svc->cancel($v['kind'], (int) $v['ref_idx'], $v['stock_txn_line_idxs'])]);
    }
}
