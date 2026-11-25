<?php

namespace MesCore\Quality\Services;

use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\InspectionItem;
use MesCore\Config\Models\InspectionSpec;
use MesCore\Config\Models\InspectionSpecItem;
use MesCore\Quality\Models\Inspection;
use MesCore\Quality\Models\InspectionMeasure;

/**
 * 검사성적서 ( 11-23 · docs/검사성적서-설계.md ) — 입고 · 공정( 검사 완료 · 초 · 중 · 종 ) · 출하 공통
 *
 *   설정 quality.report.{incoming|process|shipping} 이 켜진 검사만 : 기준서 줄마다 시료 수만큼 측정값을 받아 저장( 필수 )
 *     → 검사 기록 mode = SHEET · inspection_spec_idx = 그 기준서 · inspection_measure = 줄 복사본 + 측정값 + 판정
 *   꺼져 있으면 아무것도 하지 않는다 ( 화면이 보낸 값도 버린다 — mode = SIMPLE 그대로 )
 *   판정은 서버가 다시 매긴다 : 정량 = 하한 ~ 상한 밖이면 NG ( 한쪽만 있으면 그쪽만 ) · 정성 = 시료마다 OK / NG · 줄 = 하나라도 NG 면 NG
 *   합격 · 불합격 수량은 사람이 정한다 ( 종합 판정은 화면이 제안만 )
 */
class InspectionReportService {

    // 검사 종류 → 설정 묶음 ( 초 · 중 · 종은 공정 )
    public const GROUPS = [
        'INCOMING' => 'INCOMING', 'PROCESS' => 'PROCESS', 'IFL_FIRST' => 'PROCESS', 'IFL_MIDDLE' => 'PROCESS', 'IFL_LAST' => 'PROCESS', 'SHIPPING' => 'SHIPPING',
    ];
    private const POLICY = ['INCOMING' => 'quality.report.incoming', 'PROCESS' => 'quality.report.process', 'SHIPPING' => 'quality.report.shipping'];
    public const MAX_SAMPLES = 10;

    public function __construct(private PolicyService $policy) {}

    public function enabled(string $group): bool {
        return isset(self::POLICY[$group]) && $this->policy->get(self::POLICY[$group]) === true;
    }

    public static function groupOf(string $inspType): string {
        return self::GROUPS[$inspType] ?? 'PROCESS';
    }

    // 검사 저장 요청의 measure 칸 ( 각 컨트롤러 validate 에 더한다 )
    public static function rules(): array {
        return [
            'measure'                                  => 'nullable|array',
            'measure.inspection_spec_idx'              => 'nullable|integer',
            'measure.lines'                            => 'nullable|array|max:200',
            'measure.lines.*.inspection_spec_item_idx' => 'required|integer',
            'measure.lines.*.values'                   => 'nullable|array|max:' . self::MAX_SAMPLES,
            'measure.lines.*.values.*'                 => 'nullable|string|max:30',
        ];
    }

