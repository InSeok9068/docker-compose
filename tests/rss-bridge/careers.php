<?php

declare(strict_types=1);

// php tests/rss-bridge/careers.php /path/to/rss-bridge [--live [company]]
$root = $argv[1] ?? '/app';
require $root . '/lib/bootstrap.php';
require __DIR__ . '/../../config/rss-bridge/KoreanCareerBridge.php';
Configuration::loadConfiguration(['http' => ['retries' => 0]]);
$cache = new NullCache();
$logger = new SimpleLogger('career-test');
$container = ['cache' => $cache, 'logger' => $logger, 'http_client' => new CurlHttpClient()];
date_default_timezone_set('Asia/Seoul');
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $level) || $level === E_DEPRECATED) {
        return false;
    }
    throw new ErrorException($message, 0, $level, $file, $line);
});
$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

foreach ([
    ['서버 개발 경력 5년 이상', 5, null, false],
    ['서버 개발자로 4년 이상~12년 미만의 개발 경험', 4, 12, true],
    ['백엔드 개발 경력 5~15년', 5, 15, false],
    ['서버 개발 경력 5년~15년 이하', 5, 15, false],
    ['개발 경력 3년 이하', null, 3, false],
    ['5+ years of software development experience', 5, null, false],
    ['At least 7 years of experience', 7, null, false],
    ['8 or more years of software engineering experience', 8, null, false],
    ['5-10 years of backend development experience', 5, 10, false],
    ['Senior Backend Engineer', null, null, false],
    ['Java 17 / 2026년 출시 / 창립 15년', null, null, false],
    ['<h3>자격요건</h3><p>서버 개발 경력 5년 이상</p><h3>우대사항</h3><p>관련 경력 10년 이상</p>', 5, null, false],
    ['<h3>이런 분과 함께하고 싶어요</h3><p>3년 이상의 백엔드 엔지니어링 경험</p>', 3, null, false],
    ['웹 개발 경험자로 실무 5년차 이상', 5, null, false],
    ["8+ years of development experience\nHands-on experience within the past 3 years", 8, null, false],
    ['최근 3년 이내 서버 개발 경험', null, null, false],
] as [$text, $min, $max, $exclusive]) {
    $actual = KoreanCareerBridge::experience($text);
    check($actual['min'] === $min && $actual['max'] === $max && $actual['max_exclusive'] === $exclusive,
        $text . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}
$years = KoreanCareerBridge::experience('서버 개발 경력 3년 이상');
check(KoreanCareerBridge::overlaps($years, 5, 15), '3+ includes experienced applicants');
check(KoreanCareerBridge::fitLabel($years, 8) === '연차 조건 충족', '8 years meets 3+');
check(!KoreanCareerBridge::overlaps(KoreanCareerBridge::experience('개발 경력 3년 이하'), 5, 15), 'Junior ceiling excluded');
check(!KoreanCareerBridge::overlaps(KoreanCareerBridge::experience('개발 경력 5년 미만'), 5, 15), 'Exclusive upper bound');
check(!KoreanCareerBridge::overlaps(KoreanCareerBridge::experience('개발 경력 15년 초과'), 5, 15), 'Exclusive lower bound');
check(KoreanCareerBridge::fitLabel(KoreanCareerBridge::experience('개발 경력 10년 이상'), 8) === '연차 조건 밖', '10+ is not 8-year fit');
check(KoreanCareerBridge::experience('학사 8년 또는 석사 5년 이상 개발 경력')['uncertain'], 'Alternative degree paths');
foreach (['Frontend Engineer', 'Platform Server QA Engineer', 'DevOps Engineer', 'Data Engineer',
    '백엔드 시스템 정책/통제 (계약직)', 'Backend Engineer (인턴)', 'Software Engineer, Machine Learning',
    'Director, Back-End Engineering', 'Manager, Backend Engineering', 'Site Reliability Engineer'] as $title) {
    check(KoreanCareerBridge::backendReason($title) === null, 'Not backend: ' . $title);
}
check(KoreanCareerBridge::backendReason('Software Engineer, Backend') !== null, 'Backend title');
check(KoreanCareerBridge::backendReason('결제 개발', 'Backend') !== null, 'Official backend category');
check(KoreanCareerBridge::backendReason('Node.js Developer', 'Backend') !== null, 'Toss official backend category');
check(KoreanCareerBridge::backendReason('Software Engineer', '', '<h3>Responsibilities</h3><p>Build backend services</p>') !== null, 'Backend duties');
check(KoreanCareerBridge::backendReason('Software Engineer', '', '<h3>회사소개</h3><p>Our backend team</p>') === null, 'Company prose is not duties');
check(!KoreanCareerBridge::openDates('2999-01-01T00:00:00.000Z', null), 'Future opening');
check(!KoreanCareerBridge::openDates(null, '2000-01-01T00:00:00.000Z'), 'Closed posting');
check(KoreanCareerBridge::openDates('2000.01.01 10:00:00', '2999-12-31T14:30:00.000Z'), 'Date formats');

// Synthetic source responses exercise branches that may have no open jobs on a live run.
class FixtureCareerBridge extends KoreanCareerBridge
{
    public array $responses = [];
    protected function fetch(string $url): string
    {
        if (!array_key_exists($url, $this->responses)) {
            throw new RuntimeException('Unexpected fixture request: ' . $url);
        }
        $value = $this->responses[$url];
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }
}
$naverList = 'https://recruit.navercorp.com/rcrt/loadJobList.do?firstIndex=';
$naverRow = ['annoId' => 1, 'annoSubject' => 'Backend Engineer', 'subJobCdNm' => 'Backend',
    'empTypeCdNm' => '정규', 'workAreaCd' => '0010', 'sysCompanyCdNm' => 'NAVER',
    'staYmdTime' => '2000.01.01 00:00:00', 'endYmdTime' => '2999.01.01 00:00:00'];
$bridge = new FixtureCareerBridge($cache, $logger);
$bridge->setInput(['company' => 'naver']);
$bridge->responses = [
    $naverList . '0' => ['result' => 'Y', 'totalSize' => 2, 'list' => [$naverRow]],
    $naverList . '1' => ['result' => 'Y', 'totalSize' => 2, 'list' => [array_replace($naverRow, ['annoId' => 2, 'empTypeCdNm' => '계약'])]],
    'https://recruit.navercorp.com/rcrt/view.do?annoId=1' => '<div class="detail_wrap"><h4>필요 역량</h4><p>서버 개발 경력 5년 이상</p><h4>우대사항</h4><p>경력 12년 이상</p></div>',
];
$bridge->collectData();
check(count($bridge->getItems()) === 1 && str_contains($bridge->getItems()[0]['title'], '5년 이상'), 'NAVER pagination and detail');
check($bridge->getDiagnostics()['listed'] === 2, 'NAVER all pages');
$bridge->responses[$naverList . '1']['list'] = [$naverRow];
try {
    $bridge->collectData();
    check(false, 'Repeated page must throw');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), '중복 페이지'), 'Repeated page detected');
}
$bridge->responses[$naverList . '0'] = ['result' => 'Y', 'totalSize' => 0, 'list' => []];
$bridge->collectData();
check($bridge->getItems() === [], 'Valid empty listing');
$bridge->responses[$naverList . '0'] = ['result' => 'Y', 'totalSize' => 0];
try {
    $bridge->collectData();
    check(false, 'Missing list must throw');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), '목록 필드'), 'Schema failure is not an empty feed');
}
$wooRow = ['recruitNumber' => 'TEST', 'recruitName' => '서버 개발자',
    'employmentType' => ['recruitItemCode' => 'BA002001'], 'isHidden' => false, 'recruitDeleteYn' => false,
    'isTemporaryStatus' => false, 'recruitOpenDate' => '2000-01-01', 'recruitEndDate' => '2999-01-01', 'recruitCloseDate' => '2999-01-01'];
