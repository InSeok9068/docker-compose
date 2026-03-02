# Docker-Compose

도커 컴포즈 (ARM 기반 서버)

이미지가 `arm64v8` 호환하는지 확인

## 도커 불필요한 자원 정리

```shell
docker system prune -a
```

## /path/to/{service} 배포 권한 부여

```shell
sudo chown -R ubuntu:ubuntu /path/to/stylemate
sudo chmod -R 755 /path/to/stylemate
```

## 도커 컴포즈 명령어

### 도커 서버

```shell
# 실행
# -d 백그라운드 실행
docker compose up
# 중지
docker compose down
# 이미지 최신 다운로드
docker compose pull
```

### WSL 서버

```shell
# 실행
# -d 백그라운드 실행
docker compose -f docker-compose-wsl.yml up
# 중지
docker compose down
# 이미지 최신 다운로드
docker compose -f docker-compose-wsl.yml pull
```

## 도커 사용자 권한 부여

```shell
sudo usermod -aG docker $USER
newgrp docker
```

## WSL 도커 CLI 설치

20초간 대기 필요 ❗

```shell
curl -sSL get.docker.com | sh
```
