<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Equipment;
use MesCore\Config\Models\ProcessEquipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// 설비관리 ( BAS-07 )
//   등록만 한다 ( 설비보전·유휴관리는 Phase 3 ). 삭제는 미사용 처리( is_use = N, ADR 0019 )
//   물림 손실( setup_loss )은 여기가 아니라 공정관리의 공정 × 설비 에 있다
class EquipmentController extends Controller {

    private const ATTRIBUTES = [
        'equipment_code' => '설비코드',
        'equipment_name' => '설비명',
        'manager_idx'     => '설비담당자( 정 )',
        'sub_manager_idx' => '설비담당자( 부 )',
        'purchase_date'   => '구매일자',
        'purchase_cost'   => '구매비용',
        'check_cycle'     => '점검 기준',
        'check_base'      => '점검 기준날짜',
        'is_use'         => '사용 여부',
        'remark'         => '비고',
    ];

    // 목록 : 검색어( 코드·명 ) + 사용 여부. 이 설비를 쓰는 공정 수 포함
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'is_use'  => 'nullable|in:Y,N',
        ]);

        $equipments = Equipment::query()
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('equipment_code', 'like', "%{$keyword}%")
                                       ->orWhere('equipment_name', 'like', "%{$keyword}%"));
            })
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderBy('equipment_code')
            ->get();

        $processCounts = ProcessEquipment::selectRaw('equipment_idx, COUNT(*) c')
            ->whereIn('equipment_idx', $equipments->pluck('equipment_idx'))
            ->groupBy('equipment_idx')
            ->pluck('c', 'equipment_idx');

        $names = User::namesFor($equipments->pluck('manager_idx')->merge($equipments->pluck('sub_manager_idx')));
        $equipments->each(function (Equipment $e) use ($processCounts, $names) {
            $e->process_count     = (int) ($processCounts[$e->equipment_idx] ?? 0);
            $e->manager_name      = $names[$e->manager_idx] ?? null;      // 설비담당자( 정 ) 이름 ( 11-19 )
            $e->sub_manager_name  = $names[$e->sub_manager_idx] ?? null;
            $e->check_text        = $e->checkText();                       // 점검 기준 글자
            $e->next_check_date   = $e->nextCheckDate()?->toDateString();   // 다음 점검일 ( 월간 : 없는 날은 말일 )
        });

        return response()->json([
            'equipments' => $equipments,
            // 설비담당자 선택지 = 사용자관리의 사용 중 사용자 ( 시스템 계정 제외 )
            'users'      => User::pickable()->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
        ]);
    }

    // 등록/수정 ( equipment_idx 있으면 수정 )
    public function save(Request $request) {
        $idx       = $request->input('equipment_idx');
        $equipment = $idx ? Equipment::find($idx) : new Equipment();
        if ($idx && !$equipment) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            'equipment_code' => ['required', 'string', 'max:30', Rule::unique('equipment', 'equipment_code')->ignore($idx, 'equipment_idx')],
            'equipment_name' => 'required|string|max:60',
            'manager_idx'     => ['nullable', 'integer', User::pickableRule()],
            'sub_manager_idx' => ['nullable', 'integer', User::pickableRule(), 'different:manager_idx'],
            'purchase_date'   => 'nullable|date_format:Y-m-d',
            'purchase_cost'   => 'nullable|numeric|min:0|max:999999999999999',
            'check_cycle'     => ['required', Rule::in(array_keys(Equipment::CHECK_CYCLES))],
            'check_base'      => 'nullable|string|max:10',
            'is_use'         => 'required|in:Y,N',
            'remark'         => 'nullable|string|max:2000',
        ], ['sub_manager_idx.different' => '설비담당자( 부 )는 ( 정 )과 다른 사람이어야 합니다.'], self::ATTRIBUTES);

        // 점검 기준날짜 : 점검 기준에 맞는 값만 ( 미지정이면 비운다 )
        $base = trim((string) ($validated['check_base'] ?? ''));
        $bad  = match ($validated['check_cycle']) {
            'WEEKLY'  => !array_key_exists($base, Equipment::WEEKDAYS),
            'MONTHLY' => !ctype_digit($base) || (int) $base < 1 || (int) $base > 31,
            'YEARLY'  => !preg_match('/^\d{4}-\d{2}-\d{2}$/', $base) || !checkdate((int) substr($base, 5, 2), (int) substr($base, 8, 2), (int) substr($base, 0, 4)),
            default   => false,
        };
        if ($bad) {
            throw \Illuminate\Validation\ValidationException::withMessages(['check_base' => [match ($validated['check_cycle']) {
                'WEEKLY' => '점검 요일을 고르세요.', 'MONTHLY' => '점검 날( 1 ~ 31일 )을 고르세요.', default => '점검 기준날짜를 고르세요.',
            }]]);
        }
        $validated['check_base'] = $validated['check_cycle'] === 'NONE' ? null : (string) ($validated['check_cycle'] === 'MONTHLY' ? (int) $base : $base);

        try {
            $equipment->fill($validated)->save();

            return response()->json(['message' => 'Success', 'equipment' => $equipment->fresh()], 200);
        } catch (\Throwable $e) {
            Log::error('설비 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 ). 이미 미사용은 건너뛰고, 대상이 하나도 없으면 404
    //   공정에 연결된 설비도 미사용 처리는 허용 ( 기존 공정의 설비 목록에는 남고, 새로 고를 수는 없음 )
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'equipment_idxs'   => 'required|array|min:1',
            'equipment_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['equipment_idxs']));

        if (!Equipment::whereIn('equipment_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed = 0;
            Equipment::whereIn('equipment_idx', $idxs)->where('is_use', 'Y')->get()
                ->each(function (Equipment $equipment) use (&$changed) {
                    $equipment->is_use = 'N';
                    $equipment->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('설비 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }
}
