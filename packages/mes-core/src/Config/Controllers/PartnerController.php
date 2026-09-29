<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Partner;
use MesCore\Config\Models\PartnerSite;
use MesCore\Config\Models\PartnerContact;
use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Services\PolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 거래처관리 ( BAS-01 )
//   거래처 + 출고지 N + 담당자 N + 정책 덮어쓰기( 입고검사 )를 한 번에 저장한다 ( 한 트랜잭션 )
//   입고검사는 구매처 · 외주처만 ( 입고가 있는 거래처 ). 아니면 덮어쓰기를 해제한다
//   덮어쓰기 저장 권한은 이 화면의 쓰기 권한 ( 정책-설정-설계 §7 — 마스터를 등록하는 사람이 정한다 )
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
class PartnerController extends Controller {

    // 거래처 단위로 덮어쓸 수 있는 정책
    private const POLICY_KEYS = ['quality.incoming_inspection'];

    private const ATTRIBUTES = [
        'partner_code'          => '거래처코드',
        'partner_name'          => '거래처명',
        'biz_no'                => '사업자번호',
        'ceo_name'              => '대표자',
        'is_use'                => '사용 여부',
        'remark'                => '비고',
        'sites.*.site_name'     => '출고지명',
        'sites.*.address'       => '주소',
        'sites.*.tel'           => '연락처',
        'contacts.*.contact_type' => '구분',
        'contacts.*.site_key'   => '소속',
        'contacts.*.contact_name' => '이름',
        'contacts.*.phone'      => '연락처',
        'contacts.*.email'      => '이메일',
    ];

