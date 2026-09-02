<?php

declare(strict_types=1);

/** Public career listings only. No login, application submission, or private APIs. */
class KoreanCareerBridge extends BridgeAbstract
{
    const NAME = 'Korean Careers - Backend';
    const URI = 'https://recruit.navercorp.com/';
    const DESCRIPTION = '네이버·카카오·당근·라인·쿠팡·우아한형제들·토스 국내 백엔드 채용과 경력 조건';
    const CACHE_TIMEOUT = 1800;
    const PARAMETERS = [[
        'company' => [
            'name' => '회사', 'type' => 'list', 'required' => true,
            'values' => ['네이버' => 'naver', '카카오' => 'kakao', '당근' => 'daangn',
                '라인' => 'line', '쿠팡' => 'coupang', '우아한형제들' => 'woowahan', '토스' => 'toss'],
        ],
        'min_years' => ['name' => '탐색 경력 하한', 'type' => 'number', 'defaultValue' => 5],
        'max_years' => ['name' => '탐색 경력 상한', 'type' => 'number', 'defaultValue' => 15],
        'my_years' => ['name' => '내 경력', 'type' => 'number', 'defaultValue' => 8],
        'include_unknown' => [
            'name' => '연차 미기재·판독 불확실 공고', 'type' => 'list',
            'values' => ['포함' => 'yes', '제외' => 'no'], 'defaultValue' => 'yes',
        ],
    ]];

    private const COMPANIES = [
        'naver' => ['네이버', 'https://recruit.navercorp.com/rcrt/list.do'],
        'kakao' => ['카카오', 'https://careers.kakao.com/jobs?company=ALL&part=TECHNOLOGY'],
        'daangn' => ['당근', 'https://careers.daangn.com/jobs/'],
        'line' => ['라인', 'https://careers.linecorp.com/ko/jobs/'],
        'coupang' => ['쿠팡', 'https://www.coupang.jobs/kr/jobs/'],
        'woowahan' => ['우아한형제들', 'https://career.woowahan.com/recruitment'],
        'toss' => ['토스', 'https://toss.im/career/jobs?main_category=Engineering&sub_category=Backend'],
    ];

    private array $diagnostics = [];

    public function getName()
    {
        return (self::COMPANIES[$this->getInput('company')][0] ?? 'Korean Careers') . ' - Backend';
    }

