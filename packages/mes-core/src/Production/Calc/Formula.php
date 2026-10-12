<?php

namespace MesCore\Production\Calc;

/**
 * 좁은 수식 — docs/자재소요-계산-설계.md §2 · §3 · §6.4
 *
 *   값     : 숫자 · 변수( 대문자 + 숫자 + _ )
 *   연산   : + - * / ( ) · 단항 -
 *   함수   : CEIL FLOOR ROUND ABS ( 인자 1 ) · MIN MAX ( 인자 2 ~ 8 )
 *   없음   : 대입 · 반복 · 조건 · 문자열 — 항상 끝난다
 *   제한   : 500자 · 토큰 200 · 괄호 깊이 20 · 인자 8
 *
 *   parse()    : 식 → 트리. 등록 오류( E_* )는 FormulaException
 *   evaluate() : 트리 + 변수 → 값. 계산 경고( W_NULL · W_DIV0 )는 막지 않고 모은다
 */
class Formula {

    public const MAX_LEN    = 500;
    public const MAX_TOKENS = 200;
    public const MAX_DEPTH  = 20;
    public const MAX_ARGS   = 8;

    // 함수 → [ 최소 인자, 최대 인자 ]
    public const FUNCS = [
        'CEIL'  => [1, 1],
        'FLOOR' => [1, 1],
        'ROUND' => [1, 1],
        'ABS'   => [1, 1],
        'MIN'   => [2, self::MAX_ARGS],
        'MAX'   => [2, self::MAX_ARGS],
    ];

    private array $tokens = [];
    private int $pos      = 0;
    private int $depth    = 0;

    /** @return array 트리 */
    public static function parse(string $expr): array {
        return (new self())->run($expr);
    }

    /** @return string[] 트리에 쓰인 변수 이름 ( 중복 없음 ) */
    public static function variables(array $node): array {
        $out = [];
        $walk = function (array $n) use (&$walk, &$out) {
            match ($n[0]) {
                'v'     => $out[$n[1]] = true,
                'u'     => $walk($n[1]),
                'b'     => [$walk($n[2]), $walk($n[3])],
                'f'     => array_map($walk, $n[2]),
                default => null,
            };
        };
        $walk($node);
        return array_keys($out);
    }

    /**
     * 등록 검사 — 문법 + 정의되지 않은 변수( E_VAR )
     * @param string[] $known 쓸 수 있는 변수
     */
    public static function validate(string $expr, array $known): array {
        $tree    = self::parse($expr);
        $unknown = array_diff(self::variables($tree), $known);
        if ($unknown) {
            throw new FormulaException('E_VAR', '정의되지 않은 변수 : ' . implode(', ', $unknown));
        }
        return $tree;
    }

    /**
     * @param array<string, float|null> $vars  없는 변수 · null 은 0 + W_NULL
     * @param array<string, string> $warnings  [ 코드 => 문구 ] 에 더한다
     */
    public static function evaluate(array $node, array $vars, array &$warnings): float {
        switch ($node[0]) {
            case 'n':
                return $node[1];
            case 'v':
                $v = $vars[$node[1]] ?? null;
                if ($v === null) {
                    $warnings['W_NULL:' . $node[1]] = "{$node[1]} 값이 비어 0 으로 계산했습니다.";
                    return 0.0;
                }
                return (float) $v;
            case 'u':
                return -self::evaluate($node[1], $vars, $warnings);
            case 'b':
                $l = self::evaluate($node[2], $vars, $warnings);
                $r = self::evaluate($node[3], $vars, $warnings);
                switch ($node[1]) {
                    case '+': return $l + $r;
                    case '-': return $l - $r;
                    case '*': return $l * $r;
                    default:
                        if (abs($r) < 1e-12) {
                            $warnings['W_DIV0'] = '0 으로 나누는 곳이 있어 그 값을 0 으로 계산했습니다.';
                            return 0.0;
                        }
                        return $l / $r;
                }
            case 'f':
                $a = [];
                foreach ($node[2] as $x) {   // 화살표 함수는 $warnings 를 값으로 잡으므로 foreach
                    $a[] = self::evaluate($x, $vars, $warnings);
                }
                // 부동소수 오차로 9.0000000001 이 10 으로 올라가지 않게 9자리에서 자른다
                $t = fn (float $x) => round($x, 9);
                return match ($node[1]) {
                    'CEIL'  => ceil($t($a[0])),
                    'FLOOR' => floor($t($a[0])),
                    'ROUND' => round($a[0]),
                    'ABS'   => abs($a[0]),
                    'MIN'   => min($a),
                    'MAX'   => max($a),
                };
        }
        throw new \LogicException('알 수 없는 노드');
    }

    /** 계산 과정 표시용 — 변수 자리에 값을 넣은 식 */
    public static function substitute(string $expr, array $vars, callable $fmt): string {
        return preg_replace_callback('/\b[A-Z][A-Z0-9_]*\b/', function ($m) use ($vars, $fmt) {
            if (isset(self::FUNCS[$m[0]])) {
                return $m[0];
            }
            return array_key_exists($m[0], $vars) && $vars[$m[0]] !== null ? $fmt((float) $vars[$m[0]]) : '0';
        }, $expr);
    }

