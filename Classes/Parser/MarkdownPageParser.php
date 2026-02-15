<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Parser;

use Symfony\Component\Yaml\Yaml;

class MarkdownPageParser
{
    /**
     * Parse a single Markdown file with YAML frontmatter and CE markers.
     *
     * @return array{page: array<string, mixed>, contentElements: list<array<string, string>>}
     */
    public function parseFile(string $filePath): array
    {
        $raw = file_get_contents($filePath);

        if ($raw === false) {
            throw new \RuntimeException('Cannot read file: ' . $filePath);
        }

        if (!preg_match('/\A---\n(.+?)\n---\n(.*)\z/s', $raw, $matches)) {
            throw new \RuntimeException('No YAML frontmatter found in: ' . $filePath);
        }

        $page = Yaml::parse($matches[1]);
        $body = trim($matches[2]);

        $contentElements = $this->parseContentElements($body);

        return [
            'page' => $page,
            'contentElements' => $contentElements,
        ];
    }

    /**
     * Parse all Markdown files in a directory, sorted by nav_position.
     *
     * @return list<array{page: array<string, mixed>, contentElements: list<array<string, string>>}>
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
     * Split body content by CE markers and extract typed content elements.
     *
     * @return list<array<string, string>>
     */
    private function parseContentElements(string $body): array
    {
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
     * Parse a CE marker string like "textmedia, image: placeholder://team.jpg, position: right"
     * into an associative array.
     *
     * @return array<string, string>
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