    public function getURI()
    {
        return self::COMPANIES[$this->getInput('company')][1] ?? self::URI;
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    public function collectData()
    {
        $company = $this->getInput('company');
        $min = (int) $this->getInput('min_years');
        $max = (int) $this->getInput('max_years');
        $mine = (int) $this->getInput('my_years');
        if (!isset(self::COMPANIES[$company]) || $min < 0 || $max < $min || $max > 60 || $mine < 0 || $mine > 60) {
            throw new InvalidArgumentException('회사와 경력 범위(0~60, 하한 <= 상한)를 확인하세요.');
        }
        $this->items = [];
        $this->diagnostics = ['company' => $company, 'listed' => 0, 'backend_candidates' => 0,
            'outside_years' => 0, 'unknown_excluded' => 0, 'matched_jobs' => 0, 'fit_counts' => [], 'items' => 0];
        switch ($company) {
            case 'naver': $jobs = $this->naver(); break;
            case 'kakao': $jobs = $this->kakao(); break;
            case 'daangn': $jobs = $this->daangn(); break;
            case 'line': $jobs = $this->line(); break;
            case 'coupang': $jobs = $this->coupang(); break;
            case 'woowahan': $jobs = $this->woowahan(); break;
            case 'toss': $jobs = $this->toss(); break;
        }
        $seen = [];
        $groups = [];
        foreach ($jobs as $job) {
            if (isset($seen[$job['id']])) {
                continue;
            }
            $seen[$job['id']] = true;
            $this->diagnostics['backend_candidates']++;
            $years = self::experience($job['requirements'] ?? $job['description'], $job['title']);
            // Visible qualification text takes precedence over broad search-filter metadata.
            if ($years['source'] === 'unknown' && isset($job['years'])) {
                $years = $job['years'];
            }
            if ($years['source'] === 'unknown' || $years['uncertain']) {
                if ($this->getInput('include_unknown') === 'no') {
                    $this->diagnostics['unknown_excluded']++;
                    continue;
                }
            } elseif (!self::overlaps($years, $min, $max)) {
                $this->diagnostics['outside_years']++;
                continue;
            }
            $label = self::yearLabel($years);
            $fit = self::fitLabel($years, $mine);
            $this->diagnostics['matched_jobs']++;
            $this->diagnostics['fit_counts'][$fit] = ($this->diagnostics['fit_counts'][$fit] ?? 0) + 1;
            $content = '<p><strong>경력:</strong> ' . self::escape($label)
                . ' / ' . self::escape($mine . '년 기준: ' . $fit) . '</p>'
                . '<p><strong>근무지:</strong> ' . self::escape($job['location'])
                . ' / <strong>고용형태:</strong> ' . self::escape($job['employment']) . '</p>'
                . '<p><strong>백엔드 분류 근거:</strong> ' . self::escape($job['role_reason']) . '</p>';
            if ($years['evidence'] !== []) {
                $content .= '<p><strong>경력 판독 근거:</strong><br>'
                    . nl2br(self::escape(implode("\n", $years['evidence']))) . '</p>';
            }
            $content .= '<p>수치는 공고의 연차 조건만 비교합니다. 기술·도메인·리더십 요건은 원문을 확인하세요.</p>'
                . '<p><a href="' . self::escape($job['url']) . '">공식 공고</a></p>'
                . '<hr>' . nl2br(self::escape(self::plainText($job['description'])));
            $item = ['uid' => $company . '-career-' . $job['id'],
                'title' => '[' . $label . '] ' . $job['title'], 'uri' => $job['url'],
                'author' => $job['company'], 'content' => $content,
                'categories' => ['Backend', $years['source'], $fit]];
            if (!empty($job['published'])) {
                $item['timestamp'] = self::date($job['published']);
            }
            if (isset($job['group_uid'])) {
                $groups[$job['group_uid']][] = ['job' => $job, 'item' => $item, 'label' => $label, 'fit' => $fit];
            } else {
                $this->items[] = $item;
            }
        }
        foreach ($groups as $members) {
            $this->items[] = $this->tossGroupItem($members);
        }
        usort($this->items, static fn(array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
        $this->diagnostics['items'] = count($this->items);
        // A valid empty result is allowed; invalid schemas and failed pages throw instead.
    }

    protected function fetch(string $url): string
    {
        return getContents($url, ['Accept: application/json, text/html;q=0.9',
            'Accept-Language: ko-KR,ko;q=0.9,en;q=0.5',
            'User-Agent: Mozilla/5.0', 'Referer: ' . $this->getURI()],
            [CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40]);
    }

    private function json(string $url): array
    {
        $data = json_decode($this->fetch($url), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('JSON 객체가 아닙니다: ' . $url);
        }
        return $data;
    }

    private static function required(array $row, array $keys, string $source): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                throw new RuntimeException($source . ': 필드 누락 ' . $key . ' (사이트 구조 확인 필요)');
            }
        }
    }

