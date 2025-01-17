# Docker-Compose

도커 컴포즈 (ARM 기반 서버)

이미지가 `arm64v8` 호환하는지 확인

## 규칙

앱 데이터 마운트 시킬때

.app/서비스명 => 규칙으로 마운트

## 다른 이름의 도커 컴포즈 파일을 실행 시킬 때

docker compose -f docker-compose-wsl.yml up

## 도커 사용자 권한 부여

```shell
sudo usermod -aG docker $USER
newgrp docker
```

## 폴더 권한 부여

```shell
chmod -R 777 ./app/prometheus
chmod -R 777 ./app/grafana
chmod -R 777 ./app/loki
```

## WSL 도커 CLI 설치

```shell
curl -sSL get.docker.com | sh
```

## 서비스별 목록

| 서비스 명  | 포트 번호 | alpine 버전 |
| ---------- | --------- | ----------- |
| nginx      | 80, 443   | O           |
| prometheus | 9090      | X           |
| grafana    | 3000      | X           |
| loki       | 3100      | X           |
| promtail   | X         | X           |
| ollama     | 11434     | X           |
| open-webui | 8282:8080 | X           |
| portainer  | 8888:9000 | O           |
| redis      | 6379      | O           |