$bridge = new FixtureCareerBridge($cache, $logger);
$bridge->setInput(['company' => 'woowahan']);
$bridge->responses = [
    'https://career.woowahan.com/w1/recruits?page=0&size=25' => ['code' => '2000',
        'data' => ['totalSize' => 1, 'totalPageNumber' => 1, 'list' => [$wooRow]]],
    'https://career.woowahan.com/w1/recruits/TEST' => ['code' => '2000', 'data' => [
        'recruitContents' => '<h3>지원자격</h3><p>서버 개발 경력 7년 이상</p>',
        'careerRestrictionMinYears' => 5, 'careerRestrictionMaxYears' => 20]],
];
$bridge->collectData();
check(str_contains($bridge->getItems()[0]['title'], '7년 이상'), 'Visible requirements override broad metadata');
check(str_ends_with($bridge->getItems()[0]['uri'], '/TEST/detail'), 'Woowahan detail route');
$bridge->responses['https://career.woowahan.com/w1/recruits/TEST']['data']['recruitContents'] = '<p>Java 개발 경험</p>';
$bridge->collectData();
check(str_contains($bridge->getItems()[0]['title'], '5년 이상~20년 이하'), 'Explicit metadata fallback');
$bridge->responses['https://career.woowahan.com/w1/recruits/TEST']['data']['careerRestrictionMinYears'] = -1;
$bridge->responses['https://career.woowahan.com/w1/recruits/TEST']['data']['careerRestrictionMaxYears'] = -1;
$bridge->setInput(['company' => 'woowahan', 'include_unknown' => 'no']);
$bridge->collectData();
check($bridge->getItems() === [], 'Unknown excluded when requested');
$bridge->setInput(['company' => 'woowahan', 'include_unknown' => 'yes']);
$bridge->collectData();
check(count($bridge->getItems()) === 1, 'Unknown retained when enabled');
$bridge->responses['https://career.woowahan.com/w1/recruits/TEST']['data']['recruitContents'] = '<p>서버 개발 경력 16년 이상</p>';
$bridge->collectData();
check($bridge->getItems() === [], 'Outside 5-15 years excluded');

