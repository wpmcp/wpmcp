<?php

namespace WPMCP\Tests\Free\Release;

use PHPUnit\Framework\TestCase;

/**
 * The test cache grew to 73 GB in one night (9 to 10 November 2026) and
 * filled the disk: every branch that touched an installer or a pin built its
 * own ~700 MB install, many worktrees ran at once, and the only pruning was
 * "unused for three days". bin/test-local.sh now keeps the cache inside a
 * count and size budget (LRU by .last-used), refuses to start on a nearly
 * full disk, expires unused installs after hours, drops the core copies of
 * dead runs at once, and limits how many runs go at the same time.
 *
 * Nothing here starts a database or reaches the network: every run points at
 * a temporary WPMCP_TEST_CACHE holding fake install directories, `df` is a
 * stand-in on PATH, and WPMCP_MARIADB_BIN names stand-ins that fail, so a run
 * that gets past the guards stops at the database.
 */
class TestCacheHygieneTest extends TestCase
{
    private string $tmp;
    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp   = sys_get_temp_dir() . '/wpmcp-hyg-' . bin2hex(random_bytes(4));
        $this->cache = $this->tmp . '/c';
        mkdir($this->cache, 0777, true);
        mkdir($this->tmp . '/bin');
        mkdir($this->tmp . '/db');

        // df stand-in: reports STUB_DF_FREE_KB available on any path.
        file_put_contents($this->tmp . '/bin/df', <<<'SH'
#!/usr/bin/env bash
echo "Filesystem 1024-blocks Used Available Capacity Mounted on"
echo "/dev/stub 999999999 1 ${STUB_DF_FREE_KB:-104857600} 1% /"
SH);
        chmod($this->tmp . '/bin/df', 0755);

