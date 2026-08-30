# Issue #186: make the compliance CI job a gate

## What changed in this branch

`.github/workflows/ci.yml`: removed the last `continue-on-error: true` from the
compliance job (the distribution-profile step). The wporg-free build step was
already a hard gate via `scripts/build-wporg-release.sh`. With this change,
neither compliance step is advisory: the engine exits 1 on any blocker and the
job fails, which blocks the merge.

## Definition of done (from the issue)

- [x] Neither compliance check step carries `continue-on-error`
- [ ] A deliberately introduced blocker fails the job (verify on this PR's CI
      run: the blockers below already make the job red, which demonstrates the
      gate; remove this checkbox once observed)
- [ ] Main is green at the time the flag is removed

## Remaining work before this PR can leave draft

The distribution profile currently reports 5 blocker sites on the source tree
(run `composer compliance` locally to reproduce). All of them must be cleared,
otherwise merging this gate turns main red:

1. `src/Tools/Cli/Wp_Cli_Executor.php:53,86,87`: `fclose()` flagged under
   `WordPress.WP.AlternativeFunctions`. These close `proc_open()` pipes, where
   `WP_Filesystem` does not apply; likely resolution is a per-line phpcs ignore
   with justification, or an engine allowlist entry for pipe handles.
2. `src/Tools/Performance/Curl_Dns_Pin.php:35`: `curl_setopt()` flagged; the
   DNS pinning use case cannot be expressed through the WP HTTP API directly.
   Needs either a `http_api_curl` filter based implementation or a justified
   ignore.
3. `src/Tools/Compose/Build_Page.php:62`: pay-to-unlock copy ("pro feature")
   prohibited by guideline 9 when the feature ships in the same zip. Reword or
   gate the copy.
4. `src/Admin/Announcements.php:63`: `admin_notices` hook must be dismissible,
   self-dismissing, and free of upgrade copy outside the plugin's own settings
   screen.
5. Recheck the two `likely-reject` sites (`Transport_Guard.php:226` `ini_set()`,
   `Php_Snippet_Runner.php:47` `set_time_limit()`) since the profile may
   promote them.

Once the blockers are cleared and `composer compliance` exits 0 on this branch,
verify the gate by pushing a commit that introduces a deliberate blocker,
observing the red job, then reverting it. After that this PR can leave draft.
