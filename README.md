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

## 도커 마운트 꼬여있을때 재정리

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
