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

        self::assertSame('header', $result['contentElements'][0]['type']);
        self::assertStringContainsString('Willkommen bei La Bella Vista', $result['contentElements'][0]['content']);

        self::assertSame('textmedia', $result['contentElements'][1]['type']);
        self::assertSame('placeholder://team.jpg', $result['contentElements'][1]['image']);
        self::assertSame('right', $result['contentElements'][1]['position']);
        self::assertStringContainsString('Unsere Geschichte', $result['contentElements'][1]['content']);

        self::assertSame('text', $result['contentElements'][2]['type']);
        self::assertSame('bullets', $result['contentElements'][2]['subtype']);

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
}
