<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Equipment;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\ProcessEquipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 공정관리 ( BAS-04 )
//   공정 + 사용 가능 설비 N 을 한 번에 저장한다 ( 한 트랜잭션 )
//   시스템 기본 공정 4종( is_system = Y )은 표시만 한다 — 일반 공정과 같이 수정·미사용 처리 가능
//     ( "완료만 처리" 규칙은 작업지시·실적 기능 개발 시 is_system / system_key 로 적용 )
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
class ProcessController extends Controller {

    private const ATTRIBUTES = [
        'process_code'               => '공정코드',
        'process_name'               => '공정명',
        'process_type'               => '공정 구분',
        'class_code'                 => '구분 코드',
        'inspection_kind'            => '검사 종류',
        'is_use'                     => '사용 여부',
        'remark'                     => '비고',
        'equipments.*.equipment_idx' => '설비',
        'equipments.*.is_default'    => '기본 설비',
        'equipments.*.setup_loss'    => '물림·세팅 손실',
    ];

    // 목록 : 검색어( 코드·명 ) + 공정 구분 + 사용 여부. 설비 수 · 기본 설비명 포함
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword'      => 'nullable|string|max:100',
            'process_type' => ['nullable', Rule::in(Process::TYPES)],
            'is_use'       => 'nullable|in:Y,N',
        ]);

        $processes = Process::query()
            ->withCount('equipments')
            ->with(['equipments' => fn ($q) => $q->where('is_default', 'Y')->with('equipment:equipment_idx,equipment_name')])
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('process_code', 'like', "%{$keyword}%")
                                       ->orWhere('process_name', 'like', "%{$keyword}%"));
            })
            ->when($validated['process_type'] ?? null, fn ($q, $type) => $q->where('process_type', $type))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            // 시스템 공정을 위로
            ->orderByRaw("CASE WHEN is_system = 'Y' THEN 0 ELSE 1 END")
            ->orderBy('process_code')
            ->get()
            ->map(function (Process $p) {
                $row = $p->only([
                    'process_idx', 'process_code', 'process_name', 'process_type', 'class_code',
                    'inspection_kind', 'is_system', 'system_key', 'is_use', 'remark',
                ]);
                $row['equipments_count']       = $p->equipments_count;
                $row['default_equipment_name'] = $p->equipments->first()?->equipment?->equipment_name;
                return $row;
            });

        return response()->json(['processes' => $processes]);
    }

    // 단건 : 사용 가능 설비 포함
    public function show(int $processIdx) {
        $process = Process::with('equipments.equipment')->find($processIdx);
        if (!$process) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['process' => $this->toPayload($process)]);
    }

    // 공정 등록 화면용 설비 선택지 ( 설비관리 권한 없이도 공정 화면에서 고를 수 있도록 공정 권한으로 제공 )
    //   사용 중 설비만. 기존 공정에 연결된 미사용 설비는 화면이 현재 값으로 보여준다
    public function equipmentOptions() {
        $equipments = Equipment::select('equipment_idx', 'equipment_code', 'equipment_name')
            ->where('is_use', 'Y')
            ->orderBy('equipment_code')
            ->get();

        return response()->json(['equipments' => $equipments]);
    }

    // 등록/수정 ( process_idx 있으면 수정 )
    //   equipments : [{ process_equipment_idx?, equipment_idx, is_default, setup_loss }]
    //   요청에 없는 기존 설비 연결은 삭제된다 ( 화면 목록 = 저장 결과 )
    public function save(Request $request) {
        $idx     = $request->input('process_idx');
        $process = $idx ? Process::find($idx) : new Process();
        if ($idx && !$process) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            'process_code'    => ['required', 'string', 'max:30', Rule::unique('process', 'process_code')->ignore($idx, 'process_idx')],
            'process_name'    => 'required|string|max:60',
            'process_type'    => ['required', Rule::in(Process::TYPES)],
            // E_PROC_CLASS_CODE : 영문 대문자 1~3자
            'class_code'      => ['nullable', 'string', 'regex:/^[A-Z]{1,3}$/'],
            'inspection_kind' => ['nullable', Rule::in(Process::INSPECTION_KINDS)],
            'is_use'          => 'required|in:Y,N',
            'remark'          => 'nullable|string|max:2000',

            'equipments'                   => 'present|array',
            'equipments.*.equipment_idx'   => 'required|integer|distinct|exists:equipment,equipment_idx',
            'equipments.*.is_default'      => 'required|in:Y,N',
            'equipments.*.setup_loss'      => 'nullable|numeric|min:0|max:999999999999',
        ], [
            'class_code.regex'                    => '구분 코드는 영문 대문자 1~3자로 입력하세요.',
            'equipments.*.equipment_idx.distinct' => '같은 설비가 중복되었습니다.',
        ], self::ATTRIBUTES);

        $equipments = array_values($validated['equipments']);
        $errors     = [];

        // E_PROC_NO_EQUIP : 설비생산은 설비 1개 이상
        if ($validated['process_type'] === Process::TYPE_EQUIPMENT && count($equipments) === 0) {
            $errors['equipments'] = ['설비생산 공정은 사용 가능 설비를 1개 이상 등록하세요.'];
        }

        // E_PROC_MULTI_DEFAULT : 기본 설비는 하나
        if (collect($equipments)->where('is_default', 'Y')->count() > 1) {
            $errors['equipments'] = ['기본 설비는 하나만 지정할 수 있습니다.'];
        }

        // 새로 연결하는 설비는 사용 중이어야 함 ( 기존 연결이 미사용으로 내려간 경우는 유지 허용 )
        $currentEquipIdxs = $process->exists
            ? ProcessEquipment::where('process_idx', $process->process_idx)->pluck('equipment_idx')->flip()
            : collect();
        $activeEquipIdxs = Equipment::whereIn('equipment_idx', collect($equipments)->pluck('equipment_idx'))
            ->where('is_use', 'Y')->pluck('equipment_idx')->flip();
        foreach ($equipments as $i => $row) {
            if (!$currentEquipIdxs->has($row['equipment_idx']) && !$activeEquipIdxs->has($row['equipment_idx'])) {
                $errors["equipments.{$i}.equipment_idx"] = ['사용 중인 설비를 선택하세요.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // 검사 종류는 검사 공정만 ( 검사 공정인데 비우면 일반 )
        $validated['inspection_kind'] = $validated['process_type'] === Process::TYPE_INSPECTION
            ? ($validated['inspection_kind'] ?? 'NORMAL')
            : null;

        try {
            DB::transaction(function () use ($process, $validated, $equipments) {
                $process->fill(collect($validated)->except('equipments')->all())->save();
                $this->syncEquipments($process, $equipments);
            });

            $process = Process::with('equipments.equipment')->find($process->process_idx);

            return response()->json(['message' => 'Success', 'process' => $this->toPayload($process)], 200);
        } catch (\Throwable $e) {
            Log::error('공정 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 )
    //   이미 미사용은 건너뜀. 대상이 하나도 없으면 404
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'process_idxs'   => 'required|array|min:1',
            'process_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['process_idxs']));

        if (!Process::whereIn('process_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed = 0;
            Process::whereIn('process_idx', $idxs)->where('is_use', 'Y')->get()
                ->each(function (Process $process) use (&$changed) {
                    $process->is_use = 'N';
                    $process->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('공정 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // 설비 연결 동기화 : 같은 설비는 기존 행을 갱신( 설비 기준 매칭 ), 빠진 설비는 삭제
    private function syncEquipments(Process $process, array $rows): void {
        $existing = ProcessEquipment::where('process_idx', $process->process_idx)->get()->keyBy('equipment_idx');
        $keep     = [];

        foreach ($rows as $order => $row) {
            $link = $existing->get($row['equipment_idx'])
                ?? new ProcessEquipment(['process_idx' => $process->process_idx, 'equipment_idx' => $row['equipment_idx']]);

            $link->fill([
                'is_default' => $row['is_default'],
                'setup_loss' => $row['setup_loss'] ?? 0,
                'sort_order' => $order,
            ])->save();

            $keep[] = $link->process_equipment_idx;
        }

        ProcessEquipment::where('process_idx', $process->process_idx)
            ->whereNotIn('process_equipment_idx', $keep ?: [0])
            ->delete();
    }

    private function toPayload(Process $process): array {
        $data = $process->only([
            'process_idx', 'process_code', 'process_name', 'process_type', 'class_code',
            'inspection_kind', 'is_system', 'system_key', 'is_use', 'remark',
        ]);

        $data['equipments'] = $process->equipments->map(fn ($pe) => [
            'process_equipment_idx' => $pe->process_equipment_idx,
            'equipment_idx'         => $pe->equipment_idx,
            'equipment_code'        => $pe->equipment?->equipment_code,
            'equipment_name'        => $pe->equipment?->equipment_name,
            'equipment_is_use'      => $pe->equipment?->is_use,
            'is_default'            => $pe->is_default,
            'setup_loss'            => $pe->setup_loss,
        ])->values();

        return $data;
    }
}
