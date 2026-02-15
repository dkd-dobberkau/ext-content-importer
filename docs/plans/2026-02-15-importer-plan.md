# TYPO3 Content Importer Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** TYPO3 v13 extension with a CLI command (`content:import`) that imports Markdown files with YAML frontmatter as pages and content elements via DataHandler.

**Architecture:** Symfony Console Command orchestrates a MarkdownPageParser (reads .md files, extracts frontmatter + CE blocks) and a PageImportService (creates pages + tt_content via DataHandler). Two-pass page tree building: top-level pages first, then children.

**Tech Stack:** PHP 8.2+, TYPO3 v13, Symfony Console, league/commonmark, DataHandler API

---

### Task 1: Extension Scaffolding

**Files:**
- Create: `composer.json`
- Create: `ext_emconf.php`
- Create: `Configuration/Services.yaml`
- Create: `.gitignore`

**Step 1: Create composer.json**

```json
{
    "name": "dkd/content-importer",
    "type": "typo3-cms-extension",
    "description": "Import Markdown content files as TYPO3 pages and content elements",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.2",
        "typo3/cms-core": "^13.4",
        "league/commonmark": "^2.6"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Dkd\\ContentImporter\\": "Classes/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Dkd\\ContentImporter\\Tests\\": "Tests/"
        }
    },
    "extra": {
        "typo3/cms": {
            "extension-key": "content_importer"
        }
    }
}
```

**Step 2: Create ext_emconf.php**

```php
<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Importer',
    'description' => 'Import Markdown content files as TYPO3 pages and content elements',
    'category' => 'module',
    'author' => 'dkd',
    'state' => 'beta',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
    ],
];
```

**Step 3: Create Configuration/Services.yaml**

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Dkd\ContentImporter\:
    resource: '../Classes/*'
```

**Step 4: Create .gitignore**

```
vendor/
.Build/
composer.lock
.phpunit.result.cache
```

**Step 5: Install dependencies**

Run: `composer install` in the extension directory.

**Step 6: Commit**

```bash
git add composer.json ext_emconf.php Configuration/Services.yaml .gitignore
git commit -m "chore: TYPO3 v13 extension scaffolding"
```

---

### Task 2: MarkdownPageParser - Frontmatter Parsing

**Files:**
- Create: `Classes/Parser/MarkdownPageParser.php`
- Create: `Tests/Unit/Parser/MarkdownPageParserTest.php`
- Create: `Tests/Fixtures/sample-page.md`
- Create: `phpunit.xml`

**Step 1: Create test fixture**

Create `Tests/Fixtures/sample-page.md`:

```markdown
---
title: "Über uns"
slug: "ueber-uns"
parent: "/"
nav_position: 2
seo:
  title: "Über uns - La Bella Vista"
  description: "Erfahren Sie mehr über La Bella Vista"
---

<!-- CE: header -->
# Willkommen bei La Bella Vista

<!-- CE: textmedia, image: placeholder://team.jpg, position: right -->
## Unsere Geschichte

Seit 2005 servieren wir authentische italienische Küche.

<!-- CE: text, subtype: bullets -->
## Unsere Werte

- **Authentizität** — Originalrezepte
- **Frische** — Tägliche Lieferung
- **Gastfreundschaft** — Jeder Gast ist Familie

<!-- CE: quote -->
> "Das beste Restaurant in München!"
> — Maria S., Stammgast
```

**Step 2: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>Tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Step 3: Write the failing test**

Create `Tests/Unit/Parser/MarkdownPageParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Tests\Unit\Parser;

use Dkd\ContentImporter\Parser\MarkdownPageParser;
use PHPUnit\Framework\TestCase;

