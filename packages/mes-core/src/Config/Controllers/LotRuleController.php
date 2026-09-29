<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\LotRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// LOT규칙관리 ( BAS-10 )
//   규칙 정의만 관리한다 ( 채번 · 순번 카운터는 작업지시 · 입고 · 출하 개발 때 )
//   조합 요소의 순서 있는 목록 + 구분자. 날짜 세밀도가 순번 리셋 주기
//   거래처는 입고 · 출하만, LOT번호는 출하 · 시리얼만, 시리얼은 LOT번호 필수
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
//   W_LOT_NO_SHIP_RULE 은 목록 응답의 notices 로 알린다
//   미구현 : W_SERIAL_SEQ_CAPACITY( 품목 1회 최대 생산 수량 필요 )
class LotRuleController extends Controller {

    private const SEPARATORS = ['-', '_', '.', '/', ''];

    private const ATTRIBUTES = [
        'lot_rule_name' => '규칙명',
        'rule_type'     => '기준',
        'format_spec'   => '조합',
        'separator'     => '구분자',
        'is_use'        => '사용 여부',
        'remark'        => '비고',
    ];

    // 목록 : 검색어( 규칙명 ) + 기준 + 사용 여부. 미리보기 포함
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword'   => 'nullable|string|max:100',
            'rule_type' => ['nullable', Rule::in(array_keys(LotRule::RULE_TYPES))],
            'is_use'    => 'nullable|in:Y,N',
        ]);

        $rules = LotRule::query()
            ->when($validated['keyword'] ?? null, fn ($q, $keyword) => $q->where('lot_rule_name', 'like', "%{$keyword}%"))
            ->when($validated['rule_type'] ?? null, fn ($q, $t) => $q->where('rule_type', $t))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderByRaw("FIELD(rule_type, '" . implode("','", array_keys(LotRule::RULE_TYPES)) . "')")
            ->orderBy('lot_rule_name')
            ->get()
            ->map(fn (LotRule $r) => $this->toPayload($r));

        return response()->json([
            'lot_rules' => $rules,
            // W_LOT_NO_SHIP_RULE : 출하 LOT 자동 채번인데 사용 중인 출하일 기준 규칙이 없음 ( 정책 lot.shipment_lot )
            'notices'   => array_values(array_filter([LotRule::shippingRuleWarning()])),
        ]);
    }

    // 등록/수정 ( lot_rule_idx 있으면 수정 )
    //   format_spec : [{ type, value?( TEXT ), unit?( DATE ), digits?( SEQ ), mode?( PARTNER ) }]
    public function save(Request $request) {
        $idx     = $request->input('lot_rule_idx');
        $lotRule = $idx ? LotRule::find($idx) : new LotRule();
        if ($idx && !$lotRule) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            'lot_rule_name'        => ['required', 'string', 'max:60', Rule::unique('lot_rule', 'lot_rule_name')->ignore($idx, 'lot_rule_idx')],
            'rule_type'            => ['required', Rule::in(array_keys(LotRule::RULE_TYPES))],
            'separator'            => ['present', 'nullable', Rule::in(self::SEPARATORS)],
            'is_use'               => 'required|in:Y,N',
            'remark'               => 'nullable|string|max:2000',
            'format_spec'          => 'present|array|max:12',
            'format_spec.*.type'   => ['required', Rule::in(array_keys(LotRule::ELEMENTS))],
            'format_spec.*.value'  => 'nullable|string|max:10',
            'format_spec.*.unit'   => 'nullable|string',
            'format_spec.*.digits' => 'nullable|integer',
            'format_spec.*.mode'   => 'nullable|string',
        ], [
            'lot_rule_name.unique' => '같은 이름의 규칙이 이미 있습니다. ( 미사용 포함 )',
        ], self::ATTRIBUTES);

        $type   = $validated['rule_type'];
        $spec   = array_values($validated['format_spec']);
        $errors = [];

        // E_LOT_RULE_EMPTY : 조합 요소 0개
        if (!$spec) {
            throw ValidationException::withMessages(['format_spec' => ['조합 요소를 1개 이상 넣으세요.']]);
        }

        // 요소별 정리 ( 그 요소에 필요 없는 속성은 버린다 )
        $clean = [];
        $seen  = [];
        foreach ($spec as $i => $el) {
            $t   = $el['type'];
            $key = "format_spec.{$i}";

            if (in_array($t, LotRule::SINGLE_ELEMENTS, true)) {
                // E_LOT_ELEMENT_DUP : 같은 요소 두 번
                if (isset($seen[$t])) {
                    $errors[$key] = [LotRule::ELEMENTS[$t] . ' 요소는 한 번만 넣을 수 있습니다.'];
                    continue;
                }
                $seen[$t] = $i;
            }

            switch ($t) {
                case 'TEXT':
                    $value = trim((string) ($el['value'] ?? ''));
                    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $value)) {
                        $errors[$key] = ['고정 문자는 영문 · 숫자 1~10자로 입력하세요.'];
                    }
                    $clean[] = ['type' => $t, 'value' => strtoupper($value)];
                    break;
                case 'DATE':
                    // E_LOT_DATE_UNIT : 세밀도를 고르지 않음
                    if (!in_array($el['unit'] ?? null, LotRule::DATE_UNITS, true)) {
                        $errors[$key] = ['날짜 단위( 년도 · 년월 · 년월일 )를 고르세요.'];
                    }
                    $clean[] = ['type' => $t, 'unit' => $el['unit'] ?? null];
                    break;
                case 'SEQ':
                    $digits = (int) ($el['digits'] ?? 0);
                    if ($digits < 1 || $digits > 9) {
                        $errors[$key] = ['순번 자릿수는 1 ~ 9 입니다.'];
                    }
                    $clean[] = ['type' => $t, 'digits' => $digits];
                    break;
                case 'PARTNER':
                    // E_LOT_PARTNER_SCOPE : 생산 · 시리얼 기준에 거래처
                    if (!in_array($type, LotRule::PARTNER_TYPES, true)) {
                        $errors[$key] = ['거래처는 자재( 입고 · 외주 ) · 출하일 기준에만 넣을 수 있습니다.'];
                    }
                    if (!in_array($el['mode'] ?? null, LotRule::PARTNER_MODES, true)) {
                        $errors[$key] = ['거래처를 넣는 방식( 항상 · 발행 시 선택 )을 고르세요.'];
                    }
                    $clean[] = ['type' => $t, 'mode' => $el['mode'] ?? null];
                    break;
                case 'LOT_NO':
                    // E_LOT_NO_SCOPE : 생산 · 입고 기준에 LOT번호
                    if (!in_array($type, LotRule::LOT_NO_TYPES, true)) {
                        $errors[$key] = ['LOT번호는 출하일 · 시리얼 기준에만 넣을 수 있습니다.'];
                    }
                    $clean[] = ['type' => $t];
                    break;
                default:
                    $clean[] = ['type' => $t];
            }
        }

        // E_SERIAL_NO_LOT : 시리얼인데 LOT번호 없음
        if ($type === 'SERIAL' && !isset($seen['LOT_NO'])) {
            $errors['format_spec'] = ['시리얼 규칙에는 LOT번호가 들어가야 합니다. 시리얼만 보고 어느 LOT인지 알 수 있어야 합니다.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $validated['format_spec'] = $clean;
        $validated['separator']   = $validated['separator'] ?? '';

        try {
            $lotRule->fill($validated)->save();

            return response()->json([
                'message'  => 'Success',
                'warnings' => $this->warnings($type, $clean),
                'lot_rule' => $this->toPayload($lotRule->fresh()),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('LOT 규칙 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 )
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'lot_rule_idxs'   => 'required|array|min:1',
            'lot_rule_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['lot_rule_idxs']));

        if (!LotRule::whereIn('lot_rule_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed = 0;
            LotRule::whereIn('lot_rule_idx', $idxs)->where('is_use', 'Y')->get()
                ->each(function (LotRule $lotRule) use (&$changed) {
                    $lotRule->is_use = 'N';
                    $lotRule->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('LOT 규칙 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // 경고 ( 막지 않음 ) — 화면도 같은 규칙으로 저장 전에 보여 준다
    private function warnings(string $type, array $spec): array {
        $warnings = [];
        $byType   = collect($spec)->keyBy('type');
        $seq      = $byType->get('SEQ');

        if (!$seq) {
            // W_LOT_RULE_NO_SEQ
            $warnings[] = '순번이 없어 같은 날짜 · 품목에서 번호가 겹칠 수 있습니다.';
        } elseif ($type !== 'SERIAL') {
            // W_LOT_SEQ_CAPACITY : 세밀도 대비 순번 자릿수 부족 ( 시리얼은 LOT 단위 리셋이라 제외 )
            $unit = $byType->get('DATE')['unit'] ?? 'NONE';
            if ($seq['digits'] < LotRule::MIN_SEQ_DIGITS[$unit]) {
                $cap    = str_repeat('9', $seq['digits']);
                $period = ['YY' => '연', 'YYMM' => '월', 'YYMMDD' => '일', 'NONE' => '전체 기간'][$unit];
                $warnings[] = "순번 {$seq['digits']}자리는 {$period} {$cap}건이 상한입니다. 넘으면 발행이 막힙니다.";
            }
        }

        if ($type === 'SHIPPING' && $byType->has('LOT_NO')) {
            // W_SHIP_LOT_SPLIT
            $warnings[] = 'LOT번호가 든 출하 규칙은 제품 LOT마다 출하 LOT이 따로 발행됩니다.';
        }

        return $warnings;
    }

    private function toPayload(LotRule $r): array {
        $spec = $r->format_spec ?? [];

        return $r->only(['lot_rule_idx', 'lot_rule_name', 'rule_type', 'format_spec', 'separator', 'is_use', 'remark']) + [
            'preview' => LotRule::render($spec, $r->separator ?? '', LotRule::sampleContext()),
        ];
    }
}
