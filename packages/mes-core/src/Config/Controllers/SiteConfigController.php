<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Services\PolicyException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Services\SiteConfigService;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// 사이트설정 ( 1012 · Config/SiteConfig ) — 회사 정보 · 거래명세서 · 직인 / 로고 이미지
//   값 = 정책 묶음 SiteConfigService::GROUPS ( 검증 · 이력은 PolicyService ) / 화면에 그리는 건 노출 항목만
class SiteConfigController extends Controller {

    public function __construct(
        private PolicyService $policy,
        private SiteConfigService $site,
    ) {}

    public function data() {
        $items = collect($this->policy->definitions())
            ->filter(fn ($def) => in_array($def['group'], SiteConfigService::GROUPS, true) && $def['is_visible'])
            ->map(fn ($def) => [
                'group'   => $def['group'],
                'key'     => $def['key'],
                'name'    => $def['name'],
                'remark'  => $def['remark'],
                'type'    => $def['value_type'],
                'options' => $def['etc']['options'] ?? [],
                'value'   => $def['value'] ?? $def['default_value'] ?? '',
            ])
            ->sortBy(fn ($i) => array_search($i['group'], SiteConfigService::GROUPS, true))
            ->values();

        return response()->json([
            'items'  => $items,
            'images' => collect(SiteConfigService::IMAGES)->mapWithKeys(fn ($k) => [$k => $this->site->imageVersion($k)]),
        ]);
    }

    // 저장 : [{ group, key, value }] — 이 화면 묶음만. 빈 값 = 기본값으로
    public function save(Request $request) {
        $v = $request->validate([
            'items'         => 'required|array|min:1',
            'items.*.group' => ['required', 'string', Rule::in(SiteConfigService::GROUPS)],
            'items.*.key'   => 'required|string|max:50',
            'items.*.value' => 'nullable|string|max:300',
        ]);

        try {
            $changed = DB::transaction(function () use ($v) {
                $n = 0;
                foreach ($v['items'] as $item) {
                    $key = $item['group'] . '.' . $item['key'];
                    $def = $this->policy->definition($key);
                    if (!$def['is_visible']) throw new PolicyException('이 화면에서 바꿀 수 없는 항목입니다.');
                    $value = trim($item['value'] ?? '');
                    $n += $this->policy->set($key, $value === '' ? null : $value) ? 1 : 0;
                }
                return $n;
            });
            return response()->json(['message' => 'Success', 'changed' => $changed]);
        } catch (PolicyException | InvalidArgumentException $e) {
            $this->policy->flush();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->policy->flush();
            Log::error('사이트설정 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }

    public function image(string $kind) {
        return $this->site->imageResponse($kind);
    }

    public function uploadImage(Request $request, string $kind) {
        $request->validate([
            'file' => 'required|file|max:' . SiteConfigService::IMAGE_KB . '|extensions:' . implode(',', SiteConfigService::IMAGE_EXTS),
        ], [
            'file.required'   => '이미지를 고르세요.',
            'file.max'        => '이미지는 ' . (SiteConfigService::IMAGE_KB / 1024) . 'MB 까지 올릴 수 있습니다.',
            'file.extensions' => '올릴 수 없는 형식입니다. ( ' . implode(', ', SiteConfigService::IMAGE_EXTS) . ' )',
        ]);
        $this->site->storeImage($kind, $request->file('file'));
        return response()->json(['message' => 'Success', 'version' => $this->site->imageVersion($kind)]);
    }

    public function deleteImage(string $kind) {
        $this->site->deleteImage($kind);
        return response()->json(['message' => 'Success']);
    }
}
