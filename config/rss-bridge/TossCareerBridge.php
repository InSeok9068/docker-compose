<?php

declare(strict_types=1);

class TossCareerBridge extends BridgeAbstract
{
    const NAME = 'Toss Career - Backend';
    const URI = 'https://toss.im/career/jobs?employment_type=%EC%A0%95%EA%B7%9C%EC%A7%81&category=engineering-product%2Cengineering-platform%2Cengineering-product-platform%2Cqa%2Cengineering&main_category=Engineering&sub_category=Backend';

    const DESCRIPTION = '토스 커뮤니티의 정규직 Engineering / Backend 채용공고를 제공합니다.';

    const CACHE_TIMEOUT = 1800; // 30분

    private const API_URI =
        'https://api-public.toss.im/api/v3/ipd-eggnog/career/jobs';

    /**
     * Toss 채용 API에서 현재 채용공고를 가져온다.
     */
    public function collectData()
    {
        $headers = [
            'Accept: application/json',
            'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: https://toss.im/career',
        ];

        $response = getContents(
            self::API_URI,
            $headers
        );

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throwServerException(
                'Toss Career API 응답을 JSON으로 변환하지 못했습니다: '
                . json_last_error_msg()
            );
        }

        if (($data['resultType'] ?? null) !== 'SUCCESS') {
            throwServerException(
                'Toss Career API가 정상 응답을 반환하지 않았습니다.'
            );
        }

        $jobs = $data['success'] ?? null;

        if (!is_array($jobs)) {
            throwServerException(
                'Toss Career API 응답에서 채용공고 목록을 찾지 못했습니다.'
            );
        }

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $metadata = $this->getMetadata($job);

            // 정규직만
            if (!$this->isFullTime($metadata)) {
                continue;
            }

            // Engineering > Backend만
            if (!$this->isBackendJob($job, $metadata)) {
                continue;
            }

