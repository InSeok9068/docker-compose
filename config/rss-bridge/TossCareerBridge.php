<?php

declare(strict_types=1);

class TossCareerBridge extends BridgeAbstract
{
    const NAME = 'Toss Career - Backend';
    const URI = 'https://toss.im/career/jobs'
        . '?employment_type=%EC%A0%95%EA%B7%9C%EC%A7%81'
        . '&category=engineering-product%2Cengineering-platform%2Cengineering-product-platform%2Cqa%2Cengineering'
        . '&main_category=Engineering'
        . '&sub_category=Backend';

    const DESCRIPTION =
        '토스 커뮤니티의 현재 채용 중인 정규직 Engineering > Backend 포지션';

    // FreshRSS가 30분 간격으로 확인하므로 동일하게 30분
    const CACHE_TIMEOUT = 1800;

    private const API_URL =
        'https://api-public.toss.im/api/v3/ipd-eggnog/career/jobs';

    private const EMPLOYMENT_TYPE = '정규직';
    private const MAIN_CATEGORY = 'Engineering';
    private const SUB_CATEGORY = 'Backend';

    public function collectData()
    {
        $headers = [
            'Accept: application/json',
            'Referer: https://toss.im/career',
        ];

        $response = getContents(
            self::API_URL,
            $headers
        );

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throwServerException(
                'Toss Career API 응답을 JSON으로 읽지 못했습니다.'
            );
        }

        if (($data['resultType'] ?? null) !== 'SUCCESS') {
            throwServerException(
                'Toss Career API가 SUCCESS 응답을 반환하지 않았습니다.'
            );
        }

        $jobs = $data['success'] ?? [];

        if (!is_array($jobs)) {
            throwServerException(
                'Toss Career API에서 채용공고 목록을 찾지 못했습니다.'
            );
        }

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            /*
             * Toss Career API의 success 배열은
             * 현재 공개된 채용공고 목록이다.
             *
             * 그중 명시적으로 마감일이 지난 공고가 남아 있다면
             * 한 번 더 제외한다.
             */
            if (!$this->isOpen($job)) {
                continue;
            }

            /*
             * 정규직만
             */
            if (!$this->isFullTime($job)) {
                continue;
            }

            /*
             * Engineering > Backend만.
             *
             * 제목이나 기술스택으로 추측하지 않고
             * Toss가 내려주는 구조화된 category 필드만 확인한다.
             */
            if (!$this->isEngineeringBackend($job)) {
                continue;
            }

            $item = $this->makeItem($job);