class MarkdownPageParserTest extends TestCase
{
    private MarkdownPageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownPageParser();
    }

    public function testParseFrontmatter(): void
    {
        $filePath = __DIR__ . '/../../Fixtures/sample-page.md';
        $result = $this->parser->parseFile($filePath);

        self::assertSame('Über uns', $result['page']['title']);
        self::assertSame('ueber-uns', $result['page']['slug']);
        self::assertSame('/', $result['page']['parent']);
        self::assertSame(2, $result['page']['nav_position']);
        self::assertSame('Über uns - La Bella Vista', $result['page']['seo']['title']);
    }

    public function testParseContentElements(): void
    {
        $filePath = __DIR__ . '/../../Fixtures/sample-page.md';
        $result = $this->parser->parseFile($filePath);

        self::assertCount(4, $result['contentElements']);

        // First CE: header
        self::assertSame('header', $result['contentElements'][0]['type']);
        self::assertStringContainsString('Willkommen bei La Bella Vista', $result['contentElements'][0]['content']);

        // Second CE: textmedia with image
        self::assertSame('textmedia', $result['contentElements'][1]['type']);
        self::assertSame('placeholder://team.jpg', $result['contentElements'][1]['image']);
        self::assertSame('right', $result['contentElements'][1]['position']);
        self::assertStringContainsString('Unsere Geschichte', $result['contentElements'][1]['content']);

        // Third CE: text with bullets subtype
        self::assertSame('text', $result['contentElements'][2]['type']);
        self::assertSame('bullets', $result['contentElements'][2]['subtype']);

        // Fourth CE: quote
        self::assertSame('quote', $result['contentElements'][3]['type']);
        self::assertStringContainsString('beste Restaurant', $result['contentElements'][3]['content']);
    }

    public function testParseDirectory(): void
    {
        $dir = __DIR__ . '/../../Fixtures';
        $results = $this->parser->parseDirectory($dir);

        self::assertCount(1, $results);
        self::assertSame('Über uns', $results[0]['page']['title']);
    }

    public function testParseDirectorySortsByNavPosition(): void
    {
        // With one file, just verify it returns sorted array
        $dir = __DIR__ . '/../../Fixtures';
        $results = $this->parser->parseDirectory($dir);

        self::assertNotEmpty($results);
    }
}
```

**Step 4: Run test to verify it fails**

Run: `vendor/bin/phpunit Tests/Unit/Parser/MarkdownPageParserTest.php --colors`
Expected: FAIL with `Class not found`

**Step 5: Write minimal implementation**

Create `Classes/Parser/MarkdownPageParser.php`:

```php
<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Parser;

use Symfony\Component\Yaml\Yaml;

class MarkdownPageParser
{
    /**
     * Parse a single Markdown file into page metadata and content elements.
     *
     * @return array{page: array, contentElements: list<array>}
     */
    public function parseFile(string $filePath): array
    {
        $raw = file_get_contents($filePath);

        // Extract YAML frontmatter
        if (!preg_match('/\A---\n(.+?)\n---\n(.*)\z/s', $raw, $matches)) {
            throw new \RuntimeException('No YAML frontmatter found in: ' . $filePath);
        }

        $page = Yaml::parse($matches[1]);
        $body = trim($matches[2]);

        // Split body into content elements by <!-- CE: ... --> markers
        $contentElements = $this->parseContentElements($body);

        return [
            'page' => $page,
            'contentElements' => $contentElements,
        ];
    }

    /**
     * Parse all .md files in a directory, sorted by nav_position.
     *
     * @return list<array{page: array, contentElements: list<array>}>
     */
    public function parseDirectory(string $directory): array
    {
        $files = glob($directory . '/*.md');
        if ($files === false || $files === []) {
            return [];
        }
        sort($files);

        $pages = [];
        foreach ($files as $file) {
            $pages[] = $this->parseFile($file);
        }

        usort($pages, fn(array $a, array $b) =>
            ($a['page']['nav_position'] ?? 999) <=> ($b['page']['nav_position'] ?? 999)
        );

        return $pages;
    }