$titleYears = KoreanCareerBridge::experience('<p>개발 경력 1년 이상</p>', 'Server Developer (3년 이하)');
check($titleYears['min'] === 1 && $titleYears['max'] === 3, 'Toss title upper bound supplements body');
$titleYears = KoreanCareerBridge::experience('<p>개발 경험</p>', 'Server Developer 3~6년차 집중채용 (~9/10)');
check($titleYears['min'] === 3 && $titleYears['max'] === 6, 'Title year range ignores campaign date');
function tossFixtureJob(int $id, string $title, string $company, string $description): array
{
    return ['id' => $id, 'title' => $title, 'location' => ['name' => 'Seoul'],
        'first_published' => '2000-01-01T00:00:00Z', 'application_deadline' => null,
        'metadata' => [
            ['name' => 'Employment_Type', 'value' => '정규직'],
            ['name' => '커리어 페이지 노출 Job Category 값을 선택해주세요', 'value' => '["Backend"]'],
            ['name' => '포지션의 소속 자회사를 선택해 주세요.', 'value' => $company],
            ['name' => 'Job Description을 작성해 주세요.', 'value' => $description],
        ]];
}
$tossUrl = 'https://api-public.toss.im/api/v3/ipd-eggnog/career/job-groups';
$junior = tossFixtureJob(1, 'Server Developer (3년 이하)', '주니어회사', '개발 경력 1년 이상');
$senior = tossFixtureJob(2, 'Server Developer', '토스', "# 이런 분과 함께하고 싶어요\n- 개발 경력 5년 이상");
$lead = tossFixtureJob(3, 'Server Developer', '토스뱅크', "# 이런 분과 함께하고 싶어요\n- 개발 경력 12년 이상");
$unknown = tossFixtureJob(4, 'Node.js Developer', '토스증권', '백엔드 개발 경험을 가진 분');
$hidden = tossFixtureJob(5, 'Server Developer', '숨김회사', '개발 경력 5년 이상');
$hidden['metadata'][] = ['name' => '커리어페이지 메뉴에 "미노출" 되어야 하는 Job인가요?', 'value' => true];
$closed = tossFixtureJob(6, 'Server Developer', '마감회사', '개발 경력 5년 이상');
$closed['metadata'][] = ['name' => '커리어페이지 채용공고 클로징 일자 (서류접수 마감일이 정해진 경우)', 'value' => '2000-01-01'];
$devops = tossFixtureJob(7, 'DevOps Engineer', '토스', '개발 경력 5년 이상');
$contract = tossFixtureJob(8, 'Server Developer', '계약회사', '개발 경력 5년 이상');
$contract['metadata'][0]['value'] = '계약직';
$tossResponse = ['resultType' => 'SUCCESS', 'success' => [
    ['title' => 'Server Developer', 'primary_job' => $junior, 'jobs' => [$junior, $senior, $lead, $hidden, $closed, $contract]],
    ['title' => 'Node.js Developer', 'primary_job' => $unknown, 'jobs' => []],
    ['title' => 'DevOps Engineer', 'primary_job' => $devops, 'jobs' => [$devops]],
]];
$bridge = new FixtureCareerBridge($cache, $logger);
$bridge->setInput(['company' => 'toss']);
$bridge->responses = [$tossUrl => $tossResponse];
$bridge->collectData();
$items = $bridge->getItems();
check(count($items) === 2 && $bridge->getDiagnostics()['matched_jobs'] === 3, 'Group only matching subjobs; primary fallback');
check($items[0]['uid'] === 'toss-backend-' . sha1($junior['title']), 'Group UID uses stable representative title');
check($items[0]['uri'] === 'https://toss.im/career/job-detail?gh_jid=2', 'Filtered primary not used as link');
check(str_contains($items[0]['title'], '계열사별 연차 확인'), 'Do not merge different company requirements into one range');
check(str_contains($items[0]['content'], '5년 이상 / 8년 기준: 연차 조건 충족')
    && str_contains($items[0]['content'], '12년 이상 / 8년 기준: 연차 조건 밖'), 'Each company retains its own fit');
