<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Tests\Unit\Service;

use Dkd\ContentImporter\Service\PageImportService;
use PHPUnit\Framework\TestCase;

class PageImportServiceTest extends TestCase
{
    private PageImportService $service;

    protected function setUp(): void
    {
        $this->service = new PageImportService();
    }

    public function testBuildPageDataMap(): void
    {
        $parsedPage = [
            'page' => [
                'title' => 'Über uns',
                'slug' => 'ueber-uns',
                'parent' => '/',
                'nav_position' => 2,
                'seo' => ['title' => 'Über uns - Test', 'description' => 'Test description'],
            ],
            'contentElements' => [],
        ];

        $result = $this->service->buildPageDataMap($parsedPage, 1);

        self::assertSame('Über uns', $result['title']);
        self::assertSame(1, $result['pid']);
        self::assertSame(0, $result['hidden']);
        self::assertSame(1, $result['doktype']);
        self::assertSame(200, $result['sorting']);
        self::assertSame('Über uns - Test', $result['seo_title']);
        self::assertSame('Test description', $result['description']);
    }

    public function testBuildContentElementText(): void
    {
        $ce = [
            'type' => 'text',
            'content' => "## Headline\n\nSome text here.",
        ];

        $result = $this->service->buildContentElementDataMap($ce, 123, 100);

        self::assertSame('text', $result['CType']);
        self::assertSame(123, $result['pid']);
        self::assertSame(0, $result['colPos']);
        self::assertSame(100, $result['sorting']);
        self::assertSame('Headline', $result['header']);
        self::assertStringContainsString('Some text', $result['bodytext']);
    }

    public function testBuildContentElementHeader(): void
    {
        $ce = [
            'type' => 'header',
            'content' => '# Willkommen bei La Bella Vista',
        ];

        $result = $this->service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('header', $result['CType']);
        self::assertSame('Willkommen bei La Bella Vista', $result['header']);
    }

    public function testBuildContentElementBullets(): void
    {
        $ce = [
            'type' => 'text',
            'subtype' => 'bullets',
            'content' => "## Werte\n\n- Qualität\n- Frische\n- Service",
        ];

        $result = $this->service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('bullets', $result['CType']);
        self::assertSame('Werte', $result['header']);
        self::assertStringContainsString('Qualität', $result['bodytext']);
    }

    public function testBuildContentElementQuote(): void
    {
        $ce = [
            'type' => 'quote',
            'content' => "> \"Tolles Restaurant!\"\n> — Maria S., Stammgast",
        ];

        $result = $this->service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('text', $result['CType']);
        self::assertStringContainsString('<blockquote>', $result['bodytext']);
        self::assertStringContainsString('Tolles Restaurant', $result['bodytext']);
        self::assertStringContainsString('Maria S.', $result['header']);
    }

    public function testBuildContentElementTable(): void
    {
        $ce = [
            'type' => 'text',
            'subtype' => 'table',
            'content' => "## Preise\n\n| Paket | Preis |\n|-------|-------|\n| Basic | 10€ |\n| Pro | 20€ |",
        ];

        $result = $this->service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('table', $result['CType']);
        self::assertSame('Preise', $result['header']);
        self::assertStringContainsString('Basic', $result['bodytext']);
    }

    public function testFilterRootPages(): void
    {
        $pages = [
            ['page' => ['slug' => '/', 'parent' => '', 'nav_position' => 1, 'title' => 'Home'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns', 'parent' => '/', 'nav_position' => 2, 'title' => 'Über uns'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns/team', 'parent' => '/ueber-uns', 'nav_position' => 3, 'title' => 'Team'], 'contentElements' => []],
        ];

        $root = $this->service->filterRootPages($pages);
        self::assertCount(1, $root);
        self::assertSame('Home', $root[0]['page']['title']);
    }

    public function testFilterSectionPages(): void
    {
        $pages = [
            ['page' => ['slug' => '/', 'parent' => '', 'nav_position' => 1, 'title' => 'Home'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns', 'parent' => '/', 'nav_position' => 2, 'title' => 'Über uns'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns/team', 'parent' => '/ueber-uns', 'nav_position' => 3, 'title' => 'Team'], 'contentElements' => []],
        ];

        $sections = $this->service->filterSectionPages($pages);
        self::assertCount(1, $sections);
        self::assertSame('Über uns', $sections[0]['page']['title']);
    }

    public function testFilterSubPages(): void
    {
        $pages = [
            ['page' => ['slug' => '/', 'parent' => '', 'nav_position' => 1, 'title' => 'Home'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns', 'parent' => '/', 'nav_position' => 2, 'title' => 'Über uns'], 'contentElements' => []],
            ['page' => ['slug' => 'ueber-uns/team', 'parent' => '/ueber-uns', 'nav_position' => 3, 'title' => 'Team'], 'contentElements' => []],
        ];

        $sub = $this->service->filterSubPages($pages);
        self::assertCount(1, $sub);
        self::assertSame('Team', $sub[0]['page']['title']);
    }

    public function testBuildContentElementTextmedia(): void
    {
        $ce = [
            'type' => 'textmedia',
            'content' => "## Geschichte\n\nSeit 2005 servieren wir Küche.",
            'image' => 'placeholder://team.jpg',
            'position' => 'right',
        ];

        $result = $this->service->buildContentElementDataMap($ce, 1, 100);

        self::assertSame('textmedia', $result['CType']);
        self::assertSame('Geschichte', $result['header']);
        self::assertStringContainsString('2005', $result['bodytext']);
    }
}