            if ($item !== null) {
                $this->items[] = $item;
            }
        }

        /*
         * 최근 등록 공고 우선
         */
        usort(
            $this->items,
            static function (array $a, array $b): int {
                return ($b['timestamp'] ?? 0)
                    <=> ($a['timestamp'] ?? 0);
            }
        );
    }

    /**
     * 정규직 여부
     */
    private function isFullTime(array $job): bool
    {
        $metadata = $this->getMetadata($job);

        $employmentType =
            $this->findMetadataValue(
                $metadata,
                [
                    'Employment_Type',
                    'Employment Type',
                    'employment_type',
                ]
            );

        return $this->equals(
            $employmentType,
            self::EMPLOYMENT_TYPE
        );
    }

    /**
     * Engineering > Backend 여부
     *
     * Toss 내부 필드명이 변경될 가능성을 고려해
     * 여러 일반적인 category 키를 지원한다.
     *
     * 중요한 점:
     * title, description, 기술스택으로 Backend를 추측하지 않는다.
     */
    private function isEngineeringBackend(array $job): bool
    {
        $metadata = $this->getMetadata($job);

        /*
         * 1. 가장 명확한 main/sub category 형태 확인
         */
        $mainCategory = $this->findCategoryValue(
            $job,
            $metadata,
            [
                'main_category',
                'mainCategory',
                'Main Category',
                'Main_Category',
                'Job Main Category',
            ]
        );

        $subCategory = $this->findCategoryValue(
            $job,
            $metadata,
            [
                'sub_category',
                'subCategory',
                'Sub Category',
                'Sub_Category',
                'Job Sub Category',
            ]
        );

        if (
            $this->containsCategory(
                $mainCategory,
                self::MAIN_CATEGORY
            )
            &&
            $this->containsCategory(
                $subCategory,
                self::SUB_CATEGORY
            )
        ) {
            return true;
        }

        /*
         * 2. 일부 API에서는 하나의 Job Category에
         *    계층형 값을 넣을 수도 있으므로 확인
         *
         * 예:
         * Engineering > Backend
         * Engineering / Backend
         * ["Engineering", "Backend"]
         */
        $jobCategory = $this->findMetadataValue(
            $metadata,
            [
                'Job Category',
                'Job_Category',
                'job_category',
                'Category',
            ]
        );

        if (
            $this->containsCategory(
                $jobCategory,
                self::MAIN_CATEGORY
            )
            &&
            $this->containsCategory(
                $jobCategory,
                self::SUB_CATEGORY
            )
        ) {
            return true;
        }

        /*
         * 3. metadata 배열 자체에
         *    Main/Sub Category 계열 이름이 있는지 확인
         */
        $foundMain = false;
        $foundSub = false;

        foreach ($metadata as $name => $value) {
            $normalizedName =
                $this->normalizeKey((string) $name);

            if (
                strpos(
                    $normalizedName,
                    'maincategory'
                ) !== false
                &&
                $this->containsCategory(
                    $value,
                    self::MAIN_CATEGORY
                )
            ) {
                $foundMain = true;
            }

            if (
                strpos(
                    $normalizedName,
                    'subcategory'
                ) !== false
                &&
                $this->containsCategory(
                    $value,
                    self::SUB_CATEGORY
                )
            ) {
                $foundSub = true;
            }
        }

        if ($foundMain && $foundSub) {
            return true;
        }

        /*
         * 4. job 객체의 category 관련 필드를 재귀적으로 확인.
         *
         * 이것도 "category"라는 구조화된 필드만 대상으로 하며
         * title/description/skills는 검사하지 않는다.
         */
        $categoryData =
            $this->collectCategoryFields($job);

        $foundEngineering = false;
        $foundBackend = false;

        foreach ($categoryData as $value) {
            if (
                $this->containsCategory(
                    $value,
                    self::MAIN_CATEGORY
                )
            ) {
                $foundEngineering = true;
            }

            if (
                $this->containsCategory(
                    $value,
                    self::SUB_CATEGORY
                )
            ) {
                $foundBackend = true;
            }
        }

        return $foundEngineering && $foundBackend;
    }

    /**
     * 이미 마감된 공고 제외
     *
     * deadline이 없는 경우:
     * Toss API의 현재 공개 목록에 포함되어 있으므로 열린 것으로 본다.
     */
    private function isOpen(array $job): bool
    {
        $deadline = trim(
            (string) (
                $job['application_deadline']
                ?? ''
            )
        );

        if ($deadline === '') {
            return true;
        }

        /*
         * 상시/채용시 등의 표현
         */
        $rollingWords = [
            '상시',
            '수시',
            '채용시',
            'open until filled',
        ];

        foreach ($rollingWords as $word) {
            if (
                stripos(
                    $deadline,
                    $word
                ) !== false
            ) {
                return true;
            }
        }

        /*
         * ISO 날짜나 일반적인 날짜라면 strtotime 처리
         */
        $timestamp = strtotime($deadline);

        if ($timestamp === false) {
            /*
             * 해석할 수 없는 deadline은
             * Toss 공개 목록의 상태를 우선 신뢰
             */
            return true;
        }

        /*
         * 오늘까지는 열린 것으로 처리
         */
        return $timestamp >= strtotime('today');
    }

    /**
     * RSS Item 생성
     */
    private function makeItem(array $job): ?array
    {
        $title = trim(
            (string) ($job['title'] ?? '')
        );

        if ($title === '') {
            return null;
        }

        $url = $this->getJobUrl($job);

        if ($url === '') {
            return null;
        }

        $company = trim(
            (string) (
                $job['company_name']
                ?? '토스'
            )
        );

        $location =
            $this->getLocation($job);

        $metadata =
            $this->getMetadata($job);

        $employmentType =
            $this->findMetadataValue(
                $metadata,
                [
                    'Employment_Type',
                    'Employment Type',
                    'employment_type',
                ]
            );

        $jobCategory =
            $this->findMetadataValue(
                $metadata,
                [
                    'Job Category',
                    'Job_Category',
                    'job_category',
                ]
            );

        $firstPublished = trim(
            (string) (
                $job['first_published']
                ?? ''
            )
        );

        $deadline = trim(
            (string) (
                $job['application_deadline']
                ?? ''
            )
        );

        $timestamp = null;

        if ($firstPublished !== '') {
            $parsed =
                strtotime($firstPublished);

            if ($parsed !== false) {
                $timestamp = $parsed;
            }
        }

        $id =
            $job['id']
            ?? $job['gh_jid']
            ?? null;

        $uid = $id !== null
            ? 'toss-career-' . (string) $id
            : 'toss-career-' . sha1($url);

        $item = [
            'uid' => $uid,
            'title' => $title,
            'uri' => $url,
            'author' => $company,
            'content' => $this->buildContent(
                $company,
                $location,
                $employmentType,
                $jobCategory,
                $firstPublished,
                $deadline
            ),
        ];

        if ($timestamp !== null) {
            $item['timestamp'] =
                $timestamp;
        }

        return $item;
    }

    /**
     * metadata를
     *
     * [
     *   "Employment_Type" => "정규직",
     *   "Job Category" => ...
     * ]
     *
     * 형태로 변환
     */
    private function getMetadata(array $job): array
    {
        $result = [];

        $metadata =
            $job['metadata'] ?? [];

        if (!is_array($metadata)) {
            return $result;
        }

        foreach ($metadata as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = trim(
                (string) (
                    $entry['name']
                    ?? ''
                )
            );

            if ($name === '') {
                continue;
            }

            $result[$name] =
                $entry['value'] ?? null;
        }

        return $result;
    }

    /**
     * metadata에서 여러 후보 이름으로 값 조회
     */
    private function findMetadataValue(
        array $metadata,
        array $names
    ) {
        foreach ($metadata as $key => $value) {
            foreach ($names as $name) {
                if (
                    $this->normalizeKey(
                        (string) $key
                    )
                    ===
                    $this->normalizeKey(
                        (string) $name
                    )
                ) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * job 최상위 / metadata에서 category 값 조회
     */
    private function findCategoryValue(
        array $job,
        array $metadata,
        array $names
    ) {
        foreach ($job as $key => $value) {
            foreach ($names as $name) {
                if (
                    $this->normalizeKey(
                        (string) $key
                    )
                    ===
                    $this->normalizeKey(
                        (string) $name
                    )
                ) {
                    return $value;
                }
            }
        }

        return $this->findMetadataValue(
            $metadata,
            $names
        );
    }

    /**
     * category 이름을 가진 필드만 재귀 수집
     *
     * title, description, content 등은 검사하지 않는다.
     */
    private function collectCategoryFields(
        array $data
    ): array {
        $result = [];

        foreach ($data as $key => $value) {
            $normalizedKey =
                $this->normalizeKey(
                    (string) $key
                );

            $isCategoryKey =
                strpos(
                    $normalizedKey,
                    'category'
                ) !== false;

            if ($isCategoryKey) {
                $result[] = $value;
            }

            /*
             * metadata 등 구조 내부도 확인
             */
            if (is_array($value)) {
                $children =
                    $this->collectCategoryFields(
                        $value
                    );

                foreach ($children as $child) {
                    $result[] = $child;
                }
            }
        }

        return $result;
    }

    /**
     * 문자열/배열/객체 안에 특정 category가 존재하는지 확인
     */
    private function containsCategory(
        $value,
        string $needle
    ): bool {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $child) {
                if (
                    $this->containsCategory(
                        $child,
                        $needle
                    )
                ) {
                    return true;
                }
            }

            return false;
        }

        if (is_object($value)) {
            return $this->containsCategory(
                (array) $value,
                $needle
            );
        }

        if (!is_scalar($value)) {
            return false;
        }

        $text = trim(
            (string) $value
        );

        if ($text === '') {
            return false;
        }

        /*
         * 단일 값
         */
        if ($this->equals($text, $needle)) {
            return true;
        }

        /*
         * 계층형 / 다중 category 문자열
         *
         * Engineering > Backend
         * Engineering / Backend
         * Engineering, Backend
         */
        $parts = preg_split(
            '/[\s]*[>,\/|;]+[\s]*/u',
            $text
        );

        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (
                    $this->equals(
                        trim($part),
                        $needle
                    )
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function equals(
        $left,
        $right
    ): bool {
        if (
            !is_scalar($left)
            ||
            !is_scalar($right)
        ) {
            return false;
        }

        return strcasecmp(
            trim((string) $left),
            trim((string) $right)
        ) === 0;
    }

    private function normalizeKey(
        string $value
    ): string {
        return strtolower(
            preg_replace(
                '/[^a-zA-Z0-9]+/',
                '',
                $value
            )
        );
    }

    private function getLocation(
        array $job
    ): string {
        $location =
            $job['location'] ?? null;

        if (is_array($location)) {
            return trim(
                (string) (
                    $location['name']
                    ?? ''
                )
            );
        }

        if (is_scalar($location)) {
            return trim(
                (string) $location
            );
        }

        return '';
    }

    private function getJobUrl(
        array $job
    ): string {
        $absoluteUrl = trim(
            (string) (
                $job['absolute_url']
                ?? ''
            )
        );

        if ($absoluteUrl !== '') {
            return $absoluteUrl;
        }

        $id =
            $job['gh_jid']
            ?? $job['id']
            ?? null;

        if ($id === null) {
            return '';
        }

        return
            'https://toss.im/career/job-detail?gh_jid='
            . rawurlencode(
                (string) $id
            );
    }

    private function buildContent(
        string $company,
        string $location,
        $employmentType,
        $jobCategory,
        string $firstPublished,
        string $deadline
    ): string {
        $rows = [];

        $rows[] = [
            '회사',
            $company,
        ];

        if (
            is_scalar($employmentType)
            &&
            trim((string) $employmentType) !== ''
        ) {
            $rows[] = [
                '고용형태',
                (string) $employmentType,
            ];
        }

        if ($jobCategory !== null) {
            $categoryText =
                $this->valueToString(
                    $jobCategory
                );

            if ($categoryText !== '') {
                $rows[] = [
                    '직군',
                    $categoryText,
                ];
            }
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
                $this->formatDate(
                    $firstPublished
                ),
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
            $html .=
                $this->escape($row[1]);
            $html .= '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    private function valueToString(
        $value
    ): string {
        if (is_scalar($value)) {
            return trim(
                (string) $value
            );
        }

        if (is_array($value)) {
            $values = [];

            foreach ($value as $child) {
                $text =
                    $this->valueToString(
                        $child
                    );

                if ($text !== '') {
                    $values[] = $text;
                }
            }

            return implode(
                ', ',
                array_unique($values)
            );
        }

        return '';
    }

    private function formatDate(
        string $value
    ): string {
        $timestamp =
            strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        return date(
            'Y-m-d',
            $timestamp
        );
    }

    private function escape(
        string $value
    ): string {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
