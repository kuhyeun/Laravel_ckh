# 개발 환경 (Docker)

협력개발용 로컬 개발 환경입니다. 아래 한 번이면 셋업이 끝납니다.

## 처음 시작 (새 PC)

```bash
git clone <repo-url>
cd laravel_project
docker compose up -d --build
```

`docker compose up`이 자동으로 처리하는 것 (php 컨테이너 entrypoint):

1. `.env` 없으면 `src/.env.example`에서 자동 생성
2. `composer install` (vendor 비어있을 때)
3. `APP_KEY` 자동 생성
4. `storage:link`
5. DB 준비 대기 → `php artisan migrate`

> 첫 빌드는 composer 설치 때문에 몇 분 걸립니다. 로그 확인: `docker compose logs -f php`

## 프론트(Vite) 개발

프론트를 실시간(HMR)으로 작업할 때는 **호스트에서 직접** vite를 실행합니다. (Windows에서는 컨테이너보다 호스트 네이티브 실행이 훨씬 빠릅니다.)

```bash
cd src
npm install   # 최초 1회
npm run dev
```

`npm run dev`를 켜면 실시간 반영(HMR), 끄면 빌드된 정적 에셋(`public/build`)을 서빙합니다.

## 접속

| 주소 | 용도 |
|------|------|
| http://localhost:8080 | 앱 (nginx) |
| localhost:3305 | MySQL (호스트에서 접속 시) |
| localhost:5173 | Vite dev 서버 (호스트에서 `npm run dev` 실행 시) |

## 자주 쓰는 명령

```bash
# artisan
docker exec laravel_php php artisan <command>

# 마이그레이션 다시 (DB 초기화)
docker exec laravel_php php artisan migrate:fresh --seed

# composer 패키지 추가 (컨테이너) / npm 패키지 추가 (호스트)
docker exec laravel_php composer require <pkg>
cd src && npm install <pkg>

# 로그
docker compose logs -f php

# 정지 / 완전 초기화(DB 포함)
docker compose down
docker compose down -v && rm -rf mysql-data   # DB까지 날림
```

## 주의사항

- **`.env`는 git에 커밋하지 않습니다.** 각자 로컬에서 자동 생성되며, 개인 설정은 로컬 `.env`만 수정하세요. 공통 기본값이 바뀌면 `src/.env.example`을 수정해 공유합니다.
- **쉘 스크립트(`*.sh`)는 LF 유지** — `.gitattributes`가 강제합니다. 에디터에서 CRLF로 저장하지 마세요 (컨테이너에서 실행 안 됨).
- DB 접속 정보: 컨테이너 내부에서는 `DB_HOST=mysql`, `DB_PORT=3306` / 호스트 도구(DBeaver 등)에서는 `127.0.0.1:3305`.