    // ---- 파서 ----

    private function run(string $expr): array {
        if (mb_strlen($expr) > self::MAX_LEN) {
            throw new FormulaException('E_LIMIT', '식은 ' . self::MAX_LEN . '자를 넘을 수 없습니다.');
        }
        $this->tokens = $this->tokenize($expr);
        if (!$this->tokens) {
            throw new FormulaException('E_SYNTAX', '식이 비어 있습니다.');
        }
        if (count($this->tokens) > self::MAX_TOKENS) {
            throw new FormulaException('E_LIMIT', '토큰이 ' . self::MAX_TOKENS . '개를 넘습니다.');
        }
        $tree = $this->expr();
        if ($this->pos < count($this->tokens)) {
            $t = $this->tokens[$this->pos];
            throw new FormulaException($t[1] === ')' ? 'E_PAREN' : 'E_SYNTAX', "'{$t[1]}' 에서 식이 끝나야 합니다. ( 위치 {$t[2]} )");
        }
        return $tree;
    }

    /** @return array<int, array{0:string,1:string,2:int}> [ 종류, 글자, 위치 ] */
    private function tokenize(string $s): array {
        $out = [];
        $len = strlen($s);
        $i   = 0;
        while ($i < $len) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (preg_match('/\G\d+(\.\d+)?/', $s, $m, 0, $i)) {
                $out[] = ['num', $m[0], $i + 1];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[A-Z][A-Z0-9_]*/', $s, $m, 0, $i)) {
                $out[] = ['id', $m[0], $i + 1];
                $i += strlen($m[0]);
                continue;
            }
            if (str_contains('+-*/(),', $c)) {
                $out[] = ['op', $c, $i + 1];
                $i++;
                continue;
            }
            throw new FormulaException('E_TOKEN', "쓸 수 없는 글자 '{$c}' ( 위치 " . ($i + 1) . ' )');
        }
        return $out;
    }

    private function peek(): ?string {
        return $this->tokens[$this->pos][1] ?? null;
    }

    private function take(): array {
        $t = $this->tokens[$this->pos] ?? null;
        if (!$t) {
            throw new FormulaException('E_SYNTAX', '식이 중간에 끝났습니다.');
        }
        $this->pos++;
        return $t;
    }

    private function expect(string $s): void {
        $t = $this->tokens[$this->pos] ?? null;
        if (!$t || $t[1] !== $s) {
            throw new FormulaException($s === ')' ? 'E_PAREN' : 'E_SYNTAX', $t ? "'{$s}' 가 있어야 합니다. ( 위치 {$t[2]} )" : "'{$s}' 가 빠졌습니다.");
        }
        $this->pos++;
    }

    private function expr(): array {
        $node = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op   = $this->take()[1];
            $node = ['b', $op, $node, $this->term()];
        }
        return $node;
    }

    private function term(): array {
        $node = $this->factor();
        while (in_array($this->peek(), ['*', '/'], true)) {
            $op   = $this->take()[1];
            $node = ['b', $op, $node, $this->factor()];
        }
        return $node;
    }

    private function factor(): array {
        if ($this->peek() === '-') {
            $this->pos++;
            return ['u', $this->primary()];
        }
        return $this->primary();
    }

    private function primary(): array {
        $t = $this->take();
        if ($t[0] === 'num') {
            return ['n', (float) $t[1]];
        }
        if ($t[0] === 'id') {
            if (isset(self::FUNCS[$t[1]])) {
                return $this->call($t);
            }
            if ($this->peek() === '(') {
                throw new FormulaException('E_FUNC', "없는 함수 '{$t[1]}' ( 위치 {$t[2]} )");
            }
            return ['v', $t[1]];
        }
        if ($t[1] === '(') {
            $this->enter($t);
            $node = $this->expr();
            $this->expect(')');
            $this->depth--;
            return $node;
        }
        throw new FormulaException($t[1] === ')' ? 'E_PAREN' : 'E_SYNTAX', "'{$t[1]}' 자리에 값이 와야 합니다. ( 위치 {$t[2]} )");
    }

    private function call(array $t): array {
        $this->expect('(');
        $this->enter($t);
        $args = [$this->expr()];
        while ($this->peek() === ',') {
            $this->pos++;
            $args[] = $this->expr();
        }
        $this->expect(')');
        $this->depth--;

        [$min, $max] = self::FUNCS[$t[1]];
        if (count($args) < $min || count($args) > $max) {
            $want = $min === $max ? "{$min}개" : "{$min} ~ {$max}개";
            throw new FormulaException('E_FUNC', "{$t[1]} 의 인자는 {$want}입니다. ( 위치 {$t[2]} )");
        }
        return ['f', $t[1], $args];
    }

    private function enter(array $t): void {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new FormulaException('E_LIMIT', '괄호가 ' . self::MAX_DEPTH . '단을 넘습니다. ( 위치 ' . $t[2] . ' )');
        }
    }
}