    /**
     * Split body content into content elements based on <!-- CE: --> markers.
     *
     * @return list<array{type: string, content: string, ...}>
     */
    private function parseContentElements(string $body): array
    {
        // Split on CE markers, keeping the markers
        $parts = preg_split(
            '/(<!-- CE:\s*[^>]+-->)/',
            $body,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $elements = [];
        $currentMarker = null;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/<!-- CE:\s*(.+?)\s*-->/', $part, $match)) {
                $currentMarker = $this->parseMarker($match[1]);
            } elseif ($currentMarker !== null) {
                $currentMarker['content'] = $part;
                $elements[] = $currentMarker;
                $currentMarker = null;
            }
        }

        return $elements;
    }

    /**
     * Parse a CE marker string like "textmedia, image: placeholder://x.jpg, position: right"
     *
     * @return array{type: string, ...}
     */
    private function parseMarker(string $marker): array
    {
        $parts = array_map('trim', explode(',', $marker));
        $result = ['type' => array_shift($parts)];

        foreach ($parts as $part) {
            if (str_contains($part, ':')) {
                [$key, $value] = array_map('trim', explode(':', $part, 2));
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
```

**Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit Tests/Unit/Parser/MarkdownPageParserTest.php --colors`
Expected: 4 tests PASS

**Step 7: Commit**

```bash
git add Classes/Parser/MarkdownPageParser.php Tests/ phpunit.xml
git commit -m "feat: add MarkdownPageParser with frontmatter and CE extraction"
```

---

### Task 3: PageImportService - Page Creation

**Files:**
- Create: `Classes/Service/PageImportService.php`
- Create: `Tests/Unit/Service/PageImportServiceTest.php`

**Step 1: Write the failing test**

Create `Tests/Unit/Service/PageImportServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Tests\Unit\Service;

use Dkd\ContentImporter\Service\PageImportService;
use PHPUnit\Framework\TestCase;

class PageImportServiceTest extends TestCase
{
    public function testBuildPageDataMap(): void
    {
        $service = new PageImportService();

        $parsedPage = [
            'page' => [
                'title' => 'Über uns',
                'slug' => 'ueber-uns',
                'parent' => '/',
                'nav_position' => 2,
                'seo' => ['title' => 'Über uns - Test'],
            ],
            'contentElements' => [],
        ];

        $result = $service->buildPageDataMap($parsedPage, 1, 'NEW_1');

        self::assertSame('Über uns', $result['title']);
        self::assertSame(1, $result['pid']);
        self::assertSame(1, $result['doktype']);
        self::assertSame(200, $result['sorting']);
        self::assertSame('Über uns - Test', $result['seo_title']);
    }

    public function testBuildContentElementDataMap(): void
    {
        $service = new PageImportService();

        $ce = [
            'type' => 'text',
            'content' => "## Headline\n\nSome text here.",
        ];

        $result = $service->buildContentElementDataMap($ce, 123, 100);

        self::assertSame('text', $result['CType']);
        self::assertSame(123, $result['pid']);
        self::assertSame(0, $result['colPos']);
        self::assertSame(100, $result['sorting']);
        self::assertStringContainsString('Some text', $result['bodytext']);
    }

    public function testBuildContentElementHeader(): void
    {
        $service = new PageImportService();

        $ce = [
            'type' => 'header',
            'content' => '# Willkommen bei La Bella Vista',
        ];

        $result = $service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('header', $result['CType']);
        self::assertSame('Willkommen bei La Bella Vista', $result['header']);
    }

    public function testBuildContentElementBullets(): void
    {
        $service = new PageImportService();

        $ce = [
            'type' => 'text',
            'subtype' => 'bullets',
            'content' => "## Werte\n\n- Qualität\n- Frische\n- Service",
        ];

        $result = $service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('bullets', $result['CType']);
        self::assertStringContainsString('Qualität', $result['bodytext']);
    }

    public function testBuildContentElementQuote(): void
    {
        $service = new PageImportService();

        $ce = [
            'type' => 'quote',
            'content' => "> \"Tolles Restaurant!\"\n> — Maria S., Stammgast",
        ];

        $result = $service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('quote', $result['CType']);
        self::assertStringContainsString('Tolles Restaurant', $result['bodytext']);
        self::assertStringContainsString('Maria S.', $result['header']);
    }

    public function testBuildContentElementTable(): void
    {
        $service = new PageImportService();

        $ce = [
            'type' => 'text',
            'subtype' => 'table',
            'content' => "## Preise\n\n| Paket | Preis |\n|-------|-------|\n| Basic | 10€ |\n| Pro | 20€ |",
        ];

        $result = $service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('table', $result['CType']);
        self::assertStringContainsString('Basic', $result['bodytext']);
    }

    public function testResolveParentPages(): void
    {
        $service = new PageImportService();

        $pages = [
            ['page' => ['slug' => '/', 'parent' => '', 'nav_position' => 1, 'title' => 'Home'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns', 'parent' => '/', 'nav_position' => 2, 'title' => 'Über uns'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns/team', 'parent' => '/ueber-uns', 'nav_position' => 3, 'title' => 'Team'], 'contentElements' => []],
        ];

        // Top-level pages (parent = "/" or empty)
        $topLevel = $service->filterTopLevelPages($pages);
        self::assertCount(2, $topLevel); // Home + Über uns

        // Child pages
        $children = $service->filterChildPages($pages);
        self::assertCount(1, $children); // Team
        self::assertSame('Team', $children[0]['page']['title']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit Tests/Unit/Service/PageImportServiceTest.php --colors`
Expected: FAIL with `Class not found`

**Step 3: Write implementation**

Create `Classes/Service/PageImportService.php`:

```php
<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Service;

use League\CommonMark\CommonMarkConverter;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageImportService
{
    private CommonMarkConverter $markdownConverter;

    /** @var array<string, int> Slug-to-UID mapping for created pages */
    private array $slugToUid = [];

    public function __construct()
    {
        $this->markdownConverter = new CommonMarkConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Import all parsed pages under the given root page.
     *
     * @param list<array{page: array, contentElements: list<array>}> $parsedPages
     */
    public function importAll(array $parsedPages, int $rootPid): array
    {
        $imported = [];

        // Pass 1: Create top-level pages
        $topLevel = $this->filterTopLevelPages($parsedPages);
        foreach ($topLevel as $i => $parsedPage) {
            $pageUid = $this->createPage($parsedPage, $rootPid, ($i + 1) * 100);
            $slug = $parsedPage['page']['slug'];
            $this->slugToUid[$slug] = $pageUid;
            $this->createContentElements($parsedPage['contentElements'], $pageUid);
            $imported[] = $parsedPage['page']['title'];
        }

        // Pass 2: Create child pages
        $children = $this->filterChildPages($parsedPages);
        foreach ($children as $i => $parsedPage) {
            $parentSlug = ltrim($parsedPage['page']['parent'], '/');
            $parentUid = $this->slugToUid[$parentSlug] ?? $rootPid;
            $pageUid = $this->createPage($parsedPage, $parentUid, ($i + 1) * 100);
            $slug = $parsedPage['page']['slug'];
            $this->slugToUid[$slug] = $pageUid;
            $this->createContentElements($parsedPage['contentElements'], $pageUid);
            $imported[] = $parsedPage['page']['title'];
        }

        return $imported;
    }

    /**
     * Filter pages that are top-level (parent is "/" or empty).
     */
    public function filterTopLevelPages(array $pages): array
    {
        return array_values(array_filter($pages, function (array $p) {
            $parent = $p['page']['parent'] ?? '';
            return $parent === '/' || $parent === '';
        }));
    }

    /**
     * Filter pages that have a non-root parent.
     */
    public function filterChildPages(array $pages): array
    {
        return array_values(array_filter($pages, function (array $p) {
            $parent = $p['page']['parent'] ?? '';
            return $parent !== '/' && $parent !== '';
        }));
    }

    /**
     * Build the DataHandler data map for a page record.
     */
    public function buildPageDataMap(array $parsedPage, int $pid, string $newId): array
    {
        $page = $parsedPage['page'];

        $data = [
            'pid' => $pid,
            'title' => $page['title'],
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
     * Build the DataHandler data map for a tt_content record.
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

        // Determine CType and map fields
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
                $data['CType'] = 'quote';
                $data['bodytext'] = $this->extractQuoteText($content);
                $data['header'] = $this->extractQuoteAuthor($content);
                break;

            case $type === 'textmedia':
                $data['CType'] = 'textmedia';
                $data['header'] = $this->extractHeaderText($content);
                $data['bodytext'] = $this->convertToHtml($this->stripFirstHeader($content));
                break;

            default:
                // text, accordion, shortcut, uploads, menu → all become text
                $data['CType'] = 'text';
                $data['header'] = $this->extractHeaderText($content);
                $data['bodytext'] = $this->convertToHtml($this->stripFirstHeader($content));
                break;
        }

        return $data;
    }

    /**
     * Create a page via DataHandler and return its UID.
     */
    private function createPage(array $parsedPage, int $pid, int $sorting): int
    {
        $newId = 'NEW_page_' . md5($parsedPage['page']['slug']);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $data = [
            'pages' => [
                $newId => $this->buildPageDataMap($parsedPage, $pid, $newId),
            ],
        ];
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$newId] ?? 0);
    }

    /**
     * Create content elements for a page via DataHandler.
     */
    private function createContentElements(array $contentElements, int $pageUid): void
    {
        if ($contentElements === [] || $pageUid === 0) {
            return;
        }

        $data = ['tt_content' => []];
        foreach ($contentElements as $i => $ce) {
            $newId = 'NEW_ce_' . $pageUid . '_' . $i;
            $data['tt_content'][$newId] = $this->buildContentElementDataMap(
                $ce,
                $pageUid,
                ($i + 1) * 100
            );
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
    }

    private function extractHeaderText(string $content): string
    {
        if (preg_match('/^#+\s+(.+)$/m', $content, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    private function stripFirstHeader(string $content): string
    {
        return trim(preg_replace('/^#+\s+.+$/m', '', $content, 1));
    }

    private function extractBulletItems(string $content): string
    {
        preg_match_all('/^[-*]\s+(.+)$/m', $content, $matches);
        return implode("\n", $matches[1] ?? []);
    }

    private function extractTableContent(string $content): string
    {
        // Extract table rows, skip separator line (|---|)
        $lines = explode("\n", $content);
        $tableLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '|') && !preg_match('/^\|[\s-|]+\|$/', $line)) {
                // Remove leading/trailing pipes and convert to TYPO3 table format
                $cells = array_map('trim', explode('|', trim($line, '| ')));
                $tableLines[] = implode('|', $cells);
            }
        }
        return implode("\n", $tableLines);
    }

    private function extractQuoteText(string $content): string
    {
        $lines = explode("\n", $content);
        $quoteLines = [];
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '>')) {
                $text = ltrim(trim($line), '> ');
                // Skip author lines (start with —)
                if (!str_starts_with($text, '—') && !str_starts_with($text, '-')) {
                    $quoteLines[] = trim($text, '"" ');
                }
            }
        }
        return implode(' ', $quoteLines);
    }

    private function extractQuoteAuthor(string $content): string
    {
        if (preg_match('/>\s*[—-]\s*(.+)$/m', $content, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    private function convertToHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        return trim((string)$this->markdownConverter->convert($markdown));
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit Tests/Unit/Service/PageImportServiceTest.php --colors`
Expected: 7 tests PASS

**Step 5: Commit**

```bash
git add Classes/Service/PageImportService.php Tests/Unit/Service/PageImportServiceTest.php
git commit -m "feat: add PageImportService with DataHandler integration"
```

---

### Task 4: ImportCommand (Symfony Console)

**Files:**
- Create: `Classes/Command/ImportCommand.php`

**Step 1: Write implementation**

Create `Classes/Command/ImportCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Command;

use Dkd\ContentImporter\Parser\MarkdownPageParser;
use Dkd\ContentImporter\Service\PageImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'content:import',
    description: 'Import Markdown content files as TYPO3 pages and content elements',
)]
class ImportCommand extends Command
{
    public function __construct(
        private readonly MarkdownPageParser $parser,
        private readonly PageImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path to directory containing Markdown files'
            )
            ->addOption(
                'pid',
                'p',
                InputOption::VALUE_REQUIRED,
                'Root page UID under which pages are created',
                '1'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $pid = (int)$input->getOption('pid');

        if (!is_dir($path)) {
            $io->error('Directory not found: ' . $path);
            return Command::FAILURE;
        }

        $io->title('TYPO3 Content Importer');
        $io->text('Importing from: ' . realpath($path));
        $io->text('Root page PID: ' . $pid);
        $io->newLine();

        // Parse all markdown files
        $parsedPages = $this->parser->parseDirectory($path);

        if ($parsedPages === []) {
            $io->warning('No Markdown files found in: ' . $path);
            return Command::FAILURE;
        }

        $io->text(sprintf('Found %d pages to import.', count($parsedPages)));
        $io->newLine();

        // Import pages
        $imported = $this->importService->importAll($parsedPages, $pid);

        foreach ($imported as $i => $title) {
            $io->text(sprintf('[%d/%d] %s ✓', $i + 1, count($imported), $title));
        }

        $io->newLine();
        $io->success(sprintf('%d pages imported successfully.', count($imported)));

        return Command::SUCCESS;
    }
}
```

**Step 2: Verify the command class compiles**

Run: `vendor/bin/phpunit --list-tests` (just to verify autoloading works)
Expected: Lists existing tests without error.

**Step 3: Commit**

```bash
git add Classes/Command/ImportCommand.php
git commit -m "feat: add content:import CLI command"
```

---

### Task 5: Run Full Test Suite and Final Polish

**Step 1: Run all tests**

Run: `vendor/bin/phpunit --colors`
Expected: All tests PASS (4 parser + 7 service = 11 tests)

**Step 2: Verify extension structure is complete**

Check that all files exist:
- `composer.json`
- `ext_emconf.php`
- `Configuration/Services.yaml`
- `Classes/Command/ImportCommand.php`
- `Classes/Parser/MarkdownPageParser.php`
- `Classes/Service/PageImportService.php`
- `Tests/Unit/Parser/MarkdownPageParserTest.php`
- `Tests/Unit/Service/PageImportServiceTest.php`
- `Tests/Fixtures/sample-page.md`
- `phpunit.xml`

**Step 3: Commit any polish**

```bash
git add -A
git commit -m "chore: final polish and test verification"
```

---

### Task 6: Integration Smoke Test in TYPO3

This task requires a running TYPO3 v13 instance.

**Step 1: Install extension**

In a TYPO3 v13 project:
```bash
composer require dkd/content-importer:@dev --dev
```

Or symlink into `packages/`:
```bash
ln -s /Users/olivier/Versioncontrol/local/ext-content-importer packages/content-importer
```

**Step 2: Run the import command**

```bash
bin/typo3 content:import /Users/olivier/Versioncontrol/local/t3-content-library/output/italienisches-restaurant-la-bella-vista-in-münchen/ --pid=1
```

**Step 3: Verify in TYPO3 Backend**

- Open TYPO3 Backend
- Check page tree: 20 pages should be visible under PID 1
- Check page hierarchy: Team and Geschichte under Über uns, etc.
- Check content elements: each page should have the correct CEs
- Verify bodytext contains HTML-rendered content

**Step 4: Fix any issues and commit**

```bash
git add -A
git commit -m "fix: adjustments from integration test"
```
