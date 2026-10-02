<?php

namespace MesCore\Basic\Services;

use Illuminate\Support\Facades\Log;

/**
 * 엑셀 양식 파일 찾기 — 업체 src/resources/excel → 공통 packages/mes-core/resources/excel ( 같은 이름이면 업체가 이긴다 )
 *   {base}.json · {base}.{variant}.json · templates/*.xlsx
 *   양식 형식은 resources/excel/README.md
 *   양식 조회 API( ExcelLayoutController )와 업로드 검증( ExcelImportService )이 같이 쓴다
 */
class ExcelLayouts {

    public const NAME_PATTERN = '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/';
    public const TYPES        = ['list', 'template', 'import'];

    // 업체 → 공통 순서
    public function dirs(): array {
        return [
            'tenant' => resource_path('excel'),
            'core'   => dirname(__DIR__, 3) . '/resources/excel',
        ];
    }

    // 한 화면( base )의 양식 목록 : 기본 양식이 먼저, 추가 양식은 이름순
    public function list(string $base): array {
        $found = [];   // id → [ source, path ]
        foreach (array_reverse($this->dirs(), true) as $source => $dir) {   // 공통 먼저 담고 업체가 덮어쓴다
            // GLOB_BRACE 는 일부 리눅스( Alpine 등 )에 없어서 두 번 찾는다
            $paths = array_merge(glob($dir . '/' . $base . '.json') ?: [], glob($dir . '/' . $base . '.*.json') ?: []);
            foreach ($paths as $path) {
                $found[basename($path, '.json')] = ['source' => $source, 'path' => $path];
            }
        }

        $layouts = [];
        foreach ($found as $id => $f) {
            $json = $this->read($f['path']);
            if ($json !== null) {
                $layouts[] = ['id' => $id, 'source' => $f['source']] + $json;
            }
        }

        usort($layouts, fn ($a, $b) => [$a['id'] !== $base, $a['id']] <=> [$b['id'] !== $base, $b['id']]);

        return $layouts;
    }

    // 양식 하나 ( 정확한 id ). 없으면 null
    public function find(string $id): ?array {
        if (!preg_match(self::NAME_PATTERN, $id)) {
            return null;
        }
        foreach ($this->dirs() as $source => $dir) {
            $path = $dir . '/' . $id . '.json';
            if (is_file($path) && ($json = $this->read($path)) !== null) {
                return ['id' => $id, 'source' => $source] + $json;
            }
        }
        return null;
    }

    // 양식 폴더 안의 .xlsx 절대 경로 ( 폴더 밖 · 없는 파일이면 null )
    public function templatePath(string $rel): ?string {
        $rel = str_replace('\\', '/', $rel);
        if (str_contains($rel, '..') || str_starts_with($rel, '/') || !preg_match('/\.xlsx$/i', $rel)) {
            return null;
        }
        foreach ($this->dirs() as $dir) {
            $path = realpath($dir . '/' . $rel);
            $root = realpath($dir);
            if ($path && $root && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function read(string $path): ?array {
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || !in_array($json['type'] ?? null, self::TYPES, true)) {
            Log::warning("엑셀 양식 형식 오류 : {$path}");
            return null;
        }
        return $json;
    }
}