    /**
     * 검사 기록에 측정값을 붙인다 ( 검사 저장과 같은 트랜잭션 안에서 부른다 )
     * @param array{inspection_spec_idx?:int, lines?:array<array{inspection_spec_item_idx:int, values?:array}>}|null $measure
     */
    public function record(Inspection $inspection, ?array $measure): void {
        if (!$this->enabled(self::groupOf($inspection->insp_type))) return;

        $specIdx = (int) ($measure['inspection_spec_idx'] ?? 0);
        $spec    = $specIdx ? InspectionSpec::with('lines')->where('is_use', 'Y')->find($specIdx) : null;
        if (!$spec) {
            throw ValidationException::withMessages(['measure' => ['성적서 : 검사기준서를 고르세요.']]);
        }
        if ($spec->lines->isEmpty()) {
            throw ValidationException::withMessages(['measure' => ['성적서 : 고른 기준서에 검사 항목이 없습니다.']]);
        }

        $input  = collect($measure['lines'] ?? [])->keyBy(fn ($l) => (int) $l['inspection_spec_item_idx']);
        $errors = [];
        $rows   = [];
        foreach ($spec->lines->values() as $i => $line) {
            $n      = $this->samples($line->sample_count);
            $values = array_values($input->get($line->inspection_spec_item_idx)['values'] ?? []);
            $clean  = [];
            for ($k = 0; $k < $n; $k++) {
                $v = trim((string) ($values[$k] ?? ''));
                $label = ($i + 1) . "번 {$line->inspection_item_name}" . ($n > 1 ? ' 시료 ' . ($k + 1) : '');
                if ($v === '') {
                    $errors[] = "{$label} : 측정값이 비었습니다.";
                } elseif ($line->is_quantitative === 'Y' && !is_numeric($v)) {
                    $errors[] = "{$label} : 숫자를 넣으세요.";
                } elseif ($line->is_quantitative !== 'Y' && !in_array($v, ['OK', 'NG'], true)) {
                    $errors[] = "{$label} : OK / NG 를 고르세요.";
                }
                $clean[] = $line->is_quantitative === 'Y' && is_numeric($v) ? $this->numStr($v) : $v;
            }
            $rows[] = ['line' => $line, 'values' => $clean];
        }
        if ($errors) {
            throw ValidationException::withMessages(['measure' => array_slice($errors, 0, 20)]);
        }

        foreach ($rows as $seq => $r) {
            /** @var InspectionSpecItem $line */
            $line = $r['line'];
            InspectionMeasure::create([
                'inspection_idx'           => $inspection->inspection_idx,
                'seq'                      => $seq + 1,
                'inspection_spec_item_idx' => $line->inspection_spec_item_idx,
                'inspection_item_name'     => $line->inspection_item_name,
                'is_quantitative'          => $line->is_quantitative,
                'method'                   => $line->method,
                'standard_value'           => $line->standard_value,
                'lower_limit'              => $line->lower_limit,
                'upper_limit'              => $line->upper_limit,
                'unit_code'                => $line->unit_code,
                'criteria'                 => $line->criteria,
                'sample_count'             => count($r['values']),
                'measured'                 => $r['values'],
                'judge'                    => $this->lineJudge($line->is_quantitative === 'Y', $line->lower_limit, $line->upper_limit, $r['values']),
            ]);
        }
        $inspection->fill(['mode' => 'SHEET', 'inspection_spec_idx' => $spec->inspection_spec_idx])->save();
    }

    // 값 하나의 판정 — 화면 ( reportCodes.js valueJudge ) 과 같게
    public static function valueJudge(bool $quant, ?float $lower, ?float $upper, string $v): string {
        if (!$quant) return $v === 'NG' ? 'NG' : 'OK';
        $x = (float) $v;
        if ($lower !== null && $x < $lower - 1e-9) return 'NG';
        if ($upper !== null && $x > $upper + 1e-9) return 'NG';
        return 'OK';
    }

    public function lineJudge(bool $quant, ?float $lower, ?float $upper, array $values): string {
        foreach ($values as $v) {
            if (self::valueJudge($quant, $lower, $upper, (string) $v) === 'NG') return 'NG';
        }
        return 'OK';
    }

    /** 저장된 성적서 줄 ( 화면 · 인쇄 ) */
    public function rows(Inspection $inspection): array {
        $units = $this->units();
        return $inspection->measures->map(fn (InspectionMeasure $m) => [
            'seq'                  => $m->seq,
            'inspection_item_name' => $m->inspection_item_name,
            'is_quantitative'      => $m->is_quantitative,
            'method'               => $m->method,
            'standard_value'       => $m->standard_value,
            'lower_limit'          => $m->lower_limit,
            'upper_limit'          => $m->upper_limit,
            'unit_code'            => $m->unit_code,
            'unit'                 => $m->unit_code ? ($units[$m->unit_code] ?? $m->unit_code) : null,
            'criteria'             => $m->criteria,
            'sample_count'         => $m->sample_count,
            'measured'             => $m->measured ?? [],
            'judge'                => $m->judge,
        ])->values()->all();
    }

    private function units() {
        static $u = null;
        return $u ??= SystemCode::where('system_code_group_key', InspectionItem::UNIT_CODE_GROUP)->pluck('system_code_name', 'system_code_key');
    }

    private function samples($n): int {
        return max(1, min(self::MAX_SAMPLES, (int) ($n ?: 1)));
    }

    // 숫자 글자 정리 ( 1.50 → 1.5 · 끝 0 · 소수점 )
    private function numStr(string $v): string {
        $s = rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
