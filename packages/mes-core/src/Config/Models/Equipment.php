<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 설비 ( BAS-07 )
class Equipment extends Model {

    protected $table      = 'equipment';
    protected $primaryKey = 'equipment_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'equipment_code',
        'equipment_name',
        'line_code',   // 라인 ( 공통코드 EQUIPMENT_LINE · 11-25 )
        'plc_code',    // PLC 코드 ( 관리자만 · 11-25 )
        // 관리 항목 ( 11-19 ) — 담당자( 정 · 부 ) · 구매 · 점검 기준
        'manager_idx', 'sub_manager_idx', 'purchase_date', 'purchase_cost', 'check_cycle', 'check_base',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_use'      => 'Y',
        'check_cycle' => 'NONE',
    ];

    // 점검 기준 ( check_base 의 뜻 : 주간 = 요일 1 ~ 7 · 월간 = 날 1 ~ 31 · 연간 = 날짜 )
    public const CHECK_CYCLES = ['NONE' => '미지정', 'WEEKLY' => '주간', 'MONTHLY' => '월간', 'YEARLY' => '연간'];
    public const WEEKDAYS     = ['1' => '월', '2' => '화', '3' => '수', '4' => '목', '5' => '금', '6' => '토', '7' => '일'];

    protected function casts(): array {
        return ['purchase_date' => 'date:Y-m-d'];
    }

    /**
     * 다음 점검일 ( 11-19 ) — $from( 기본 오늘 ) 이후 처음 오는 점검일 · 오늘이 점검일이면 오늘 · 미지정이면 null
     *   주간 : 그 요일 · 월간 : 그 날 — 그 달에 없는 날( 29 ~ 31일 )이면 그 달의 말일 ( 2월에 31일 → 2월 28 / 29일 )
     *   연간 : 기준날짜의 월 · 일 — 2월 29일은 윤년이 아닌 해에 2월 28일
     *   화면 EquipmentForm 의 미리 보기( nextCheck )와 같은 규칙
     */
    public function nextCheckDate(?\Carbon\CarbonInterface $from = null): ?\Carbon\Carbon {
        $from = \Carbon\Carbon::parse(($from ?? now())->toDateString());
        $base = (string) $this->check_base;
        if ($base === '') {
            return null;
        }
        switch ($this->check_cycle) {
            case 'WEEKLY':
                $dow = (int) $base;   // 1 월 ~ 7 일
                return $dow >= 1 && $dow <= 7 ? $from->copy()->addDays(($dow - $from->dayOfWeekIso + 7) % 7) : null;
            case 'MONTHLY':
                $day = (int) $base;
                if ($day < 1 || $day > 31) return null;
                $at = fn (\Carbon\Carbon $m) => $m->copy()->day(min($day, $m->daysInMonth));   // 없는 날 → 말일
                $d  = $at($from->copy()->startOfMonth());
                return $d->lt($from) ? $at($from->copy()->startOfMonth()->addMonthNoOverflow()) : $d;
            case 'YEARLY':
                if (!preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $base, $m)) return null;
                $at = fn (int $y) => \Carbon\Carbon::create($y, (int) $m[1], 1)->day(min((int) $m[2], \Carbon\Carbon::create($y, (int) $m[1], 1)->daysInMonth));
                $d  = $at($from->year);
                return $d->lt($from) ? $at($from->year + 1) : $d;
        }
        return null;
    }

    // 점검 기준 글자 ( 목록 · 엑셀 ) — 예: 주간 · 월요일 / 월간 · 15일 / 연간 · 2026-03-02
    public function checkText(): string {
        $cycle = self::CHECK_CYCLES[$this->check_cycle] ?? '미지정';
        return match ($this->check_cycle) {
            'WEEKLY'  => $this->check_base ? "{$cycle} · " . (self::WEEKDAYS[$this->check_base] ?? $this->check_base) . '요일' : $cycle,
            'MONTHLY' => $this->check_base ? "{$cycle} · {$this->check_base}일" : $cycle,
            'YEARLY'  => $this->check_base ? "{$cycle} · {$this->check_base}" : $cycle,
            default   => '',
        };
    }

    protected static function booted(): void {
        static::creating(function (self $m) {
            if (empty($m->create_account_idx)) {
                $m->create_account_idx = Auth::id() ?? 0;
            }
        });
        static::updating(function (self $m) {
            $m->update_account_idx = Auth::id();
        });
    }
}