            $title = trim((string) ($job['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $url = $this->getJobUrl($job);

            if ($url === '') {
                continue;
            }

            $company = trim(
                (string) ($job['company_name'] ?? 'Toss')
            );

            $location = $this->getLocation($job);
            $employmentType = $this->getEmploymentType($metadata);
            $category = $this->getJobCategory($metadata);

            $firstPublished = trim(
                (string) ($job['first_published'] ?? '')
            );

            $deadline = trim(
                (string) ($job['application_deadline'] ?? '')
            );

            $timestamp = null;

            if ($firstPublished !== '') {
                $parsedTimestamp = strtotime($firstPublished);

                if ($parsedTimestamp !== false) {
                    $timestamp = $parsedTimestamp;
                }
            }

            $id = $job['id'] ?? null;

            if ($id !== null && $id !== '') {
                $uid = 'toss-career-' . (string) $id;
            } else {
                $uid = 'toss-career-' . sha1($url);
            }

            $item = [
                'uid' => $uid,
                'title' => $title,
                'uri' => $url,
                'author' => $company,
                'content' => $this->buildContent(
                    $company,
                    $location,
                    $employmentType,
                    $category,
                    $firstPublished,
                    $deadline
                ),
            ];

            if ($timestamp !== null) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }

        // 최신 공고부터 보여준다.
        usort(
            $this->items,
            static function (array $a, array $b): int {
                return ($b['timestamp'] ?? 0)
                    <=> ($a['timestamp'] ?? 0);
            }
        );
    }

    /**
     * Toss metadata를 name => value 형태로 변환한다.
     */
    private function getMetadata(array $job): array
    {
        $result = [];

        $metadata = $job['metadata'] ?? [];

        if (!is_array($metadata)) {
            return $result;
        }

        foreach ($metadata as $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $name = trim((string) ($meta['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $value = $meta['value'] ?? '';

            if (is_array($value)) {
                $value = implode(
                    ', ',
                    array_map('strval', $value)
                );
            }

            if (is_scalar($value)) {
                $result[$name] = trim((string) $value);
            }
        }

        return $result;
    }

    /**
     * 정규직 여부 확인.
     */
    private function isFullTime(array $metadata): bool
    {
        return $this->getEmploymentType($metadata) === '정규직';
    }

    /**
     * Backend 직군 여부 확인.
     *
     * 우선 Toss가 제공하는 구조화된 카테고리 데이터를 확인하고,
     * 해당 정보가 부족한 경우 제목을 보조적으로 확인한다.
     */
    private function isBackendJob(
        array $job,
        array $metadata
    ): bool {
        $categoryValues = [];

        /*
         * Toss API가 현재 또는 향후 아래 필드를 직접 제공하는 경우
         * 그대로 활용할 수 있도록 해둔다.
         */
        $possibleKeys = [
            'main_category',
            'mainCategory',
            'sub_category',
            'subCategory',
            'category',
            'job_category',
            'jobCategory',
        ];

        foreach ($possibleKeys as $key) {
            if (!isset($job[$key])) {
                continue;
            }

            $this->appendTextValues(
                $categoryValues,
                $job[$key]
            );
        }

        /*
         * metadata의 Category 관련 값들을 확인한다.
         */
        foreach ($metadata as $name => $value) {
            $normalizedName = strtolower($name);

            if (
                strpos($normalizedName, 'category') !== false
                || strpos($normalizedName, 'department') !== false
                || strpos($normalizedName, 'position') !== false
                || strpos($normalizedName, 'job family') !== false
            ) {
                $categoryValues[] = $value;
            }
        }

        /*
         * Greenhouse 계열 응답에 departments가 포함되는 경우 대응.
         */
        if (isset($job['departments'])) {
            $this->appendTextValues(
                $categoryValues,
                $job['departments']
            );
        }

        foreach ($categoryValues as $value) {
            if (
                stripos((string) $value, 'Backend') !== false
            ) {
                return true;
            }
        }

        /*
         * Toss에서는 Backend 직군을
         * "Server Developer" 형태로 표기하는 공고도 있으므로
         * 제목을 보조 조건으로 사용한다.
         */
        $title = trim((string) ($job['title'] ?? ''));

        if ($title === '') {
            return false;
        }

        $patterns = [
            '/\bbackend\b/i',
            '/\bserver\s+developer\b/i',
            '/\bserver\s+engineer\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 배열/객체 형태의 값을 재귀적으로 문자열 목록으로 변환한다.
     */
    private function appendTextValues(
        array &$target,
        $value
    ): void {
        if (is_scalar($value)) {
            $text = trim((string) $value);

            if ($text !== '') {
                $target[] = $text;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->appendTextValues(
                $target,
                $child
            );
        }
    }

    /**
     * Employment_Type 메타데이터 추출.
     */
    private function getEmploymentType(array $metadata): string
    {
        if (isset($metadata['Employment_Type'])) {
            return trim(
                (string) $metadata['Employment_Type']
            );
        }

        foreach ($metadata as $name => $value) {
            if (
                strtolower($name)
                === strtolower('Employment_Type')
            ) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Job Category 메타데이터 추출.
     */
    private function getJobCategory(array $metadata): string
    {
        foreach ($metadata as $name => $value) {
            if (
                stripos($name, 'Job Category') !== false
            ) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * 근무지 추출.
     */
    private function getLocation(array $job): string
    {
        $location = $job['location'] ?? null;

        if (is_array($location)) {
            return trim(
                (string) ($location['name'] ?? '')
            );
        }

        if (is_scalar($location)) {
            return trim((string) $location);
        }

        return '';
    }

    /**
     * 공고 URL 추출.
     */
    private function getJobUrl(array $job): string
    {
        $absoluteUrl = trim(
            (string) ($job['absolute_url'] ?? '')
        );

        if ($absoluteUrl !== '') {
            return $absoluteUrl;
        }

        $id = $job['id'] ?? null;

        if ($id !== null && $id !== '') {
            return 'https://toss.im/career/job-detail?job_id='
                . rawurlencode((string) $id);
        }

        return '';
    }

    /**
     * RSS 본문 HTML.
     */
    private function buildContent(
        string $company,
        string $location,
        string $employmentType,
        string $category,
        string $firstPublished,
        string $deadline
    ): string {
        $rows = [];

        if ($company !== '') {
            $rows[] = [
                '회사',
                $company,
            ];
        }

        if ($employmentType !== '') {
            $rows[] = [
                '고용형태',
                $employmentType,
            ];
        }

        if ($category !== '') {
            $rows[] = [
                '직군',
                $category,
            ];
        }

        if ($location !== '') {
            $rows[] = [
                '근무지',
                $location,
            ];
        }

        if ($firstPublished !== '') {
            $rows[] = [
                '등록일',
                $this->formatDate($firstPublished),
            ];
        }

        if ($deadline !== '') {
            $rows[] = [
                '마감',
                $deadline,
            ];
        }

        $html = '<div>';

        foreach ($rows as $row) {
            $html .= '<p>';
            $html .= '<strong>'
                . $this->escape($row[0])
                . ':</strong> ';
            $html .= $this->escape($row[1]);
            $html .= '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * ISO 날짜를 읽기 좋은 날짜로 변환.
     */
    private function formatDate(string $value): string
    {
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * RSS HTML escape.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
