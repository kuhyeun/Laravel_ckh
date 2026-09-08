# ADR 0014. 백엔드는 Laravel, 프런트는 Nuxt SPA, 그리드는 TUI Grid

- 상태: 채택
- 날짜: 2026-09-02
- 관련: `docs/프로그램-스펙.md`

## 배경

온프레미스 설치본을 고객사 담당자가 운영해야 한다. 빌드가 복잡하거나
런타임이 무거우면 지원 비용이 그대로 는다.

## 검토한 대안

| 후보 | 판단 |
|---|---|
| Java · Spring Boot | 초안에서 검토. **JVM 튜닝과 메모리**가 온프레미스에 부담 |
| Laravel 11 | 채택. PHP-FPM 운영이 단순하고 국내 인력이 두텁다 |
| React | 후보. 팀 숙련도와 그리드 연동에서 Vue가 앞섰다 |
| **Nuxt 3 SPA(`ssr: false`)** | 채택. 사내망 전용이라 SSR이 줄 이점이 없다 |
| AG Grid | 상용 라이선스 |
| **TUI Grid 4.x** | 채택. MIT, 한글 문서, 엑셀·트리 내장 |

## 결정

PHP 8.3 · Laravel 11 · Laravel Reverb(웹소켓) · MariaDB 10.11
Vue 3 · Nuxt 3 SPA · TypeScript · TUI Grid 4.x

쓰기는 Eloquent, 읽기는 Query Builder.
배치는 `schedule:work` 상주 프로세스로 돌린다 — crontab을 쓰지 않는다.
브라우저는 **Chrome · Edge** 최근 2개 메이저만 지원한다.

## 결과

- 컨테이너 4개(nginx · php-fpm · reverb · scheduler)로 끝난다
- SSR이 없으므로 SEO·초기 렌더 최적화 논의가 없다. 사내 도구에 맞다
- 그리드 교체 가능성은 **래퍼 하나로** 막는다 (ADR 0015)
