<?php

namespace MesCore\Support;

use Illuminate\Validation\ValidationException;

/**
 * 도장 ( 양식 위에 떠 있는 그림 · 11-25 ) — 문서 양식 · 라벨 양식의 design.stamps 검사 · 정리
 *   stamps : [ { id, src, x_mm, y_mm, w_mm, h_mm } ] — 위치 · 크기는 mm ( 문서 = A4 장 왼쪽 위 · 여백 포함 / 라벨 = 라벨 왼쪽 위 )
 *   src    : 'site:seal' · 'site:logo' ( 자사정보관리의 직인 · 로고 — 그릴 때 images 로 바꾼다 )
 *            또는 올린 그림 data URI ( data:image/png · jpeg;base64 — 화면이 600px 안으로 줄여 보낸다 · 400KB 까지 )
 *   그 밖의 키는 버린다 · 숫자는 장 / 라벨 안으로 · 그림이 아니면 422
 *   그리기 : 화면 Components/Common/StampLayer.vue ( 편집 · 미리보기 · 인쇄가 같이 쓴다 )
 */
class StampDesign {

    public const MAX       = 10;          // 양식 하나에 도장 수
    public const MAX_BYTES = 400 * 1024;  // 올린 그림 하나 ( 바이트 )
    public const SITE      = ['site:seal', 'site:logo'];
    private const MIN_MM   = 3;

    public static function clean($list, float $boxW, float $boxH): array {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['design' => [$msg]]);
        if ($list === null) {
            return [];
        }
        if (!is_array($list)) {
            $fail('도장 정보가 올바르지 않습니다.');
        }
        $list = array_values($list);
        if (count($list) > self::MAX) {
            $fail('도장은 ' . self::MAX . '개까지 둘 수 있습니다.');
        }

        $out = [];
        $ids = [];
        foreach ($list as $s) {
            if (!is_array($s)) {
                $fail('도장 정보가 올바르지 않습니다.');
            }
            $src = self::src($s['src'] ?? null);
            if ($src === null) {
                $fail('도장 그림이 올바르지 않습니다. ( PNG · JPG 만 · ' . (self::MAX_BYTES / 1024) . 'KB 까지 )');
            }
            $id = is_string($s['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $s['id']) && !isset($ids[$s['id']])
                ? $s['id'] : 'st' . bin2hex(random_bytes(6));
            $ids[$id] = true;

            // 크기 : 가로 · 세로 비율은 화면이 맞춰 온다 — 장 / 라벨보다 크면 비율을 지켜 줄인다
            $w = self::num($s['w_mm'] ?? null, 20);
            $h = self::num($s['h_mm'] ?? null, 20);
            $w = max(self::MIN_MM, $w); $h = max(self::MIN_MM, $h);
            $k = min(1, $boxW / $w, $boxH / $h);
            $w = round(max(self::MIN_MM, $w * $k), 1);
            $h = round(max(self::MIN_MM, $h * $k), 1);
            $x = round(max(0, min($boxW - $w, self::num($s['x_mm'] ?? null, 0))), 1);
            $y = round(max(0, min($boxH - $h, self::num($s['y_mm'] ?? null, 0))), 1);

            $out[] = ['id' => $id, 'src' => $src, 'x_mm' => $x, 'y_mm' => $y, 'w_mm' => $w, 'h_mm' => $h];
        }

        return $out;
    }

    // 그림 : 자사정보의 직인 · 로고 / PNG · JPEG data URI ( 실제 그림인지 · 종류가 맞는지 본다 ) — 아니면 null
    private static function src($src): ?string {
        if (!is_string($src)) {
            return null;
        }
        if (in_array($src, self::SITE, true)) {
            return $src;
        }
        if (strlen($src) > intdiv(self::MAX_BYTES * 4, 3) + 64
            || !preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/]+={0,2})$#', $src, $m)) {
            return null;
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || ($info['mime'] ?? null) !== "image/{$m[1]}") {
            return null;
        }

        return $src;
    }

    private static function num($v, float $default): float {
        return is_numeric($v) && is_finite((float) $v) ? (float) $v : $default;
    }
}
