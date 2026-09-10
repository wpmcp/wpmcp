<?php
/**
 * Fail a vertical build whose prune list left a dangling inheritance edge.
 *
 * Usage: php scripts/lib/check-dangling-inheritance.php <staged-src-dir>
 *
 * The flavor gate in Plugin.php is a RUNTIME check, so a `use` import of a
 * pruned class is harmless (PHP never resolves it unless the symbol is
 * touched). An `extends` / `implements` edge is not: PHP resolves the parent
 * the moment the child class is autoloaded, and a child a still-registered
 * ability group instantiates therefore fatals with
 * `Error: Class "..." not found` on every request.
 *
 * That is exactly the shape that shipped in the first cut of the WooCommerce
 * operations catalog (issue #68): Woo_Read extended Tools\Rest\Call_Rest
 * while build-woo-release.sh prunes src/Tools/Rest, and the woocommerce
 * flavor still runs register_woocommerce_abilities(), which instantiates it.
 * Only inheritance edges are checked, so the many legitimate lazy imports in
 * Plugin.php stay quiet.
 *
 * Exits 1 and prints every offending edge, or 0 when the tree is coherent.
 */

$dir = $argv[1] ?? '';
if ('' === $dir || ! is_dir($dir)) {
    fwrite(STDERR, "usage: check-dangling-inheritance.php <src-dir>\n");
    exit(2);
}

$declared = [];
$edges    = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($it as $file) {
    if ('php' !== $file->getExtension()) {
        continue;
    }
    $path = $file->getPathname();
    $src  = file_get_contents($path);

    $namespace = preg_match('/^\s*namespace\s+([^;]+);/m', $src, $m) ? trim($m[1]) : '';

    // Alias map from this file's imports, so `extends Call_Rest` can be
    // resolved back to WPMCP\Tools\Rest\Call_Rest.
    $aliases = [];
    if (preg_match_all('/^\s*use\s+([^;\s]+?)(?:\s+as\s+(\w+))?\s*;/mi', $src, $us, PREG_SET_ORDER)) {
        foreach ($us as $u) {
            $fqcn  = ltrim($u[1], '\\');
            $short = $u[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            $aliases[$short] = $fqcn;
        }
    }

    $pattern = '/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)'
        . '(?:\s+extends\s+([\\\\\w]+))?'
        . '(?:\s+implements\s+([\\\\\w,\s]+?))?\s*(?:\{|$)/m';
    if (! preg_match_all($pattern, $src, $cs, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($cs as $c) {
        $declared[ ('' === $namespace ? '' : $namespace . '\\') . $c[1] ] = true;

        $parents = [];
        if (! empty($c[2])) {
            $parents[] = $c[2];
        }
        if (! empty($c[3])) {
            foreach (explode(',', $c[3]) as $iface) {
                $parents[] = trim($iface);
            }
        }

        foreach ($parents as $parent) {
            $parent = trim($parent);
            if ('' === $parent) {
                continue;
            }
            if ('\\' === $parent[0]) {
                $fqcn = ltrim($parent, '\\');
            } elseif (false !== strpos($parent, '\\')) {
                $head = strstr($parent, '\\', true);
                $fqcn = isset($aliases[$head])
                    ? $aliases[$head] . substr($parent, strlen($head))
                    : ('' === $namespace ? $parent : $namespace . '\\' . $parent);
            } elseif (isset($aliases[$parent])) {
                $fqcn = $aliases[$parent];
            } else {
                $fqcn = ('' === $namespace ? $parent : $namespace . '\\' . $parent);
            }

            // Only this plugin's own classes can be pruned by the build.
            if (0 !== strpos($fqcn, 'WPMCP\\')) {
                continue;
            }
            $edges[] = [ $path, $c[1], $fqcn ];
        }
    }
}

$bad = [];
foreach ($edges as [$path, $child, $parent]) {
    if (! isset($declared[$parent])) {
        $bad[] = sprintf('%s: %s inherits from pruned class %s', $path, $child, $parent);
    }
}

if ($bad) {
    fwrite(STDERR, implode("\n", $bad) . "\n");
    exit(1);
}
exit(0);
