<?php

namespace MesCore\Basic\Services\Import;

use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Services\ExcelLayouts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 엑셀 일괄 등록 공통 처리 — 검증( 저장 안 함 )과 반영( 다시 검증 후 저장 )
 *
 *   요청 : { layout: 'item.import', version: 1, mode: create | upsert, rows: [{ row: 3, values: { field: 원본값 } }] }
 *     values 에는 엑셀에 있던 열만 온다 ( 화면이 머리글 이름으로 찾아 field 로 바꾼 것 )
 *   응답 : { ok, committed, summary: { total, create, update, error }, rows: [{ row, key, action, status, messages, data }] }
 *     rows 는 나중에 결과 목록 모달이 그대로 그린다
 *
 *   규칙
 *     - 양식 · 버전이 맞아야 한다 ( 내보낸 파일 · 옛 양식 거부는 화면이, 버전 확인은 여기서 한 번 더 )
 *     - 신규만( create ) : 이미 있는 키는 오류 / 신규+수정( upsert ) : 있으면 수정
 *     - 수정할 때 빈 칸은 바꾸지 않는다 ( 실수로 지워지지 않게 ) — 신규는 빈 칸 = 기본값
 *     - 필수 = 양식 JSON 의 required + 핸들러의 serverRequired ( JSON 으로 필수를 풀 수는 없다 )
 *     - 파일 안에서 같은 키가 두 번 나오면 오류
 *     - 반영은 전부 성공일 때만, 한 트랜잭션 ( 하나라도 오류면 아무것도 저장하지 않는다 )
 */
class ExcelImportService {

    public const MAX_ROWS = 1000;

    /** @var array<string, ImportHandler> */
    private array $handlers = [];

    public function __construct(private ExcelLayouts $layouts) {}

    public function register(ImportHandler $handler): void {
        $this->handlers[$handler->entity()] = $handler;
    }

    // 업로드 양식에 서버 필수를 표시 ( 양식 받기 · 안내 시트가 * 를 붙이도록 )
    public function withServerRequired(array $layout): array {
        $handler = $this->handlers[$layout['entity'] ?? ''] ?? null;
        if (!$handler) {
            return $layout;
        }
        $must = array_merge($handler->serverRequired(), [$handler->keyField()]);
        $layout['columns'] = array_map(
            fn ($c) => in_array($c['field'] ?? null, $must, true) ? ['required' => true] + $c : $c,
            $layout['columns'] ?? []
        );
        return $layout;
    }

