#!/bin/bash
set -e

cd /var/www/html

# 1. .env 없으면 .env.example 에서 생성 ( 협력개발: clone 직후 자동 준비 )
if [ ! -f .env ]; then
    cp .env.example .env
    echo "[entrypoint] .env 생성 완료 ( .env.example 복사 )"
fi

# 2. composer 의존성 설치 ( vendor 비어있을 때만 )
#    - path repository( /var/www/packages/mes-core )는 컨테이너 안에서만 해석됨
if [ ! -d vendor ] || [ -z "$(ls -A vendor 2>/dev/null)" ]; then
    echo "[entrypoint] composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# 3. APP_KEY 없으면 생성
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "[entrypoint] APP_KEY 생성..."
    php artisan key:generate --force
fi

# 4. storage 심볼릭 링크 ( 이미 있으면 무시 )
php artisan storage:link 2>/dev/null || true

# 5. DB 준비될 때까지 대기 후 마이그레이션 ( depends_on 은 준비완료를 보장 안 함 )
echo "[entrypoint] 데이터베이스 대기중..."
tries=0
until php artisan migrate --force 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "[entrypoint] DB 연결 실패( 30회 ). 마이그레이션 없이 계속 진행."
        break
    fi
    echo "[entrypoint] DB 준비 안 됨, 재시도 $tries/30..."
    sleep 3
done

# 6. 초기 데이터 시드 ( 시더가 idempotent → 매번 실행해도 없는 데이터만 생성 )
echo "[entrypoint] 초기 데이터 시드..."
php artisan db:seed --force || echo "[entrypoint] 시드 건너뜀 ( DB 미준비 등, 무시 )"

echo "[entrypoint] 준비 완료. php-fpm 시작."

# 기본 명령 실행 (php-fpm)
exec "$@"