        // MariaDB stand-ins: never answer, and record any attempt to start.
        foreach (['mariadb-admin', 'mariadb', 'mariadbd', 'mariadb-install-db'] as $tool) {
            file_put_contents($this->tmp . "/db/$tool", "#!/usr/bin/env bash\necho $tool >>\"\$STUB_DB_LOG\"\nexit 1\n");
            chmod($this->tmp . "/db/$tool", 0755);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function environment(array $env): array
    {
        return $env + [
            'PATH'                => $this->tmp . '/bin:' . (string) getenv('PATH'),
            'HOME'                => (string) getenv('HOME'),
            'TMPDIR'              => $this->tmp,
            'WPMCP_TEST_PHP'      => PHP_BINARY,
            'WPMCP_TEST_CACHE'    => $this->cache,
            'WPMCP_MARIADB_BIN'   => $this->tmp . '/db',
            'STUB_DB_LOG'         => $this->tmp . '/db.log',
            // No git repository: a run must not touch this clone's hooks config.
            'GIT_DIR'             => $this->tmp . '/no-git',
            'WPMCP_PLUGIN_INFO_URL' => 'file://' . $this->tmp . '/offline/%s.json',
        ];
    }

    /**
     * @param string[]              $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string} exit status, stdout and stderr
     */
    private function run_gate(array $args, array $env = []): array
    {
        $command = array_merge(['bash', $this->repository() . '/bin/test-local.sh'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repository(), $this->environment($env));
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout . $stderr];
    }

    /** A fake ready install last used $age seconds ago, holding $kb of data. */
    private function install(string $name, int $age, int $kb = 4): string
    {
        $dir = $this->cache . '/' . $name;
        mkdir($dir . '/wordpress', 0777, true);
        file_put_contents($dir . '/wordpress/payload', str_repeat('x', $kb * 1024));
        touch($dir . '/.ready', time() - $age);
        touch($dir . '/.last-used', time() - $age);

        return $dir;
    }

    /** Installs wp-7.1-…-plugins-0000000<n>, n = 1 newest, each an hour older than the last. */
    private function installs(int $count, int $step = 3600, int $kb = 4): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $this->install($this->name($n), $n * $step, $kb);
        }
    }

    private function name(int $n): string
    {
        return sprintf('wp-7.1-aaaaaaaaaaaa-plugins-%08d', $n);
    }

    /** @return string[] the install directories left in the cache, sorted. */
    private function left(): array
    {
        $dirs = array_map('basename', glob($this->cache . '/wp-*', GLOB_ONLYDIR) ?: []);
        sort($dirs);

        return $dirs;
    }

    /** @param int[] $numbers */
    private function names(array $numbers): array
    {
        $names = array_map([$this, 'name'], $numbers);
        sort($names);

        return $names;
    }

    private function dead_pid(): int
    {
        return (int) trim((string) shell_exec('sh -c \'echo $$\''));
    }

    public function test_prune_keeps_the_most_recently_used_installs_within_the_count(): void
    {
        $this->installs(8);

        [$status, $out] = $this->run_gate(['--prune'], ['WPMCP_TEST_KEEP_INSTALLS' => '3', 'WPMCP_TEST_INSTALL_TTL_HOURS' => '100']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([1, 2, 3]), $this->left(), $out);
    }

    public function test_the_default_count_budget_is_six(): void
    {
        // Used 20 to 160 minutes ago: inside the default six hour TTL.
        $this->installs(8, 1200);

        [$status, $out] = $this->run_gate(['--prune']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([1, 2, 3, 4, 5, 6]), $this->left(), $out);
    }

    public function test_prune_keeps_the_total_size_within_the_budget(): void
    {
        $this->installs(5, 3600, 1024);

        // 3.5 MB: three 1 MB installs fit, four do not.
        [$status, $out] = $this->run_gate(['--prune'], [
            'WPMCP_TEST_KEEP_INSTALLS'     => '10',
            'WPMCP_TEST_MAX_GB'            => (string) (3.5 / 1024),
            'WPMCP_TEST_INSTALL_TTL_HOURS' => '100',
        ]);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([1, 2, 3]), $this->left(), $out);
    }

    public function test_unused_installs_expire_after_the_default_ttl_of_six_hours(): void
    {
        $this->install($this->name(1), 5 * 3600);
        $this->install($this->name(2), 7 * 3600);

        [$status, $out] = $this->run_gate(['--prune']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([1]), $this->left(), $out);
    }

    public function test_prune_never_removes_an_install_in_use_or_locked(): void
    {
        $this->installs(5);
        // Oldest: a live run uses it.
        mkdir($this->cache . '/' . $this->name(5) . '/.users');
        touch($this->cache . '/' . $this->name(5) . '/.users/' . getmypid());
        // Next: its pristine copy is being made by a live run.
        mkdir($this->cache . '/locks/pristine-' . $this->name(4), 0777, true);
        file_put_contents($this->cache . '/locks/pristine-' . $this->name(4) . '/pid', (string) getmypid());
        // Next: the run that used it is dead.
        $dead = $this->dead_pid();
        mkdir($this->cache . '/' . $this->name(3) . '/.users');
        touch($this->cache . '/' . $this->name(3) . '/.users/' . $dead);

        [$status, $out] = $this->run_gate(['--prune'], ['WPMCP_TEST_KEEP_INSTALLS' => '1', 'WPMCP_TEST_INSTALL_TTL_HOURS' => '100']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([1, 4, 5]), $this->left(), $out);
    }

    public function test_an_install_used_minutes_ago_by_an_older_copy_of_the_script_is_kept(): void
    {
        // Older copies of the script in other worktrees leave no .users
        // marker; a fresh .last-used is all that says they are running.
        $this->installs(3);
        $this->install($this->name(9), 300);

        [$status, $out] = $this->run_gate(['--prune'], ['WPMCP_TEST_KEEP_INSTALLS' => '1', 'WPMCP_TEST_INSTALL_TTL_HOURS' => '100']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([9]), $this->left(), $out);
    }

    public function test_prune_removes_failed_builds_but_not_one_being_built(): void
    {
        $this->installs(1);
        // A build that died: no .ready, nobody holds its lock.
        mkdir($this->cache . '/' . $this->name(2) . '/tmp', 0777, true);
        file_put_contents($this->cache . '/' . $this->name(2) . '/install.log', 'failed');
        // A build in progress: its install lock is held by a live run.
        mkdir($this->cache . '/' . $this->name(3) . '/tmp', 0777, true);
        mkdir($this->cache . '/locks/install-' . $this->name(3), 0777, true);
        file_put_contents($this->cache . '/locks/install-' . $this->name(3) . '/pid', (string) getmypid());

        [$status, $out] = $this->run_gate(['--prune']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([1, 3]), $this->left(), $out);
    }

    public function test_prune_all_removes_every_install_not_in_use(): void
    {
        $this->installs(3);
        mkdir($this->cache . '/' . $this->name(2) . '/.users');
        touch($this->cache . '/' . $this->name(2) . '/.users/' . getmypid());
        // An install from before issue #532, with no plugins key.
        mkdir($this->cache . '/wp-6.9-bbbbbbbbbbbb/wordpress', 0777, true);

        [$status, $out] = $this->run_gate(['--prune']);
        $this->assertSame(0, $status, $out);
        $this->assertContains('wp-6.9-bbbbbbbbbbbb', $this->left(), 'a plain prune leaves pre-#532 installs alone');

        [$status, $out] = $this->run_gate(['--prune', '--all']);

        $this->assertSame(0, $status, $out);
        $this->assertSame($this->names([2]), $this->left(), $out);
    }

    public function test_core_copies_of_dead_runs_go_at_once_and_old_ones_after_a_day(): void
    {
        $cores = $this->cache . '/cores';
        mkdir($cores . '/aaaaaaaaaaaa-' . $this->dead_pid(), 0777, true);
        mkdir($cores . '/bbbbbbbbbbbb-' . getmypid(), 0777, true);
        mkdir($cores . '/cccccccccccc-' . getmypid(), 0777, true);
        touch($cores . '/cccccccccccc-' . getmypid(), time() - 3 * 86400);

        [$status, $out] = $this->run_gate(['--prune']);

        $this->assertSame(0, $status, $out);
        $this->assertSame(['bbbbbbbbbbbb-' . getmypid()], array_map('basename', glob($cores . '/*') ?: []), $out);
    }

    public function test_a_low_disk_prunes_to_the_two_most_recently_used_before_the_run(): void
    {
        $this->installs(5);

        [$status, $out] = $this->run_gate(['--wp', '7.1'], [
            'STUB_DF_FREE_KB'              => (string) (15 * 1048576),
            'WPMCP_TEST_INSTALL_TTL_HOURS' => '100',
        ]);

        $this->assertNotSame(0, $status, 'the stand-in database never starts');
        $this->assertStringContainsString('15 GB free', $out);
        $this->assertSame($this->names([1, 2]), $this->left(), $out);
        $this->assertFileExists($this->tmp . '/db.log', 'above the hard minimum the run goes on to the database');
    }

    public function test_a_nearly_full_disk_stops_the_run_before_anything_starts(): void
    {
        $this->installs(5);

        [$status, $out] = $this->run_gate(['--wp', '7.1'], [
            'STUB_DF_FREE_KB'              => (string) (5 * 1048576),
            'WPMCP_TEST_INSTALL_TTL_HOURS' => '100',
        ]);

        $this->assertNotSame(0, $status, $out);
        $this->assertStringContainsString('WPMCP_TEST_HARD_MIN_FREE_GB', $out);
        $this->assertSame($this->names([1, 2]), $this->left(), $out);
        $this->assertFileDoesNotExist($this->tmp . '/db.log', 'no database is started on a full disk');
    }

    public function test_enough_free_space_leaves_the_cache_to_the_budget(): void
    {
        $this->installs(4);

        [, $out] = $this->run_gate(['--wp', '7.1'], ['WPMCP_TEST_INSTALL_TTL_HOURS' => '100']);

        $this->assertStringNotContainsString('GB free', $out);
        $this->assertSame($this->names([1, 2, 3, 4]), $this->left(), $out);
    }

    public function test_a_run_waits_for_a_free_slot_and_gives_up_after_the_timeout(): void
    {
        mkdir($this->cache . '/locks/run-1', 0777, true);
        file_put_contents($this->cache . '/locks/run-1/pid', (string) getmypid());

        $start = time();
        [$status, $out] = $this->run_gate(['--wp', '7.1'], ['WPMCP_TEST_MAX_PARALLEL' => '1', 'WPMCP_TEST_SLOT_TIMEOUT' => '2']);

        $this->assertNotSame(0, $status, $out);
        $this->assertStringContainsString('Waiting', $out);
        $this->assertStringContainsString('WPMCP_TEST_MAX_PARALLEL', $out);
        $this->assertFileDoesNotExist($this->tmp . '/db.log', 'a run without a slot starts nothing');
        $this->assertLessThan(30, time() - $start);
        $this->assertSame((string) getmypid(), file_get_contents($this->cache . '/locks/run-1/pid'), 'the live holder keeps its slot');
    }

    public function test_a_slot_held_by_a_dead_run_is_reclaimed_and_released_on_exit(): void
    {
        mkdir($this->cache . '/locks/run-1', 0777, true);
        file_put_contents($this->cache . '/locks/run-1/pid', (string) $this->dead_pid());

        [$status, $out] = $this->run_gate(['--wp', '7.1'], ['WPMCP_TEST_MAX_PARALLEL' => '1', 'WPMCP_TEST_SLOT_TIMEOUT' => '5']);

        $this->assertNotSame(0, $status, 'the stand-in database never starts');
        $this->assertStringNotContainsString('Waiting', $out);
        $this->assertFileExists($this->tmp . '/db.log', 'the reclaimed slot let the run start');
        $this->assertDirectoryDoesNotExist($this->cache . '/locks/run-1', 'the slot is released when the run exits');
    }

    public function test_a_terminated_run_releases_its_slot_and_prunes_to_the_budget(): void
    {
        $this->installs(5);
        // Two slots, one held by a live run: the run under test takes the other,
        // then waits on the database lock, held by a live run too.
        mkdir($this->cache . '/locks/run-1', 0777, true);
        file_put_contents($this->cache . '/locks/run-1/pid', (string) getmypid());
        mkdir($this->cache . '/locks/db', 0777, true);
        file_put_contents($this->cache . '/locks/db/pid', (string) getmypid());

        $command = ['bash', $this->repository() . '/bin/test-local.sh', '--wp', '7.1'];
        $env     = $this->environment(['WPMCP_TEST_KEEP_INSTALLS' => '2', 'WPMCP_TEST_INSTALL_TTL_HOURS' => '100']);
        $process = proc_open($command, [1 => ['file', $this->tmp . '/out.log', 'w'], 2 => ['file', $this->tmp . '/out.log', 'a']], $pipes, $this->repository(), $env);
        $this->assertIsResource($process);

        $slot = null;
        for ($i = 0; $i < 100 && null === $slot; $i++) {
            usleep(100000);
            $slot = is_dir($this->cache . '/locks/run-2') ? $this->cache . '/locks/run-2' : null;
        }
        $this->assertNotNull($slot, 'the run took the free slot: ' . file_get_contents($this->tmp . '/out.log'));
        // The installs are not pruned before the run ends: none is new.
        $this->assertCount(5, $this->left());
        sleep(1);

        proc_terminate($process, 15);
        $status = proc_close($process);

        $out = (string) file_get_contents($this->tmp . '/out.log');
        $this->assertNotSame(0, $status, $out);
        $this->assertDirectoryDoesNotExist($this->cache . '/locks/run-2', 'the slot is released on TERM: ' . $out);
        $this->assertDirectoryExists($this->cache . '/locks/run-1', 'another run\'s slot is left alone');
        $this->assertSame($this->names([1, 2]), $this->left(), 'the exit trap prunes to the budget: ' . $out);
    }

    public function test_the_header_documents_the_budget_variables_and_prune(): void
    {
        [$status, $out] = $this->run_gate(['--help']);

        $this->assertSame(0, $status, $out);
        foreach (['--prune', 'WPMCP_TEST_KEEP_INSTALLS', 'WPMCP_TEST_MAX_GB', 'WPMCP_TEST_MIN_FREE_GB', 'WPMCP_TEST_HARD_MIN_FREE_GB', 'WPMCP_TEST_INSTALL_TTL_HOURS', 'WPMCP_TEST_MAX_PARALLEL', 'WPMCP_TEST_SLOT_TIMEOUT'] as $name) {
            $this->assertStringContainsString($name, $out);
        }
    }
}
