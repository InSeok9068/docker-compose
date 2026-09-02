# 채용 브리지 검증 기록

검증 시각: **2026-09-02 16:46 KST**. 공고 수는 이후 변경될 수 있습니다.

Windows 로컬의 공식 PHP **8.4.25**와 당일 받은 RSS-Bridge `master` 소스로 실행했습니다.
다운로드한 PHP는 공식 릴리스 메타데이터의 SHA-256과 대조했습니다.

## 실제 조회 결과

기본 설정: `min_years=5`, `max_years=15`, `my_years=8`, `include_unknown=yes`.

| 회사 | 읽은 원본 목록 | 최종 피드 항목 | 포함된 세부 공고 | 8년 연차 충족 | 8년 조건 밖 | 연차 미기재/보류 |
|---|---:|---:|---:|---:|---:|---:|
| 네이버 | 32 | 0 | 0 | 0 | 0 | 0 |
| 카카오 | 31 | 5 | 5 | 3 | 1 | 1 |
| 당근 | 45 | 9 | 9 | 8 | 0 | 1 |
| 라인 | 376 | 3 | 3 | 2 | 0 | 1 |
| 쿠팡 | 685 | 24 | 24 | 16 | 7 | 1 |
| 우아한형제들 | 49 | 0 | 0 | 0 | 0 | 0 |
| 토스 | 454 | 5 | 25 | 6 | 1 | 18 |

연차 판정 열은 세부 공고 기준입니다. 토스는 원본 271개 그룹의 454개 공고에서 조건에 맞는 25개를 5개 그룹으로 묶었습니다.
토스의 `Server Developer (3년 이하)`는 제외됐습니다. `3~6년차 집중채용`은 5~15년 탐색 범위에는 겹치지만 8년 기준으로는 조건 밖이라고 표시했습니다.

원본 목록에는 다른 직군이 포함됩니다. 특히 라인은 비공개·과거·해외 기록도 들어 있어 공개 상태·날짜·한국 근무지 필터가 필요합니다.
네이버/우아한형제들 0건은 정상 응답 전체 목록을 읽고 위의 백엔드 개발자 조건으로 걸러낸 결과입니다.
해당 회사에 모든 종류의 개발자 채용이 없다는 뜻은 아닙니다.

## 확인한 공식 수집 구조

| 회사 | 출처 | 확인 사항 |
|---|---|---|
| 네이버 | [목록 API](https://recruit.navercorp.com/rcrt/loadJobList.do?firstIndex=0) | `firstIndex` 페이지 이동, `totalSize`, Backend/정규/근무지/마감 필드. 한국어 `Accept-Language` 필요 |
| 카카오 | [공식 포털 API](https://careers.kakao.com/public/api/job-list?company=ALL&part=TECHNOLOGY&page=1) | `company=ALL`, 1부터 시작하는 페이지, `totalPage`, 본문·공개/마감 상태 |
| 당근 | [채용 목록](https://careers.daangn.com/jobs/) | HTML 카드 직무/고용형태, 전체 카드 수, 상세 페이지의 `JobPosting` JSON-LD |
| 라인 | [페이지 데이터](https://careers.linecorp.com/page-data/ko/jobs/page-data.json) | `allStrapiJobs.edges`, `publish`, `is_public`, 한국 근무지, 상세 `strapiJobs.content` |
| 쿠팡 | [공개 Greenhouse API](https://boards-api.greenhouse.io/v1/boards/coupang/jobs?content=true) | 전체 게시 공고+본문, 국내 근무지, 고용형태 미기재 처리. [공식 API 문서](https://docs.greenhouse.io/job-board.html) |
| 우아한형제들 | [목록 API](https://career.woowahan.com/w1/recruits?page=0&size=25) | 0부터 시작하는 `page`, `totalPageNumber`, 정규직 코드 `BA002001`, 공개/마감 상태 |
| 토스 | [공개 목록 API](https://api-public.toss.im/api/v3/ipd-eggnog/career/job-groups) | `success[].jobs`, Employment/Category metadata, 계열사명, 공개 Job Description의 Markdown 본문, 미노출 플래그·클로징 일자/시각 |

사이트의 공개 HTML/JavaScript에서 요청 주소·필드·상세 경로를 확인했습니다.
쿠팡 API가 주는 `absolute_url`도 실제 공식 공고로 리다이렉트되는 것을 확인했습니다.

## 실행한 검사

- **64개 단정 검사 통과**: 호환용 파일 제거 후 경력·페이지·오류 처리, 토스 제목 경력 조건, 그룹화 전 필터링, 회사별 경력 유지, 숨김/마감/계약 공고 제외, 대표 공고 대체, 필터 변경 시 UID 유지를 검사했습니다.
- 7개 사이트를 PHP의 실제 RSS-Bridge `getContents()`로 조회했습니다. 회사별 Atom을 생성하고 XML 파싱·항목 수·UID 중복·URL 형식을 확인했습니다. 기존 6개 회사의 결과 수도 이전 검증과 동일합니다.
- 실제 RSS-Bridge `index.php`에서 `bridge=KoreanCareerBridge&company=toss`를 실행해 **5개 entry의 정상 Atom XML**을 확인했습니다.
- 현재 백엔드 공고가 없는 네이버·우아한형제들의 상세 파싱/피드 생성은 **합성 응답 테스트**로 검증했습니다. 네이버 실제 상세 HTML 구조와 우아한형제들 실제 상세 API 필드도 다른 직무 공고로 별도 확인했습니다.

## 검증 범위

서버 Docker와 FreshRSS 구독 등록은 이번 로컬 검증에 포함되지 않습니다.
현재 정상 수집과 알려진 필터 규칙을 검증한 것이며, 임의 문장에 대한 경력 추정 정확도나 모든 백엔드 공고의 누락 없음은 보장하지 않습니다.
카카오 관계사 공고의 공개 여부는 카카오 공식 포털 기준입니다. 연결된 별도 지원 사이트의 접수 상태까지 각각 재조회하지는 않습니다.
쿠팡의 고용형태 미기재 항목과 숫자가 없는 공고는 원문 확인이 필요합니다.
