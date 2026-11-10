<?php

namespace MesCore\Document\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Models\Attachment;
use MesCore\Document\Models\DocFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// 문서관리 ( 11004 · Document/DocumentManage ) — 웹 폴더
//   폴더 : 만들기 · 이름 바꾸기 · 삭제( 하위 어디에도 파일이 없을 때 하위째 ) — 이 컨트롤러 · 폴더 옮기기는 없음 ( 사용자 결정 11-10 )
//   파일 : 올리기 · 받기 · 삭제는 공용 첨부 API( attachment.* — ref_type doc_folder ) · 다른 폴더로 옮기기만 여기
//   권한 = 라우트( routes/document.php )의 메뉴 권한 : 보기 can_read · 만들기 · 이름 바꾸기 · 파일 옮기기 can_write · 삭제 can_delete
class DocumentController extends Controller {

    private const NAME_RULE = ['required', 'string', 'max:100', 'not_regex:/[\\\\\/:*?"<>|]/'];

    // 폴더 전체 ( 화면이 parent_folder_idx 로 트리를 만든다 ) + 폴더마다 파일 수
    public function folders() {
        $counts = Attachment::where('ref_type', DocFolder::REF_TYPE)
            ->selectRaw('ref_idx, COUNT(*) c')
            ->groupBy('ref_idx')
            ->pluck('c', 'ref_idx');

        $folders = DocFolder::orderBy('folder_name')
            ->get(['folder_idx', 'parent_folder_idx', 'folder_name'])
            ->map(fn (DocFolder $f) => $f->only(['folder_idx', 'parent_folder_idx', 'folder_name'])
                + ['file_count' => (int) ($counts[$f->folder_idx] ?? 0)]);

        return response()->json(['folders' => $folders]);
    }

    // 만들기 ( folder_idx 없음 ) · 이름 바꾸기 ( folder_idx 있음 — 이름만 )
    public function save(Request $request) {
        $v = $request->validate([
            'folder_idx'        => 'nullable|integer|exists:doc_folder,folder_idx',
            'parent_folder_idx' => 'nullable|integer|exists:doc_folder,folder_idx',
            'folder_name'       => self::NAME_RULE,
        ], [
            'folder_name.not_regex' => '폴더 이름에 \\ / : * ? " < > | 는 쓸 수 없습니다.',
        ], [
            'folder_name' => '폴더 이름',
        ]);
        $name = trim($v['folder_name']);

        if (!empty($v['folder_idx'])) {
            $folder = DocFolder::findOrFail($v['folder_idx']);
            $this->assertNameFree($folder->parent_folder_idx, $name, $folder->folder_idx);
            $folder->fill(['folder_name' => $name])->save();
        } else {
            $parentIdx = $v['parent_folder_idx'] ?? null;
            $this->assertNameFree($parentIdx, $name);
            $folder = DocFolder::create(['parent_folder_idx' => $parentIdx, 'folder_name' => $name]);
        }

        return response()->json(['message' => 'Success', 'folder_idx' => $folder->folder_idx]);
    }

