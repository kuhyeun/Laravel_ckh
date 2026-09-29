<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Basic\Services\PolicyService;

// LOT 규칙 ( BAS-10 )
//   format_spec = 요소의 순서 있는 목록. render() 가 요소 값을 구분자로 이어 번호를 만든다
//   ( 화면 미리보기와 나중의 실제 발행이 같은 조립 규칙을 쓰도록 여기에 둔다 )
class LotRule extends Model {

    protected $table      = 'lot_rule';
    protected $primaryKey = 'lot_rule_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    // 기준 → 라벨
    const RULE_TYPES = [
        'MATERIAL'      => '자재',
        'PRODUCE_START' => '생산일',
        'PRODUCE_END'   => '종료일',
        'SPLIT'         => '분할생산',
        'SHIPPING'      => '출하일',
        'SERIAL'        => '시리얼',
    ];

    // 조합 요소 → 라벨
    const ELEMENTS = [
        'TEXT'    => '고정 문자',
        'ITEM_CD' => '품목코드',
        'DATE'    => '날짜',
        'SEQ'     => '순번',
        'LOT_NO'  => 'LOT번호',
        'PARTNER' => '거래처',
        'PLANT'   => '생산공장',
        'LINE'    => '생산라인',
        'SHIFT'   => '교대조',
    ];

    const DATE_UNITS    = ['YY', 'YYMM', 'YYMMDD'];
    const PARTNER_MODES = ['ALWAYS', 'ASK'];   // 넣지 않음( NEVER )은 요소를 빼는 것

    // 거래처를 번호에 넣을 수 있는 기준 ( 거래처가 언제나 있는 LOT ) — 외주 입고는 MATERIAL 기준
    const PARTNER_TYPES = ['MATERIAL', 'SHIPPING'];
    // LOT번호를 물고 갈 수 있는 기준 ( 아래층이 위층 번호를 씀 )
    const LOT_NO_TYPES  = ['SHIPPING', 'SERIAL'];

    // 한 규칙에 한 번만 넣을 수 있는 요소 ( TEXT 는 여러 번 가능 )
    const SINGLE_ELEMENTS = ['ITEM_CD', 'DATE', 'SEQ', 'LOT_NO', 'PARTNER', 'PLANT', 'LINE', 'SHIFT'];

    // 순번 자릿수가 이보다 작으면 경고 ( W_LOT_SEQ_CAPACITY ) — 날짜 세밀도 = 리셋 주기
    const MIN_SEQ_DIGITS = [
        'YY'     => 4,   // 연 999건은 부족하기 쉽다
        'YYMM'   => 3,
        'YYMMDD' => 2,
        'NONE'   => 6,   // 날짜 없으면 리셋 없이 계속 누적
    ];

    protected $fillable = [
        'lot_rule_name',
        'rule_type',
        'format_spec',
        'separator',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'separator' => '-',
        'is_use'    => 'Y',
    ];

    protected function casts(): array {
        return [
            'format_spec' => 'array',
        ];
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

    // 번호 조립
    //   $ctx : item_code · date( Y-m-d ) · seq · lot_no · partner_code · plant · line · shift · with_partner( ASK 일 때 )
    //   거래처를 빼면 구분자까지 함께 빠진다 ( 빈 요소는 건너뜀 )
    public static function render(array $spec, string $separator, array $ctx): string {
        $date  = isset($ctx['date']) ? strtotime($ctx['date']) : time();
        $parts = [];

        foreach ($spec as $el) {
            $parts[] = match ($el['type'] ?? '') {
                'TEXT'    => (string) ($el['value'] ?? ''),
                'ITEM_CD' => (string) ($ctx['item_code'] ?? ''),
                'DATE'    => match ($el['unit'] ?? '') {
                    'YY'     => date('y', $date),
                    'YYMM'   => date('ym', $date),
                    'YYMMDD' => date('ymd', $date),
                    default  => '',
                },
                'SEQ'     => str_pad((string) ($ctx['seq'] ?? 1), (int) ($el['digits'] ?? 3), '0', STR_PAD_LEFT),
                'LOT_NO'  => (string) ($ctx['lot_no'] ?? ''),
                'PARTNER' => (($el['mode'] ?? 'ALWAYS') === 'ALWAYS' || !empty($ctx['with_partner'])) ? (string) ($ctx['partner_code'] ?? '') : '',
                'PLANT'   => (string) ($ctx['plant'] ?? ''),
                'LINE'    => (string) ($ctx['line'] ?? ''),
                'SHIFT'   => (string) ($ctx['shift'] ?? ''),
                default   => '',
            };
        }

        return implode($separator, array_filter($parts, fn ($p) => $p !== ''));
    }

    // W_LOT_NO_SHIP_RULE : 출하 LOT 을 자동 채번( lot.shipment_lot = AUTO )하는데 사용 중인 출하일 기준 규칙이 없음
    //   설정 화면과 LOT규칙관리가 같이 쓴다. 문제 없으면 null
    public static function shippingRuleWarning(): ?string {
        $policy = app(PolicyService::class);
        if (!$policy->isEnabled('feature.lot') || $policy->get('lot.shipment_lot') !== 'AUTO') {
            return null;
        }
        if (self::where('rule_type', 'SHIPPING')->where('is_use', 'Y')->exists()) {
            return null;
        }
        return '출하 LOT 을 자동 채번으로 설정했지만 사용 중인 출하일 기준 LOT 규칙이 없습니다. LOT규칙관리에서 출하일 기준 규칙을 등록하세요.';
    }

    // 화면 미리보기용 예시 값 ( 문서의 예와 같은 모양 )
    public static function sampleContext(array $overrides = []): array {
        return array_merge([
            'item_code'    => 'PN-A',
            'date'         => date('Y-m-d'),
            'seq'          => 1,
            'lot_no'       => 'L-' . date('ym') . '-001',
            'partner_code' => 'PT0012',
            'plant'        => 'P1',
            'line'         => 'L01',
            'shift'        => 'A',
            'with_partner' => true,
        ], $overrides);
    }
}
