<?php

namespace MesCore\Basic\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MesCore\Basic\Models\Attachment;

/**
 * 첨부파일 ( CMP-03 ) — 대상 등록 · 저장 · 삭제
 *
 *   대상( ref_type )은 등록한 것만 받는다 : ref_type → [ 권한을 따를 화면( page_component ), 대상 모델 ]
 *     권한은 그 화면의 메뉴 권한 ( 보기 · 받기 = can_read, 올리기 · 삭제 = can_write )
 *     업체 모듈은 resolving 으로 더 붙일 수 있다 ( ExcelImportService 와 같은 방식 )
 *   저장 경로 : attachments/{DB명}/{yyyyMM}/{uuid}.{확장자} — 업체 구분 폴더 ( 멀티테넌시 대비, ADR 0027 )
 *   삭제 : 행과 파일 모두. 파일은 커밋 후에 지운다 ( 롤백되면 파일만 사라지는 일이 없도록 )
 */
class AttachmentService {

    // 파일당 최대 크기 ( KB ) — nginx client_max_body_size · PHP upload_max_filesize 와 맞출 것
    public const MAX_KB = 51200;

    // 한 번에 올릴 수 있는 파일 수
    public const MAX_FILES = 10;

    // 허용 확장자 ( 내려받기만 하므로 브라우저에서 열리지 않는다 )
    public const EXTENSIONS = [
        'pdf',
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp',
        'xls', 'xlsx', 'doc', 'docx', 'ppt', 'pptx', 'hwp', 'hwpx', 'txt', 'csv',
        'dwg', 'dxf',
        'zip',
    ];

    /** @var array<string, array{menu: string, model: class-string}> */
    private array $refs = [];

    public function register(string $refType, string $menu, string $model): void {
        $this->refs[$refType] = ['menu' => $menu, 'model' => $model];
    }

    public function ref(string $refType): ?array {
        return $this->refs[$refType] ?? null;
    }

    // 대상이 실제로 있는지
    public function targetExists(string $refType, int $refIdx): bool {
        $model = $this->refs[$refType]['model'] ?? null;
        return $model !== null && $model::whereKey($refIdx)->exists();
    }

    public function list(string $refType, int $refIdx) {
        return Attachment::with('creator:account_idx,user_id,user_name')
            ->where('ref_type', $refType)
            ->where('ref_idx', $refIdx)
            ->orderBy('attachment_idx')
            ->get();
    }

    /**
     * @param UploadedFile[] $files
     * @return Attachment[]
     */
    public function store(string $refType, int $refIdx, string $category, array $files): array {
        $disk  = config('mes.attachment_disk', 'local');
        $dir   = 'attachments/' . DB::connection()->getDatabaseName() . '/' . now()->format('Ym');
        $saved = [];   // 실패 시 지울 경로

        try {
            return DB::transaction(function () use ($files, $disk, $dir, $refType, $refIdx, $category, &$saved) {
                $rows = [];
                foreach ($files as $file) {
                    $ext  = strtolower($file->getClientOriginalExtension());
                    $path = $file->storeAs($dir, Str::uuid() . '.' . $ext, $disk);
                    if ($path === false) {
                        throw new \RuntimeException('파일을 저장하지 못했습니다.');
                    }
                    $saved[] = $path;

                    $rows[] = Attachment::create([
                        'ref_type'      => $refType,
                        'ref_idx'       => $refIdx,
                        'category_code' => $category,
                        'file_name'     => mb_substr($file->getClientOriginalName(), 0, 255),
                        'file_ext'      => $ext,
                        'file_size'     => $file->getSize(),
                        'mime_type'     => mb_substr((string) $file->getClientMimeType(), 0, 120),
                        'storage_disk'  => $disk,
                        'storage_path'  => $path,
                    ]);
                }
                return $rows;
            });
        } catch (\Throwable $e) {
            // DB 가 롤백됐으니 이미 쓴 파일도 지운다
            foreach ($saved as $path) {
                Storage::disk($disk)->delete($path);
            }
            throw $e;
        }
    }

    public function delete(Attachment $attachment): void {
        $disk = $attachment->storage_disk;
        $path = $attachment->storage_path;

        $attachment->delete();

        DB::afterCommit(function () use ($disk, $path) {
            if (!Storage::disk($disk)->delete($path)) {
                Log::warning('첨부파일 삭제 실패 ( 행은 지워짐 )', ['disk' => $disk, 'path' => $path]);
            }
        });
    }
}
