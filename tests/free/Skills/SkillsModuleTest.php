<?php

namespace WPMCP\Tests\Free\Skills;

use WPMCP\Admin\Skills_Settings_Page;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Skills\Skill_Library;
use WPMCP\Skills\Skills_Module;

/**
 * The module toggle of issue #74.
 *
 * The acceptance criterion is "removes the skills surface AND its token
 * footprint entirely", so the assertions here are about REGISTRATION, not
 * about hiding: with the module off, neither ability exists in the registrar
 * at all, which is what keeps it out of tools/list and out of the payload a
 * client pays for on connect.
 */
class SkillsModuleTest extends \WP_UnitTestCase
{
    use Skill_Fixtures;

    protected function tearDown(): void
    {
        delete_option(Skills_Module::OPTION);
        $this->clean_up_skill_fixtures();
        parent::tearDown();
    }

    /** @return string[] ability names produced by a full registration pass. */
    private function registered_names(): array
    {
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        return array_map(fn ($a) => $a->name, $registrar->all());
    }

    public function test_the_module_is_on_by_default(): void
    {
        delete_option(Skills_Module::OPTION);

        $this->assertTrue(Skills_Module::is_enabled());
        $names = $this->registered_names();
        $this->assertContains('wpmcp/list-skills', $names);
        $this->assertContains('wpmcp/get-skill', $names);
    }

    public function test_the_skill_abilities_are_free_read_only_and_in_their_own_domain(): void
    {
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        foreach (['wpmcp/list-skills', 'wpmcp/get-skill'] as $name) {
            $ability = $registrar->get($name);
            $this->assertNotNull($ability);
            $this->assertSame('free', $ability->tier, 'The skill tools themselves are free; only skill bodies tier.');
            $this->assertSame('skills', $ability->domain);
            $this->assertSame('read', $ability->operation);
            $this->assertSame('edit_posts', $ability->capability);
            $this->assertTrue($ability->read_only_hint);
            $this->assertFalse($ability->destructive_hint);
        }
    }

    public function test_turning_the_option_off_unregisters_the_whole_surface(): void
    {
        update_option(Skills_Module::OPTION, '');

        $this->assertFalse(Skills_Module::is_enabled());

        $names = $this->registered_names();
        $this->assertNotContains('wpmcp/list-skills', $names);
        $this->assertNotContains('wpmcp/get-skill', $names);
        // Nothing else moved.
        $this->assertContains('wpmcp/get-page', $names);
    }

    public function test_a_zero_string_also_disables(): void
    {
        update_option(Skills_Module::OPTION, '0');
        $this->assertFalse(Skills_Module::is_enabled());
    }

    public function test_the_filter_can_disable_the_surface_without_an_option(): void
    {
        $filter = '__return_false';
        add_filter('wpmcp_skills_enabled', $filter);
        try {
            $this->assertFalse(Skills_Module::is_enabled());
            $this->assertNotContains('wpmcp/get-skill', $this->registered_names());
        } finally {
            remove_filter('wpmcp_skills_enabled', $filter);
        }
    }

    public function test_disabling_removes_the_tools_list_bytes_they_cost(): void
    {
        $with = $this->tools_list_bytes();

        update_option(Skills_Module::OPTION, '');
        $without = $this->tools_list_bytes();

        $this->assertLessThan($with, $without, 'Disabling the module must shrink the advertised payload.');
    }

    private function tools_list_bytes(): int
    {
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        $payload = [];
        foreach ($registrar->all() as $ability) {
            $payload[] = [
                'name'        => $ability->name,
                'description' => $ability->description,
                'inputSchema' => $ability->input_schema,
            ];
        }

        return strlen((string) wp_json_encode($payload));
    }

    public function test_sanitize_normalizes_checkbox_submissions(): void
    {
        $this->assertSame('1', Skills_Module::sanitize('1'));
        $this->assertSame('1', Skills_Module::sanitize('on'));
        $this->assertSame('1', Skills_Module::sanitize(true));
        $this->assertSame('', Skills_Module::sanitize(''));
        $this->assertSame('', Skills_Module::sanitize('0'));
        $this->assertSame('', Skills_Module::sanitize(null));
    }

    public function test_the_setting_is_registered_with_its_sanitizer(): void
    {
        Skills_Settings_Page::register_setting();

        $this->assertSame('', sanitize_option(Skills_Module::OPTION, '0'));
        $this->assertSame('1', sanitize_option(Skills_Module::OPTION, 'on'));
    }

    public function test_the_skills_submenu_is_registered_under_the_management_capability(): void
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Plugin::instance()->register_admin_menu();

        $found = null;
        foreach ($submenu['wpmcp'] ?? [] as $item) {
            if (Skills_Settings_Page::SLUG === $item[2]) {
                $found = $item;
                break;
            }
        }

        $this->assertNotNull($found, 'Expected a wpmcp-skills submenu entry.');
        $this->assertSame(\WPMCP\Admin\Management_Access::CAPABILITY, $found[1]);
    }

    public function test_the_screen_shows_the_toggle_and_the_served_catalog(): void
    {
        ob_start();
        (new Skills_Settings_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString(Skills_Module::OPTION, $html);
        $this->assertStringContainsString('wpmcp-safe-writes', $html);
        // The Elementor playbook needs pro-tier builder tools, absent on an
        // unlicensed site, so the screen must explain why it is not served.
        $this->assertStringContainsString('Hidden, requires:', $html);
    }

    public function test_the_screen_names_rejected_documents_and_their_errors(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'broken', "---\nname: Broken\n---\n\nNothing else.\n");
        $this->use_only_source($dir);

        ob_start();
        (new Skills_Settings_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Rejected documents', $html);
        $this->assertStringContainsString('broken/SKILL.md', $html);
        $this->assertStringContainsString('missing_version', $html);
        $this->assertStringContainsString('No skills found.', $html);
    }

    public function test_the_screen_marks_a_pro_skill_as_licence_gated(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'premium-playbook', $this->skill_doc(['tier' => 'pro']));
        $this->use_only_source($dir);

        ob_start();
        (new Skills_Settings_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('premium-playbook', $html);
        $this->assertStringContainsString('body needs a Pro licence', $html);
        $this->assertSame([], Skill_Library::invalid());
    }
}