    // 목록 : 검색어( 코드·명 ) + 구분 + 사용 여부
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'role'    => ['nullable', Rule::in(['supplier', 'customer', 'subcon'])],
            'is_use'  => 'nullable|in:Y,N',
        ]);

        $partners = Partner::query()
            ->withCount(['sites', 'contacts'])
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('partner_code', 'like', "%{$keyword}%")
                                       ->orWhere('partner_name', 'like', "%{$keyword}%"));
            })
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where("is_{$role}", 'Y'))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderBy('partner_code')
            ->get();

        // 입고검사 덮어쓰기 ( 없으면 null = 업체 설정을 따름 )
        $incoming = app(PolicyService::class)->overridesOf('quality.incoming_inspection', 'PARTNER');
        $partners->each(fn ($p) => $p->incoming_inspection = $incoming[$p->partner_idx] ?? null);

        return response()->json(['partners' => $partners]);
    }

    // 거래처 폼의 정책 칸 : 덮어쓴 값 + 따를 때의 값 ( 신규면 partner_idx 없이 )
    public function policies(Request $request, PolicyService $policy) {
        $validated = $request->validate(['partner_idx' => 'nullable|integer']);

        return response()->json(['policies' => $policy->entityFields(self::POLICY_KEYS, 'PARTNER', $validated['partner_idx'] ?? null)]);
    }

    // 단건 : 출고지 · 담당자 포함
    //   출고지 key( 's{idx}' ) 로 담당자 소속을 연결 → 화면에서 새 출고지와 같은 방식으로 다룬다
    public function show(int $partnerIdx) {
        $partner = Partner::with(['sites', 'contacts'])->find($partnerIdx);
        if (!$partner) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['partner' => $this->toPayload($partner)]);
    }

    // 등록/수정 ( partner_idx 있으면 수정 )
    //   sites    : [{ site_idx?, key, site_name, address, tel, remark }]
    //   contacts : [{ contact_idx?, contact_type, site_key?, contact_name, phone, email, remark }]
    //   요청에 없는 기존 출고지·담당자는 삭제된다 ( 화면 목록 = 저장 결과 )
    public function save(Request $request) {
        $idx     = $request->input('partner_idx');
        $partner = $idx ? Partner::find($idx) : new Partner();
        if ($idx && !$partner) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $siteKeys = collect($request->input('sites', []))->pluck('key')->filter()->all();

        $validated = $request->validate([
            'partner_code' => ['required', 'string', 'max:20', Rule::unique('partner', 'partner_code')->ignore($idx, 'partner_idx')],
            'partner_name' => 'required|string|max:60',
            'is_supplier'  => 'required|in:Y,N',
            'is_customer'  => 'required|in:Y,N',
            'is_subcon'    => 'required|in:Y,N',
            'biz_no'       => 'nullable|string|max:20',
            'ceo_name'     => 'nullable|string|max:50',
            'is_use'       => 'required|in:Y,N',
            'remark'       => 'nullable|string|max:2000',

            'sites'               => 'present|array',
            'sites.*.site_idx'    => 'nullable|integer',
            'sites.*.key'         => 'required|string|max:40|distinct',
            'sites.*.site_name'   => 'required|string|max:60',
            'sites.*.address'     => 'nullable|string|max:200',
            'sites.*.tel'         => 'nullable|string|max:30',
            'sites.*.remark'      => 'nullable|string|max:2000',

            'contacts'                => 'present|array',
            'contacts.*.contact_idx'  => 'nullable|integer',
            'contacts.*.contact_type' => [
                'bail', 'required', 'string', 'max:100',
                Rule::exists('system_code', 'system_code_key')->where('system_code_group_key', Partner::CONTACT_TYPE_CODE_GROUP),
            ],
            // 소속 : 같은 요청의 출고지 key 또는 비움( 본사 )
            'contacts.*.site_key'     => ['nullable', 'string', Rule::in($siteKeys)],
            'contacts.*.contact_name' => 'required|string|max:50',
            'contacts.*.phone'        => 'nullable|string|max:30',
            'contacts.*.email'        => 'nullable|email|max:100',
            'contacts.*.remark'       => 'nullable|string|max:2000',

            // 정책 덮어쓰기 [ key => 값 | null( 따름 ) ] — 값 검증은 PolicyService
            'policies'   => 'nullable|array',
            'policies.*' => 'nullable|string|max:300',
        ], [
            'contacts.*.site_key.in' => '소속 출고지가 목록에 없습니다.',
        ], self::ATTRIBUTES);

        // E_CUSTOMER_ROLE_NONE : 구분은 하나 이상
        if (collect(Partner::ROLE_COLUMNS)->every(fn ($col) => $validated[$col] === 'N')) {
            throw ValidationException::withMessages(['roles' => ['구매처 · 고객사 · 외주처 중 하나 이상 선택하세요.']]);
        }

        // 새로 고르는 담당자 구분은 사용 중인 코드여야 함 ( 기존 담당자의 구분이 미사용으로 내려간 경우는 유지 허용 )
        $this->assertActiveContactTypes($partner, $validated['contacts']);

        $policy = app(PolicyService::class);

        try {
            DB::transaction(function () use ($partner, $validated, $policy) {
                $partner->fill(collect($validated)->except(['sites', 'contacts', 'policies'])->all())->save();

                $siteIdxByKey = $this->syncSites($partner, $validated['sites']);
                $this->syncContacts($partner, $validated['contacts'], $siteIdxByKey);

                // 입고가 없는 거래처( 고객사만 )는 입고검사 덮어쓰기를 둘 이유가 없다 → 해제
                $values = $validated['policies'] ?? [];
                if ($validated['is_supplier'] !== 'Y' && $validated['is_subcon'] !== 'Y') {
                    $values = ['quality.incoming_inspection' => null];
                }
                $policy->applyEntity(self::POLICY_KEYS, $values, 'PARTNER', $partner->partner_idx);
            });

            $partner = Partner::with(['sites', 'contacts'])->find($partner->partner_idx);

            return response()->json(['message' => 'Success', 'partner' => $this->toPayload($partner)], 200);
        } catch (ValidationException $e) {
            $policy->flush();   // 롤백된 값이 이 요청의 메모에 남지 않도록
            throw $e;
        } catch (\Throwable $e) {
            $policy->flush();
            Log::error('거래처 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 ). 이미 미사용은 건너뛰고, 대상이 하나도 없으면 404
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'partner_idxs'   => 'required|array|min:1',
            'partner_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['partner_idxs']));

        if (!Partner::whereIn('partner_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed = 0;
            Partner::whereIn('partner_idx', $idxs)->where('is_use', 'Y')->get()
                ->each(function (Partner $partner) use (&$changed) {
                    $partner->is_use = 'N';
                    $partner->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('거래처 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // 출고지 동기화 → key => site_idx 반환 ( 담당자 소속 연결용 )
    //   다른 거래처의 site_idx 가 넘어오면 무시하고 새로 만든다 ( 남의 출고지 수정 방지 )
    private function syncSites(Partner $partner, array $rows): array {
        $existing = PartnerSite::where('partner_idx', $partner->partner_idx)->get()->keyBy('site_idx');
        $keep     = [];
        $idxByKey = [];

        foreach (array_values($rows) as $order => $row) {
            $site = (!empty($row['site_idx']) && $existing->has($row['site_idx']))
                ? $existing->get($row['site_idx'])
                : new PartnerSite(['partner_idx' => $partner->partner_idx]);

            $site->fill([
                'site_name'  => $row['site_name'],
                'address'    => $row['address'] ?? null,
                'tel'        => $row['tel'] ?? null,
                'remark'     => $row['remark'] ?? null,
                'sort_order' => $order,
            ])->save();

            $keep[]                = $site->site_idx;
            $idxByKey[$row['key']] = $site->site_idx;
        }

        // 화면에서 뺀 출고지 삭제 ( 소속이던 담당자는 FK nullOnDelete → 본사, 이어서 담당자 동기화로 다시 정리됨 )
        PartnerSite::where('partner_idx', $partner->partner_idx)->whereNotIn('site_idx', $keep ?: [0])->delete();

        return $idxByKey;
    }

    private function syncContacts(Partner $partner, array $rows, array $siteIdxByKey): void {
        $existing = PartnerContact::where('partner_idx', $partner->partner_idx)->get()->keyBy('contact_idx');
        $keep     = [];

        foreach (array_values($rows) as $order => $row) {
            $contact = (!empty($row['contact_idx']) && $existing->has($row['contact_idx']))
                ? $existing->get($row['contact_idx'])
                : new PartnerContact(['partner_idx' => $partner->partner_idx]);

            $contact->fill([
                'contact_type' => $row['contact_type'],
                'site_idx'     => !empty($row['site_key']) ? ($siteIdxByKey[$row['site_key']] ?? null) : null,
                'contact_name' => $row['contact_name'],
                'phone'        => $row['phone'] ?? null,
                'email'        => $row['email'] ?? null,
                'remark'       => $row['remark'] ?? null,
                'sort_order'   => $order,
            ])->save();

            $keep[] = $contact->contact_idx;
        }

        PartnerContact::where('partner_idx', $partner->partner_idx)->whereNotIn('contact_idx', $keep ?: [0])->delete();
    }

    private function assertActiveContactTypes(Partner $partner, array $contacts): void {
        $currentTypes = $partner->exists
            ? PartnerContact::where('partner_idx', $partner->partner_idx)->pluck('contact_type', 'contact_idx')
            : collect();

        $activeTypes = SystemCode::where('system_code_group_key', Partner::CONTACT_TYPE_CODE_GROUP)
            ->where('is_use', 'Y')
            ->pluck('system_code_key')
            ->flip();

        $errors = [];
        foreach (array_values($contacts) as $i => $row) {
            $type = $row['contact_type'];
            $unchanged = !empty($row['contact_idx']) && ($currentTypes[$row['contact_idx']] ?? null) === $type;
            if (!$unchanged && !$activeTypes->has($type)) {
                $errors["contacts.{$i}.contact_type"] = ['사용 중인 구분을 선택하세요.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    // 화면용 형식 : 출고지에 key, 담당자에 site_key 부여
    private function toPayload(Partner $partner): array {
        $data = $partner->only([
            'partner_idx', 'partner_code', 'partner_name', 'is_supplier', 'is_customer', 'is_subcon',
            'biz_no', 'ceo_name', 'is_use', 'remark', 'create_datetime', 'update_datetime',
        ]);

        $data['sites'] = $partner->sites->map(fn ($s) => [
            'site_idx'  => $s->site_idx,
            'key'       => 's' . $s->site_idx,
            'site_name' => $s->site_name,
            'address'   => $s->address,
            'tel'       => $s->tel,
            'remark'    => $s->remark,
        ])->values();

        $data['contacts'] = $partner->contacts->map(fn ($c) => [
            'contact_idx'  => $c->contact_idx,
            'contact_type' => $c->contact_type,
            'site_key'     => $c->site_idx ? 's' . $c->site_idx : null,
            'contact_name' => $c->contact_name,
            'phone'        => $c->phone,
            'email'        => $c->email,
            'remark'       => $c->remark,
        ])->values();

        return $data;
    }
}
