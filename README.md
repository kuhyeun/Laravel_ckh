# Laravel Project (MES)

Docker 기반 Laravel 11 프로젝트입니다. **클론 후 한 줄이면 셋업이 끝납니다** — `.env` 생성, 의존성 설치, 키 생성, 마이그레이션이 컨테이너 기동 시 자동으로 처리됩니다.

## 요구 사항

- Docker / Docker Compose
- Git

## 빠른 시작

```bash
git clone <repo-url>
cd laravel_project
docker compose up -d --build
```

끝입니다. http://localhost:8080 으로 접속하세요.

> 첫 빌드는 composer 설치 때문에 몇 분 걸립니다. 진행 상황: `docker compose logs -f php`

### 자동으로 처리되는 것

`docker compose up` 시 php 컨테이너의 [docker-entrypoint.sh](docker-entrypoint.sh)가 아래를 자동 수행합니다. **수동 명령은 필요 없습니다.**

1. `.env` 없으면 `src/.env.example`에서 자동 생성
2. `composer install` (vendor 비어있을 때만)
3. `APP_KEY` 자동 생성
4. `storage:link`
5. DB 준비 대기 → `php artisan migrate`

## 접속

| 주소 | 용도 |
|------|------|
| http://localhost:8080 | 앱 (nginx) |
| `127.0.0.1:3305` | MySQL (DBeaver 등 호스트 도구) |
| http://localhost:5173 | Vite dev 서버 (호스트에서 `npm run dev` 실행 시) |

> 컨테이너 **내부**에서의 DB 접속 정보는 `DB_HOST=mysql`, `DB_PORT=3306` 입니다. 호스트 도구에서는 `127.0.0.1:3305`.

## 프론트엔드(Vite) 개발

실시간(HMR) 작업 시에는 **호스트에서 직접** vite를 실행합니다. (Windows에서는 컨테이너보다 호스트 네이티브 실행이 훨씬 빠릅니다.)

```bash
cd src
npm install   # 최초 1회
npm run dev
```

`npm run dev`를 켜면 실시간 반영(HMR), 끄면 빌드된 정적 에셋(`public/build`)을 서빙합니다.

## 자주 쓰는 명령

```bash
# artisan 명령
docker exec laravel_php php artisan <command>

# DB 초기화 + 시드
docker exec laravel_php php artisan migrate:fresh --seed

# 패키지 추가 (composer는 컨테이너, npm은 호스트)
docker exec laravel_php composer require <pkg>
cd src && npm install <pkg>

# 로그
docker compose logs -f php

# 정지 / 완전 초기화(DB 포함)
docker compose down
docker compose down -v && rm -rf mysql-data   # DB까지 삭제
```

## 주의사항

- **`.env`는 git에 커밋하지 않습니다.** 각자 로컬에서 자동 생성되며, 개인 설정은 로컬 `.env`만 수정하세요. 공통 기본값이 바뀌면 `src/.env.example`을 수정해 공유합니다.
- **쉘 스크립트(`*.sh`)는 LF 유지** — `.gitattributes`가 강제합니다. 에디터에서 CRLF로 저장하지 마세요 (컨테이너에서 실행 안 됨).

## 참고

- 상세 개발 환경 안내: [DOCKER.md](DOCKER.md)
- 이 구성은 **개발/협업용(`APP_ENV=local`)** 입니다. 실제 프로덕션 배포 시에는 `APP_DEBUG=false`, DB 비밀번호 시크릿화, 소스 이미지 빌드, HTTPS, `config:cache`/`route:cache`/`npm run build` 등 별도 설정이 필요합니다.