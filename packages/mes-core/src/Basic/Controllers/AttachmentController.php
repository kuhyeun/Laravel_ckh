<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Auth\Models\User;
use MesCore\Basic\Models\Attachment;
use MesCore\Basic\Services\AttachmentService;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// 첨부파일 ( CMP-03 ) — 어떤 화면에서나 쓰는 공용 API
//   권한은 라우트가 아니라 여기서 : ref_type 이 가리키는 화면의 메뉴 권한 ( 보기 · 받기 = can_read, 올리기 · 삭제 = can_write )
//   컴포넌트는 권한을 판단하지 않는다 ( 화면정의 CMP-03 )
class AttachmentController extends Controller {

    public function __construct(
        private AttachmentService $attachments,
        private MenuPermissionService $permissions,
    ) {}

    public function list(Request $request) {
        $validated = $request->validate([
            'ref_type' => 'required|string|max:30',
            'ref_idx'  => 'required|integer|min:1',
        ]);
        $this->authorizeRef($validated['ref_type'], 'can_read');

        return response()->json([
            'attachments' => $this->attachments->list($validated['ref_type'], (int) $validated['ref_idx']),
        ]);
    }

    public function upload(Request $request) {
        $validated = $request->validate([
            'ref_type'      => 'required|string|max:30',
            'ref_idx'       => 'required|integer|min:1',
            'category_code' => ['required', 'string', Rule::exists('system_code', 'system_code_key')
                ->where('system_code_group_key', Attachment::CATEGORY_GROUP)],
            'files'         => 'required|array|min:1|max:' . AttachmentService::MAX_FILES,
            'files.*'       => 'file|max:' . AttachmentService::MAX_KB . '|extensions:' . implode(',', AttachmentService::EXTENSIONS),
        ], [
            'files.required'  => '파일을 고르세요.',
            'files.max'       => '한 번에 ' . AttachmentService::MAX_FILES . '개까지 올릴 수 있습니다.',
            'files.*.file'    => '파일을 올리지 못했습니다. ( 크기 제한을 넘었을 수 있습니다 )',
            'files.*.uploaded' => '파일을 올리지 못했습니다. ( 크기 제한을 넘었을 수 있습니다 )',
            'files.*.max'     => '파일 하나는 ' . (AttachmentService::MAX_KB / 1024) . 'MB 까지 올릴 수 있습니다.',
            'files.*.extensions' => '올릴 수 없는 형식입니다. ( ' . implode(', ', AttachmentService::EXTENSIONS) . ' )',
        ], [
            'category_code' => '구분',
        ]);

        $this->authorizeRef($validated['ref_type'], 'can_write');
        if (!$this->attachments->targetExists($validated['ref_type'], (int) $validated['ref_idx'])) {
            abort(404, '첨부할 대상을 찾을 수 없습니다.');
        }

        $rows = $this->attachments->store(
            $validated['ref_type'],
            (int) $validated['ref_idx'],
            $validated['category_code'],
            $request->file('files')
        );

        return response()->json(['message' => 'Success', 'count' => count($rows)]);
    }

    public function download(int $attachmentIdx) {
        $attachment = Attachment::findOrFail($attachmentIdx);
        $this->authorizeRef($attachment->ref_type, 'can_read');

        $disk = Storage::disk($attachment->storage_disk);
        if (!$disk->exists($attachment->storage_path)) {
            abort(404, '파일이 없습니다.');
        }
        return $disk->download($attachment->storage_path, $attachment->file_name);
    }

    public function delete(int $attachmentIdx) {
        $attachment = Attachment::findOrFail($attachmentIdx);
        $this->authorizeRef($attachment->ref_type, 'can_write');

        $this->attachments->delete($attachment);

        return response()->json(['message' => 'Success']);
    }

    // ref_type → 화면( page_component ) → 메뉴 권한
    private function authorizeRef(string $refType, string $action): void {
        $ref = $this->attachments->ref($refType);
        if ($ref === null) {
            abort(422, '첨부할 수 없는 대상입니다.');
        }
        $menuCode = Menu::where('page_component', $ref['menu'])->value('menu_code');
        /** @var User|null $user */
        $user     = Auth::user();
        if ($user === null || $menuCode === null || !$this->permissions->can($user, $menuCode, $action)) {
            abort(403, 'Permission Denied.');
        }
    }
}
