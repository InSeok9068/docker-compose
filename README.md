# Docker-Compose

도커 컴포즈 (ARM 기반 서버)

이미지가 `arm64v8` 호환하는지 확인

## 도커 불필요한 자원 정리

```shell
docker system prune -a
```

## 도커 컴포즈에 정의되지 않는 서비스 제외

```shell
docker compose up -d --remove-orphans
```

## /path/to/{service} 배포 권한 부여

```shell
sudo chown -R ubuntu:ubuntu /path/to/stylemate
sudo chmod -R 755 /path/to/stylemate
```

## 도커 볼륨 마운트 꼬여있을때 재정리

### 도커 볼륨 마운트 정리

```shell
docker compose down kjca-pocketpages -v
```

### 폴더 강제 삭제

```shell
docker compose stop kjca-pocketpages
find /path/to/kjca/hooks -mindepth 1 -maxdepth 1 -exec rm -rf {} +
docker compose up -d --force-recreate kjca-pocketpages
```

## WSL 도커 CLI 설치

20초간 대기 필요 ❗

```shell
curl -sSL get.docker.com | sh
```

## 서비스 은퇴

### 1. Compose / Caddy 설정 제거

- `docker-compose.yml`
  - 해당 서비스 정의 제거

- `Caddyfile`
  - 해당 서비스 도메인 및 `reverse_proxy` 제거

### 2. 기존 컨테이너 제거

```shell
docker compose up -d --remove-orphans
```

### 3. 서비스 데이터 삭제

```shell
rm -rf /path/to/{service}
```

> `pb_data` 등 필요한 데이터가 없는지 확인 후 삭제

### 4. 잔여 설정 정리

- `.env` 전용 환경변수 제거
- 사용하지 않는 DNS 레코드 제거
- 필요 시 Docker 이미지 정리

```shell
docker system prune -a
```
