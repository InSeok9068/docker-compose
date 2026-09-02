# 백엔드 채용 피드

`KoreanCareerBridge.php`에서 토스를 포함한 7개 회사의 수집·경력 판별을 관리합니다.
PHP 8.1 이상과 RSS-Bridge 기본 확장을 사용하며, 추가 패키지·API 키는 필요 없습니다.

## 서버 반영

서버의 브리지 파일을 `KoreanCareerBridge.php`로 교체한 뒤 실행합니다.

```sh
docker compose up -d --force-recreate rss-bridge
```

현재 Compose의 `./config/rss-bridge:/config` 마운트를 그대로 사용합니다.
RSS-Bridge는 [컨테이너 시작 시 `/config`의 브리지 파일을 복사](https://github.com/RSS-Bridge/rss-bridge/blob/master/docker-entrypoint.sh)합니다.
이전에 복사된 브리지도 제거되도록 컨테이너를 재생성합니다.
별도의 허용 목록을 운영 중이라면 `KoreanCareerBridge`도 추가하세요. 이 저장소에는 별도 허용 목록이 없습니다.

## FreshRSS 구독 주소

각 주소는 별도 피드입니다. 기본값은 탐색 범위 **5~15년**, 내 경력 **8년**, 연차 미기재 공고 **포함**입니다.

```text
http://rss-bridge/?action=display&bridge=KoreanCareerBridge&company=naver&format=Atom
http://rss-bridge/?action=display&bridge=KoreanCareerBridge&company=kakao&format=Atom
http://rss-bridge/?action=display&bridge=KoreanCareerBridge&company=daangn&format=Atom
http://rss-bridge/?action=display&bridge=KoreanCareerBridge&company=line&format=Atom
http://rss-bridge/?action=display&bridge=KoreanCareerBridge&company=coupang&format=Atom
http://rss-bridge/?action=display&bridge=KoreanCareerBridge&company=woowahan&format=Atom
http://rss-bridge/?action=display&bridge=KoreanCareerBridge&company=toss&format=Atom
```

브라우저에서 확인하려면 호스트를 RSS-Bridge 외부 주소로 바꾸고 `format=Html`을 사용합니다.
토스 구독도 위의 `company=toss` 주소로 교체합니다.

## 판별 기준

- 공식 목록의 공개·마감 상태와 제공되는 날짜를 확인합니다. 목록이 여러 페이지이면 끝까지 읽습니다.
- 공식 Backend/Server-side 분류 또는 백엔드·서버 개발 직무명을 사용합니다. 일반 Software Engineer는 담당업무에 백엔드 개발 근거가 있을 때만 포함합니다.
- 프론트엔드, 모바일, QA, SRE, DevOps, 데이터 엔지니어, 인턴, 신입, 관리 전담 Manager/Director 등은 제외합니다. Senior/Staff 개발자는 포함합니다.
- 정규직을 선택합니다. 쿠팡은 API에 고용형태 필드가 없어 본문에서 계약직을 제외하며, 정규직도 확인되지 않으면 **고용형태 미기재**로 표시하고 포함합니다.
- 네이버·카카오는 공식 포털에 게시된 관계사도 포함합니다. 라인·쿠팡은 국내 근무지를 필터링합니다. 카카오 관계사의 근무지가 비어 있으면 원문 확인으로 표시합니다.
- 경력 숫자는 지원자격 본문을 우선 사용하고 우대사항은 구분합니다. 우아한형제들은 본문에 숫자가 없으면 공식 경력 검색 필드를 사용합니다.
- 제목에 명시된 `3년 이하`, `3~6년차` 등의 조건도 반영합니다.
- `Senior`, `Staff`만으로 연차를 만들지 않습니다. 연차가 없거나 학위별 대체 조건처럼 해석이 불확실하면 판단을 보류합니다. 정규식 기반이므로 원문 근거도 함께 표시합니다.

**5~15년과 공고의 허용 경력이 겹치면 포함**합니다. `3년 이상`도 8년차가 지원할 수 있으므로 포함합니다.
`10년 이상`도 탐색 범위에는 포함되지만 본문에 `8년 기준: 연차 조건 밖`이라고 표시합니다.
`4년 이상~12년 미만`은 상한의 미만 조건까지 구분합니다. 이 표시는 연차 조건 비교이며 전체 지원 자격 판정은 아닙니다.

토스는 공식 `job-groups`의 묶음을 유지합니다. 계열사별 공개·고용형태·직무·경력 조건을 먼저 필터링하고, 남은 세부 공고들을 그룹당 한 항목으로 만듭니다.
회사마다 연차가 다르면 `계열사별 연차 확인`으로 표시하고 본문에서 각 회사의 연차와 8년차 기준 판정을 보여줍니다.
기존 토스의 넓은 Backend 분류에 포함된 DevOps/SRE도 공통 개발자 필터에 따라 제외합니다.

파라미터를 추가해 범위를 바꿀 수 있습니다.

```text
&min_years=5&max_years=15&my_years=8&include_unknown=yes
```

숫자로 확인된 **8년차 조건만** 보려면:

```text
&min_years=8&max_years=8&my_years=8&include_unknown=no
```

캐시는 30분입니다. 정상적인 0건은 빈 피드로 반환하고, API/본문 구조 변경·페이지 누락·조회 실패는 오류로 처리합니다.
공고가 닫히면 다음 수집 결과에서 빠지지만 **FreshRSS에 이미 저장된 글까지 삭제되지는 않습니다.**

## 검증

2026-09-02 실제 조회 결과와 검증 범위는 [검증 기록](../../tests/rss-bridge/VERIFICATION.md)에 있습니다.

RSS-Bridge 소스를 가진 PHP 환경에서:

```sh
# 판별·파싱·오류 처리 검사
php tests/rss-bridge/careers.php /path/to/rss-bridge

# 7개 회사 실제 수집 및 Atom XML 검증
php tests/rss-bridge/careers.php /path/to/rss-bridge --live

# 특정 회사만 실제 조회
php tests/rss-bridge/careers.php /path/to/rss-bridge --live toss
```

`CAREER_REPORT_DIR` 환경변수를 지정하면 회사별 JSON/Atom 결과도 저장합니다.

실제 서버의 브리지 로딩 확인:

```sh
docker exec rss-bridge php /app/index.php 'action=display&bridge=KoreanCareerBridge&company=toss&format=Atom'
```
