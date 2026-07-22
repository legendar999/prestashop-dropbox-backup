<?php

declare(strict_types=1);

namespace Akvabackup\Files;

/**
 * Deterministic depth-first filesystem walk. The yielded FILE order is the archive
 * cursor: it MUST be stable across ticks so an interrupted archiving phase can resume.
 *
 * Ordering: at every directory the children are sorted by strcmp on their name (which,
 * for siblings, is identical to strcmp on their full relative path), then descended
 * depth-first. Two files are therefore ordered by the first differing path segment.
 * $afterRel resumes the same walk, yielding only files strictly after it in that order.
 */
final class TreeScanner
{
    private string $rootDir;
    /** @var string[] */
    private array $excludeGlobs;

    /**
     * @param string[] $excludeGlobs newline/array globs relative to root; matched with
     *                               fnmatch WITHOUT FNM_PATHNAME (so '*' spans '/').
     */
    public function __construct(string $rootDir, array $excludeGlobs)
    {
        $this->rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
        $clean = [];
        foreach ($excludeGlobs as $g) {
            $g = trim((string) $g);
            if ($g !== '') {
                $clean[] = str_replace('\\', '/', $g);
            }
        }
        $this->excludeGlobs = $clean;
    }

    /**
     * @param string|null $afterRel resume cursor: skip until strictly after this rel path
     *
     * @return \Generator<array{rel:string,abs:string,size:int}>
     */
    public function iterate(?string $afterRel = null): \Generator
    {
        yield from $this->walk('', $afterRel);
    }

    private function walk(string $relDir, ?string $afterRel): \Generator
    {
        $absDir = $relDir === '' ? $this->rootDir : $this->rootDir . '/' . $relDir;
        $entries = @scandir($absDir);
        if ($entries === false) {
            return;
        }

        $names = [];
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') {
                $names[] = $e;
            }
        }
        usort($names, 'strcmp');

        foreach ($names as $name) {
            $rel = $relDir === '' ? $name : $relDir . '/' . $name;
            $abs = $this->rootDir . '/' . $rel;

            if (is_link($abs)) {
                continue;
            }
            if ($this->isExcluded($rel)) {
                continue;
            }

            if (is_dir($abs)) {
                yield from $this->walk($rel, $afterRel);
                continue;
            }

            if ($afterRel !== null && self::relCompare($rel, $afterRel) <= 0) {
                continue;
            }
            if (!is_readable($abs)) {
                continue;
            }
            $size = @filesize($abs);
            if ($size === false) {
                continue;
            }

            yield ['rel' => $rel, 'abs' => $abs, 'size' => (int) $size];
        }
    }

    private function isExcluded(string $rel): bool
    {
        foreach ($this->excludeGlobs as $g) {
            if (fnmatch($g, $rel)) {
                return true;
            }
        }

        return false;
    }

    /** Compare two relative paths in depth-first-sorted walk order (segment-wise strcmp). */
    private static function relCompare(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $pa = explode('/', $a);
        $pb = explode('/', $b);
        $n = min(count($pa), count($pb));
        for ($i = 0; $i < $n; ++$i) {
            $c = strcmp($pa[$i], $pb[$i]);
            if ($c !== 0) {
                return $c < 0 ? -1 : 1;
            }
        }

        return count($pa) <=> count($pb);
    }
}