    private static function rows(array $data, string $key, string $source): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new RuntimeException($source . ': 목록 필드 누락/변경 ' . $key);
        }
        return $data[$key];
    }

    private function toss(): array
    {
        $data = $this->json('https://api-public.toss.im/api/v3/ipd-eggnog/career/job-groups');
        if (($data['resultType'] ?? '') !== 'SUCCESS') {
            throw new RuntimeException('Toss 목록 조회 실패');
        }
        $groups = self::rows($data, 'success', 'Toss');
        $this->diagnostics['source_groups'] = count($groups);
        $jobs = [];
        $markdown = new Parsedown();
        $markdown->setSafeMode(true);
        foreach ($groups as $group) {
            self::required($group, ['title', 'primary_job'], 'Toss group');
            $rows = self::rows($group, 'jobs', 'Toss group');
            if ($rows === [] && is_array($group['primary_job'])) {
                $rows = [$group['primary_job']];
            }
            // Keep the group UID stable when the representative is filtered out.
            $uidTitle = trim($group['primary_job']['title'] ?? $group['title']);
            foreach ($rows as $row) {
                self::required($row, ['id', 'title', 'metadata', 'location', 'first_published', 'application_deadline'], 'Toss job');
                $this->diagnostics['listed']++;
                $metadata = [];
                foreach (self::rows($row, 'metadata', 'Toss job') as $entry) {
                    self::required($entry, ['name', 'value'], 'Toss metadata');
                    $metadata[trim($entry['name'])] = $entry['value'];
                }
                $categoryKey = '커리어 페이지 노출 Job Category 값을 선택해주세요';
                self::required($metadata, ['Employment_Type', $categoryKey], 'Toss metadata');
                if (!in_array('정규직', self::metadataValues($metadata['Employment_Type']), true)
                    || !in_array('Backend', self::metadataValues($metadata[$categoryKey]), true)) {
                    continue;
                }
                $hidden = $metadata['커리어페이지 메뉴에 "미노출" 되어야 하는 Job인가요?'] ?? false;
                if (in_array(strtolower((string) $hidden), ['1', 'true', 'yes'], true)) {
                    continue;
                }
                $closingDate = $metadata['커리어페이지 채용공고 클로징 일자 (서류접수 마감일이 정해진 경우)'] ?? null;
                $closingTime = $metadata['커리어페이지 채용공고 클로징 시각 (자정 마감이 아닌 경우)'] ?? null;
                $deadline = $closingDate ? $closingDate . ' ' . ($closingTime ?: '23:59:59') : null;
                if (!self::openDates($row['first_published'], $row['application_deadline']) || !self::openDates(null, $deadline)) {
                    continue;
                }
                $reason = self::backendReason($row['title'], 'Backend');
                if ($reason === null) {
                    continue;
                }
                // Public Job Description metadata contains the full Markdown used by Toss's detail page.
                $description = '';
                foreach ($metadata as $name => $value) {
                    if (str_starts_with($name, 'Job Description')) {
                        $description = is_string($value) ? trim($value) : '';
                        break;
                    }
                }
                if ($description === '') {
                    throw new RuntimeException('Toss Job Description 본문 누락: ' . $row['id']);
                }
                $company = implode(', ', self::metadataValues($metadata['포지션의 소속 자회사를 선택해 주세요.'] ?? null));
                $jobs[] = ['id' => (string) $row['id'], 'title' => trim($row['title']),
                    'url' => 'https://toss.im/career/job-detail?gh_jid=' . (int) $row['id'],
                    'company' => $company ?: ($row['company_name'] ?? 'Toss'),
                    'location' => $row['location']['name'] ?? '공식 원문 확인', 'employment' => '정규직',
                    'description' => $markdown->text($description), 'published' => $row['first_published'],
                    'role_reason' => $reason, 'group_title' => trim($group['title']),
                    'group_uid' => 'toss-backend-' . sha1($uidTitle)];
            }
        }
        return $jobs;
    }

    private static function metadataValues($value): array
    {
        if (is_array($value)) {
            $values = [];
            foreach ($value as $part) {
                $values = array_merge($values, self::metadataValues($part));
            }
            return $values;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? self::metadataValues($decoded) : [trim($value)];
    }

    private function tossGroupItem(array $members): array
    {
        $first = $members[0];
        $labels = array_unique(array_column($members, 'label'));
        $fits = array_unique(array_column($members, 'fit'));
        $label = count($labels) === 1 ? $first['label'] : '계열사별 연차 확인';
        $fit = count($fits) === 1 ? $first['fit'] : '계열사별 연차 확인';
        $companies = [];
        $latest = 0;
        $summary = '<p>현재 조건에 맞는 세부 공고 ' . count($members) . '개입니다. 계열사별 조건을 확인하세요.</p><ul>';
        $details = '';
        foreach ($members as $member) {
            $item = $member['item'];
            $companies[] = $item['author'];
            $latest = max($latest, $item['timestamp'] ?? 0);
            $summary .= '<li><a href="' . self::escape($item['uri']) . '">'
                . self::escape($item['author'] . ' / ' . $member['job']['title']) . '</a>: '
                . self::escape($member['label'] . ' / ' . $this->getInput('my_years') . '년 기준: ' . $member['fit']) . '</li>';
            $details .= '<hr><h2>' . self::escape($item['author'] . ' / ' . $member['job']['title']) . '</h2>' . $item['content'];
        }
        $item = ['uid' => $first['job']['group_uid'], 'title' => '[' . $label . '] ' . $first['job']['group_title'],
            'uri' => $first['item']['uri'], 'author' => implode(', ', array_unique($companies)),
            'content' => $summary . '</ul>' . $details, 'categories' => ['Backend', 'group', $fit]];
        if ($latest > 0) {
            $item['timestamp'] = $latest;
        }
        return $item;
    }

    private function naver(): array
    {
        $jobs = [];
        $seen = [];
        $offset = 0;
        do {
            $data = $this->json('https://recruit.navercorp.com/rcrt/loadJobList.do?firstIndex=' . $offset);
            self::required($data, ['result', 'totalSize'], 'NAVER');
            if ($data['result'] !== 'Y') {
                throw new RuntimeException('NAVER 목록 조회 실패');
            }
            $rows = self::rows($data, 'list', 'NAVER');
            $this->checkPage($rows, $seen, 'annoId', $offset < (int) $data['totalSize'], 'NAVER');
            foreach ($rows as $row) {
                self::required($row, ['annoId', 'annoSubject', 'subJobCdNm', 'empTypeCdNm', 'workAreaCd', 'staYmdTime', 'endYmdTime'], 'NAVER');
                $this->diagnostics['listed']++;
                $locations = ['0010' => '분당', '0020' => '서울', '0030' => '춘천', '0040' => '세종'];
                if ($row['empTypeCdNm'] !== '정규' || !isset($locations[$row['workAreaCd']])
                    || !self::openDates($row['staYmdTime'], $row['endYmdTime'])) {
                    continue;
                }
                $reason = self::backendReason($row['annoSubject'], $row['subJobCdNm']);
                if ($reason === null) {
                    continue;
                }
                $url = 'https://recruit.navercorp.com/rcrt/view.do?annoId=' . (int) $row['annoId'];
                $html = str_get_html($this->fetch($url));
                $detail = $html->find('.detail_wrap', 0);
                if (!$detail || trim($detail->plaintext) === '') {
                    throw new RuntimeException('NAVER 상세 본문 누락: ' . $url);
                }
                $jobs[] = ['id' => (string) $row['annoId'], 'title' => $row['annoSubject'], 'url' => $url,
                    'company' => $row['sysCompanyCdNm'], 'location' => $locations[$row['workAreaCd']],
                    'employment' => '정규직', 'description' => $detail->innertext,
                    'published' => $row['staYmdTime'], 'role_reason' => $reason];
            }
            $offset += count($rows);
        } while ($offset < (int) $data['totalSize']);
        return $jobs;
    }

    private function kakao(): array
    {
        $jobs = [];
        $seen = [];
        $page = 1;
        do {
            $data = $this->json('https://careers.kakao.com/public/api/job-list?company=ALL&part=TECHNOLOGY&page=' . $page);
            self::required($data, ['totalPage', 'totalJobCount'], 'Kakao');
            $rows = self::rows($data, 'jobList', 'Kakao');
            $this->checkPage($rows, $seen, 'realId', (int) $data['totalJobCount'] > 0, 'Kakao');
            foreach ($rows as $row) {
                self::required($row, ['realId', 'jobOfferTitle', 'employeeTypeName', 'closeFlag', 'privateFlag', 'useFlag'], 'Kakao');
                $this->diagnostics['listed']++;
                if ($row['closeFlag'] || $row['privateFlag'] || !$row['useFlag'] || $row['employeeTypeName'] !== '정규직'
                    || !self::openDates(null, $row['endDate'] ?? null)
                    || !self::openDates(null, $row['resumeSubmissionEndDatetime'] ?? null)) {
                    continue;
                }
                $reason = self::backendReason($row['jobOfferTitle'], self::names($row['skillSetList'] ?? [], 'skillSetName'));
                if ($reason === null) {
                    continue;
                }
                $description = ($row['introduction'] ?? '') . '<h3>업무내용</h3>' . ($row['workContentDesc'] ?? '')
                    . '<h3>지원자격</h3>' . ($row['qualification'] ?? '');
                if (mb_strlen(self::plainText($description)) < 30) {
                    throw new RuntimeException('Kakao 상세 본문 누락: ' . $row['realId']);
                }
                $jobs[] = ['id' => $row['realId'], 'title' => $row['jobOfferTitle'],
                    'url' => 'https://careers.kakao.com/jobs/' . rawurlencode($row['realId']),
                    'company' => $row['companyName'], 'location' => $row['locationName'] ?: '공식 원문 확인',
                    'employment' => '정규직', 'description' => $description,
                    'published' => $row['regDate'], 'role_reason' => $reason];
            }
            $page++;
        } while ($page <= (int) $data['totalPage']);
        return $jobs;
    }

    private function daangn(): array
    {
        $html = str_get_html($this->fetch('https://careers.daangn.com/jobs/'));
        $list = $html->find('[data-job-list]', 0);
        $count = $html->find('[data-job-count]', 0);
        if (!$list || !$count) {
            throw new RuntimeException('당근 공고 목록 구조 변경');
        }
        $cards = $list->find('[data-job-card]');
        if (count($cards) !== (int) $count->plaintext) {
            throw new RuntimeException('당근 전체 공고 수와 수집된 카드 수 불일치');
        }
        $jobs = [];
        foreach ($cards as $card) {
            $this->diagnostics['listed']++;
            if ($card->getAttribute('data-employment-type') !== 'full-time'
                || !in_array('software-engineer-backend', explode(',', $card->getAttribute('data-department-slugs')), true)) {
                continue;
            }
            $link = $card->find('a', 0);
            if (!$link || !preg_match('~^/jobs/role/(\d+)/$~', $link->href, $match)) {
                throw new RuntimeException('당근 공고 링크 구조 변경');
            }
            $url = 'https://careers.daangn.com' . $link->href;
            $detail = str_get_html($this->fetch($url));
            $posting = null;
            foreach ($detail->find('script[type="application/ld+json"]') as $script) {
                $schema = json_decode($script->innertext, true, 512, JSON_THROW_ON_ERROR);
                foreach (isset($schema['@type']) ? [$schema] : $schema as $entry) {
                    if (($entry['@type'] ?? '') === 'JobPosting') {
                        $posting = $entry;
                    }
                }
            }
            if (!$posting) {
                throw new RuntimeException('당근 JobPosting 본문 누락: ' . $url);
            }
            self::required($posting, ['title', 'description', 'datePosted', 'employmentType'], 'Daangn');
            if (!self::openDates($posting['datePosted'], $posting['validThrough'] ?? null)) {
                continue;
            }
            $jobs[] = ['id' => $match[1], 'title' => $posting['title'], 'url' => $url,
                'company' => $posting['hiringOrganization']['name'], 'location' => '대한민국',
                'employment' => '정규직', 'description' => $posting['description'],
                'published' => $posting['datePosted'], 'role_reason' => '공식 직무: Software Engineer, Backend'];
        }
        return $jobs;
    }

    private function line(): array
    {
        $data = $this->json('https://careers.linecorp.com/page-data/ko/jobs/page-data.json');
        $rows = self::rows($data['result']['data']['allStrapiJobs'] ?? [], 'edges', 'LINE');
        $jobs = [];
        foreach ($rows as $edge) {
            $row = $edge['node'] ?? [];
            self::required($row, ['strapiId', 'publish', 'is_public', 'cities', 'employment_type', 'job_fields', 'title', 'start_date', 'end_date'], 'LINE');
            $this->diagnostics['listed']++;
            if (!$row['publish'] || !$row['is_public'] || !in_array(1, array_column($row['cities'], 'country'), true)
                || !in_array('Full-time', array_column($row['employment_type'], 'name'), true)
                || !self::openDates($row['start_date'], $row['end_date'])) {
                continue;
            }
            $reason = self::backendReason($row['title'], self::names($row['job_fields']));
            if ($reason === null) {
                continue;
            }
            $id = (int) $row['strapiId'];
            $detail = $this->json('https://careers.linecorp.com/page-data/ko/jobs/' . $id . '/page-data.json');
            $job = $detail['result']['data']['strapiJobs'] ?? [];
            self::required($job, ['content', 'publish', 'start_date', 'end_date'], 'LINE detail');
            if (!$job['publish'] || !self::openDates($job['start_date'], $job['end_date'])) {
                continue;
            }
            $jobs[] = ['id' => (string) $id, 'title' => $row['title'],
                'url' => 'https://careers.linecorp.com/ko/jobs/' . $id . '/',
                'company' => self::names($row['companies']), 'location' => self::names($row['cities']),
                'employment' => '정규직', 'description' => $job['content'],
                'published' => $row['start_date'], 'role_reason' => $reason];
        }
        return $jobs;
    }

    private function coupang(): array
    {
        // Greenhouse's documented, public published-jobs API includes every page and full descriptions.
        $data = $this->json('https://boards-api.greenhouse.io/v1/boards/coupang/jobs?content=true');
        $rows = self::rows($data, 'jobs', 'Coupang');
        if (!isset($data['meta']['total']) || (int) $data['meta']['total'] !== count($rows)) {
            throw new RuntimeException('Coupang 전체 공고 수 불일치');
        }
        $jobs = [];
        foreach ($rows as $row) {
            self::required($row, ['id', 'title', 'location', 'content', 'absolute_url'], 'Coupang');
            $this->diagnostics['listed']++;
            $location = $row['location']['name'] ?? '';
            if (!preg_match('/South Korea|대한민국|서울|Seoul|Pangyo|Bundang|판교|분당/i', $location)
                || !self::openDates($row['first_published'] ?? null, $row['application_deadline'] ?? null)) {
                continue;
            }
            $description = html_entity_decode($row['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/계약직|인턴|\b(intern|contractor|temporary)\b/iu', $row['title'])
                || preg_match('/고용\s*형태[^\n.]{0,30}계약직/u', self::plainText($description))) {
                continue;
            }
            $reason = self::backendReason($row['title'], '', $description);
            if ($reason === null) {
                continue;
            }
            $jobs[] = ['id' => (string) $row['id'], 'title' => $row['title'], 'url' => $row['absolute_url'],
                'company' => $row['company_name'] ?? 'Coupang', 'location' => $location,
                'employment' => preg_match('/정규직/u', $description) ? '정규직' : '공고 API 미기재 (원문 확인)',
                'description' => $description, 'published' => $row['first_published'] ?? null, 'role_reason' => $reason];
        }
        return $jobs;
    }

    private function woowahan(): array
    {
        $jobs = [];
        $seen = [];
        $page = 0;
        do {
            $response = $this->json('https://career.woowahan.com/w1/recruits?page=' . $page . '&size=25');
            if (($response['code'] ?? '') !== '2000') {
                throw new RuntimeException('우아한형제들 목록 조회 실패');
            }
            $data = $response['data'] ?? [];
            self::required($data, ['totalSize', 'totalPageNumber'], 'Woowahan');
            $rows = self::rows($data, 'list', 'Woowahan');
            $this->checkPage($rows, $seen, 'recruitNumber', (int) $data['totalSize'] > 0, 'Woowahan');
            foreach ($rows as $row) {
                self::required($row, ['recruitName', 'recruitNumber', 'employmentType', 'isHidden', 'recruitDeleteYn',
                    'isTemporaryStatus', 'recruitOpenDate', 'recruitEndDate', 'recruitCloseDate'], 'Woowahan');
                $this->diagnostics['listed']++;
                if ($row['isHidden'] || $row['recruitDeleteYn'] || $row['isTemporaryStatus']
                    || ($row['employmentType']['recruitItemCode'] ?? '') !== 'BA002001'
                    || !self::openDates($row['recruitOpenDate'], $row['recruitEndDate'])
                    || !self::openDates(null, $row['recruitCloseDate'])) {
                    continue;
                }
                $reason = self::backendReason($row['recruitName']);
                if ($reason === null) {
                    continue;
                }
                $detail = $this->json('https://career.woowahan.com/w1/recruits/' . rawurlencode($row['recruitNumber']));
                $job = $detail['data'] ?? [];
                self::required($job, ['recruitContents', 'careerRestrictionMinYears', 'careerRestrictionMaxYears'], 'Woowahan detail');
                if (($detail['code'] ?? '') !== '2000' || !$job['recruitContents']) {
                    throw new RuntimeException('우아한형제들 상세 본문 누락');
                }
                $entry = ['id' => $row['recruitNumber'], 'title' => $row['recruitName'],
                    'url' => 'https://career.woowahan.com/recruitment/' . rawurlencode($row['recruitNumber']) . '/detail',
                    'company' => '우아한형제들', 'location' => '대한민국 (원문 근무지 확인)',
                    'employment' => '정규직', 'description' => $job['recruitContents'],
                    'published' => $row['recruitOpenDate'], 'role_reason' => $reason];
                $min = $job['careerRestrictionMinYears'];
                $max = $job['careerRestrictionMaxYears'];
                if ($min >= 0 || $max >= 0) {
                    $entry['years'] = ['min' => $min >= 0 ? (int) $min : null, 'max' => $max >= 0 ? (int) $max : null,
                        'max_exclusive' => false, 'min_exclusive' => false, 'source' => 'metadata', 'uncertain' => false,
                        'evidence' => ['공식 경력 검색 필드: ' . $min . '~' . $max . '년 (-1은 미지정)']];
                }
                $jobs[] = $entry;
            }
            $page++;
        } while ($page < (int) $data['totalPageNumber']);
        return $jobs;
    }

    private function checkPage(array $rows, array &$seen, string $idKey, bool $expected, string $source): void
    {
        if ($rows === [] && $expected) {
            throw new RuntimeException($source . ': 전체 건수와 달리 페이지가 비었습니다.');
        }
        foreach ($rows as $row) {
            self::required($row, [$idKey], $source);
            if (isset($seen[$row[$idKey]])) {
                throw new RuntimeException($source . ': 중복 페이지 감지 (페이지 이동 실패 또는 조회 중 공고 변경)');
            }
            $seen[$row[$idKey]] = true;
        }
        if (count($seen) > 5000) {
            throw new RuntimeException($source . ': 예상 공고 수 초과');
        }
    }

    public static function backendReason(string $title, string $category = '', string $description = ''): ?string
    {
        if (preg_match('/front[ -]?end|프론트|\bandroid\b|\bios\b|\bQA\b|\bSRE\b|site\s+reliability|DevOps|security|보안|'
            . '\b(manager|director|head|VP)\b|팀장|본부장|'
            . 'data\s*(scientist|engineer)|데이터\s*(과학|엔지니어)|machine\s*learning|research|로보틱스|'
            . 'hardware|firmware|full[ -]?stack|풀스택|계약직|정책\/통제|인턴|신입|주니어|junior|추론.*최적화/iu', $title)) {
            return null;
        }
        if (preg_match('/(?:^|[,|])\s*(Backend|Server-side|Server)\s*(?:$|[,|])/i', $category)) {
            return '공식 직무: ' . $category;
        }
        if (preg_match('/back[ -]?end|백엔드|서버\s*(개발|엔지니어)|server[ -]?(side|engineer|developer)/iu', $title)) {
            return '공고 제목의 Backend/서버 개발 직무';
        }
        if ($description !== '' && preg_match('/software\s*(engineer|development)|소프트웨어\s*(개발|엔지니어)/iu', $title)) {
            $duties = self::section(self::plainText($description), 'duties');
            if (preg_match('/back[ -]?end|백엔드|서버\s*(개발|설계)|server[ -]?side/iu', $duties)) {
                return 'Software Engineer 제목 + 담당업무의 백엔드/서버 개발 (본문 기반 추정)';
            }
        }
        return null;
    }

    public static function plainText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $html = preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6]|tr)>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", "\xe2\x80\x8b", "\xef\xbb\xbf"], [' ', '', ''], $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        return trim(preg_replace('/\n\s*\n+/u', "\n", $text));
    }

    private static function section(string $text, string $kind): string
    {
        $start = $kind === 'duties'
            ? '/^(?:[\s◆\[\]#*•-]*)(?:업무\s*내용|담당\s*업무|주요\s*업무|이런 일을 해요|What you(?:[’\x27]ll| will) do|Key Responsibilities|Responsibilities)\s*[:\] ]*$/imu'
            : '/^(?:[\s◆\[\]#*•-]*)(?:지원\s*자격|자격\s*요건|필수\s*(?:요건|사항)|필요\s*역량|이런 분과 함께하고 싶어요|이런 분을 찾고 있어요|Basic Qualifications|Minimum Qualifications|Qualifications|Requirements)\s*[:\] ]*$/imu';
        $end = '/^(?:[\s◆\[\]#*•-]*)(?:우대\s*사항|선호\s*역량.*|Preferred Qualifications|전형.*|채용.*|접수.*|근무.*|기타|혜택.*|개발\s*환경|Benefits|지원\s*자격|자격\s*요건|Basic Qualifications|Qualifications)\s*[:\] ]*$/imu';
        if (preg_match($start, $text, $match, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, $match[0][1] + strlen($match[0][0]));
            if (preg_match($end, $text, $match, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, $match[0][1]);
            }
            return trim($text);
        }
        return $kind === 'duties' ? '' : $text;
    }

    /** Conservative numeric extraction: titles such as Senior/Staff never imply invented years. */
    public static function experience(string $html, string $title = ''): array
    {
        $text = self::section(self::plainText($html), 'requirements');
        // Explicit title limits (e.g. "3년 이하") still apply when the body omits them.
        if (preg_match('/\d+\s*년(?:차)?\s*(?:이상|이하|초과|미만)|\d+\s*년?\s*[~～–—-]\s*\d+\s*년/u', $title)) {
            $text .= "\n개발 경력 (공고 제목): " . $title;
        }
        $result = ['min' => null, 'max' => null, 'min_exclusive' => false, 'max_exclusive' => false,
            'source' => 'unknown', 'uncertain' => false, 'evidence' => []];
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/경력|경험|개발자|엔지니어|experience/iu', $line) || !preg_match('/\d+\s*(?:년|\+?\s*(?:or more\s+)?years?)/iu', $line)) {
                continue;
            }
            // "Within the past 3 years" describes recency, not three years of experience.
            if (preg_match('/최근\s*\d+\s*년\s*(이내|내)|(?:within|over|in)\s+the\s+(?:past|last)\s+\d+\s*years?/iu', $line)) {
                continue;
            }
            $min = $max = null;
            $minExclusive = $maxExclusive = false;
            if (preg_match('/(\d+)\s*년?\s*(?:이상)?\s*[~～–—-]\s*(\d+)\s*년\s*(미만|이하)?/u', $line, $m)
                || preg_match('/(\d+)\s*(?:-|–|to)\s*(\d+)\s*years?/iu', $line, $m)) {
                $min = (int) $m[1]; $max = (int) $m[2];
                $maxExclusive = ($m[3] ?? '') === '미만';
            } elseif (preg_match('/(\d+)\s*년(?:차)?\s*(이상|초과|이하|미만)/u', $line, $m)) {
                if (in_array($m[2], ['이상', '초과'], true)) {
                    $min = (int) $m[1]; $minExclusive = $m[2] === '초과';
                } else {
                    $max = (int) $m[1]; $maxExclusive = $m[2] === '미만';
                }
            } elseif (preg_match('/(?:at\s+least|minimum(?:\s+of)?)\s*(\d+)\s*years?|\b(\d+)\s*\+\s*years?|\b(\d+)\s*(?:or more\s+)?years?[^\n.]{0,80}experience/iu', $line, $m)) {
                $min = (int) ($m[1] ?: ($m[2] ?: $m[3]));
            } else {
                $result['uncertain'] = true;
            }
            $result['evidence'][] = trim($line);
            if (preg_match('/석사|박사|bachelor|master|ph\.?d|\d+\s*년[^\n]{0,40}(?:또는|혹은|\bor\b)[^\n]{0,40}\d+\s*(?:년|years?)/iu', $line)) {
                $result['uncertain'] = true; // Alternative education/career paths need a human reading.
            }
            if ($min !== null && ($result['min'] === null || $min >= $result['min'])) {
                $result['min'] = $min; $result['min_exclusive'] = $minExclusive;
            }
            if ($max !== null && ($result['max'] === null || $max <= $result['max'])) {
                $result['max'] = $max; $result['max_exclusive'] = $maxExclusive;
            }
        }
        if ($result['min'] !== null || $result['max'] !== null) {
            $result['source'] = 'explicit';
        }
        if (($result['min'] ?? 0) > ($result['max'] ?? 60)) {
            $result['uncertain'] = true;
        }
        return $result;
    }

    public static function overlaps(array $years, int $min, int $max): bool
    {
        return ($years['min'] === null || $years['min'] < $max || ($years['min'] === $max && !$years['min_exclusive']))
            && ($years['max'] === null || $years['max'] > $min || ($years['max'] === $min && !$years['max_exclusive']));
    }

    public static function yearLabel(array $years): string
    {
        if ($years['uncertain']) {
            return '연차 원문 확인';
        }
        if ($years['min'] === null && $years['max'] === null) {
            return '연차 미기재';
        }
        if ($years['min'] !== null && $years['max'] !== null) {
            return $years['min'] . '년' . ($years['min_exclusive'] ? ' 초과' : ' 이상')
                . '~' . $years['max'] . '년 ' . ($years['max_exclusive'] ? '미만' : '이하');
        }
        return $years['min'] !== null
            ? $years['min'] . '년 ' . ($years['min_exclusive'] ? '초과' : '이상')
            : $years['max'] . '년 ' . ($years['max_exclusive'] ? '미만' : '이하');
    }

    public static function fitLabel(array $years, int $mine): string
    {
        if ($years['source'] === 'unknown' || $years['uncertain']) {
            return '연차 판단 보류';
        }
        return self::overlaps($years, $mine, $mine) ? '연차 조건 충족' : '연차 조건 밖';
    }

    private static function date(string $value): int
    {
        $value = preg_replace('/^(\d{4})\.(\d{2})\.(\d{2})/', '$1-$2-$3', $value);
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Seoul')))->getTimestamp();
    }

    public static function openDates(?string $start, ?string $end): bool
    {
        $now = time();
        return (!$start || self::date($start) <= $now) && (!$end || self::date($end) > $now);
    }

    private static function names(array $rows, string $key = 'name'): string
    {
        return implode(', ', array_column($rows, $key));
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
