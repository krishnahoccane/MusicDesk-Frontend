<?php

namespace App\Licensing;

use ZipArchive;

/**
 * Reads a version's release notes from the CHANGELOG.md inside a release package (LabelDesk's
 * build ships it at labeldesk-<version>/CHANGELOG.md; add-ons at <slug>/CHANGELOG.md).
 *
 * The file follows Keep a Changelog: "## [4.3.3] — 2026-11-23", then "### Added / Changed / Fixed /
 * Security / Removed / Deprecated" with "- " bullets that may wrap onto indented lines. Sections other
 * than those (e.g. notes for MusicDesk.tech) are left out, since customers see the changelog.
 */
class ReleaseChangelog
{
    private const KINDS = [
        'added' => 'new', 'new' => 'new',
        'changed' => 'improved', 'improved' => 'improved', 'removed' => 'improved', 'deprecated' => 'improved',
        'fixed' => 'fixed',
        'security' => 'security',
    ];

    /**
     * Notes for $version from the package's CHANGELOG.md: null when the package has no
     * CHANGELOG.md, [] when it has no section for that version.
     *
     * @return list<array{kind: string, text: string}>|null
     */
    public static function fromPackage(string $zipPath, string $version): ?array
    {
        $markdown = self::changelogFile($zipPath);

        return $markdown === null ? null : self::parse($markdown, $version);
    }

    /** @return list<array{kind: string, text: string}> */
    public static function parse(string $markdown, string $version): array
    {
        $lines = preg_split('/\R/', $markdown);
        $quoted = preg_quote($version, '/');
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^##\s+\[?v?'.$quoted.'\]?(\s|$)/i', $line)) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return [];
        }

        $notes = [];
        $kind = null;          // null = a section customers don't see
        $current = null;
        $flush = function () use (&$notes, &$current, &$kind) {
            if ($current !== null && $kind !== null) {
                $text = trim(preg_replace('/\s+/', ' ', $current));
                $text = trim(str_replace(['**', '`'], '', $text));
                if ($text !== '') {
                    $notes[] = ['kind' => $kind, 'text' => $text];
                }
            }
            $current = null;
        };

        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^##\s/', $line)) {
                break;   // next version
            }
            if (preg_match('/^###\s+(.+)$/', $line, $m)) {
                $flush();
                $kind = self::KINDS[strtolower(trim($m[1]))] ?? null;

                continue;
            }
            if (preg_match('/^\s{0,3}[-*+]\s+(.*)$/', $line, $m)) {
                $flush();
                $current = $m[1];

                continue;
            }
            if ($current !== null && trim($line) !== '' && preg_match('/^\s+/', $line)) {
                $current .= ' '.trim($line);   // wrapped bullet

                continue;
            }
            if (trim($line) === '') {
                $flush();
            }
        }
        $flush();

        return $notes;
    }

    /** CHANGELOG.md at the ZIP root or in its single top folder. */
    private static function changelogFile(string $zipPath): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            $root = $zip->getFromName('CHANGELOG.md');
            if ($root !== false) {
                return $root;
            }
            $first = (string) $zip->getNameIndex(0);
            $top = explode('/', $first, 2)[0];
            $nested = $top !== '' && str_contains($first, '/') ? $zip->getFromName($top.'/CHANGELOG.md') : false;

            return $nested === false ? null : $nested;
        } finally {
            $zip->close();
        }
    }
}
