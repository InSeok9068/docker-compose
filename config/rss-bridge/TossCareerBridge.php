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
        '토스 커뮤니티의 현재 정규직 Engineering > Backend 포지션';

    const CACHE_TIMEOUT = 0;

    private const API_URL =
        'https://api-public.toss.im/api/v3/ipd-eggnog/career/job-groups';

    private const EMPLOYMENT_METADATA = 'Employment_Type';

    private const CATEGORY_METADATA =
        '커리어 페이지 노출 Job Category 값을 선택해주세요';

    private const EMPLOYMENT_TYPE = '정규직';
    private const CATEGORY = 'Backend';

    public function collectData()
    {
        $response = getContents(
            self::API_URL,
            [
                'Accept: application/json',
                'Referer: https://toss.im/career',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                    . 'AppleWebKit/605.1.15 (KHTML, like Gecko) '
                    . 'Version/17.6 Safari/605.1.15',
            ]
        );

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throwServerException(
                'Toss Career API 응답을 JSON으로 읽지 못했습니다: '
                . json_last_error_msg()
            );
        }

        if (($data['resultType'] ?? null) !== 'SUCCESS') {
            throwServerException(
                'Toss Career API가 SUCCESS 응답을 반환하지 않았습니다.'
            );
        }

        $groups = $data['success'] ?? null;

        if (!is_array($groups)) {
            throwServerException(
                'Toss Career API에서 job group 목록을 찾지 못했습니다.'
            );
        }

        $observedCategories = [];
        $matchedGroups = 0;

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $jobs = $this->getJobsFromGroup($group);

            if ($jobs === []) {
                continue;
            }

            $matchingJobs = [];

            foreach ($jobs as $job) {
                $metadata = $this->getMetadata($job);

                $employmentType =
                    $this->getEmploymentType($metadata);

                $category =
                    $this->getCareerCategory($metadata);

                /*
                 * 디버깅용:
                 * 정규직 공고에 어떤 Category 값이 실제로 있는지
                 * 0건일 경우 에러 메시지에 보여준다.
                 */
                if (
                    $this->valueMatches(
                        $employmentType,
                        self::EMPLOYMENT_TYPE
                    )
                ) {
                    foreach (
                        $this->flattenValues($category)
                        as $value
                    ) {
                        $value = trim($value);

                        if ($value !== '') {
                            $observedCategories[$value] = true;
                        }
                    }
                }

                /*
                 * 정규직만
                 */
                if (
                    !$this->valueMatches(
                        $employmentType,
                        self::EMPLOYMENT_TYPE
                    )
                ) {
                    continue;
                }

                /*
                 * Toss Career UI의 Backend Category만
                 */
                if (
                    !$this->valueMatches(
                        $category,
                        self::CATEGORY
                    )
                ) {
                    continue;
                }

                $matchingJobs[] = $job;
            }

            /*
             * 이 그룹에 Backend + 정규직 공고가 하나도 없으면 제외
             */
            if ($matchingJobs === []) {
                continue;
            }

            $item = $this->buildGroupItem(
                $group,
                $matchingJobs
            );

            if ($item !== null) {
                $this->items[] = $item;
                $matchedGroups++;
            }
        }

        /*
         * 이전 코드처럼 조용히 0개를 반환하지 않는다.
         *
         * 만약 Toss가 metadata 값을 바꿨다면
         * 실제 값이 에러 화면에 바로 표시된다.
         */
        if ($matchedGroups === 0) {
            $categories =
                array_keys($observedCategories);

            natcasesort($categories);

            $message =
                'Toss API 호출은 성공했지만 '
                . '정규직 Backend 그룹이 0개입니다.';

            if ($categories !== []) {
                $message .=
                    ' 현재 정규직 공고에서 확인된 '
                    . 'Job Category 값: '
                    . implode(
                        ' | ',
                        array_slice(
                            array_values($categories),
                            0,
                            50
                        )
                    );
            } else {
                $message .=
                    ' 정규직 공고의 Job Category '
                    . 'metadata도 확인되지 않았습니다.';
            }

            throwServerException($message);
        }

        /*
         * 최근 등록된 공고가 있는 그룹부터
         */
        usort(
            $this->items,
            static function (
                array $a,
                array $b
            ): int {
                return
                    ($b['timestamp'] ?? 0)
                    <=>
                    ($a['timestamp'] ?? 0);
            }
        );
    }

    /**
     * Toss UI의 한 행 = 하나의 job group.
     *
     * 한 그룹 안에 같은 직무명의 여러 계열사 공고가
     * jobs[] 형태로 들어있다.
     */
    private function getJobsFromGroup(
        array $group
    ): array {
        $jobs = $group['jobs'] ?? [];

        if (
            is_array($jobs)
            && $jobs !== []
        ) {
            return array_values(
                array_filter(
                    $jobs,
                    static fn ($job): bool =>
                        is_array($job)
                )
            );
        }

        /*
         * 구조 변경 등에 대비한 fallback
         */
        $primaryJob =
            $group['primary_job'] ?? null;

        if (is_array($primaryJob)) {
            return [$primaryJob];
        }

        return [];
    }

    /**
     * 하나의 Toss UI 포지션 그룹을
     * RSS item 하나로 만든다.
     */
    private function buildGroupItem(
        array $group,
        array $matchingJobs
    ): ?array {
        $primaryJob =
            $group['primary_job'] ?? null;

        /*
         * primary_job 자체가 현재 필터와 맞지 않는다면
         * 실제 matching job 중 첫 번째를 대표로 사용.
         */
        if (
            !is_array($primaryJob)
            ||
            !$this->isMatchingJob($primaryJob)
        ) {
            $primaryJob =
                $matchingJobs[0] ?? null;
        }

        if (!is_array($primaryJob)) {
            return null;
        }

        $title = trim(
            (string) (
                $primaryJob['title']
                ?? ''
            )
        );

        $uri = trim(
            (string) (
                $primaryJob['absolute_url']
                ?? ''
            )
        );

        if (
            $title === ''
            || $uri === ''
        ) {
            return null;
        }

        $companies = [];
        $locations = [];
        $deadlines = [];
        $links = [];

        $latestTimestamp = 0;

        foreach ($matchingJobs as $job) {
            /*
             * 회사
             */
            $company = trim(
                (string) (
                    $job['company_name']
                    ?? ''
                )
            );

            if ($company === '') {
                $metadata =
                    $this->getMetadata($job);

                $company =
                    $this->valueToString(
                        $this->findMetadataValue(
                            $metadata,
                            '포지션의 소속 자회사를 선택해 주세요.'
                        )
                    );
            }

            if ($company !== '') {
                $companies[] = $company;
            }

            /*
             * 근무지
             */
            $location =
                $this->getLocation($job);

            if ($location !== '') {
                $locations[] = $location;
            }

            /*
             * 마감일
             */
            $deadline =
                $this->getDeadline($job);

            if ($deadline !== '') {
                $deadlines[] = $deadline;
            }

            /*
             * 그룹 안의 실제 개별 공고 링크
             */
            $jobUrl = trim(
                (string) (
                    $job['absolute_url']
                    ?? ''
                )
            );

            if ($jobUrl !== '') {
                $links[] = [
                    'company' =>
                        $company !== ''
                            ? $company
                            : '공고',

                    'url' => $jobUrl,
                ];
            }

            /*
             * 그룹 내 가장 최근 등록일
             */
            $firstPublished = trim(
                (string) (
                    $job['first_published']
                    ?? ''
                )
            );

            if ($firstPublished !== '') {
                $timestamp =
                    strtotime($firstPublished);

                if ($timestamp !== false) {
                    $latestTimestamp = max(
                        $latestTimestamp,
                        $timestamp
                    );
                }
            }
        }

        $companies =
            array_values(
                array_unique($companies)
            );

        $locations =
            array_values(
                array_unique($locations)
            );

        $deadlines =
            array_values(
                array_unique($deadlines)
            );

        $item = [
            /*
             * Toss 화면이 동일 제목을 한 행으로 묶으므로
             * 대표 회사가 바뀌더라도 UID가 유지되도록
             * title 기반으로 만든다.
             */
            'uid' =>
                'toss-backend-'
                . sha1($title),

            'title' => $title,

            'uri' => $uri,

            'author' =>
                $companies !== []
                    ? implode(', ', $companies)
                    : 'Toss',

            'content' =>
                $this->buildContent(
                    $companies,
                    $locations,
                    $deadlines,
                    $links,
                    count($matchingJobs)
                ),
        ];

        if ($latestTimestamp > 0) {
            $item['timestamp'] =
                $latestTimestamp;
        }

        return $item;
    }

    private function isMatchingJob(
        array $job
    ): bool {
        $metadata =
            $this->getMetadata($job);

        return
            $this->valueMatches(
                $this->getEmploymentType(
                    $metadata
                ),
                self::EMPLOYMENT_TYPE
            )
            &&
            $this->valueMatches(
                $this->getCareerCategory(
                    $metadata
                ),
                self::CATEGORY
            );
    }

    /**
     * metadata:
     *
     * [
     *   [
     *     "name" => "...",
     *     "value" => "..."
     *   ]
     * ]
     *
     * 를 name => value 형태로 변환.
     */
    private function getMetadata(
        array $job
    ): array {
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

    private function getEmploymentType(
        array $metadata
    ) {
        return $this->findMetadataValue(
            $metadata,
            self::EMPLOYMENT_METADATA
        );
    }

    private function getCareerCategory(
        array $metadata
    ) {
        /*
         * Toss가 현재 Career UI 노출용으로 쓰는
         * 정확한 metadata 이름.
         */
        $value =
            $this->findMetadataValue(
                $metadata,
                self::CATEGORY_METADATA
            );

        if ($value !== null) {
            return $value;
        }

        /*
         * 이름이 조금 변경되었을 경우에만
         * 제한적으로 fallback.
         */
        foreach (
            $metadata
            as $name => $candidate
        ) {
            if (
                stripos(
                    (string) $name,
                    'Job Category'
                ) !== false
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function findMetadataValue(
        array $metadata,
        string $wantedName
    ) {
        foreach (
            $metadata
            as $name => $value
        ) {
            if (
                trim((string) $name)
                ===
                $wantedName
            ) {
                return $value;
            }
        }

        return null;
    }

    /**
     * metadata value가
     * 문자열 / 배열 어느 형태로 와도 처리.
     */
    private function valueMatches(
        $value,
        string $expected
    ): bool {
        foreach (
            $this->flattenValues($value)
            as $candidate
        ) {
            $candidate =
                trim($candidate);

            if ($candidate === '') {
                continue;
            }

            if (
                strcasecmp(
                    $candidate,
                    $expected
                ) === 0
            ) {
                return true;
            }

            /*
             * ["Backend"] 형태가
             * 문자열로 내려오는 경우.
             */
            if (
                ($candidate[0] ?? '') === '['
                ||
                ($candidate[0] ?? '') === '{'
            ) {
                $decoded =
                    json_decode(
                        $candidate,
                        true
                    );

                if (
                    is_array($decoded)
                    &&
                    $this->valueMatches(
                        $decoded,
                        $expected
                    )
                ) {
                    return true;
                }
            }

            /*
             * 혹시
             * Engineering > Backend
             * Backend, Infra
             *
             * 같은 형태일 경우.
             */
            $parts =
                preg_split(
                    '/\s*[,>\/|;]+\s*/u',
                    $candidate
                );

            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (
                        strcasecmp(
                            trim($part),
                            $expected
                        ) === 0
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function flattenValues(
        $value
    ): array {
        if ($value === null) {
            return [];
        }

        if (is_scalar($value)) {
            return [
                (string) $value
            ];
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $child) {
            foreach (
                $this->flattenValues($child)
                as $text
            ) {
                $result[] = $text;
            }
        }

        return $result;
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

    private function getDeadline(
        array $job
    ): string {
        $metadata =
            $this->getMetadata($job);

        /*
         * Toss 자체 클로징 일자 metadata 우선
         */
        $customDeadline =
            $this->findMetadataValue(
                $metadata,
                '커리어페이지 채용공고 클로징 일자 '
                . '(서류접수 마감일이 정해진 경우)'
            );

        $customDeadlineText =
            $this->valueToString(
                $customDeadline
            );

        if ($customDeadlineText !== '') {
            return $customDeadlineText;
        }

        return trim(
            (string) (
                $job['application_deadline']
                ?? ''
            )
        );
    }

    private function valueToString(
        $value
    ): string {
        $values = [];

        foreach (
            $this->flattenValues($value)
            as $text
        ) {
            $text = trim($text);

            if ($text !== '') {
                $values[] = $text;
            }
        }

        return implode(
            ', ',
            array_values(
                array_unique($values)
            )
        );
    }

    private function buildContent(
        array $companies,
        array $locations,
        array $deadlines,
        array $links,
        int $positionCount
    ): string {
        $html = '<div>';

        $html .=
            '<p><strong>직군:</strong> Backend</p>';

        $html .=
            '<p><strong>고용형태:</strong> 정규직</p>';

        if ($companies !== []) {
            $html .=
                '<p><strong>소속:</strong> '
                . $this->escape(
                    implode(
                        ', ',
                        $companies
                    )
                )
                . '</p>';
        }

        if ($positionCount > 1) {
            $html .=
                '<p><strong>현재 세부 공고:</strong> '
                . $positionCount
                . '개</p>';
        }

        if ($locations !== []) {
            $html .=
                '<p><strong>근무지:</strong> '
                . $this->escape(
                    implode(
                        ', ',
                        $locations
                    )
                )
                . '</p>';
        }

        if ($deadlines !== []) {
            $html .=
                '<p><strong>마감:</strong> '
                . $this->escape(
                    implode(
                        ', ',
                        $deadlines
                    )
                )
                . '</p>';
        }

        if ($links !== []) {
            $html .=
                '<p><strong>세부 공고</strong></p>'
                . '<ul>';

            foreach ($links as $link) {
                $html .=
                    '<li><a href="'
                    . $this->escape(
                        (string) $link['url']
                    )
                    . '">'
                    . $this->escape(
                        (string) $link['company']
                    )
                    . '</a></li>';
            }

            $html .= '</ul>';
        }

        $html .= '</div>';

        return $html;
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
