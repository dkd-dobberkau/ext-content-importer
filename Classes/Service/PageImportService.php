<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Service;

use League\CommonMark\CommonMarkConverter;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageImportService
{
    private CommonMarkConverter $markdownConverter;

    /** @var array<string, int> */
    private array $slugToUid = [];

    public function __construct()
    {
        $this->markdownConverter = new CommonMarkConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @param list<array{page: array, contentElements: list<array>}> $parsedPages
     * @return list<string> Imported page titles
     */
    public function importAll(array $parsedPages, int $rootPid): array
    {
        Bootstrap::initializeBackendAuthentication();
        $imported = [];

        // Pass 1: Create root page (parent="" or slug="/") at rootPid
        $rootPages = $this->filterRootPages($parsedPages);
        $siteRootUid = 0;
        foreach ($rootPages as $parsedPage) {
            $pageUid = $this->createPage($parsedPage, $rootPid);
            $slug = $parsedPage['page']['slug'];
            $this->slugToUid[$slug] = $pageUid;
            if ($siteRootUid === 0) {
                $siteRootUid = $pageUid;
            }
            $this->createContentElements($parsedPage['contentElements'], $pageUid);
            $imported[] = $parsedPage['page']['title'];
        }

        // Pass 2: Create section pages (parent="/") as children of root page
        $sectionPages = $this->filterSectionPages($parsedPages);
        $lastSectionUid = 0;
        foreach ($sectionPages as $parsedPage) {
            $targetPid = $lastSectionUid > 0 ? -$lastSectionUid : $siteRootUid;
            $pageUid = $this->createPage($parsedPage, $targetPid);
            $slug = $parsedPage['page']['slug'];
            $this->slugToUid[$slug] = $pageUid;
            $lastSectionUid = $pageUid;
            $this->createContentElements($parsedPage['contentElements'], $pageUid);
            $imported[] = $parsedPage['page']['title'];
        }

        // Pass 3: Create sub-pages under their respective parents
        $childrenByParent = [];
        $subPages = $this->filterSubPages($parsedPages);
        foreach ($subPages as $parsedPage) {
            $parentSlug = ltrim($parsedPage['page']['parent'], '/');
            $childrenByParent[$parentSlug][] = $parsedPage;
        }

        foreach ($childrenByParent as $parentSlug => $siblings) {
            $lastSiblingUid = 0;
            foreach ($siblings as $parsedPage) {
                $parentUid = $this->slugToUid[$parentSlug] ?? $siteRootUid;
                $targetPid = $lastSiblingUid > 0 ? -$lastSiblingUid : $parentUid;
                $pageUid = $this->createPage($parsedPage, $targetPid);
                $slug = $parsedPage['page']['slug'];
                $this->slugToUid[$slug] = $pageUid;
                $lastSiblingUid = $pageUid;
                $this->createContentElements($parsedPage['contentElements'], $pageUid);
                $imported[] = $parsedPage['page']['title'];
            }
        }

        return $imported;
    }

    /**
     * Filter root pages (parent is empty) - typically just the homepage.
     */
    public function filterRootPages(array $pages): array
    {
        return array_values(array_filter($pages, function (array $p) {
            $parent = $p['page']['parent'] ?? '';
            return $parent === '' || $parent === null;
        }));
    }

    /**
     * Filter section pages (parent is '/') - main navigation items.
     */
    public function filterSectionPages(array $pages): array
    {
        return array_values(array_filter($pages, function (array $p) {
            $parent = $p['page']['parent'] ?? '';
            return $parent === '/';
        }));
    }

    /**
     * Filter sub-pages (parent is a specific page like '/ueber-uns').
     */
    public function filterSubPages(array $pages): array
    {
        return array_values(array_filter($pages, function (array $p) {
            $parent = $p['page']['parent'] ?? '';
            return $parent !== '' && $parent !== '/' && $parent !== null;
        }));
    }

    /**
     * Build a TYPO3 page data map from a parsed page structure.
     *
     * @param array $parsedPage
     * @param int $pid
     * @return array
     */
    public function buildPageDataMap(array $parsedPage, int $pid): array
    {
        $page = $parsedPage['page'];

        $data = [
            'pid' => $pid,
            'title' => $page['title'],
            'hidden' => 0,
            'doktype' => 1,
            'sorting' => ($page['nav_position'] ?? 1) * 100,
        ];

        if (isset($page['seo']['title'])) {
            $data['seo_title'] = $page['seo']['title'];
        }
        if (isset($page['seo']['description'])) {
            $data['description'] = $page['seo']['description'];
        }

        return $data;
    }

    /**
     * Build a TYPO3 content element data map from a parsed content element.
     *
     * @param array $ce
     * @param int $pid
     * @param int $sorting
     * @return array
     */
    public function buildContentElementDataMap(array $ce, int $pid, int $sorting): array
    {
        $type = $ce['type'];
        $content = $ce['content'] ?? '';
        $subtype = $ce['subtype'] ?? null;

        $data = [
            'pid' => $pid,
            'colPos' => 0,
            'sorting' => $sorting,
        ];

        switch (true) {
            case $type === 'header':
                $data['CType'] = 'header';
                $data['header'] = $this->extractHeaderText($content);
                break;

            case $type === 'text' && $subtype === 'bullets':
                $data['CType'] = 'bullets';
                $data['header'] = $this->extractHeaderText($content);
                $data['bodytext'] = $this->extractBulletItems($content);
                break;

            case $type === 'text' && $subtype === 'table':
                $data['CType'] = 'table';
                $data['header'] = $this->extractHeaderText($content);
                $data['bodytext'] = $this->extractTableContent($content);
                break;

            case $type === 'quote':
                $data['CType'] = 'text';
                $quoteText = $this->extractQuoteText($content);
                $quoteAuthor = $this->extractQuoteAuthor($content);
                $data['bodytext'] = '<blockquote>' . htmlspecialchars($quoteText) . '</blockquote>';
                $data['header'] = $quoteAuthor;
                break;

            case $type === 'textmedia':
                $data['CType'] = 'textmedia';
                $data['header'] = $this->extractHeaderText($content);
                $data['bodytext'] = $this->convertToHtml($this->stripFirstHeader($content));
                break;

            default:
                $data['CType'] = 'text';
                $data['header'] = $this->extractHeaderText($content);
                $data['bodytext'] = $this->convertToHtml($this->stripFirstHeader($content));
                break;
        }

        return $data;
    }

    private function createPage(array $parsedPage, int $pid): int
    {
        $newId = 'NEW_page_' . md5($parsedPage['page']['slug']);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->isImporting = true;
        $data = [
            'pages' => [
                $newId => $this->buildPageDataMap($parsedPage, $pid),
            ],
        ];
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException(
                'DataHandler errors for page "' . $parsedPage['page']['title'] . '": '
                . implode(', ', $dataHandler->errorLog)
            );
        }

        return (int)($dataHandler->substNEWwithIDs[$newId] ?? 0);
    }

    private function createContentElements(array $contentElements, int $pageUid): void
    {
        if ($contentElements === [] || $pageUid === 0) {
            return;
        }

        $lastCeUid = 0;
        foreach ($contentElements as $i => $ce) {
            $newId = 'NEW_ce_' . $pageUid . '_' . $i;
            $targetPid = $lastCeUid > 0 ? -$lastCeUid : $pageUid;
            $data = [
                'tt_content' => [
                    $newId => $this->buildContentElementDataMap($ce, $targetPid, ($i + 1) * 100),
                ],
            ];

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($data, []);
            $dataHandler->process_datamap();

            $lastCeUid = (int)($dataHandler->substNEWwithIDs[$newId] ?? 0);
        }
    }

    /**
     * Extract heading text from Markdown content (first heading found).
     */
    private function extractHeaderText(string $content): string
    {
        if (preg_match('/^#+\s+(.+)$/m', $content, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    /**
     * Strip the first Markdown heading from content.
     */
    private function stripFirstHeader(string $content): string
    {
        return trim(preg_replace('/^#+\s+.+$/m', '', $content, 1));
    }

    /**
     * Extract bullet list items from Markdown content.
     */
    private function extractBulletItems(string $content): string
    {
        preg_match_all('/^[-*]\s+(.+)$/m', $content, $matches);
        return implode("\n", $matches[1] ?? []);
    }

    /**
     * Extract table content from Markdown, excluding separator lines.
     */
    private function extractTableContent(string $content): string
    {
        $lines = explode("\n", $content);
        $tableLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '|') && !preg_match('/^\|[\s\-|]+\|$/', $line)) {
                $cells = array_map('trim', explode('|', trim($line, '| ')));
                $tableLines[] = implode('|', $cells);
            }
        }
        return implode("\n", $tableLines);
    }

    /**
     * Extract quote text from Markdown blockquote, excluding author lines.
     */
    private function extractQuoteText(string $content): string
    {
        $lines = explode("\n", $content);
        $quoteLines = [];
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '>')) {
                $text = ltrim(trim($line), '> ');
                if (!str_starts_with($text, "\u{2014}") && !str_starts_with($text, '-')) {
                    $quoteLines[] = trim($text, "\"\u{201C}\u{201D} ");
                }
            }
        }
        return implode(' ', $quoteLines);
    }

    /**
     * Extract author attribution from a Markdown blockquote.
     */
    private function extractQuoteAuthor(string $content): string
    {
        if (preg_match('/>\s*[\x{2014}\-]\s*(.+)$/mu', $content, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    /**
     * Convert Markdown to HTML using CommonMark.
     */
    private function convertToHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        return trim((string)$this->markdownConverter->convert($markdown));
    }
}
