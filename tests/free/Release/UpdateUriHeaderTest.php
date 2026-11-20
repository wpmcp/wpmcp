<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * The self-hosted build (wpmcp.php, the headline GitHub download) declares
 * Update URI so WordPress never offers a same-slug wordpress.org package as
 * its update; the two wordpress.org loaders must never declare it, because
 * the directory rejects any value but its own. The value lives once, in
 * scripts/lib/release-flavors.sh, which the build and release scripts share.
 */
class UpdateUriHeaderTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function shared_value(): string
    {
        $lib = (string) file_get_contents(self::root() . '/scripts/lib/release-flavors.sh');
        self::assertSame(1, preg_match("/^WPMCP_UPDATE_URI='([^']+)'$/m", $lib, $m), 'WPMCP_UPDATE_URI not found in release-flavors.sh');
        return $m[1];
    }

    public function test_the_self_hosted_loader_declares_the_shared_update_uri(): void
    {
        $main = (string) file_get_contents(self::root() . '/wpmcp.php');
        $this->assertSame(1, preg_match('/^ \* Update URI: (\S+)$/m', $main, $m), 'wpmcp.php has no Update URI header');
        $this->assertSame(self::shared_value(), $m[1]);
    }

    /** @return array<string, array{string}> */
    public static function wporg_loaders(): array
    {
        return [
            'directory'   => [ 'scripts/flavors/wporg/wpmcp.php' ],
            'woocommerce' => [ 'scripts/flavors/woocommerce/safe-mcp-for-woocommerce.php' ],
        ];
    }

    /** @dataProvider wporg_loaders */
    public function test_wporg_loaders_never_declare_update_uri(string $path): void
    {
        $loader = (string) file_get_contents(self::root() . '/' . $path);
        $this->assertNotSame('', $loader, "$path is missing");
        $this->assertDoesNotMatchRegularExpression('/^[ *]*Update URI:/mi', $loader);
    }

    public function test_every_stable_asset_name_is_unversioned_and_unique(): void
    {
        $cmd  = 'bash -c ' . escapeshellarg('. ' . escapeshellarg(self::root() . '/scripts/lib/release-flavors.sh') . ' && release_asset_table');
        $rows = array_filter(explode("\n", (string) shell_exec($cmd)));
        $this->assertNotEmpty($rows);
        $first = explode('|', reset($rows));
        $this->assertSame([ 'wpmcp', 'wpmcp/wpmcp.php', 'self-hosted', 'wpmcp.zip' ], array_slice($first, 0, 4), 'the headline asset is the self-hosted wpmcp.zip');
        $stable = [];
        foreach ($rows as $row) {
            $cols = explode('|', $row);
            $this->assertCount(5, $cols, $row);
            $this->assertContains($cols[2], [ 'self-hosted', 'wporg' ], $row);
            if ('-' !== $cols[3]) {
                $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.zip$/', $cols[3]);
                $this->assertDoesNotMatchRegularExpression('/\d+\.\d+\.\d+/', $cols[3]);
                $stable[] = $cols[3];
            }
        }
        $this->assertContains('safe-mcp-for-woocommerce.zip', $stable);
        $this->assertSame($stable, array_unique($stable));
    }
}
