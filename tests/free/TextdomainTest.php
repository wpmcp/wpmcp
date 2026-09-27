<?php

namespace WPMCP\Tests\Free;

use WPMCP\Plugin;

/**
 * Self-hosted translations (issue #184). The Domain Path header points at
 * languages/, the off-directory builds ship that directory, and boot() hooks
 * Plugin::load_textdomain() on init so a .mo placed there actually loads.
 * The domain comes from WPMCP_TEXT_DOMAIN in each build's main file: the
 * WooCommerce build rewrites every string in src/ to its own slug, so the
 * loader must follow the bootstrap rather than carry a literal.
 */
class TextdomainTest extends \WP_UnitTestCase
{
    private const MO_FIXTURE = '/languages/plugins/internationalized-plugin-de_DE.mo';

    private string $languages_dir = '';

    private string $original_locale = 'en_US';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original_locale = \WP_Translation_Controller::get_instance()->get_locale();
        // The exact directory the loader hands WordPress: the checkout is
        // outside WP_PLUGIN_DIR under the test bootstrap, so plugin_basename()
        // yields the checkout path and the directory has to be created here.
        $this->languages_dir = WP_PLUGIN_DIR . '/' . trim(dirname(plugin_basename(WPMCP_FILE)) . '/languages', '/');
    }

    protected function tearDown(): void
    {
        unload_textdomain(Plugin::text_domain(), true);
        \WP_Translation_Controller::get_instance()->set_locale($this->original_locale);
        $GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();
        $GLOBALS['wp_textdomain_registry']->init();
        if (is_dir($this->languages_dir)) {
            foreach (glob($this->languages_dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->languages_dir);
        }
        parent::tearDown();
    }

    public function test_boot_hooks_the_loader_on_init(): void
    {
        $this->assertSame(10, has_action('init', [Plugin::instance(), 'load_textdomain']));
    }

    public function test_the_domain_matches_every_main_file_header(): void
    {
        $this->assertSame('wpmcp', Plugin::text_domain());
        $this->assertSame('wpmcp', $this->declared_text_domain(WPMCP_FILE));
        $this->assertSame('wpmcp', $this->defined_text_domain(WPMCP_FILE));

        // The WooCommerce build's main file declares its own domain, and its
        // WPMCP_TEXT_DOMAIN has to agree with the header the sniff reads.
        $woo = dirname(WPMCP_FILE) . '/scripts/flavors/woocommerce/wpmcp-for-woocommerce.php';
        $this->assertSame('wpmcp-for-woocommerce', $this->declared_text_domain($woo));
        $this->assertSame('wpmcp-for-woocommerce', $this->defined_text_domain($woo));
        $this->assertStringContainsString('Domain Path: /languages', (string) file_get_contents($woo));
        $this->assertStringContainsString('Domain Path: /languages', (string) file_get_contents(WPMCP_FILE));
    }

    public function test_a_mo_in_the_languages_directory_loads_through_the_init_hook(): void
    {
        $domain = Plugin::text_domain();
        $this->assertFalse(is_textdomain_loaded($domain));

        $this->assertTrue(wp_mkdir_p($this->languages_dir));
        $this->assertTrue(copy(DIR_TESTDATA . self::MO_FIXTURE, $this->languages_dir . '/' . $domain . '-de_DE.mo'));

        // A German site: determine_locale() drives the just-in-time loader,
        // the controller's locale drives the lookup.
        add_filter('locale', static fn () => 'de_DE');
        \WP_Translation_Controller::get_instance()->set_locale('de_DE');

        Plugin::instance()->load_textdomain();

        // The first lookup for the domain triggers the load from languages/.
        $this->assertSame('Das ist ein Dummy Plugin', __('This is a dummy plugin', $domain));
        $this->assertTrue(is_textdomain_loaded($domain));
    }

    public function test_the_loader_is_a_no_op_without_a_languages_directory(): void
    {
        $domain = Plugin::text_domain();

        Plugin::instance()->load_textdomain();

        $this->assertSame('This is a dummy plugin', __('This is a dummy plugin', $domain));
        $this->assertFalse(is_textdomain_loaded($domain));
    }

    private function declared_text_domain(string $file): string
    {
        return (string) (get_file_data($file, ['domain' => 'Text Domain'])['domain'] ?? '');
    }

    private function defined_text_domain(string $file): string
    {
        preg_match("/define\\(\\s*'WPMCP_TEXT_DOMAIN',\\s*'([^']+)'\\s*\\)/", (string) file_get_contents($file), $m);
        return $m[1] ?? '';
    }
}