check(!preg_match('/주니어회사|숨김회사|마감회사|계약회사/', $items[0]['content']), 'Filtered companies absent from group');
$bridge->setInput(['company' => 'toss', 'min_years' => 8, 'max_years' => 8, 'include_unknown' => 'no']);
$bridge->collectData();
check(count($bridge->getItems()) === 1 && $bridge->getDiagnostics()['matched_jobs'] === 1, 'Exact eight-year filter before grouping');
check($bridge->getItems()[0]['uid'] === $items[0]['uid'], 'UID stable across filtering');
$bridge->responses[$tossUrl]['success'][0]['jobs'][1]['metadata'][3]['value'] = '';
try {
    $bridge->collectData();
    check(false, 'Missing Toss JD must throw');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), '본문 누락'), 'Missing Toss JD is not unknown years');
}
$bridge->responses[$tossUrl] = ['resultType' => 'SUCCESS', 'success' => []];
$bridge->collectData();
check($bridge->getItems() === [], 'Valid empty Toss feed');

echo "PASS {$checks} checks\n";

if (($argv[2] ?? '') !== '--live') {
    exit(0);
}
$companies = isset($argv[3]) ? [$argv[3]] : ['naver', 'kakao', 'daangn', 'line', 'coupang', 'woowahan', 'toss'];
$failed = false;
foreach ($companies as $company) {
    $started = microtime(true);
    try {
        $bridge = new KoreanCareerBridge($cache, $logger);
        $bridge->setInput(['company' => $company]);
        $bridge->collectData();
        $items = $bridge->getItems();
        check(count(array_unique(array_column($items, 'uid'))) === count($items), 'Unique IDs');
        foreach ($items as $item) {
            check(!empty($item['title']) && filter_var($item['uri'], FILTER_VALIDATE_URL) !== false, 'Feed item fields');
        }
        $format = new AtomFormat();
        $format->setFeed($bridge->getFeed());
        $format->setItems($items);
        $format->setLastModified(time());
        $xml = $format->render();
        $document = new DOMDocument();
        check($document->loadXML($xml), 'Atom XML parses');
        check($document->getElementsByTagName('entry')->length === count($items), 'Atom count');
        $output = getenv('CAREER_REPORT_DIR');
        if ($output) {
            if (!is_dir($output)) {
                mkdir($output, 0777, true);
            }
            file_put_contents($output . '/' . $company . '.atom', $xml);
            file_put_contents($output . '/' . $company . '.json', json_encode([
                'checked_at' => date(DATE_ATOM), 'diagnostics' => $bridge->getDiagnostics(), 'items' => $items,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        echo json_encode(['status' => 'PASS', 'seconds' => round(microtime(true) - $started, 1),
            'diagnostics' => $bridge->getDiagnostics(), 'titles' => array_column($items, 'title')], JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, $company . ' FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    }
}
exit($failed ? 1 : 0);