    /**
     * @param bool $commit false = 검증만, true = 검증 후 전부 성공이면 저장
     */
    public function run(string $entity, array $request, bool $commit): array {
        $handler = $this->handlers[$entity] ?? throw new \InvalidArgumentException("업로드 핸들러 없음 : {$entity}");

        $layout = $this->layouts->find((string) ($request['layout'] ?? ''));
        if (!$layout || $layout['type'] !== 'import' || ($layout['entity'] ?? null) !== $entity) {
            throw ValidationException::withMessages(['layout' => ['업로드 양식이 아닙니다. [양식 받기]로 받은 파일을 사용하세요.']]);
        }
        $layout = $this->withServerRequired($layout);
        if ((int) ($request['version'] ?? 0) !== (int) ($layout['version'] ?? 1)) {
            throw ValidationException::withMessages(['layout' => ['양식이 바뀌었습니다. [양식 받기]로 새 양식을 받아 다시 작성하세요.']]);
        }

        $mode = ($request['mode'] ?? 'create') === 'upsert' ? 'upsert' : 'create';
        $rows = $request['rows'] ?? [];
        if (!$rows) {
            throw ValidationException::withMessages(['rows' => ['올릴 행이 없습니다.']]);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => ['한 번에 ' . self::MAX_ROWS . '행까지 올릴 수 있습니다. ( 지금 ' . count($rows) . '행 )']]);
        }

        $columns    = collect($layout['columns'] ?? [])->keyBy('field');
        $attributes = $columns->map(fn ($c) => $c['header'] ?? $c['field'])->all();
        $keyField   = $handler->keyField();
        $seen       = [];   // 키 → 처음 나온 행 번호
        $results    = [];

        foreach ($rows as $r) {
            $rowNo    = (int) ($r['row'] ?? 0);
            $values   = is_array($r['values'] ?? null) ? $r['values'] : [];
            $messages = [];

            // 1) 값 변환 ( 엑셀에 있던 열만 )
            $converted = [];
            $blank     = [];
            $failed    = [];   // 변환에 실패한 칸 ( 필수 판정에서 같은 문제를 두 번 말하지 않도록 )
            foreach ($values as $field => $raw) {
                $col = $columns->get($field);
                if (!$col) {
                    continue;   // 양식에 없는 열은 무시
                }
                $text = $this->text($raw);
                if ($text === '') {
                    $blank[] = $field;
                    continue;
                }
                [$ok, $value] = $this->convert($handler, $col, $text);
                if ($ok) {
                    $converted[$field] = $value;
                } else {
                    $messages[] = $value;
                    $failed[]   = $field;
                }
            }

            // 2) 키 · 파일 안 중복 · 모드
            $key      = isset($converted[$keyField]) ? (string) $converted[$keyField] : '';
            $existing = $key !== '' ? $handler->find($key) : null;
            $action   = $existing ? 'update' : 'create';

            if ($key === '') {
                $messages[] = ($attributes[$keyField] ?? $keyField) . ' 은(는) 필수입니다.';
            } elseif (isset($seen[$key])) {
                $messages[] = "파일 안에서 중복됩니다. ( {$seen[$key]}행과 같은 " . ($attributes[$keyField] ?? $keyField) . ' )';
            } else {
                $seen[$key] = $rowNo;
            }
            if ($existing && $mode === 'create') {
                $messages[] = "이미 등록된 {$key} 입니다. 수정하려면 '신규 + 수정'으로 올리세요.";
            }

            // 3) 시작 데이터 + 엑셀 값 ( 수정은 빈 칸 유지, 신규는 빈 칸 = 기본값 )
            $data = array_merge($handler->baseData($existing), $converted);

            // 4) 필수 ( 양식 required + 서버 필수 )
            foreach ($columns as $field => $col) {
                if (!empty($col['required']) && $field !== $keyField && !in_array($field, $failed, true) && blank($data[$field] ?? null)) {
                    $messages[] = ($col['header'] ?? $field) . ' 은(는) 필수입니다.';
                }
            }

            // 5) 단건 저장과 같은 검증
            $validated = null;
            if (!$messages) {
                try {
                    $validated = $handler->validate($data, $existing, $attributes);
                } catch (ValidationException $e) {
                    $messages = array_merge($messages, array_merge(...array_values($e->errors())));
                }
            }

            $results[] = [
                'row'       => $rowNo,
                'key'       => $key,
                'action'    => $action,
                'status'    => $messages ? 'error' : 'ok',
                'messages'  => array_values(array_unique($messages)),
                'data'      => $validated ?? $data,
                '_existing' => $existing,
            ];
        }

        $errors = collect($results)->where('status', 'error')->count();

        // 6) 반영 : 전부 성공일 때만 한 트랜잭션
        $committed = false;
        if ($commit && $errors === 0) {
            DB::transaction(function () use ($handler, &$results) {
                foreach ($results as &$res) {
                    $model = $handler->save($res['data'], $res['_existing']);
                    $res['id'] = $model->getKey();
                }
            });
            $committed = true;
        }

        $rowsOut = array_map(function ($r) {
            unset($r['_existing']);
            return $r;
        }, $results);

        return [
            'ok'        => $errors === 0,
            'committed' => $committed,
            'summary'   => [
                'total'  => count($results),
                'create' => collect($results)->where('status', 'ok')->where('action', 'create')->count(),
                'update' => collect($results)->where('status', 'ok')->where('action', 'update')->count(),
                'error'  => $errors,
            ],
            'rows' => $rowsOut,
        ];
    }

    // ---- 값 변환 ----

    // 엑셀 셀 값 → 문자열 ( 숫자 1001.0 → "1001", 앞뒤 공백 제거 )
    private function text(mixed $raw): string {
        if ($raw === null) {
            return '';
        }
        if (is_float($raw) && floor($raw) === $raw && abs($raw) < 1e15) {
            return (string) (int) $raw;
        }
        if (is_bool($raw)) {
            return $raw ? 'Y' : 'N';
        }
        return trim((string) $raw);
    }

    // [ 성공 여부, 값 또는 오류 문구 ]
    private function convert(ImportHandler $handler, array $col, string $text): array {
        $label = $col['header'] ?? $col['field'];

        switch ($col['type'] ?? 'text') {
            case 'number':
            case 'integer':
                $n = str_replace([',', ' '], '', $text);
                if (!is_numeric($n)) {
                    return [false, "{$label} : 숫자가 아닙니다. ( {$text} )"];
                }
                if (($col['type'] ?? '') === 'integer' && (float) $n != (int) (float) $n) {
                    return [false, "{$label} : 정수로 입력하세요. ( {$text} )"];
                }
                return [true, ($col['type'] ?? '') === 'integer' ? (int) (float) $n : $n + 0];

            case 'yn':
                $u = mb_strtoupper($text);
                if (in_array($u, ['Y', 'YES', '예', '사용', 'O', '1', 'TRUE'], true)) return [true, 'Y'];
                if (in_array($u, ['N', 'NO', '아니오', '미사용', 'X', '0', 'FALSE'], true)) return [true, 'N'];
                return [false, "{$label} : Y 또는 N 으로 입력하세요. ( {$text} )"];

            case 'enum':
                foreach ($col['options'] ?? [] as $o) {
                    if (strcasecmp((string) $o['value'], $text) === 0 || ($o['label'] ?? null) === $text) {
                        return [true, (string) $o['value']];
                    }
                }
                $choices = implode(', ', array_map(fn ($o) => $o['label'] ?? $o['value'], $col['options'] ?? []));
                return [false, "{$label} : 선택할 수 있는 값이 아닙니다. ( {$text} → {$choices} )"];

            case 'code':
                $code = SystemCode::where('system_code_group_key', $col['codeGroup'] ?? '')
                    ->where(fn ($q) => $q->where('system_code_key', $text)->orWhere('system_code_name', $text))
                    ->value('system_code_key');
                return $code !== null ? [true, $code] : [false, "{$label} : 코드관리에 없는 값입니다. ( {$text} )"];

            case 'ref':
                $value = $handler->resolveRef((string) ($col['ref'] ?? ''), $text);
                return $value !== null ? [true, $value] : [false, "{$label} : 등록되지 않은 값입니다. ( {$text} )"];

            default:
                $max = (int) ($col['maxLength'] ?? 0);
                if ($max > 0 && mb_strlen($text) > $max) {
                    return [false, "{$label} : {$max}자 이하로 입력하세요."];
                }
                return [true, $text];
        }
    }
}
