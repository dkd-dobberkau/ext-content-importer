# TYPO3 Content Importer

TYPO3 v13 extension that imports Markdown files with YAML frontmatter as pages and content elements. Designed as the counterpart to [t3-content-library](https://github.com/dkd-dobberkau/t3-content-library), which generates the Markdown files.

## What it does

The extension provides a CLI command `content:import` that:

1. Reads a directory of Markdown files with YAML frontmatter
2. Parses page metadata (title, slug, nav position, parent)
3. Extracts content elements from `<!-- CE: type -->` annotations
4. Creates TYPO3 pages and content elements via DataHandler

Supported content element types: `header`, `text`, `textmedia`, `quote`, `bullets`, `table`, `accordion`, `shortcut`, `uploads`, `menu`.

## Requirements

- TYPO3 v13.4
- PHP 8.2+

## Installation

```bash
composer require dkd/content-importer
```

Or for local development, add to `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./local_packages/content_importer"
        }
    ]
}
```

## Usage

```bash
bin/typo3 content:import /path/to/markdown-files --pid=1
```

**Options:**
- `path` — Directory containing Markdown files (required)
- `--pid`, `-p` — Parent page UID under which pages are created (default: `0` = root level)

### Expected Markdown format

```markdown
---
title: "Über uns"
slug: "ueber-uns"
parent: "/"
nav_position: 2
seo:
  title: "Über uns - Firma GmbH"
  description: "Über uns von Firma GmbH"
---

<!-- CE: header -->
# Willkommen bei Firma GmbH

<!-- CE: textmedia, image: placeholder://team.jpg, position: right -->
Seit 2005 servieren wir authentische Küche.

<!-- CE: text, subtype: bullets -->
- **Qualität** — Frische Zutaten
- **Service** — Herzlich
```

## Project Structure

```
ext-content-importer/
├── Classes/
│   ├── Command/ImportCommand.php        # CLI command
│   ├── Parser/MarkdownPageParser.php    # Markdown + frontmatter parser
│   └── Service/PageImportService.php    # DataHandler page/CE creation
├── Configuration/
│   └── Services.yaml                    # Symfony DI config
├── Tests/
│   ├── Fixtures/sample-page.md
│   └── Unit/
├── composer.json
├── ext_emconf.php
└── phpunit.xml
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

[MIT](LICENSE)
