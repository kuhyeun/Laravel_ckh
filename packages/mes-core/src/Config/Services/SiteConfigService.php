<?php

namespace MesCore\Config\Services;

use MesCore\Basic\Services\PolicyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 사이트설정 ( 1012 ) — 회사 정보 · 거래명세서 · ( 세금계산서 — 기능이 생기면 묶음 추가 )
 *
 *   값은 정책( system_config )의 묶음 GROUPS 에 둔다 — 시스템 환경설정 화면에서는 빠진다 ( ConfigController )
 *   이미지( 직인 · 로고 ) : 파일은 site/{DB명}/{kind}-{uuid}.{확장자} , 경로는 정책 company.{kind}_image ( 화면 숨김 )
 *     바꾸면 옛 파일은 커밋 후에 지운다 / 인쇄용으로 data URI 를 만든다 ( 거래명세서 )
 */
class SiteConfigService {

    // 이 화면이 맡는 정책 묶음 ( 순서 = 화면 섹션 순서 )
    public const GROUPS = ['company', 'invoice'];

    public const IMAGES     = ['seal', 'logo'];
    public const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    public const IMAGE_KB   = 1024;

    public function __construct(private PolicyService $policy) {}

    public static function ownsKey(string $policyKey): bool {
        return in_array(strstr($policyKey, '.', true), self::GROUPS, true);
    }

    private function imageKey(string $kind): string {
        return "company.{$kind}_image";
    }

    private function disk() {
        return Storage::disk(config('mes.attachment_disk', 'local'));
    }

    public function imagePath(string $kind): ?string {
        $path = $this->policy->get($this->imageKey($kind));
        return $path && $this->disk()->exists($path) ? $path : null;
    }

    // 화면 표시용 : 있으면 바뀐 시각( 캐시 무효화 )
    public function imageVersion(string $kind): ?int {
        $path = $this->imagePath($kind);
        return $path ? $this->disk()->lastModified($path) : null;
    }

    public function imageResponse(string $kind) {
        $path = $this->imagePath($kind) ?? abort(404, '이미지가 없습니다.');
        return response($this->disk()->get($path), 200, [
            'Content-Type'  => $this->disk()->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    // 인쇄용 data URI ( 없으면 null )
    public function imageDataUri(string $kind): ?string {
        $path = $this->imagePath($kind);
        if (!$path) return null;
        $mime = $this->disk()->mimeType($path) ?: 'image/png';
        return "data:{$mime};base64," . base64_encode($this->disk()->get($path));
    }

    public function storeImage(string $kind, UploadedFile $file): void {
        $old  = $this->policy->get($this->imageKey($kind));
        $dir  = 'site/' . DB::connection()->getDatabaseName();
        $path = $file->storeAs($dir, $kind . '-' . Str::uuid() . '.' . strtolower($file->getClientOriginalExtension()), config('mes.attachment_disk', 'local'));
        if ($path === false) {
            throw new \RuntimeException('이미지를 저장하지 못했습니다.');
        }
        try {
            DB::transaction(fn () => $this->policy->set($this->imageKey($kind), $path));
        } catch (\Throwable $e) {
            $this->disk()->delete($path);
            throw $e;
        }
        if ($old && $old !== $path) $this->disk()->delete($old);
    }

    public function deleteImage(string $kind): void {
        $old = $this->policy->get($this->imageKey($kind));
        DB::transaction(fn () => $this->policy->set($this->imageKey($kind), null));
        if ($old) $this->disk()->delete($old);
    }
}
