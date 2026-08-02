<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Merge;

/**
 * Line-oriented three-way merge (diff3).
 *
 * Given the output Anvil produced last time (`$base`), the file as it stands on
 * disk now (`$local`, possibly hand-edited), and the output Anvil would produce
 * today (`$remote`), produce a single file that keeps both sets of changes.
 *
 * Behaviour is deliberately identical to `git merge-file`, including the cases
 * where that means conflicting: two edits with no unchanged line between them
 * conflict even when they are logically independent. Generated stubs should
 * separate class members with a blank line, which is enough to keep adjacent
 * edits mergeable.
 *
 * This class has no dependencies and no I/O. It is pure string in, string out.
 */
final readonly class ThreeWayMerge
{
    /**
     * Cap on the LCS table. Beyond this the merge degrades to a whole-file
     * conflict rather than allocating an unbounded table. Generated files are
     * far smaller than this in practice; the guard exists for pathological
     * input, not for normal operation.
     */
    private const int MAX_CELLS = 4_000_000;

    public function __construct(
        private string $localLabel = 'your changes',
        private string $remoteLabel = 'anvil',
    ) {}

    public function merge(string $base, string $local, string $remote): MergeResult
    {
        // Fast paths. These are the common cases and skipping the diff for them
        // keeps a full regeneration cheap.
        if ($local === $remote) {
            return MergeResult::clean($local);
        }

        if ($base === $local) {
            return MergeResult::clean($remote);
        }

        if ($base === $remote) {
            return MergeResult::clean($local);
        }

        $eol = $this->detectEol($local);

        $b = $this->split($base);
        $l = $this->split($local);
        $r = $this->split($remote);

        if (count($b) * max(count($l), count($r)) > self::MAX_CELLS) {
            return $this->degraded($l, $r, $eol);
        }

        $out = [];
        $conflicts = [];

        foreach ($this->chunk($b, $l, $r) as $chunk) {
            if ($chunk->stable) {
                array_push($out, ...$chunk->base);

                continue;
            }

            $resolved = $this->resolve($chunk);

            if ($resolved !== null) {
                array_push($out, ...$resolved);

                continue;
            }

            $conflicts[] = count($out) + 1;
            $out[] = '<<<<<<< '.$this->localLabel;
            array_push($out, ...$chunk->local);
            $out[] = '=======';
            array_push($out, ...$chunk->remote);
            $out[] = '>>>>>>> '.$this->remoteLabel;
        }

        return new MergeResult(implode($eol, $out), $conflicts);
    }

    /**
     * Resolve an unstable chunk, or null when it genuinely conflicts.
     *
     * @return list<string>|null
     */
    private function resolve(MergeChunk $chunk): ?array
    {
        if ($chunk->local === $chunk->remote) {
            return $chunk->local;      // both sides made the same edit
        }

        if ($chunk->base === $chunk->local) {
            return $chunk->remote;     // only Anvil changed this region
        }

        if ($chunk->base === $chunk->remote) {
            return $chunk->local;      // only the developer changed this region
        }

        return null;
    }

    /**
     * Split the three inputs into stable and unstable chunks.
     *
     * A base line is a stable anchor only when it is aligned in *both* local
     * and remote at or beyond the current cursors. Everything between anchors
     * is unstable and gets resolved or conflicted as a unit.
     *
     * @param  list<string>  $base
     * @param  list<string>  $local
     * @param  list<string>  $remote
     * @return list<MergeChunk>
     */
    private function chunk(array $base, array $local, array $remote): array
    {
        $lm = $this->matches($base, $local);
        $rm = $this->matches($base, $remote);

        $nb = count($base);
        $nl = count($local);
        $nr = count($remote);

        $bi = $li = $ri = 0;
        $chunks = [];

        while (true) {
            $stable = null;

            for ($b = $bi; $b < $nb; $b++) {
                if (isset($lm[$b], $rm[$b]) && $lm[$b] >= $li && $rm[$b] >= $ri) {
                    $stable = $b;
                    break;
                }
            }

            if ($stable === null) {
                if ($bi < $nb || $li < $nl || $ri < $nr) {
                    $chunks[] = MergeChunk::unstable(
                        array_slice($base, $bi),
                        array_slice($local, $li),
                        array_slice($remote, $ri),
                    );
                }

                break;
            }

            $sl = $lm[$stable];
            $sr = $rm[$stable];

            if ($stable > $bi || $sl > $li || $sr > $ri) {
                $chunks[] = MergeChunk::unstable(
                    array_slice($base, $bi, $stable - $bi),
                    array_slice($local, $li, $sl - $li),
                    array_slice($remote, $ri, $sr - $ri),
                );
            }

            // Extend the anchor into a run while all three advance in lockstep.
            $run = 0;

            while (
                $stable + $run < $nb
                && isset($lm[$stable + $run], $rm[$stable + $run])
                && $lm[$stable + $run] === $sl + $run
                && $rm[$stable + $run] === $sr + $run
            ) {
                $run++;
            }

            $chunks[] = MergeChunk::stable(array_slice($base, $stable, $run));

            $bi = $stable + $run;
            $li = $sl + $run;
            $ri = $sr + $run;

            if ($bi >= $nb && $li >= $nl && $ri >= $nr) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Longest-common-subsequence alignment: index in $a to index in $b.
     *
     * Common prefix and suffix are matched directly and excluded from the DP
     * table, which for regenerated files collapses the table to near nothing —
     * the vast majority of any two generations of the same file is identical.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array<int,int>
     */
    private function matches(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        $prefix = 0;

        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while (
            $suffix < $n - $prefix
            && $suffix < $m - $prefix
            && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]
        ) {
            $suffix++;
        }

        $out = [];

        for ($i = 0; $i < $prefix; $i++) {
            $out[$i] = $i;
        }

        $middleA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $middleB = array_slice($b, $prefix, $m - $prefix - $suffix);

        foreach ($this->lcs($middleA, $middleB) as $i => $j) {
            $out[$i + $prefix] = $j + $prefix;
        }

        for ($k = 0; $k < $suffix; $k++) {
            $out[$n - 1 - $k] = $m - 1 - $k;
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array<int,int>
     */
    private function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0) {
            return [];
        }

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $out = [];
        $i = $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[$i] = $j;
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $local
     * @param  list<string>  $remote
     */
    private function degraded(array $local, array $remote, string $eol): MergeResult
    {
        $out = ['<<<<<<< '.$this->localLabel];
        array_push($out, ...$local);
        $out[] = '=======';
        array_push($out, ...$remote);
        $out[] = '>>>>>>> '.$this->remoteLabel;

        return new MergeResult(implode($eol, $out), [1], degraded: true);
    }

    /**
     * @return list<string>
     */
    private function split(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return explode("\n", str_replace("\r\n", "\n", $text));
    }

    private function detectEol(string $text): string
    {
        return str_contains($text, "\r\n") ? "\r\n" : "\n";
    }
}
