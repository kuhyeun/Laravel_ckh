<?php

namespace Database\Seeders\Config;

use Illuminate\Database\Seeder;
use MesCore\Production\Calc\Formula;
use MesCore\Production\Models\CalcDef;

// 내장 계산식 ( BOM_BASIC · BOM_LOSS · BAR_CUT ) — 매번 덮어쓴다 ( 업체끼리 같게 · 수정 불가 )
//   저장 전에 시험 계산을 돌린다 ( 자재소요-계산-설계 §8 ) — 통과 못 하면 시더가 멈춘다
class CalcDefSeeder extends Seeder {

    public function run(): void {
        $known = array_merge(array_keys(CalcDef::SYSTEM_VARS), array_keys(config('mes.calc_vars', [])));

        foreach (CalcDef::BUILTINS as $code => $d) {
            $tree     = Formula::validate($d['expr'], $known);
            $warnings = [];
            Formula::evaluate($tree, $d['sample'], $warnings);
            if ($warnings) {
                throw new \RuntimeException("내장 계산식 {$code} 시험 계산 실패 : " . implode(' / ', $warnings));
            }

            CalcDef::updateOrCreate(['calc_code' => $code], [
                'name'        => $d['name'],
                'expr'        => $d['expr'],
                'result_kind' => $d['kind'],
                'sample_json' => json_encode($d['sample']),
                'is_builtin'  => 'Y',
                'is_use'      => 'Y',
            ]);
        }
    }
}