    // 삭제 : 그 폴더와 하위 폴더 어디에도 파일이 없으면 하위 폴더째 지운다 ( 사용자 결정 11-10 )
    //   파일이 하나라도 있으면 막고 어느 폴더에 몇 개인지 알려 준다 ( 파일은 지우지 않는다 )
    //   깊이와 상관없이 쿼리 4번 : 폴더 전체 · 파일 수 · 상위 연결 끊기 · 삭제 — 하위 계산은 메모리에서 · 한 트랜잭션
    public function delete(Request $request) {
        $v = $request->validate(['folder_idx' => 'required|integer|exists:doc_folder,folder_idx']);

        $deleted = DB::transaction(function () use ($v) {
            $all = DocFolder::lockForUpdate()->get(['folder_idx', 'parent_folder_idx', 'folder_name'])->keyBy('folder_idx');
            $rootIdx = (int) $v['folder_idx'];

            $children = [];
            foreach ($all as $f) {
                if ($f->parent_folder_idx !== null) $children[(int) $f->parent_folder_idx][] = (int) $f->folder_idx;
            }

            // 단계별 하위 폴더 ( [ [루트], [자식들], [손자들] … ] ) — 순환 방어
            $levels = [[$rootIdx]];
            $seen   = [$rootIdx => true];
            while (true) {
                $next = [];
                foreach (end($levels) as $idx) {
                    foreach ($children[$idx] ?? [] as $c) {
                        if (!isset($seen[$c])) {
                            $seen[$c] = true;
                            $next[]   = $c;
                        }
                    }
                }
                if (!$next) break;
                $levels[] = $next;
            }
            $idxs = array_keys($seen);

            $fileCounts = Attachment::where('ref_type', DocFolder::REF_TYPE)
                ->whereIn('ref_idx', $idxs)
                ->lockForUpdate()
                ->selectRaw('ref_idx, COUNT(*) c')
                ->groupBy('ref_idx')
                ->pluck('c', 'ref_idx');

            if ($fileCounts->isNotEmpty()) {
                // 지우려던 폴더 기준 경로 ( 예: 성적서 > 2026 )
                $pathOf = function (int $idx) use ($all, $rootIdx): string {
                    $names = [];
                    for ($cur = $idx; $cur !== null && count($names) < 50; $cur = $cur === $rootIdx ? null : ($all[$cur]->parent_folder_idx ?? null)) {
                        array_unshift($names, $all[$cur]->folder_name ?? '?');
                    }
                    // 깊으면 가운데를 줄인다 ( 루트 > … > 마지막 두 단계 )
                    if (count($names) > 4) $names = [$names[0], '…', ...array_slice($names, -2)];
                    return implode(' > ', $names);
                };
                $total = $fileCounts->sum();
                $where = $fileCounts->take(3)->map(fn ($c, $idx) => "'{$pathOf((int) $idx)}' {$c}개")->implode(', ')
                       . ($fileCounts->count() > 3 ? ' 외 ' . ($fileCounts->count() - 3) . '개 폴더' : '');
                throw ValidationException::withMessages(['folder_idx' => [
                    "폴더 안에 파일 {$total}개가 있어 삭제할 수 없습니다. ( {$where} ) 파일을 옮기거나 지운 뒤 다시 삭제하세요.",
                ]]);
            }

            // 서로 가리키는 상위 연결을 먼저 끊고 한 번에 지운다 ( 깊이만큼 나눠 지우지 않게 — 쿼리 2번 )
            DocFolder::whereIn('folder_idx', $idxs)->update(['parent_folder_idx' => null]);
            DocFolder::whereIn('folder_idx', $idxs)->delete();
            return count($idxs);
        });

        return response()->json(['message' => 'Success', 'deleted' => $deleted]);
    }

    // 파일 옮기기 : 이 화면의 파일( ref_type doc_folder )만 · 저장된 파일은 그대로 두고 붙은 폴더만 바꾼다
    public function moveFiles(Request $request) {
        $v = $request->validate([
            'attachment_idxs'   => 'required|array|min:1',
            'attachment_idxs.*' => 'integer',
            'folder_idx'        => 'required|integer|exists:doc_folder,folder_idx',
        ]);

        $moved = DB::transaction(fn () => Attachment::where('ref_type', DocFolder::REF_TYPE)
            ->whereIn('attachment_idx', array_unique($v['attachment_idxs']))
            ->get()
            ->each(fn (Attachment $a) => $a->fill(['ref_idx' => $v['folder_idx']])->save())
            ->count());

        if (!$moved) {
            throw ValidationException::withMessages(['attachment_idxs' => ['옮길 파일을 찾을 수 없습니다.']]);
        }
        return response()->json(['message' => 'Success', 'moved' => $moved]);
    }

    // ---- 내부 ----

    // 같은 상위 안에 같은 이름 ( 대소문자 · 앞뒤 공백 무시 — DB 정렬 규칙 ) 이 있으면 막는다
    private function assertNameFree(?int $parentIdx, string $name, ?int $exceptIdx = null): void {
        $exists = DocFolder::query()
            ->when($parentIdx === null, fn ($q) => $q->whereNull('parent_folder_idx'), fn ($q) => $q->where('parent_folder_idx', $parentIdx))
            ->where('folder_name', $name)
            ->when($exceptIdx, fn ($q) => $q->where('folder_idx', '!=', $exceptIdx))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['folder_name' => ["같은 위치에 '{$name}' 폴더가 이미 있습니다."]]);
        }
    }
}
