# Docker-Compose

도커 컴포즈 (ARM 기반 서버)

이미지가 `arm64v8` 호환하는지 확인

## 앱 데이터 마운트

- .app/서비스명 => 규칙으로 마운트
- 폴더 권한 부여

```shell
# 폴더 생성
mkdir ./app/{grafana,loki,redis,ollama,portainer,open-webui,prometheus}

# 모두
chmod -R 777 ./app

# 서비스별
chmod -R 777 ./app/grafana
chmod -R 777 ./app/loki
chmod -R 777 ./app/redis
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

## 서비스별 목록

| 서비스 명  | 포트 번호 | 설명                 | alpine 버전 |
| ---------- | --------- | -------------------- | ----------- |
| nginx      | 80, 443   | 웹 서버              | O           |
| prometheus | 9090      | 시계열 데이터베이스  | X           |
| grafana    | 3000      | 데이터 시각화 도구   | X           |
| loki       | 3100      | 로그 수집 서버       | X           |
| promtail   | X         | 로그 수집 클라이언트 | X           |
| ollama     | 11434     | AI 서버              | X           |
| open-webui | 8282:8080 | AI 서버 UI           | X           |
| portainer  | 8888:9000 | 컨테이너 관리 도구   | O           |
| redis      | 6379      | 캐시 데이터베이스    | O           |
| certbot    | x         | SSL 인증서 발급/갱신 | X           |
| jaeger     | 다량      | Jaeger 트레이싱      | X           |
| pocketbase | 8090      | 포켓베이스(BaaS)     | X           |
